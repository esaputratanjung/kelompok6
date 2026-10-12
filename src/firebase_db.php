<?php
/**
 * Koneksi Firebase (Kreait SDK) + semua logika data booking.
 *
 * Struktur Realtime Database:
 *   /bookings/{booking_id}                     -> data lengkap booking (hanya bisa dibaca pemiliknya)
 *   /schedules/{room_id}/{YYYY-MM-DD}/{id}     -> jadwal ringkas per ruang & tanggal (tanpa data pribadi)
 *   /rate_limits/{nama}/{uid}                  -> pembatas jumlah request (khusus backend)
 *
 * Jadwal per ruang per tanggal disimpan terpisah supaya pengecekan bentrok
 * cukup membaca SATU node di dalam SATU transaksi atomik.
 */

declare(strict_types=1);

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\Transaction;
use Kreait\Firebase\Exception\Database\TransactionFailed;
use Kreait\Firebase\Factory;

// ---------------------------------------------------------------------------
// Koneksi
// ---------------------------------------------------------------------------

function firebase_factory(): Factory
{
    static $factory = null;
    if ($factory !== null) {
        return $factory;
    }

    $databaseUrl = env('FIREBASE_DATABASE_URL');
    if (!$databaseUrl) {
        throw new RuntimeException('FIREBASE_DATABASE_URL belum diisi.');
    }

    $factory = (new Factory())->withDatabaseUri($databaseUrl);

    // FIREBASE_CREDENTIALS boleh berupa path file JSON service account
    // (mis. /etc/secrets/firebase.json di Render) ATAU isi JSON-nya langsung.
    $credentials = env('FIREBASE_CREDENTIALS');
    if ($credentials) {
        if (!str_starts_with($credentials, '{')) {
            // Path relatif dihitung dari folder root project.
            $isAbsolute = str_starts_with($credentials, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $credentials); // Linux atau Windows (C:\...)
            $path = $isAbsolute ? $credentials : dirname(__DIR__) . '/' . preg_replace('#^\.[\\\\/]#', '', $credentials);
            if (!is_readable($path)) {
                throw new RuntimeException("File service account tidak ditemukan: $path");
            }
            $credentials = (string) file_get_contents($path);
        }
        $serviceAccount = json_decode($credentials, true);
        if (!is_array($serviceAccount)) {
            throw new RuntimeException('FIREBASE_CREDENTIALS bukan JSON service account yang valid.');
        }
        $factory = $factory->withServiceAccount($serviceAccount);
    }
    if ($projectId = env('FIREBASE_PROJECT_ID')) {
        $factory = $factory->withProjectId($projectId);
    }

    return $factory;
}

function db(): Database
{
    static $db = null;
    return $db ??= firebase_factory()->createDatabase();
}

function firebase_auth(): Auth
{
    static $auth = null;
    return $auth ??= firebase_factory()->createAuth();
}

/**
 * Jalankan transaksi dengan retry terbatas.
 * Kreait memakai ETag: jika data berubah di antara "baca" dan "tulis",
 * penulisan ditolak (TransactionFailed) lalu kita ulangi dari awal.
 */
function with_transaction(callable $callback, int $maxAttempts = 5): mixed
{
    for ($attempt = 1; ; $attempt++) {
        try {
            return db()->runTransaction($callback);
        } catch (TransactionFailed $e) {
            if ($attempt >= $maxAttempts) {
                throw new ApiError('Server sedang sibuk, silakan coba lagi.', 503);
            }
            usleep(random_int(50_000, 200_000) * $attempt); // jeda acak lalu coba lagi
        }
    }
}

// ---------------------------------------------------------------------------
// Validasi & bantuan jadwal
// ---------------------------------------------------------------------------

/** Ubah "2026-10-13" + "10:00" (WIB) menjadi epoch milidetik, atau null jika tidak valid. */
function parse_wib(string $date, string $time): ?int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", wib());
    // Tolak tanggal "aneh" seperti 2026-02-31 atau 25:00 yang otomatis digeser PHP.
    if (!$dt || $dt->format('Y-m-d H:i') !== "$date $time") {
        return null;
    }
    return $dt->getTimestamp() * 1000;
}

/**
 * Validasi input booking. Mengembalikan [start_ms, end_ms] atau melempar ApiError.
 */
function validate_booking_input(mixed $roomId, mixed $date, mixed $start, mixed $end, ?int $now = null): array
{
    $now ??= now_ms();

    if (!is_string($roomId) || !isset(ROOMS[$roomId])) {
        throw new ApiError('Ruang tidak valid. Pilih ruang A, B, atau C.', 422);
    }
    if (!is_string($date) || !is_string($start) || !is_string($end)) {
        throw new ApiError('Tanggal, jam mulai, dan jam selesai wajib diisi.', 422);
    }
    $startMs = parse_wib($date, $start);
    $endMs = parse_wib($date, $end);
    if ($startMs === null || $endMs === null) {
        throw new ApiError('Format tanggal/jam tidak valid.', 422);
    }
    if ($endMs <= $startMs) {
        throw new ApiError('Jam selesai harus setelah jam mulai.', 422);
    }
    $duration = intdiv($endMs - $startMs, 60_000);
    if ($duration < MIN_DURATION_MIN) {
        throw new ApiError('Durasi booking minimal ' . MIN_DURATION_MIN . ' menit.', 422);
    }
    if ($duration > MAX_DURATION_MIN) {
        throw new ApiError('Durasi booking maksimal ' . (MAX_DURATION_MIN / 60) . ' jam.', 422);
    }
    $open = sprintf('%02d:00', OPEN_HOUR);
    $close = sprintf('%02d:00', CLOSE_HOUR);
    if ($start < $open || $end > $close) {
        throw new ApiError("Ruang hanya bisa dipesan pukul $open – $close WIB.", 422);
    }
    if ($startMs <= $now) {
        throw new ApiError('Jam mulai sudah lewat. Pilih waktu yang akan datang.', 422);
    }
    if ($startMs > $now + MAX_DAYS_AHEAD * 86_400_000) {
        throw new ApiError('Booking maksimal ' . MAX_DAYS_AHEAD . ' hari ke depan.', 422);
    }

    return [$startMs, $endMs];
}

/** Apakah slot [start, end) bertabrakan dengan salah satu jadwal aktif? */
function find_conflict(array $slots, int $startMs, int $endMs): ?array
{
    foreach ($slots as $id => $slot) {
        if (!is_array($slot) || !in_array($slot['status'] ?? '', ACTIVE_STATUSES, true)) {
            continue;
        }
        // Dua rentang waktu bertabrakan jika: mulaiA < selesaiB DAN mulaiB < selesaiA
        if ($startMs < (int) ($slot['end_at'] ?? 0) && (int) ($slot['start_at'] ?? 0) < $endMs) {
            return ['booking_id' => (string) $id] + $slot;
        }
    }
    return null;
}

/** Jadwal satu ruang pada satu tanggal (Y-m-d). */
function get_room_schedule(string $roomId, string $date): array
{
    $value = db()->getReference("schedules/$roomId/$date")->getValue();
    return is_array($value) ? $value : [];
}

/** Buat kode booking acak, mis. "RS-7KQ2MX" (tanpa huruf/angka yang mirip seperti O/0, I/1). */
function generate_booking_id(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return 'RS-' . $code;
}

/** Token check-in acak. Yang disimpan di database hanya hash-nya. */
function generate_checkin_token(): array
{
    $token = bin2hex(random_bytes(24));
    return [$token, hash('sha256', $token)];
}

// ---------------------------------------------------------------------------
// Operasi booking
// ---------------------------------------------------------------------------

/**
 * Buat booking baru secara atomik (anti double booking).
 *
 * @return array{0: array, 1: string} [data booking, token check-in asli]
 */
function create_booking(string $uid, string $email, string $roomId, int $startMs, int $endMs): array
{
    do {
        $bookingId = generate_booking_id();
    } while (db()->getReference("bookings/$bookingId")->getSnapshot()->exists());

    $dateKey = date_key($startMs);
    $scheduleRef = db()->getReference("schedules/$roomId/$dateKey");

    // 1) Klaim slot di jadwal ruang: cek bentrok + simpan, dalam satu transaksi.
    with_transaction(function (Transaction $tx) use ($scheduleRef, $bookingId, $startMs, $endMs): void {
        $slots = $tx->snapshot($scheduleRef)->getValue();
        $slots = is_array($slots) ? $slots : [];

        $conflict = find_conflict($slots, $startMs, $endMs);
        if ($conflict !== null) {
            throw new ApiError(sprintf(
                'Slot bentrok: ruang sudah dipesan pukul %s – %s WIB.',
                format_wib((int) $conflict['start_at']),
                format_wib((int) $conflict['end_at'])
            ), 409);
        }

        $slots[$bookingId] = ['start_at' => $startMs, 'end_at' => $endMs, 'status' => 'booked'];
        $tx->set($scheduleRef, $slots);
    });

    // 2) Simpan detail booking. Jika gagal, lepaskan lagi slot yang sudah diklaim.
    [$token, $tokenHash] = generate_checkin_token();
    $booking = [
        'booking_id' => $bookingId,
        'room_id' => $roomId,
        'uid' => $uid,
        'email' => $email,
        'start_at' => $startMs,
        'end_at' => $endMs,
        'status' => 'booked',
        'token_hash' => $tokenHash,
        'checked_in_at' => null,
        'created_at' => now_ms(),
        'last_email_at' => 0,
    ];
    try {
        db()->getReference("bookings/$bookingId")->set($booking);
    } catch (Throwable $e) {
        db()->getReference("schedules/$roomId/$dateKey/$bookingId")->remove();
        throw $e;
    }

    return [$booking, $token];
}

/**
 * Ubah data booking secara atomik.
 * $mutate menerima data booking saat ini dan mengembalikan data baru,
 * atau null jika tidak ada yang perlu diubah. Status di /schedules ikut disinkronkan.
 */
function update_booking(string $bookingId, callable $mutate): ?array
{
    if (!preg_match('/^RS-[A-Z0-9]{6}$/', $bookingId)) {
        throw new ApiError('Kode booking tidak valid.', 404);
    }
    $ref = db()->getReference("bookings/$bookingId");

    $updated = with_transaction(function (Transaction $tx) use ($ref, $mutate): ?array {
        $booking = $tx->snapshot($ref)->getValue();
        if (!is_array($booking)) {
            throw new ApiError('Booking tidak ditemukan.', 404);
        }
        $new = $mutate($booking);
        if ($new === null) {
            return null;
        }
        $tx->set($ref, $new);
        return $new;
    });

    if ($updated !== null) {
        $path = sprintf('schedules/%s/%s/%s/status', $updated['room_id'], date_key((int) $updated['start_at']), $bookingId);
        db()->getReference($path)->set($updated['status']);
    }
    return $updated;
}

/** Check-in oleh pemilik booking memakai token dari e-ticket. */
function checkin_booking(string $uid, string $bookingId, string $token, ?int $now = null): array
{
    $now ??= now_ms();

    return update_booking($bookingId, function (array $b) use ($uid, $token, $now): array {
        if (($b['uid'] ?? '') !== $uid) {
            throw new ApiError('Booking ini bukan milik akun Anda.', 403);
        }
        switch ($b['status']) {
            case 'checked_in':
                throw new ApiError('Anda sudah check-in untuk booking ini.', 409);
            case 'expired':
                throw new ApiError('Booking sudah kedaluwarsa karena tidak check-in tepat waktu.', 409);
            case 'completed':
                throw new ApiError('Booking sudah selesai.', 409);
        }
        if (empty($b['token_hash']) || !hash_equals((string) $b['token_hash'], hash('sha256', $token))) {
            throw new ApiError('Link check-in tidak valid atau sudah tidak berlaku.', 403);
        }
        $start = (int) $b['start_at'];
        if ($now < $start) {
            throw new ApiError('Check-in baru dibuka pukul ' . format_wib($start) . ' WIB.', 409);
        }
        if ($now > $start + CHECKIN_GRACE_MIN * 60_000) {
            throw new ApiError('Batas waktu check-in (' . CHECKIN_GRACE_MIN . ' menit) sudah lewat.', 409);
        }

        $b['status'] = 'checked_in';
        $b['checked_in_at'] = $now;
        $b['token_hash'] = null; // token sekali pakai
        return $b;
    });
}

/**
 * Auto release: dijalankan oleh Cron Job setiap menit.
 *  - booked & tidak check-in > 10 menit setelah jam mulai -> expired
 *  - checked_in & jam selesai sudah lewat                 -> completed
 */
function auto_release(?int $now = null): array
{
    $now ??= now_ms();
    $result = ['expired' => 0, 'completed' => 0];

    $booked = db()->getReference('bookings')->orderByChild('status')->equalTo('booked')->getValue();
    foreach ((array) $booked as $id => $b) {
        if ($now <= (int) $b['start_at'] + CHECKIN_GRACE_MIN * 60_000) {
            continue;
        }
        $changed = update_booking((string) $id, function (array $cur) use ($now): ?array {
            // Cek ulang di dalam transaksi: mungkin pengguna baru saja check-in.
            if ($cur['status'] !== 'booked' || $now <= (int) $cur['start_at'] + CHECKIN_GRACE_MIN * 60_000) {
                return null;
            }
            $cur['status'] = 'expired';
            $cur['token_hash'] = null;
            return $cur;
        });
        $result['expired'] += $changed ? 1 : 0;
    }

    $checkedIn = db()->getReference('bookings')->orderByChild('status')->equalTo('checked_in')->getValue();
    foreach ((array) $checkedIn as $id => $b) {
        if ($now < (int) $b['end_at']) {
            continue;
        }
        $changed = update_booking((string) $id, function (array $cur) use ($now): ?array {
            if ($cur['status'] !== 'checked_in' || $now < (int) $cur['end_at']) {
                return null;
            }
            $cur['status'] = 'completed';
            return $cur;
        });
        $result['completed'] += $changed ? 1 : 0;
    }

    return $result;
}

/**
 * Pembatas sederhana: maksimal $max request per $windowSec detik per pengguna.
 */
function rate_limit(string $name, string $uid, int $max, int $windowSec): void
{
    $ref = db()->getReference("rate_limits/$name/$uid");
    $allowed = with_transaction(function (Transaction $tx) use ($ref, $max, $windowSec): bool {
        $data = $tx->snapshot($ref)->getValue();
        $now = time();
        if (!is_array($data) || $now - (int) ($data['window_start'] ?? 0) >= $windowSec) {
            $data = ['window_start' => $now, 'count' => 0];
        }
        if ($data['count'] >= $max) {
            return false;
        }
        $data['count']++;
        $tx->set($ref, $data);
        return true;
    });

    if (!$allowed) {
        throw new ApiError('Terlalu banyak permintaan. Coba lagi beberapa saat lagi.', 429);
    }
}

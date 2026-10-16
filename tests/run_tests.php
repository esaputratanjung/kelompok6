<?php
/**
 * Pengujian otomatis RoomSync Cloud (skenario dari "Rencana Pengujian").
 * Memakai mock Firebase Realtime DB + mock Resend/Gemini, jadi tidak butuh internet/akun.
 *
 * Jalankan dari root project:   php tests/run_tests.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Folder vendor/ belum ada. Jalankan dulu:  composer install\n");
    exit(1);
}
if (PHP_VERSION_ID < 80300) {
    // Di PHP 8.2 Composer memakai Kreait v7 yang mode emulatornya tetap butuh koneksi ke Google,
    // jadi mock offline ini tidak bisa dipakai. Aplikasinya sendiri tetap jalan normal di PHP 8.2.
    echo 'Tes otomatis butuh PHP 8.3+ (versi Anda: ' . PHP_VERSION . "). Aplikasi tetap bisa dijalankan.\n";
    exit(0);
}
@mkdir(__DIR__ . '/.mockdb', 0777, true);
@unlink(__DIR__ . '/.mockdb/db.json');
@unlink(__DIR__ . '/.mockdb/emails.jsonl');

$env = [
    'APP_ENV' => 'testing', // jangan baca file .env milik Anda
    'FIREBASE_PROJECT_ID' => 'demo-roomsync',
    'FIREBASE_DATABASE_URL' => 'https://demo-roomsync-default-rtdb.firebaseio.com',
    'FIREBASE_DATABASE_EMULATOR_HOST' => '127.0.0.1:9000',
    'FIREBASE_AUTH_EMULATOR_HOST' => '127.0.0.1:9099',
    // FIREBASE_CREDENTIALS sengaja tidak diisi (mode emulator)
    'APP_URL' => 'http://127.0.0.1:8000',
    'RESEND_API_KEY' => 'test-resend-key',
    'RESEND_API_URL' => 'http://127.0.0.1:9100/emails',
    'GEMINI_API_KEY' => 'test-gemini-key',
    'GEMINI_API_BASE' => 'http://127.0.0.1:9100/v1beta',
    'CRON_SECRET' => 'secret-for-tests-1234567890',
    'PHP_CLI_SERVER_WORKERS' => '8',
];
foreach ($env as $k => $v) {
    putenv("$k=$v");
}

// --- Jalankan 3 server lokal ---
$procs = [];
// Perintah berbentuk array (tanpa shell) agar jalan di Windows maupun Linux/Mac.
$start = function (array $args) use (&$procs, $root, $env): void {
    $log = __DIR__ . '/.mockdb/server.log';
    $procs[] = proc_open(array_merge([PHP_BINARY], $args), [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $env + getenv());
};
$start(['-S', '127.0.0.1:9000', 'tests/mock_rtdb.php']);
$start(['-S', '127.0.0.1:9100', 'tests/mock_services.php']);
$start(['-S', '127.0.0.1:8000', '-t', 'public']);
register_shutdown_function(function () use (&$procs) {
    foreach ($procs as $p) {
        proc_terminate($p);
    }
});
foreach ([9000, 9100, 8000] as $port) {
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
}

require $root . '/vendor/autoload.php';

// --- Helper ---
$passed = 0;
$failed = 0;
function check(bool $cond, string $name, mixed $debug = null): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  \033[32m✓\033[0m $name\n";
    } else {
        $failed++;
        echo "  \033[31m✗ $name\033[0m\n";
        if ($debug !== null) {
            echo '    ' . json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
}

/** ID token palsu (tanpa tanda tangan) — diterima karena mode emulator. */
function token(string $uid, ?string $email = null): string
{
    $b64 = fn($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
    $now = time();
    $claims = ['iss' => 'https://securetoken.google.com/demo-roomsync', 'aud' => 'demo-roomsync', 'sub' => $uid,
        'iat' => $now, 'exp' => $now + 3600, 'auth_time' => $now];
    if ($email !== null) {
        $claims['email'] = $email;
    }
    return $b64(['alg' => 'none', 'typ' => 'JWT']) . '.' . $b64($claims) . '.';
}

function request(string $path, ?array $body, ?string $token = null, string $method = 'POST'): array
{
    $ch = curl_init('http://127.0.0.1:8000' . $path);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$status, json_decode($raw, true) ?? $raw];
}

function last_email(): array
{
    $lines = file(__DIR__ . '/.mockdb/emails.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
    return json_decode((string) end($lines), true) ?? [];
}

function token_from_email(array $mail): string
{
    preg_match('/token=([a-f0-9]+)/', $mail['html'] ?? '', $m);
    return $m[1] ?? '';
}

/** Geser waktu booking (untuk simulasi jam check-in) — langsung ke database. */
function shift_booking(string $id, int $startMs, int $endMs): void
{
    $b = db()->getReference("bookings/$id")->getValue();
    db()->getReference("schedules/{$b['room_id']}/" . date_key((int) $b['start_at']) . "/$id")->remove();
    db()->getReference("bookings/$id")->update(['start_at' => $startMs, 'end_at' => $endMs]);
    db()->getReference("schedules/{$b['room_id']}/" . date_key($startMs) . "/$id")
        ->set(['start_at' => $startMs, 'end_at' => $endMs, 'status' => $b['status']]);
}

$alice = token('alice', 'alice@example.com');
$bob = token('bob', 'bob@example.com');
$carol = token('carol', 'carol@example.com');
$tomorrow = date_key(now_ms() + 86_400_000);
$now = now_ms();

// =====================================================================
echo "\n1. Autentikasi\n";
[$s] = request('/booking.php', ['action' => 'create']);
check($s === 401, 'Tanpa token ditolak (401)');
[$s] = request('/booking.php', ['action' => 'create'], 'token.palsu.sekali');
check($s === 401, 'Token tidak valid ditolak (401)');
[$s] = request('/booking.php', null, $alice, 'GET');
check($s === 405, 'Method GET ditolak (405)');

// =====================================================================
echo "\n2. Validasi input\n";
$cases = [
    'Jam selesai ≤ jam mulai' => ['A', $tomorrow, '12:00', '10:00', 'Jam selesai harus setelah'],
    'Jam selesai = jam mulai' => ['A', $tomorrow, '10:00', '10:00', 'Jam selesai harus setelah'],
    'Ruang tidak dikenal' => ['Z', $tomorrow, '10:00', '11:00', 'Ruang tidak valid'],
    'Tanggal tidak ada (31 Feb)' => ['A', '2027-02-31', '10:00', '11:00', 'Format tanggal/jam'],
    'Jam 25:00' => ['A', $tomorrow, '10:00', '25:00', 'Format tanggal/jam'],
    'Di luar jam operasional' => ['A', $tomorrow, '06:00', '08:00', 'hanya bisa dipesan'],
    'Durasi > 4 jam' => ['A', $tomorrow, '08:00', '13:00', 'maksimal'],
    'Durasi < 30 menit' => ['A', $tomorrow, '08:00', '08:15', 'minimal'],
    'Waktu sudah lewat' => ['A', date_key($now - 86_400_000), '10:00', '11:00', 'sudah lewat'],
    'Lebih dari 30 hari' => ['A', date_key($now + 40 * 86_400_000), '10:00', '11:00', 'maksimal 30 hari'],
];
foreach ($cases as $name => [$room, $date, $st, $en, $expect]) {
    [$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => $room, 'date' => $date, 'start' => $st, 'end' => $en], $alice);
    check($s === 422 && str_contains($r['error'] ?? '', $expect), "$name → ditolak", $r);
}

// =====================================================================
echo "\n3. Booking, e-ticket & anti bentrok\n";
[$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => 'A', 'date' => $tomorrow, 'start' => '10:00', 'end' => '12:00'], $alice);
check($s === 200 && $r['ok'] === true && $r['email_sent'] === true, 'Booking A 10:00–12:00 berhasil + e-ticket terkirim', $r);
$bookingA = $r['booking']['booking_id'] ?? '';
$mail = last_email();
$tokenA = token_from_email($mail);
check(($mail['to'][0] ?? '') === 'alice@example.com' && str_contains($mail['html'] ?? '', $bookingA) && $tokenA !== '', 'Email berisi kode booking & link check-in');
$stored = db()->getReference("bookings/$bookingA")->getValue();
check($stored['token_hash'] === hash('sha256', $tokenA) && !str_contains(json_encode($stored), $tokenA), 'Token disimpan sebagai hash (bukan token asli)');
$slot = db()->getReference("schedules/A/$tomorrow/$bookingA")->getValue();
check($slot === ['start_at' => $stored['start_at'], 'end_at' => $stored['end_at'], 'status' => 'booked'], 'Jadwal publik tanpa data pribadi', $slot);
check(!isset($r['booking']['token_hash']) && !isset($r['booking']['uid']), 'Respons API tidak membocorkan token_hash/uid');

[$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => 'A', 'date' => $tomorrow, 'start' => '11:00', 'end' => '13:00'], $bob);
check($s === 409 && str_contains($r['error'], 'bentrok'), 'Booking tumpang tindih (11–13) ditolak (409)', $r);
[$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => 'A', 'date' => $tomorrow, 'start' => '09:00', 'end' => '13:00'], $bob);
check($s === 409, 'Booking yang "membungkus" slot (09–13) ditolak', $r);
[$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => 'A', 'date' => $tomorrow, 'start' => '12:00', 'end' => '13:00'], $bob);
check($s === 200, 'Booking bersebelahan (12–13) diizinkan', $r);
[$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => 'B', 'date' => $tomorrow, 'start' => '10:00', 'end' => '12:00'], $bob);
check($s === 200, 'Ruang lain di jam yang sama diizinkan', $r);
$bookingB = $r['booking']['booking_id'] ?? '';

// Email gagal -> booking tetap tersimpan
[$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => 'C', 'date' => $tomorrow, 'start' => '07:00', 'end' => '08:00'], token('noemail'));
check($s === 200 && $r['email_sent'] === false && db()->getReference('bookings/' . $r['booking']['booking_id'])->getSnapshot()->exists(),
    'Email gagal → booking tetap tersimpan', $r);

// =====================================================================
echo "\n4. Dua (bahkan 8) booking serentak untuk slot yang sama\n";
$mh = curl_multi_init();
$handles = [];
for ($i = 0; $i < 8; $i++) {
    $ch = curl_init('http://127.0.0.1:8000/booking.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . token("racer$i", "racer$i@example.com")],
        CURLOPT_POSTFIELDS => json_encode(['action' => 'create', 'room_id' => 'C', 'date' => $tomorrow, 'start' => '14:00', 'end' => '15:00'])]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
$codes = array_map(fn($h) => curl_getinfo($h, CURLINFO_HTTP_CODE), $handles);
$okCount = count(array_filter($codes, fn($c) => $c === 200));
$slotsC = array_filter(get_room_schedule('C', $tomorrow), fn($x) => $x['start_at'] === parse_wib($tomorrow, '14:00'));
check($okCount === 1 && count($slotsC) === 1, "Hanya 1 berhasil, sisanya ditolak (kode: " . implode(',', $codes) . ')');

// =====================================================================
echo "\n5. Check-in\n";
[$s, $r] = request('/checkin.php', ['booking_id' => $bookingA, 'token' => $tokenA], $bob);
check($s === 403 && str_contains($r['error'], 'bukan milik'), 'Check-in oleh bukan pemilik ditolak', $r);
[$s, $r] = request('/checkin.php', ['booking_id' => $bookingA, 'token' => $tokenA], $alice);
check($s === 409 && str_contains($r['error'], 'baru dibuka'), 'Check-in sebelum jam mulai ditolak', $r);

shift_booking($bookingA, now_ms() - 2 * 60_000, now_ms() + 60 * 60_000); // jam mulai = 2 menit lalu
[$s, $r] = request('/checkin.php', ['booking_id' => $bookingA, 'token' => str_repeat('a', 48)], $alice);
check($s === 403, 'Token salah ditolak', $r);
[$s, $r] = request('/checkin.php', ['booking_id' => 'RS-XXXXXX', 'token' => $tokenA], $alice);
check($s === 404, 'Kode booking tidak ada → 404', $r);
[$s, $r] = request('/checkin.php', ['booking_id' => '../../hack', 'token' => $tokenA], $alice);
check($s === 404, 'Kode booking aneh (path injection) ditolak', $r);
[$s, $r] = request('/checkin.php', ['booking_id' => $bookingA, 'token' => $tokenA], $alice);
check($s === 200 && $r['ok'] === true, 'Check-in oleh pemilik dalam jendela waktu berhasil', $r);
$b = db()->getReference("bookings/$bookingA")->getValue();
$slotStatus = db()->getReference("schedules/A/" . date_key((int) $b['start_at']) . "/$bookingA/status")->getValue();
check($b['status'] === 'checked_in' && isset($b['checked_in_at']) && !isset($b['token_hash']) && $slotStatus === 'checked_in',
    'Status checked_in tersimpan, token dihapus, jadwal ikut diperbarui', $b);
[$s, $r] = request('/checkin.php', ['booking_id' => $bookingA, 'token' => $tokenA], $alice);
check($s === 409, 'Link check-in dipakai ulang ditolak', $r);

// Terlambat > 10 menit
shift_booking($bookingB, now_ms() - 11 * 60_000, now_ms() + 60 * 60_000);
[$s, $r] = request('/booking.php', ['action' => 'resend', 'booking_id' => $bookingB], $bob);
check($s === 409, 'Kirim ulang e-ticket setelah batas check-in ditolak', $r);
[$s, $r] = request('/checkin.php', ['booking_id' => $bookingB, 'token' => 'x'], $bob);
check($s === 409 || $s === 403, 'Check-in setelah lewat 10 menit ditolak', $r);

// =====================================================================
echo "\n6. Kirim ulang e-ticket\n";
[$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => 'B', 'date' => $tomorrow, 'start' => '16:00', 'end' => '17:00'], $alice);
$bookingR = $r['booking']['booking_id'] ?? '';
$oldToken = token_from_email(last_email());
[$s, $r] = request('/booking.php', ['action' => 'resend', 'booking_id' => $bookingR], $alice);
check($s === 429, 'Kirim ulang < 1 menit ditolak (anti spam)', $r);
db()->getReference("bookings/$bookingR/last_email_at")->set(0);
[$s, $r] = request('/booking.php', ['action' => 'resend', 'booking_id' => $bookingR], $bob);
check($s === 403, 'Kirim ulang booking milik orang lain ditolak', $r);
touch(__DIR__ . '/.mockdb/email_down.flag');
[$s, $r] = request('/booking.php', ['action' => 'resend', 'booking_id' => $bookingR], $alice);
unlink(__DIR__ . '/.mockdb/email_down.flag');
$hash = db()->getReference("bookings/$bookingR/token_hash")->getValue();
check($s === 502 && $hash === hash('sha256', $oldToken), 'Kirim ulang gagal → token lama tetap berlaku', $r);
db()->getReference("bookings/$bookingR/last_email_at")->set(0);
[$s, $r] = request('/booking.php', ['action' => 'resend', 'booking_id' => $bookingR], $alice);
$newToken = token_from_email(last_email());
check($s === 200 && $newToken !== '' && $newToken !== $oldToken, 'Kirim ulang berhasil dengan token baru', $r);
shift_booking($bookingR, now_ms() - 60_000, now_ms() + 30 * 60_000);
[$s] = request('/checkin.php', ['booking_id' => $bookingR, 'token' => $oldToken], $alice);
check($s === 403, 'Token lama tidak berlaku lagi');
[$s] = request('/checkin.php', ['booking_id' => $bookingR, 'token' => $newToken], $alice);
check($s === 200, 'Token baru bisa dipakai check-in');

// =====================================================================
echo "\n6b. Batalkan booking (Delete)\n";
[$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => 'A', 'date' => $tomorrow, 'start' => '15:00', 'end' => '16:00'], $alice);
$bookingX = $r['booking']['booking_id'] ?? '';
[$s, $r] = request('/booking.php', ['action' => 'cancel', 'booking_id' => $bookingX], $bob);
check($s === 403 && db()->getReference("bookings/$bookingX")->getSnapshot()->exists(), 'Batal oleh bukan pemilik ditolak', $r);
[$s, $r] = request('/booking.php', ['action' => 'cancel', 'booking_id' => $bookingX], $alice);
check($s === 200 && !db()->getReference("bookings/$bookingX")->getSnapshot()->exists()
    && db()->getReference("schedules/A/$tomorrow/$bookingX")->getValue() === null, 'Batal oleh pemilik: booking & slot terhapus', $r);
[$s, $r] = request('/booking.php', ['action' => 'cancel', 'booking_id' => $bookingX], $alice);
check($s === 404, 'Batal booking yang sudah dihapus ditolak', $r);
[$s, $r] = request('/booking.php', ['action' => 'create', 'room_id' => 'A', 'date' => $tomorrow, 'start' => '15:00', 'end' => '16:00'], $bob);
check($s === 200, 'Pesan ulang slot yang dibatalkan berhasil', $r);
[$s, $r] = request('/booking.php', ['action' => 'cancel', 'booking_id' => $bookingR], $alice);
check($s === 409 && db()->getReference("bookings/$bookingR/status")->getValue() === 'checked_in', 'Booking yang sudah check-in tidak bisa dibatalkan', $r);
[$s, $r] = request('/booking.php', ['action' => 'cancel', 'booking_id' => '../rate_limits'], $alice);
check($s === 404, 'Kode booking aneh (path injection) ditolak', $r);

// =====================================================================
echo "\n7. Auto release (cron)\n";
// bookingB: booked, mulai 11 menit lalu -> expired. bookingR: checked_in -> completed setelah jam selesai.
shift_booking($bookingR, now_ms() - 90 * 60_000, now_ms() - 60_000);
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/cron/auto_release.php') . ' 2>&1');
check(str_contains((string) $out, '1 expired, 1 completed'), 'Cron CLI: 1 expired, 1 completed', $out);
$bB = db()->getReference("bookings/$bookingB")->getValue();
check($bB['status'] === 'expired' && !isset($bB['token_hash']), 'Booking tanpa check-in → expired');
check(db()->getReference("bookings/$bookingR/status")->getValue() === 'completed', 'Booking checked_in yang selesai → completed');
check(db()->getReference("bookings/$bookingA/status")->getValue() === 'checked_in', 'Booking yang masih berlangsung tidak diubah');
$schedB = get_room_schedule('B', date_key((int) $bB['start_at']));
check(find_conflict($schedB, (int) $bB['start_at'], (int) $bB['end_at']) === null, 'Slot booking expired kembali tersedia');
check(auto_release() === ['expired' => 0, 'completed' => 0], 'Cron dijalankan ulang tidak mengubah apa pun (idempoten)');
// Sinkron status ke /schedules gagal (booking sudah expired, jadwal masih "booked") + slot tanpa booking.
$dayB = date_key((int) $bB['start_at']);
db()->getReference("schedules/B/$dayB/$bookingB/status")->set('booked');
db()->getReference("schedules/B/$dayB/RS-ZZZZZZ")->set(['start_at' => (int) $bB['start_at'], 'end_at' => (int) $bB['end_at'], 'status' => 'booked']);
auto_release();
$schedB = get_room_schedule('B', $dayB);
check(($schedB[$bookingB]['status'] ?? '') === 'expired' && !isset($schedB['RS-ZZZZZZ']), 'Cron memperbaiki jadwal yang tertinggal', $schedB);
[$s] = request('/cron.php', null, null, 'GET');
check($s === 403, 'Endpoint /cron.php tanpa secret ditolak');
[$s, $r] = request('/cron.php?key=secret-for-tests-1234567890', null, null, 'GET');
check($s === 200 && $r['ok'] === true, 'Endpoint /cron.php dengan secret berjalan', $r);

// =====================================================================
echo "\n8. Asisten AI\n";
$ai = function (array $json) use (&$alice) {
    return request('/ai_assistant.php', ['action' => 'chat', 'message' => 'TEST:' . json_encode($json)], $alice);
};
[$s, $r] = $ai(['intent' => 'booking', 'room_id' => 'C', 'date' => $tomorrow, 'start' => '10:00', 'end' => '12:00', 'reply' => 'ok']);
check($s === 200 && count($r['proposals']) === 1 && $r['proposals'][0]['room_id'] === 'C' && $r['proposals'][0]['start'] === '10:00',
    '"besok jam 10–12 ruang C" → usulan Ruang C', $r);
check(db()->getReference("schedules/C/$tomorrow")->getValue() !== null && count(array_filter(get_room_schedule('C', $tomorrow), fn($x) => $x['start_at'] === parse_wib($tomorrow, '10:00'))) === 0,
    'AI tidak menulis booking ke database (harus konfirmasi)');

// Ruang A sudah dipesan 12–13 oleh bob -> AI harus memberi alternatif yang benar-benar kosong
[$s, $r] = $ai(['intent' => 'booking', 'room_id' => 'A', 'date' => $tomorrow, 'start' => '12:00', 'end' => '13:00', 'reply' => 'ok']);
$allFree = true;
foreach ($r['proposals'] ?? [] as $p) {
    $ps = parse_wib($p['date'], $p['start']);
    $pe = parse_wib($p['date'], $p['end']);
    $allFree = $allFree && find_conflict(get_room_schedule($p['room_id'], $p['date']), $ps, $pe) === null && $pe - $ps === 3_600_000;
}
check($s === 200 && str_contains($r['reply'], 'penuh') && count($r['proposals']) >= 2 && $allFree,
    'Ruang penuh → AI memberi alternatif slot yang benar-benar kosong', $r);

[$s, $r] = $ai(['intent' => 'booking', 'room_id' => 'ANY', 'date' => $tomorrow, 'start' => '18:00', 'end' => '19:00', 'reply' => 'ok']);
check($s === 200 && count($r['proposals']) === 3, 'Tanpa menyebut ruang → semua ruang kosong diusulkan', $r);

// Booking dari usulan AI lewat booking.php (konfirmasi)
$p = $r['proposals'][0];
[$s, $r2] = request('/booking.php', ['action' => 'create'] + $p, $alice);
check($s === 200, 'Konfirmasi usulan AI → booking dibuat lewat booking.php', $r2);

// (pengguna lain, karena AI dibatasi 10 permintaan/menit per pengguna)
$alice = $carol;
[$s, $r] = $ai(['intent' => 'booking', 'room_id' => 'C', 'date' => $tomorrow . 'T00:00:00', 'start' => '13.00', 'end' => '15:00:00', 'reply' => 'ok']);
check($s === 200 && ($r['proposals'][0]['start'] ?? '') === '13:00' && ($r['proposals'][0]['end'] ?? '') === '15:00',
    'Format jam/tanggal AI yang tidak baku ("13.00", "15:00:00") tetap dipahami', $r);
[$s, $r] = $ai(['intent' => 'clarify', 'reply' => 'Sampai jam berapa?']);
check($s === 200 && $r['reply'] === 'Sampai jam berapa?' && $r['proposals'] === [], 'Permintaan ambigu → AI minta klarifikasi, tanpa booking', $r);
[$s, $r] = $ai(['intent' => 'other', 'reply' => 'Saya hanya membantu booking ruang.']);
check($s === 200 && $r['proposals'] === [], 'Di luar topik → tidak membuat booking', $r);
[$s, $r] = $ai(['intent' => 'booking', 'room_id' => 'A', 'date' => '2026-13-45', 'start' => '10:00', 'end' => '12:00', 'reply' => 'ok']);
check($s === 200 && $r['proposals'] === [] && str_contains($r['reply'], 'Mohon sebutkan'), 'Output AI dengan tanggal ngawur divalidasi & ditolak', $r);
[$s, $r] = $ai(['intent' => 'booking', 'room_id' => 'A', 'date' => $tomorrow, 'start' => '20:00', 'end' => '23:00', 'reply' => 'ok']);
check($s === 200 && $r['proposals'] === [] && str_contains($r['reply'], 'Maaf'), 'Output AI di luar jam operasional ditolak', $r);
[$s, $r] = $ai(['intent' => 'hapus_semua', 'reply' => '']);
check($s === 200 && $r['proposals'] === [], 'Intent tidak dikenal diabaikan', $r);
[$s, $r] = request('/ai_assistant.php', ['action' => 'chat', 'message' => 'TEST:INVALID'], $alice);
check($s === 502, 'Jawaban AI bukan JSON → error ditangani (502)', $r);
[$s, $r] = request('/ai_assistant.php', ['action' => 'chat', 'message' => 'TEST:FAIL'], $alice);
check($s === 503 && str_contains($r['error'], 'form booking manual'), 'AI gagal/kuota habis → fallback ke form manual (503)', $r);
@unlink(__DIR__ . '/.mockdb/flaky.flag');
[$s, $r] = request('/ai_assistant.php', ['action' => 'chat', 'message' => 'TEST:FLAKY'], token('dave', 'dave@example.com'));
check($s === 200 && ($r['reply'] ?? '') === 'Berhasil setelah retry', 'Gemini sibuk sesaat (503) → dicoba ulang otomatis lalu berhasil', $r);
[$s, $r] = request('/ai_assistant.php', ['action' => 'chat', 'message' => str_repeat('x', 501)], $alice);
check($s === 422, 'Pesan terlalu panjang ditolak', $r);
[$s, $r] = request('/ai_assistant.php', ['action' => 'summary'], $bob);
check($s === 200 && $r['source'] === 'ai' && $r['stats']['total_booking'] >= 1, 'Ringkasan pemakaian mingguan', $r);
check(!str_contains(json_encode($r), '@example.com'), 'Ringkasan tidak memuat data pribadi');

echo "\n9. Rate limit\n";
$codes = [];
for ($i = 0; $i < 12; $i++) {
    [$codes[]] = request('/ai_assistant.php', ['action' => 'chat', 'message' => 'TEST:' . json_encode(['intent' => 'other', 'reply' => 'x'])], token('spammer', 's@example.com'));
}
check(in_array(429, $codes, true) && $codes[0] === 200, 'Lebih dari 10 permintaan AI/menit → 429');

echo "\n10. Halaman web\n";
foreach (['/login.php', '/register.php', '/dashboard.php', '/checkin.php?id=RS-ABC123&token=x'] as $page) {
    $html = (string) @file_get_contents('http://127.0.0.1:8000' . $page);
    check(str_contains($html, 'window.FIREBASE_CONFIG') && str_contains($html, '</html>'), "GET $page tampil normal");
}
$headers = get_headers('http://127.0.0.1:8000/index.php');
check(str_contains(implode("\n", $headers), 'Location: /dashboard.php'), 'Halaman utama diarahkan ke dashboard');

$log = (string) @file_get_contents(__DIR__ . '/.mockdb/server.log');
check(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'Tidak ada PHP warning/error di log server',
    array_slice(preg_grep('/PHP (Warning|Notice|Deprecated|Fatal)/', explode("\n", $log)), 0, 5));

echo "\nHasil: \033[32m$passed lulus\033[0m, " . ($failed ? "\033[31m$failed gagal\033[0m" : '0 gagal') . "\n";
exit($failed ? 1 : 0);

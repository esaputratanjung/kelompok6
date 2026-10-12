<?php
/**
 * Konfigurasi dasar aplikasi + fungsi bantu (helper) umum.
 * File ini dimuat otomatis oleh Composer (lihat "autoload.files" di composer.json).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Baca file .env (hanya untuk development lokal).
//    Di Render, variabel diisi lewat menu Environment, jadi file .env tidak ada.
// ---------------------------------------------------------------------------
(function (): void {
    $file = dirname(__DIR__) . '/.env';
    if (getenv('APP_ENV') === 'testing' || !is_file($file)) { // tes otomatis tidak memakai .env
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        // Hapus tanda kutip di awal/akhir nilai: KEY="nilai"
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        // Variabel yang sudah diset dari luar (mis. oleh Render) tidak ditimpa.
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
})();

date_default_timezone_set('Asia/Jakarta');

// ---------------------------------------------------------------------------
// 2. Aturan bisnis (bisa diubah sesuai kebutuhan)
// ---------------------------------------------------------------------------
const ROOMS = ['A' => 'Ruang A', 'B' => 'Ruang B', 'C' => 'Ruang C'];
const OPEN_HOUR = 7;               // ruang bisa dipesan mulai 07:00 WIB
const CLOSE_HOUR = 21;             // ... sampai 21:00 WIB
const MIN_DURATION_MIN = 30;       // durasi minimal 30 menit
const MAX_DURATION_MIN = 240;      // durasi maksimal 4 jam
const MAX_DAYS_AHEAD = 30;         // boleh pesan maksimal 30 hari ke depan
const CHECKIN_GRACE_MIN = 10;      // batas check-in: 10 menit setelah jam mulai
const ACTIVE_STATUSES = ['booked', 'checked_in']; // status yang "menempati" slot

/**
 * Error yang pesannya aman ditampilkan ke pengguna (beserta kode HTTP-nya).
 */
class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

/** Ambil variabel environment; kembalikan $default jika kosong. */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || trim($value) === '') {
        return $_ENV[$key] ?? $default;
    }
    return trim($value);
}

function wib(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Asia/Jakarta');
}

/** Waktu sekarang dalam milidetik (UTC epoch). */
function now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

/** Ubah epoch milidetik menjadi teks WIB, default "13:00". */
function format_wib(int $ms, string $format = 'H:i'): string
{
    return (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(wib())->format($format);
}

/** Tanggal (Y-m-d) WIB dari epoch milidetik. */
function date_key(int $ms): string
{
    return format_wib($ms, 'Y-m-d');
}

/** Tanggal panjang bahasa Indonesia, mis. "Selasa, 13 Oktober 2026". */
function format_tanggal(int $ms): string
{
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
        'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $d = (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(wib());
    return $hari[(int) $d->format('w')] . ', ' . $d->format('j') . ' ' . $bulan[(int) $d->format('n')] . ' ' . $d->format('Y');
}

/** URL dasar aplikasi (untuk link di email). */
function app_url(): string
{
    $url = env('APP_URL');
    if ($url) {
        return rtrim($url, '/');
    }
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/** Header keamanan dasar untuk semua halaman & API. */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// ---------------------------------------------------------------------------
// 3. Helper API JSON
// ---------------------------------------------------------------------------

function json_response(array $data, int $status = 200): never
{
    send_security_headers();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Baca body request berformat JSON. */
function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 10_000) {
        throw new ApiError('Request terlalu besar.', 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ApiError('Body request harus JSON.', 400);
    }
    return $data;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new ApiError('Method tidak diizinkan.', 405);
    }
}

/**
 * Jalankan handler API dan ubah exception menjadi respons JSON yang rapi.
 * Error tak terduga dicatat ke log, pengguna hanya melihat pesan umum.
 */
function run_api(callable $handler): never
{
    try {
        json_response($handler());
    } catch (ApiError $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], $e->status);
    } catch (Throwable $e) {
        error_log('[RoomSync] ' . get_class($e) . ': ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'Terjadi kesalahan di server. Silakan coba lagi.'], 500);
    }
}

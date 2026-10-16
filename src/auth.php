<?php
/**
 * Verifikasi Firebase ID token yang dikirim browser di header:
 *   Authorization: Bearer <ID token>
 * Identitas pengguna (uid & email) SELALU diambil dari token, bukan dari input pengguna.
 */

declare(strict_types=1);

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Pastikan request berasal dari pengguna yang login.
 *
 * @return array{uid: string, email: string}
 */
function require_user(): array
{
    $token = bearer_token();
    if ($token === null) {
        throw new ApiError('Silakan login terlebih dahulu.', 401);
    }

    $auth = firebase_auth(); // jika konfigurasi Firebase salah, error 500 + detail di log server

    try {
        $verified = $auth->verifyIdToken($token);
    } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
        throw new ApiError('Sesi login tidak valid atau sudah habis. Silakan login ulang.', 401);
    } catch (Throwable $e) {
        error_log('[RoomSync] Gagal verifikasi token: ' . $e->getMessage()); // biasanya salah konfigurasi
        throw new ApiError('Sesi login tidak valid atau sudah habis. Silakan login ulang.', 401);
    }

    $claims = $verified->claims();
    $uid = (string) $claims->get('sub', '');
    $email = (string) $claims->get('email', '');
    if ($uid === '') {
        throw new ApiError('Sesi login tidak valid.', 401);
    }
    if ($claims->get('email_verified') !== true) {
        throw new ApiError('Email belum diverifikasi. Klik link verifikasi di email Anda, lalu login ulang.', 403);
    }

    return ['uid' => $uid, 'email' => $email];
}

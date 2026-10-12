<?php
/**
 * Kirim e-ticket lewat Resend API (HTTPS), karena port SMTP biasa sering diblokir di hosting.
 */

declare(strict_types=1);

use GuzzleHttp\Client;

function http_client(): Client
{
    static $client = null;
    return $client ??= new Client(['timeout' => 20, 'connect_timeout' => 10]);
}

/** Link check-in yang dikirim di e-ticket. */
function checkin_url(string $bookingId, string $token): string
{
    return app_url() . '/checkin.php?' . http_build_query(['id' => $bookingId, 'token' => $token]);
}

/**
 * Kirim e-ticket. Mengembalikan true jika berhasil.
 * Kegagalan email TIDAK membatalkan booking: pengguna bisa menekan "Kirim ulang e-ticket".
 */
function send_ticket_email(array $booking, string $token): bool
{
    $apiKey = env('RESEND_API_KEY');
    $to = (string) ($booking['email'] ?? '');
    if (!$apiKey || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[RoomSync] Email tidak dikirim: RESEND_API_KEY kosong atau alamat email tidak valid.');
        return false;
    }

    $start = (int) $booking['start_at'];
    $end = (int) $booking['end_at'];
    $room = ROOMS[$booking['room_id']] ?? $booking['room_id'];
    $link = checkin_url($booking['booking_id'], $token);

    $html = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;border:1px solid #ddd;border-radius:8px;padding:24px">
  <h2 style="margin-top:0;color:#0d6efd">E-Ticket RoomSync Cloud</h2>
  <p>Booking Anda berhasil. Berikut detailnya:</p>
  <table style="width:100%;border-collapse:collapse">
    <tr><td style="padding:6px 0;color:#666">Kode booking</td><td><b>' . e($booking['booking_id']) . '</b></td></tr>
    <tr><td style="padding:6px 0;color:#666">Ruang</td><td><b>' . e($room) . '</b></td></tr>
    <tr><td style="padding:6px 0;color:#666">Tanggal</td><td>' . e(format_tanggal($start)) . '</td></tr>
    <tr><td style="padding:6px 0;color:#666">Jam</td><td>' . e(format_wib($start) . ' – ' . format_wib($end)) . ' WIB</td></tr>
  </table>
  <p style="margin:24px 0;text-align:center">
    <a href="' . e($link) . '" style="background:#0d6efd;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none">Check-in Sekarang</a>
  </p>
  <p style="font-size:13px;color:#666">Check-in dibuka pukul ' . e(format_wib($start)) . ' dan ditutup pukul '
        . e(format_wib($start + CHECKIN_GRACE_MIN * 60_000)) . ' WIB. Jika tidak check-in, ruang otomatis dilepas.
  Link ini hanya untuk Anda dan hanya bisa dipakai satu kali.</p>
</div>';

    try {
        http_client()->post(env('RESEND_API_URL', 'https://api.resend.com/emails'), [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            'json' => [
                'from' => env('MAIL_FROM', 'RoomSync Cloud <onboarding@resend.dev>'),
                'to' => [$to],
                'subject' => "E-Ticket {$booking['booking_id']} - $room, " . format_wib($start, 'd/m/Y H:i') . ' WIB',
                'html' => $html,
            ],
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[RoomSync] Gagal kirim email: ' . $e->getMessage());
        return false;
    }
}

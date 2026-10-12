<?php
/**
 * API Booking (POST, JSON, wajib login).
 *
 *  { "action": "create", "room_id": "A", "date": "2026-10-14", "start": "10:00", "end": "12:00" }
 *  { "action": "resend", "booking_id": "RS-XXXXXX" }   -> kirim ulang e-ticket
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

run_api(function (): array {
    require_post();
    $user = require_user();
    $input = read_json_body();

    switch ($input['action'] ?? '') {
        case 'create':
            [$startMs, $endMs] = validate_booking_input(
                $input['room_id'] ?? null,
                $input['date'] ?? null,
                $input['start'] ?? null,
                $input['end'] ?? null,
            );
            rate_limit('booking', $user['uid'], 10, 60); // maks. 10 booking per menit per pengguna
            [$booking, $token] = create_booking($user['uid'], $user['email'], $input['room_id'], $startMs, $endMs);
            $emailSent = send_ticket_email($booking, $token);
            if ($emailSent) {
                try {
                    db()->getReference("bookings/{$booking['booking_id']}/last_email_at")->set(now_ms());
                } catch (Throwable $e) {
                    error_log('[RoomSync] Gagal mencatat last_email_at: ' . $e->getMessage()); // tidak fatal
                }
            }

            return [
                'ok' => true,
                'booking' => public_booking($booking),
                'email_sent' => $emailSent,
                'message' => $emailSent
                    ? "Booking berhasil! E-ticket {$booking['booking_id']} dikirim ke {$user['email']}."
                    : "Booking {$booking['booking_id']} berhasil, tetapi e-ticket gagal dikirim. Gunakan tombol \"Kirim ulang e-ticket\".",
            ];

        case 'resend':
            $bookingId = (string) ($input['booking_id'] ?? '');
            $token = null;
            $booking = update_booking($bookingId, function (array $b) use ($user, &$token): array {
                if (($b['uid'] ?? '') !== $user['uid']) {
                    throw new ApiError('Booking ini bukan milik akun Anda.', 403);
                }
                if ($b['status'] !== 'booked') {
                    throw new ApiError('E-ticket hanya bisa dikirim ulang untuk booking yang belum check-in.', 409);
                }
                if (now_ms() > (int) $b['start_at'] + CHECKIN_GRACE_MIN * 60_000) {
                    throw new ApiError('Batas waktu check-in sudah lewat.', 409);
                }
                if (now_ms() - (int) ($b['last_email_at'] ?? 0) < 60_000) {
                    throw new ApiError('Tunggu 1 menit sebelum mengirim ulang e-ticket.', 429);
                }
                // Token lama otomatis tidak berlaku karena hash-nya diganti.
                [$token, $b['token_hash']] = generate_checkin_token();
                $b['last_email_at'] = now_ms();
                return $b;
            });

            if (!send_ticket_email($booking, $token)) {
                throw new ApiError('E-ticket gagal dikirim. Booking Anda tetap aman, silakan coba lagi nanti.', 502);
            }
            return ['ok' => true, 'message' => "E-ticket {$bookingId} sudah dikirim ulang ke email Anda."];

        default:
            throw new ApiError('Action tidak dikenal.', 400);
    }
});

/** Data booking yang aman dikirim ke browser (tanpa token_hash). */
function public_booking(array $b): array
{
    return [
        'booking_id' => $b['booking_id'],
        'room_id' => $b['room_id'],
        'start_at' => $b['start_at'],
        'end_at' => $b['end_at'],
        'status' => $b['status'],
    ];
}

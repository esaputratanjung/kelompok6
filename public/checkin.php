<?php
/**
 * Check-in.
 *  GET  /checkin.php?id=RS-XXXXXX&token=...   -> halaman konfirmasi (link dari e-ticket)
 *  POST /checkin.php  { "booking_id": "...", "token": "..." }  -> proses check-in (wajib login)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    run_api(function (): array {
        $user = require_user();
        $input = read_json_body();
        rate_limit('checkin', $user['uid'], 10, 60);

        $booking = checkin_booking(
            $user['uid'],
            (string) ($input['booking_id'] ?? ''),
            (string) ($input['token'] ?? ''),
        );

        return [
            'ok' => true,
            'message' => 'Check-in berhasil! Selamat menggunakan ' . (ROOMS[$booking['room_id']] ?? 'ruang') . '.',
        ];
    });
}

page_header('Check-in', true);
?>
<div class="row justify-content-center">
  <div class="col-md-7 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4 text-center">
        <h1 class="h4 mb-3">Check-in Ruang</h1>
        <div id="checkin-detail" class="text-start mb-3">
          <p class="text-muted text-center mb-0">Memuat data booking…</p>
        </div>
        <div id="checkin-msg" class="mb-3"></div>
        <button id="btn-checkin" class="btn btn-success btn-lg w-100" type="button" disabled>Konfirmasi Check-in</button>
        <a href="/dashboard.php" class="btn btn-link mt-2">Kembali ke Dashboard</a>
      </div>
    </div>
  </div>
</div>
<?php page_footer(['/assets/checkin.js']);

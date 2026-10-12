<?php
require __DIR__ . '/../vendor/autoload.php';

page_header('Dashboard', true);
$open = sprintf('%02d:00', OPEN_HOUR);
$close = sprintf('%02d:00', CLOSE_HOUR);
?>
<!-- Filter tanggal & jam -->
<div class="card shadow-sm mb-4">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label for="f-date" class="form-label mb-1">Tanggal</label>
        <input type="date" id="f-date" class="form-control">
      </div>
      <div class="col-6 col-md-3">
        <label for="f-start" class="form-label mb-1">Jam mulai</label>
        <input type="time" id="f-start" class="form-control" min="<?= $open ?>" max="<?= $close ?>" step="900">
      </div>
      <div class="col-6 col-md-3">
        <label for="f-end" class="form-label mb-1">Jam selesai</label>
        <input type="time" id="f-end" class="form-control" min="<?= $open ?>" max="<?= $close ?>" step="900">
      </div>
      <div class="col-12 col-md-2 small text-muted">
        <span id="live-indicator">● Menghubungkan…</span>
      </div>
    </div>
  </div>
</div>

<!-- Status ruang real-time -->
<div class="row g-3 mb-4" id="room-cards"></div>

<div class="row g-4">
  <div class="col-lg-6">
    <!-- Form booking manual -->
    <div class="card shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold">Booking Ruang</div>
      <div class="card-body">
        <form id="booking-form" novalidate>
          <div class="row g-2">
            <div class="col-12">
              <label for="b-room" class="form-label mb-1">Ruang</label>
              <select id="b-room" class="form-select">
                <?php foreach (ROOMS as $id => $name): ?>
                  <option value="<?= e($id) ?>"><?= e($name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-sm-6">
              <label for="b-date" class="form-label mb-1">Tanggal</label>
              <input type="date" id="b-date" class="form-control" required>
            </div>
            <div class="col-6 col-sm-3">
              <label for="b-start" class="form-label mb-1">Mulai</label>
              <input type="time" id="b-start" class="form-control" min="<?= $open ?>" max="<?= $close ?>" step="900" required>
            </div>
            <div class="col-6 col-sm-3">
              <label for="b-end" class="form-label mb-1">Selesai</label>
              <input type="time" id="b-end" class="form-control" min="<?= $open ?>" max="<?= $close ?>" step="900" required>
            </div>
          </div>
          <div class="form-text mb-2">Jam operasional <?= $open ?>–<?= $close ?> WIB · durasi <?= MIN_DURATION_MIN ?> menit s.d. <?= MAX_DURATION_MIN / 60 ?> jam.</div>
          <div id="booking-msg" class="mb-2"></div>
          <button type="submit" class="btn btn-primary w-100">Pesan</button>
        </form>
      </div>
    </div>

    <!-- Booking saya -->
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">Booking Saya</div>
      <div class="card-body">
        <div id="my-msg"></div>
        <div id="my-bookings"><p class="text-muted mb-0">Memuat…</p></div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <!-- Asisten AI -->
    <div class="card shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold">Asisten AI <span class="badge text-bg-info">Gemini</span></div>
      <div class="card-body">
        <div id="chat-box" class="chat-box border rounded p-2 mb-2">
          <div class="chat-msg bg-white border rounded p-2 mb-2 small">
            Halo! Tulis permintaan booking dengan bahasa sehari-hari, contoh:
            "Pesan ruang untuk rapat besok jam 10 sampai 12, kalau bisa ruang B".
          </div>
        </div>
        <form id="chat-form" class="d-flex gap-2">
          <input id="chat-input" class="form-control" maxlength="500" placeholder="Tulis permintaan…" autocomplete="off" aria-label="Pesan untuk asisten AI">
          <button class="btn btn-primary" type="submit">Kirim</button>
        </form>
      </div>
    </div>

    <!-- Ringkasan pemakaian -->
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        Ringkasan Pemakaian (7 hari)
        <button id="btn-summary" class="btn btn-sm btn-outline-primary" type="button">Buat Ringkasan</button>
      </div>
      <div class="card-body">
        <div id="summary" class="summary-text text-muted small">Tekan "Buat Ringkasan" untuk melihat insight mingguan.</div>
      </div>
    </div>
  </div>
</div>
<?php page_footer(['/assets/dashboard.js']);

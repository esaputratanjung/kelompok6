<?php
/**
 * Template HTML bersama (header & footer) untuk semua halaman.
 */

declare(strict_types=1);

/** Konfigurasi Firebase untuk browser (nilai ini memang publik, bukan rahasia). */
function firebase_web_config(): array
{
    return [
        'apiKey' => env('FIREBASE_API_KEY', ''),
        'authDomain' => env('FIREBASE_AUTH_DOMAIN', ''),
        'databaseURL' => env('FIREBASE_DATABASE_URL', ''),
        'projectId' => env('FIREBASE_PROJECT_ID', ''),
    ];
}

function page_header(string $title, bool $showNav = false): void
{
    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    $config = json_encode(firebase_web_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    $rooms = json_encode(ROOMS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · RoomSync Cloud</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/style.css">
  <script>
    window.FIREBASE_CONFIG = <?= $config ?>;
    window.APP = { rooms: <?= $rooms ?>, openHour: <?= OPEN_HOUR ?>, closeHour: <?= CLOSE_HOUR ?>, graceMin: <?= CHECKIN_GRACE_MIN ?> };
    // Tampilkan error JavaScript di halaman (bukan hanya di console), supaya mudah dicari penyebabnya.
    window.showFatal = function (msg) {
      var show = function () {
        var box = document.getElementById('fatal-error');
        if (!box) {
          box = document.createElement('div');
          box.id = 'fatal-error';
          box.className = 'alert alert-danger m-3';
          box.setAttribute('role', 'alert');
          document.body.prepend(box);
        }
        box.textContent = 'Terjadi kesalahan: ' + msg;
      };
      if (document.body) show(); else document.addEventListener('DOMContentLoaded', show);
    };
    window.addEventListener('error', function (e) { window.showFatal(e.message || 'script error'); });
    window.addEventListener('unhandledrejection', function (e) {
      window.showFatal((e.reason && e.reason.message) || String(e.reason));
    });
  </script>
</head>
<body class="bg-light">
<?php if ($showNav): ?>
<nav class="navbar navbar-dark bg-primary mb-4">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/dashboard.php">RoomSync Cloud</a>
    <div class="d-flex align-items-center gap-2">
      <span id="user-email" class="text-white small d-none d-sm-inline"></span>
      <button id="btn-logout" class="btn btn-sm btn-outline-light" type="button">Keluar</button>
    </div>
  </div>
</nav>
<?php endif; ?>
<main class="container pb-5">
    <?php
}

function page_footer(array $scripts = []): void
{
    ?>
</main>
<?php foreach ($scripts as $src): ?>
<script type="module" src="<?= e($src) ?>"
        onerror="showFatal('Script Firebase/aplikasi gagal dimuat. Periksa koneksi internet atau ekstensi pemblokir iklan.')"></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}

<?php
require __DIR__ . '/../vendor/autoload.php';

page_header('Masuk');
?>
<div class="row justify-content-center mt-5">
  <div class="col-md-5 col-lg-4">
    <h1 class="h3 text-center fw-bold text-primary mb-1">RoomSync Cloud</h1>
    <p class="text-center text-muted mb-4">Booking &amp; monitoring ruang real-time</p>
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Masuk</h2>
        <form id="auth-form" data-mode="login" novalidate>
          <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" autocomplete="email" required>
          </div>
          <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" autocomplete="current-password" required>
          </div>
          <div id="auth-msg" class="mb-3"></div>
          <button type="submit" class="btn btn-primary w-100">Masuk</button>
        </form>
      </div>
    </div>
    <p class="text-center mt-3">Belum punya akun? <a href="/register.php" id="link-switch">Daftar</a></p>
  </div>
</div>
<?php page_footer(['/assets/auth.js']);

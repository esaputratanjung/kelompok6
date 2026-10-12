# RoomSync Cloud

Sistem booking & monitoring ruang **real-time** berbasis cloud dengan **asisten AI** (Kelompok 6).

**Teknologi:** PHP 8.2+ (Docker: 8.3) + Composer · Bootstrap 5 · Firebase Authentication · Firebase Realtime Database · Kreait Firebase PHP SDK · Resend API · Google Gemini API · Guzzle · Docker · Render (Web Service + Cron Job)

## Fitur

| # | Fitur | Keterangan |
|---|-------|------------|
| 1 | Login & Daftar | Firebase Auth email/password. ID token diverifikasi PHP di setiap request. |
| 2 | Dashboard real-time | Status Ruang A, B, C per tanggal & jam, update otomatis (listener `onValue`) tanpa reload. |
| 3 | Booking anti-bentrok | Validasi waktu + cek tumpang tindih di dalam **transaksi atomik** (ETag), retry terbatas. |
| 4 | E-ticket & check-in | Email berisi kode booking + link check-in dengan token **sekali pakai** (disimpan sebagai hash). |
| 5 | Auto release | Cron tiap menit: tidak check-in ≤ 10 menit → `expired`, jam selesai lewat → `completed`. |
| 6 | Asisten AI | Booking dengan bahasa sehari-hari, saran slot alternatif, ringkasan pemakaian mingguan (Gemini). |

## Struktur Folder

```
├── public/                  ← document root (satu-satunya folder yang bisa diakses dari web)
│   ├── login.php            halaman masuk
│   ├── register.php         halaman daftar
│   ├── dashboard.php        dashboard real-time + form booking + chat AI
│   ├── checkin.php          halaman & API check-in
│   ├── booking.php          API booking (buat, kirim ulang e-ticket)
│   ├── ai_assistant.php     API asisten AI (chat & ringkasan)
│   ├── cron.php             (opsional) pemicu auto release lewat URL
│   └── assets/              JavaScript & CSS
├── src/
│   ├── bootstrap.php        konfigurasi, aturan booking, helper
│   ├── firebase_db.php      koneksi Firebase + logika booking (transaksi, check-in, auto release)
│   ├── auth.php             verifikasi Firebase ID token
│   ├── mailer.php           kirim e-ticket via Resend
│   ├── gemini.php           klien Gemini API
│   └── layout.php           template HTML
├── cron/auto_release.php    dijalankan Render Cron Job tiap menit
├── tests/                   pengujian otomatis (mock Firebase/Resend/Gemini)
├── database.rules.json      aturan keamanan Realtime Database
├── Dockerfile · render.yaml · composer.json · .env.example
```

## Model Data (Realtime Database)

```
/bookings/{booking_id}            ← detail lengkap, hanya bisa dibaca pemiliknya
    booking_id, room_id, uid, email, start_at, end_at (epoch ms, UTC),
    status (booked | checked_in | completed | expired), token_hash, checked_in_at, created_at

/schedules/{room_id}/{YYYY-MM-DD}/{booking_id}   ← jadwal ringkas, tanpa data pribadi
    start_at, end_at, status
```

Jadwal per ruang per tanggal disimpan terpisah, sehingga cek bentrok cukup membaca **satu node** dalam **satu transaksi**. Waktu disimpan dalam UTC (epoch ms) dan ditampilkan dalam WIB.

---

## Cara Menjalankan

### 1. Siapkan Firebase

1. Buat project di <https://console.firebase.google.com>.
2. **Authentication** → Sign-in method → aktifkan **Email/Password**.
3. **Realtime Database** → Create database (pilih lokasi Singapore / `asia-southeast1`).
4. **Rules**: salin isi `database.rules.json` ke tab *Rules* lalu **Publish**.
   > Wajib! Tanpa rules ini daftar "Booking Saya" & auto release akan gagal (butuh `.indexOn`).
5. **Project settings → General → Your apps → Web app (`</>`)**: catat `apiKey`, `authDomain`, `projectId`, `databaseURL`.
6. **Project settings → Service accounts → Generate new private key** → simpan sebagai `firebase-credentials.json` di root project (**jangan di-commit**, sudah ada di `.gitignore`).
7. **Authentication → Settings → Authorized domains**: tambahkan domain Render Anda (mis. `roomsync-cloud.onrender.com`).

### 2. Siapkan API key

- **Resend**: <https://resend.com/api-keys>. Tanpa domain terverifikasi, pengirim `onboarding@resend.dev` hanya bisa mengirim ke email pemilik akun Resend (cukup untuk demo).
- **Gemini**: <https://aistudio.google.com/apikey>.

### 3. Jalankan lokal

Butuh **PHP 8.2 atau lebih baru** (XAMPP 8.2 bisa) dan **Composer**. Cek dengan `php -v` dan `composer -V`.

Di `php.ini`, pastikan ekstensi ini aktif (hapus tanda `;` di depannya): `curl`, `mbstring`, `openssl`, `sodium`, `fileinfo`.
Khusus Windows, isi juga lokasi sertifikat SSL agar PHP bisa menghubungi Firebase/Resend/Gemini
(tanpa ini muncul `cURL error 60`). Unduh <https://curl.se/ca/cacert.pem>, lalu di `php.ini`:

```ini
curl.cainfo = "C:\php\extras\cacert.pem"
openssl.cafile = "C:\php\extras\cacert.pem"
```

Lalu:

```bash
cp .env.example .env          # Windows: copy .env.example .env  → lalu isi semua nilainya
composer install
php -S localhost:8000 -t public
```

Buka <http://localhost:8000>. Untuk auto release lokal, jalankan `php cron/auto_release.php` (atau pasang di cron/Task Scheduler tiap menit).

Atau dengan Docker:

```bash
docker build -t roomsync .
docker run -p 8000:80 --env-file .env -v "$PWD/firebase-credentials.json:/var/www/html/firebase-credentials.json:ro" roomsync
```

### 4. Deploy ke Render

1. Push repo ini ke GitHub.
2. Render Dashboard → **New → Blueprint** → pilih repo → Render membaca `render.yaml` dan membuat:
   - `roomsync-cloud` (Web Service, Docker, free)
   - `roomsync-auto-release` (Cron Job tiap menit, min. US$1/bulan)
3. Isi environment variable yang diminta. `APP_URL` = URL Render Anda, mis. `https://roomsync-cloud.onrender.com`.
4. Di **kedua** service: *Environment → Secret Files* → tambahkan file bernama `firebase.json` berisi JSON service account.

**Alternatif gratis untuk Cron Job:** isi `CRON_SECRET` (min. 16 karakter), lalu buat jadwal tiap menit di <https://cron-job.org> yang memanggil
`https://<app>.onrender.com/cron.php?key=<CRON_SECRET>`.

---

## Keamanan

- **Verifikasi token** – setiap booking & check-in memverifikasi Firebase ID token di PHP; `uid` & email diambil dari token, bukan dari input.
- **Tulis hanya via backend** – `database.rules.json`: klien hanya boleh *membaca*; semua penulisan lewat service account di server.
- **Token check-in aman** – token acak 192-bit, disimpan sebagai hash SHA-256, sekali pakai, dan hanya berlaku di jendela check-in.
- **Secret terlindungi** – service account & API key disimpan sebagai secret Render; repo hanya berisi `.env.example`.
- **Privasi** – dashboard hanya membaca `/schedules` (tanpa email/uid); `/bookings` hanya bisa dibaca pemiliknya.
- **Transaksi atomik** – mencegah race condition saat dua orang memesan slot yang sama.
- **Anti XSS & open redirect** – teks dirender lewat `textContent`; parameter `?next=` hanya menerima path lokal.
- **Rate limit** – maks. 10 booking/menit & 10 permintaan AI/menit per pengguna; kirim ulang e-ticket maks. 1×/menit.

## Guardrail AI

1. **AI tidak menulis ke database** – AI hanya mengubah kalimat menjadi JSON; booking dibuat lewat `booking.php` setelah pengguna menekan tombol **Pesan**.
2. **Output divalidasi** – structured output (JSON schema), lalu dicek ulang: ruang, format tanggal/jam, jam operasional, durasi, tidak di masa lalu.
3. **Ketersediaan dicek dari data asli** – saran slot alternatif dihitung dari jadwal di database, jadi selalu benar-benar kosong.
4. **API key di server** – browser tidak pernah melihat Gemini API key.
5. **Fallback manual** – jika AI gagal/kuota habis, pengguna diarahkan ke form booking biasa; ringkasan mingguan tetap muncul (versi statistik tanpa AI).

## Pengujian

```bash
php tests/run_tests.php
```

Menjalankan 69 skenario otomatis memakai *mock* Firebase Realtime Database, Resend, dan Gemini (tanpa internet/akun), termasuk seluruh skenario di Rencana Pengujian:

| Skenario | Hasil yang diharapkan | Status |
|---|---|---|
| Dua booking serentak, slot sama (diuji 8 request paralel) | Hanya satu berhasil, lainnya ditolak | ✅ |
| Jam selesai ≤ jam mulai | Ditolak oleh validasi | ✅ |
| Email e-ticket | Terkirim; jika gagal bisa kirim ulang, booking tetap | ✅ |
| Check-in oleh bukan pemilik | Ditolak (uid tidak cocok) | ✅ |
| Link check-in dipakai ulang / kedaluwarsa | Ditolak (token sekali pakai) | ✅ |
| Tidak check-in > 10 menit | Status menjadi `expired` | ✅ |
| Auto release saat browser ditutup | Tetap berjalan via cron di server | ✅ |
| AI: "besok jam 10–12 ruang A" | JSON benar; booking hanya setelah konfirmasi | ✅ |
| AI: permintaan ambigu / di luar topik | AI minta klarifikasi, tidak membuat booking | ✅ |

## Aturan Booking (bisa diubah di `src/bootstrap.php`)

- Ruang: A, B, C · jam operasional 07:00–21:00 WIB
- Durasi 30 menit – 4 jam · maksimal 30 hari ke depan
- Check-in dibuka saat jam mulai, ditutup 10 menit setelahnya

## Pembagian Pekerjaan

| Anggota 1 – Infrastruktur, Auth & Email | Anggota 2 – Database, Booking & Dashboard |
|---|---|
| `login.php`, `register.php`, `auth.php`, `mailer.php`, `ai_assistant.php`, `gemini.php`, `composer.json`, `Dockerfile`, `render.yaml`, `.env.example`, `README.md` | `dashboard.php`, `booking.php`, `firebase_db.php`, `checkin.php`, `auto_release.php`, `database.rules.json` |

// Logika halaman Masuk & Daftar (Firebase Authentication email/password).
import { auth, showAlert } from './firebase.js';
import {
  signInWithEmailAndPassword, createUserWithEmailAndPassword, onAuthStateChanged,
  sendEmailVerification, signOut,
} from 'https://www.gstatic.com/firebasejs/10.12.2/firebase-auth.js';

auth.languageCode = 'id'; // email verifikasi berbahasa Indonesia

const form = document.getElementById('auth-form');
const msg = document.getElementById('auth-msg');
const mode = form.dataset.mode; // "login" atau "register"

// Tujuan setelah login. Hanya boleh path lokal (mencegah open redirect ke situs lain).
const params = new URLSearchParams(location.search);
let next = params.get('next') || '/dashboard.php';
if (!next.startsWith('/') || next.startsWith('//') || next.startsWith('/\\')) next = '/dashboard.php';

// Bawa parameter ?next= saat pindah antara halaman Masuk dan Daftar.
const switchLink = document.getElementById('link-switch');
if (switchLink && params.get('next')) {
  switchLink.href += '?next=' + encodeURIComponent(next);
}

let submitting = false;
onAuthStateChanged(auth, (user) => {
  if (user && user.emailVerified && !submitting) location.replace(next); // sudah login
});

const ERRORS = {
  'auth/invalid-email': 'Format email tidak valid.',
  'auth/invalid-credential': 'Email atau password salah.',
  'auth/wrong-password': 'Email atau password salah.',
  'auth/user-not-found': 'Email atau password salah.',
  'auth/email-already-in-use': 'Email sudah terdaftar. Silakan masuk.',
  'auth/weak-password': 'Password minimal 6 karakter.',
  'auth/too-many-requests': 'Terlalu banyak percobaan. Coba lagi nanti.',
  'auth/network-request-failed': 'Koneksi internet bermasalah.',
};

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const email = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;

  if (!email || !password) return showAlert(msg, 'Email dan password wajib diisi.');
  if (mode === 'register') {
    if (password.length < 6) return showAlert(msg, 'Password minimal 6 karakter.');
    if (password !== document.getElementById('password2').value) {
      return showAlert(msg, 'Konfirmasi password tidak sama.');
    }
  }

  const button = form.querySelector('button[type=submit]');
  button.disabled = true;
  submitting = true;
  msg.replaceChildren();
  try {
    const { user } = mode === 'register'
      ? await createUserWithEmailAndPassword(auth, email, password)
      : await signInWithEmailAndPassword(auth, email, password);
    if (user.emailVerified) return location.replace(next);

    // Akun baru / belum verifikasi: kirim link verifikasi, lalu keluar sampai link diklik.
    let sent = true;
    try { await sendEmailVerification(user); } catch { sent = false; } // mis. terlalu sering kirim
    await signOut(auth);
    showAlert(msg, sent
      ? `Link verifikasi dikirim ke ${email}. Klik link itu (cek juga folder spam), lalu masuk.`
      : 'Email belum diverifikasi. Klik link verifikasi yang sudah dikirim ke email Anda, lalu masuk.', 'success');
    button.disabled = false;
    submitting = false;
  } catch (err) {
    showAlert(msg, ERRORS[err.code] || 'Gagal: ' + err.message);
    button.disabled = false;
    submitting = false;
  }
});

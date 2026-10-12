// Logika halaman Masuk & Daftar (Firebase Authentication email/password).
import { auth, showAlert } from './firebase.js';
import {
  signInWithEmailAndPassword, createUserWithEmailAndPassword, onAuthStateChanged,
} from 'https://www.gstatic.com/firebasejs/10.12.2/firebase-auth.js';

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
  if (user && !submitting) location.replace(next); // sudah login
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
    if (mode === 'register') {
      await createUserWithEmailAndPassword(auth, email, password);
    } else {
      await signInWithEmailAndPassword(auth, email, password);
    }
    location.replace(next);
  } catch (err) {
    showAlert(msg, ERRORS[err.code] || 'Gagal: ' + err.message);
    button.disabled = false;
    submitting = false;
  }
});

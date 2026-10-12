// Inisialisasi Firebase di browser + fungsi bantu yang dipakai semua halaman.
import { initializeApp } from 'https://www.gstatic.com/firebasejs/10.12.2/firebase-app.js';
import {
  getAuth, onAuthStateChanged, signOut,
} from 'https://www.gstatic.com/firebasejs/10.12.2/firebase-auth.js';
import { getDatabase } from 'https://www.gstatic.com/firebasejs/10.12.2/firebase-database.js';

const cfg = window.FIREBASE_CONFIG || {};
const missing = ['apiKey', 'authDomain', 'projectId', 'databaseURL'].filter((k) => !cfg[k]);
if (missing.length > 0) {
  throw new Error(`Konfigurasi Firebase belum lengkap (${missing.join(', ')}). `
    + 'Isi FIREBASE_API_KEY, FIREBASE_AUTH_DOMAIN, FIREBASE_PROJECT_ID, FIREBASE_DATABASE_URL di file .env, lalu restart server.');
}

export const app = initializeApp(cfg);
export const auth = getAuth(app);
export const db = getDatabase(app);

/** Tunggu status login. Jika belum login, arahkan ke halaman login. */
export function requireLogin() {
  return new Promise((resolve) => {
    const stop = onAuthStateChanged(auth, (user) => {
      stop();
      if (!user) {
        const next = location.pathname + location.search;
        location.replace('/login.php?next=' + encodeURIComponent(next));
        return;
      }
      const emailEl = document.getElementById('user-email');
      if (emailEl) emailEl.textContent = user.email;
      const logoutBtn = document.getElementById('btn-logout');
      if (logoutBtn) {
        logoutBtn.addEventListener('click', async () => {
          await signOut(auth);
          location.replace('/login.php');
        });
      }
      resolve(user);
    });
  });
}

/** Panggil API backend (PHP) dengan menyertakan Firebase ID token. */
export async function api(path, body) {
  const user = auth.currentUser;
  if (!user) throw new Error('Silakan login terlebih dahulu.');
  const token = await user.getIdToken();

  let res;
  try {
    res = await fetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
      body: JSON.stringify(body),
    });
  } catch {
    throw new Error('Tidak dapat terhubung ke server. Periksa koneksi internet Anda.');
  }

  let data = null;
  try { data = await res.json(); } catch { /* bukan JSON, mis. server sedang bangun (cold start) */ }
  if (!res.ok || !data || data.ok === false) {
    throw new Error((data && data.error) || `Server sedang tidak tersedia (HTTP ${res.status}). Coba lagi sebentar.`);
  }
  return data;
}

// ---- Format waktu (semua ditampilkan dalam WIB) ----
const TZ = 'Asia/Jakarta';

/** Tanggal hari ini di WIB, format YYYY-MM-DD. */
export function todayWib() {
  return new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/** Jam sekarang di WIB (0-23). */
export function hourWib() {
  return Number(new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hour: '2-digit', hour12: false }).format(new Date())) % 24;
}

/** "2026-10-13" + "10:00" (WIB) -> epoch milidetik. */
export function toMs(date, time) {
  return Date.parse(`${date}T${time}:00+07:00`);
}

export function fmtTime(ms) {
  return new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false }).format(ms);
}

export function fmtDate(ms) {
  return new Intl.DateTimeFormat('id-ID', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(ms);
}

export const STATUS_LABEL = {
  booked: { text: 'Dipesan', cls: 'text-bg-warning' },
  checked_in: { text: 'Sedang Digunakan', cls: 'text-bg-danger' },
  completed: { text: 'Selesai', cls: 'text-bg-secondary' },
  expired: { text: 'Kedaluwarsa', cls: 'text-bg-dark' },
};

/** Buat elemen HTML dengan aman (teks selalu lewat textContent, anti-XSS). */
export function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'class') node.className = value;
    else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
    else node.setAttribute(key, value);
  }
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

/** Tampilkan pesan (Bootstrap alert) di dalam elemen target. */
export function showAlert(target, message, type = 'danger') {
  target.replaceChildren(el('div', { class: `alert alert-${type} py-2 mb-0`, role: 'alert' }, message));
}

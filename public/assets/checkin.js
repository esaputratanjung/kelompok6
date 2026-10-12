// Halaman check-in: tampilkan detail booking lalu konfirmasi check-in ke backend.
import {
  db, requireLogin, api, el, showAlert, fmtDate, fmtTime, STATUS_LABEL,
} from './firebase.js';
import { ref, get } from 'https://www.gstatic.com/firebasejs/10.12.2/firebase-database.js';

const detail = document.getElementById('checkin-detail');
const msg = document.getElementById('checkin-msg');
const button = document.getElementById('btn-checkin');
let statusBadge = null;

const params = new URLSearchParams(location.search);
const bookingId = params.get('id') || '';
const token = params.get('token') || '';

await requireLogin();

if (!/^RS-[A-Z0-9]{6}$/.test(bookingId) || !token) {
  detail.replaceChildren();
  showAlert(msg, 'Link check-in tidak lengkap. Buka link langsung dari email e-ticket Anda.');
} else {
  try {
    const snap = await get(ref(db, `bookings/${bookingId}`));
    if (!snap.exists()) throw new Error('not found');
    const b = snap.val();
    const status = STATUS_LABEL[b.status] || { text: b.status, cls: 'text-bg-light' };
    const closeAt = b.start_at + window.APP.graceMin * 60000;
    statusBadge = el('span', { class: `badge ${status.cls}` }, status.text);

    detail.replaceChildren(el('table', { class: 'table mb-0' }, el('tbody', {},
      el('tr', {}, el('th', {}, 'Kode'), el('td', {}, b.booking_id)),
      el('tr', {}, el('th', {}, 'Ruang'), el('td', {}, window.APP.rooms[b.room_id] || b.room_id)),
      el('tr', {}, el('th', {}, 'Tanggal'), el('td', {}, fmtDate(b.start_at))),
      el('tr', {}, el('th', {}, 'Jam'), el('td', {}, `${fmtTime(b.start_at)} – ${fmtTime(b.end_at)} WIB`)),
      el('tr', {}, el('th', {}, 'Status'), el('td', {}, statusBadge)),
    )));

    if (b.status === 'booked') {
      showAlert(msg, `Jendela check-in: ${fmtTime(b.start_at)} – ${fmtTime(closeAt)} WIB.`, 'info');
      button.disabled = false;
    }
  } catch {
    // Aturan database hanya mengizinkan pemilik membaca booking-nya.
    detail.replaceChildren();
    showAlert(msg, 'Booking tidak ditemukan atau bukan milik akun yang sedang login.');
  }
}

button.addEventListener('click', async () => {
  button.disabled = true;
  try {
    const res = await api('/checkin.php', { booking_id: bookingId, token });
    showAlert(msg, res.message, 'success');
    button.textContent = 'Sudah Check-in';
    if (statusBadge) {
      statusBadge.className = `badge ${STATUS_LABEL.checked_in.cls}`;
      statusBadge.textContent = STATUS_LABEL.checked_in.text;
    }
    history.replaceState(null, '', '/checkin.php'); // hapus token dari address bar
  } catch (err) {
    showAlert(msg, err.message);
    button.disabled = false;
  }
});

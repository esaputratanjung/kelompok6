// Dashboard: status ruang real-time, form booking, daftar booking saya, asisten AI.
import {
  db, requireLogin, api, el, showAlert, todayWib, hourWib, toMs, fmtTime, fmtDate, STATUS_LABEL,
} from './firebase.js';
import {
  ref, onValue, query, orderByChild, equalTo,
} from 'https://www.gstatic.com/firebasejs/10.12.2/firebase-database.js';

const { rooms, openHour, closeHour, graceMin } = window.APP;
const ROOM_IDS = Object.keys(rooms);
const pad = (n) => String(n).padStart(2, '0');
const $ = (id) => document.getElementById(id);

const user = await requireLogin();

// ---------------------------------------------------------------------------
// Filter tanggal & jam (default: jam berikutnya, selama 1 jam)
// ---------------------------------------------------------------------------
let defaultDate = todayWib();
let startHour = hourWib() + 1;
if (startHour < openHour) startHour = openHour;
if (startHour > closeHour - 1) { // sudah lewat jam operasional -> besok pagi
  defaultDate = new Date(Date.parse(defaultDate + 'T12:00:00+07:00') + 86400000).toISOString().slice(0, 10);
  startHour = openHour;
}
$('f-date').value = defaultDate;
$('f-date').min = todayWib();
$('f-start').value = `${pad(startHour)}:00`;
$('f-end').value = `${pad(startHour + 1)}:00`;
$('b-date').min = todayWib();

function copyFilterToForm() {
  $('b-date').value = $('f-date').value;
  $('b-start').value = $('f-start').value;
  $('b-end').value = $('f-end').value;
}
copyFilterToForm();

// ---------------------------------------------------------------------------
// Listener real-time jadwal: /schedules/{ruang}/{tanggal}
// ---------------------------------------------------------------------------
const schedules = {};
let unsubscribers = [];

function subscribeSchedules() {
  unsubscribers.forEach((stop) => stop());
  unsubscribers = [];
  const date = $('f-date').value;
  ROOM_IDS.forEach((room) => {
    schedules[room] = null; // null = sedang memuat
    if (!date) return;
    unsubscribers.push(onValue(ref(db, `schedules/${room}/${date}`), (snap) => {
      schedules[room] = snap.val() || {};
      renderRooms();
    }, () => {
      schedules[room] = {};
      $('live-indicator').textContent = '● Gagal memuat data (cek database rules)';
      $('live-indicator').className = 'text-danger';
    }));
  });
  renderRooms();
}

onValue(ref(db, '.info/connected'), (snap) => {
  const ok = snap.val() === true;
  $('live-indicator').textContent = ok ? '● Live (real-time)' : '● Terputus, menghubungkan ulang…';
  $('live-indicator').className = ok ? 'text-success' : 'text-warning';
});

// ---------------------------------------------------------------------------
// Kartu status ruang
// ---------------------------------------------------------------------------
function renderRooms() {
  const date = $('f-date').value;
  const fStart = toMs(date, $('f-start').value);
  const fEnd = toMs(date, $('f-end').value);
  const validRange = date && fStart < fEnd;
  const now = Date.now();

  const cards = ROOM_IDS.map((room) => {
    const data = schedules[room];
    const entries = Object.values(data || {})
      .filter((s) => s && s.start_at && s.end_at)
      .sort((a, b) => a.start_at - b.start_at);
    const active = entries.filter((s) => s.status === 'booked' || s.status === 'checked_in');
    const overlap = validRange ? active.filter((s) => s.start_at < fEnd && fStart < s.end_at) : [];

    let status;
    if (data === null) status = { text: 'Memuat…', color: '#adb5bd', cls: 'text-muted' };
    else if (!validRange) status = { text: 'Jam filter tidak valid', color: '#adb5bd', cls: 'text-muted' };
    else if (overlap.some((s) => s.status === 'checked_in' && s.start_at <= now && now < s.end_at)) {
      status = { text: 'Sedang Digunakan', color: '#dc3545', cls: 'text-danger' };
    } else if (overlap.length > 0) status = { text: 'Penuh / Dipesan', color: '#fd7e14', cls: 'text-warning-emphasis' };
    else status = { text: 'Tersedia', color: '#198754', cls: 'text-success' };

    const slotList = entries.length === 0
      ? el('li', { class: 'text-muted' }, 'Belum ada booking di tanggal ini.')
      : entries.map((s) => {
        const label = STATUS_LABEL[s.status] || { text: s.status, cls: 'text-bg-light' };
        return el('li', { class: 'd-flex justify-content-between border-bottom py-1' },
          `${fmtTime(s.start_at)} – ${fmtTime(s.end_at)}`,
          el('span', { class: `badge ${label.cls}` }, label.text));
      });

    return el('div', { class: 'col-md-4' },
      el('div', { class: 'card room-card h-100 shadow-sm' },
        el('div', { class: 'card-body' },
          el('h2', { class: 'h5 card-title' }, rooms[room]),
          el('p', { class: `room-status fw-semibold ${status.cls} mb-1` },
            el('span', { class: 'status-dot', style: `background:${status.color}` }), status.text),
          overlap.length > 0
            ? el('p', { class: 'small text-muted mb-2' }, 'Booking ' + overlap.map((s) => `${fmtTime(s.start_at)} – ${fmtTime(s.end_at)}`).join(', '))
            : el('p', { class: 'small text-muted mb-2' }, validRange ? `Slot ${$('f-start').value} – ${$('f-end').value}` : ''),
          el('ul', { class: 'list-unstyled slot-list mb-3' }, slotList),
          el('button', {
            class: 'btn btn-sm btn-outline-primary w-100',
            type: 'button',
            onclick: () => {
              $('b-room').value = room;
              copyFilterToForm();
              $('booking-form').scrollIntoView({ behavior: 'smooth' });
            },
          }, 'Pilih ruang ini'))));
  });
  $('room-cards').replaceChildren(...cards);
}

['f-date', 'f-start', 'f-end'].forEach((id) => $(id).addEventListener('change', () => {
  if (id === 'f-date') subscribeSchedules(); else renderRooms();
  copyFilterToForm();
}));
subscribeSchedules();
setInterval(() => { renderRooms(); renderMyBookings(); }, 30000); // perbarui status berbasis waktu

// ---------------------------------------------------------------------------
// Booking
// ---------------------------------------------------------------------------
async function createBooking(payload) {
  return api('/booking.php', { action: 'create', ...payload });
}

$('booking-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const payload = {
    room_id: $('b-room').value, date: $('b-date').value, start: $('b-start').value, end: $('b-end').value,
  };
  if (!payload.date || !payload.start || !payload.end) {
    return showAlert($('booking-msg'), 'Tanggal, jam mulai, dan jam selesai wajib diisi.');
  }
  if (payload.end <= payload.start) return showAlert($('booking-msg'), 'Jam selesai harus setelah jam mulai.');

  const button = event.submitter || $('booking-form').querySelector('button');
  button.disabled = true;
  showAlert($('booking-msg'), 'Memproses booking…', 'secondary');
  try {
    const res = await createBooking(payload);
    showAlert($('booking-msg'), res.message, res.email_sent ? 'success' : 'warning');
  } catch (err) {
    showAlert($('booking-msg'), err.message);
  } finally {
    button.disabled = false;
  }
});

// ---------------------------------------------------------------------------
// Booking saya (real-time, hanya milik pengguna ini — dijaga database rules)
// ---------------------------------------------------------------------------
let myBookings = [];

onValue(query(ref(db, 'bookings'), orderByChild('uid'), equalTo(user.uid)), (snap) => {
  myBookings = Object.values(snap.val() || {}).sort((a, b) => b.start_at - a.start_at);
  renderMyBookings();
}, () => {
  $('my-bookings').replaceChildren();
  showAlert($('my-msg'), 'Gagal memuat booking (pastikan database.rules.json sudah di-deploy).');
});

function renderMyBookings() {
  const box = $('my-bookings');
  if (myBookings.length === 0) {
    box.replaceChildren(el('p', { class: 'text-muted mb-0' }, 'Anda belum punya booking.'));
    return;
  }
  const now = Date.now();
  box.replaceChildren(el('ul', { class: 'list-group list-group-flush' }, myBookings.slice(0, 10).map((b) => {
    const label = STATUS_LABEL[b.status] || { text: b.status, cls: 'text-bg-light' };
    const canResend = b.status === 'booked' && now <= b.start_at + graceMin * 60000;
    return el('li', { class: 'list-group-item px-0' },
      el('div', { class: 'd-flex justify-content-between align-items-start gap-2' },
        el('div', {},
          el('div', { class: 'fw-semibold' }, `${b.booking_id} · ${rooms[b.room_id] || b.room_id}`),
          el('div', { class: 'small text-muted' }, `${fmtDate(b.start_at)}, ${fmtTime(b.start_at)} – ${fmtTime(b.end_at)} WIB`)),
        el('span', { class: `badge ${label.cls}` }, label.text)),
      canResend && el('button', {
        class: 'btn btn-sm btn-link px-0',
        type: 'button',
        onclick: async (event) => {
          event.target.disabled = true;
          try {
            const res = await api('/booking.php', { action: 'resend', booking_id: b.booking_id });
            showAlert($('my-msg'), res.message, 'success');
          } catch (err) {
            showAlert($('my-msg'), err.message);
          } finally {
            event.target.disabled = false;
          }
        },
      }, 'Kirim ulang e-ticket'));
  })));
}

// ---------------------------------------------------------------------------
// Asisten AI
// ---------------------------------------------------------------------------
const chatBox = $('chat-box');

function addChat(text, from = 'ai') {
  const bubble = el('div', {
    class: `chat-msg rounded p-2 mb-2 small ${from === 'user' ? 'bg-primary text-white ms-auto' : 'bg-white border'}`,
  }, text);
  chatBox.append(bubble);
  chatBox.scrollTop = chatBox.scrollHeight;
  return bubble;
}

$('chat-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const input = $('chat-input');
  const message = input.value.trim();
  if (!message) return;
  input.value = '';
  addChat(message, 'user');
  const thinking = addChat('Sedang berpikir…');
  const button = event.submitter || $('chat-form').querySelector('button');
  button.disabled = true;

  try {
    const res = await api('/ai_assistant.php', { action: 'chat', message });
    thinking.textContent = res.reply;
    // Usulan booking: pengguna WAJIB menekan tombol untuk konfirmasi.
    res.proposals.forEach((p) => {
      const btn = el('button', { class: 'btn btn-sm btn-success mt-2 me-2', type: 'button' }, `Pesan: ${p.label}`);
      btn.addEventListener('click', async () => {
        thinking.querySelectorAll('button').forEach((b) => { b.disabled = true; });
        try {
          const booked = await createBooking({ room_id: p.room_id, date: p.date, start: p.start, end: p.end });
          addChat(booked.message);
        } catch (err) {
          addChat('Gagal: ' + err.message);
          thinking.querySelectorAll('button').forEach((b) => { b.disabled = false; });
        }
      });
      thinking.append(el('br'), btn);
    });
  } catch (err) {
    thinking.textContent = err.message + ' (Anda tetap bisa memakai form booking manual.)';
  } finally {
    button.disabled = false;
  }
});

$('btn-summary').addEventListener('click', async (event) => {
  const button = event.currentTarget;
  button.disabled = true;
  $('summary').textContent = 'Menyusun ringkasan…';
  try {
    const res = await api('/ai_assistant.php', { action: 'summary' });
    $('summary').textContent = res.summary + (res.source === 'system' ? '\n\n(ringkasan otomatis tanpa AI)' : '');
    $('summary').classList.remove('text-muted');
  } catch (err) {
    $('summary').textContent = err.message;
  } finally {
    button.disabled = false;
  }
});

<?php
session_start();
$is_logged_in = !empty($_SESSION['passenger_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Passenger Agent | LebanEASE</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('agent'); ?>

<main class="agent-shell">
  <section class="agent-hero">
    <span class="badge">Passenger Agent</span>
    <h1>Ask the passenger agent</h1>
    <p>Search trips, reserve a seat, view reservations, or request a cancellation using simple chat in English, Arabic, or French.</p>
  </section>

  <div class="agent-toolbar">
    <div class="lang-row"><span>English</span><span>العربية</span><span>Français</span></div>
    <button class="btn btn-secondary btn-sm" type="button" onclick="newSession()">New Session</button>
  </div>

  <?php if (!$is_logged_in): ?>
    <div class="guest-notice">You are browsing as a guest. The agent can help with trip search, but booking or cancellation requires <a href="login.php">passenger login</a>.</div>
  <?php endif; ?>

  <section class="agent-panel">
    <div id="chat" class="agent-window ax-window">
      <div class="b bot">
        Hi! I can search trips and help you reserve seats.<br>
        مرحبًا! يمكنني البحث عن الرحلات ومساعدتك في الحجز.<br>
        Bonjour! Je peux chercher des trajets et aider avec la réservation.
      </div>
    </div>

    <div class="agent-composer ax-composer">
      <textarea id="msg" placeholder="Try: I want to book Beirut to Tripoli tomorrow"></textarea>
      <button id="send" type="button" onclick="send()">Send</button>
    </div>
  </section>

  <div class="ax-suggestions">
    <button class="sg" data-lang="en" onclick="useExample(this)">
      <span class="flag">EN</span>
      <div class="text">I want to book a trip from Beirut to Tripoli tomorrow</div>
    </button>
    <button class="sg" data-lang="ar" onclick="useExample(this)">
      <span class="flag">AR</span>
      <div class="text">اريد حجز رحلة من زحلة الى بعلبك</div>
    </button>
    <button class="sg" data-lang="fr" onclick="useExample(this)">
      <span class="flag">FR</span>
      <div class="text">Quelles sont mes réservations actuelles?</div>
    </button>
  </div>
</main>

<script>
const chat    = document.getElementById('chat');
const msgEl   = document.getElementById('msg');
const sendBtn = document.getElementById('send');

const history = [];

// === Multilingual labels ============================================
const I18N = {
  en: {
    confirm_book:   'Confirm Booking',
    confirm_cancel: 'Confirm Cancellation',
    cancel:         'No, never mind',
    confirming:     'Confirming…',
    booked_success: 'Booking confirmed!',
    cancel_success: 'Reservation cancelled.',
    cancelled:      'Cancelled',
    label_from:     'From',  label_to:    'To',
    label_date:     'Date',  label_time:  'Departure',
    label_seat:     'Seat',  label_bus:   'Bus',
    label_book:     'BOOKING PROPOSAL',
    label_cancl:    'CANCELLATION PROPOSAL',
    err:            'Error',
  },
  ar: {
    confirm_book:   'تأكيد الحجز',
    confirm_cancel: 'تأكيد الإلغاء',
    cancel:         'لا، اترك',
    confirming:     'جارٍ التأكيد…',
    booked_success: 'تم تأكيد الحجز!',
    cancel_success: 'تم إلغاء الحجز.',
    cancelled:      'تم الإلغاء',
    label_from:     'من',   label_to:    'إلى',
    label_date:     'التاريخ', label_time: 'المغادرة',
    label_seat:     'المقعد', label_bus:  'الباص',
    label_book:     'اقتراح حجز',
    label_cancl:    'اقتراح إلغاء',
    err:            'خطأ',
  },
  fr: {
    confirm_book:   'Confirmer la réservation',
    confirm_cancel: "Confirmer l'annulation",
    cancel:         'Non, laissez',
    confirming:     'Confirmation…',
    booked_success: 'Réservation confirmée!',
    cancel_success: 'Réservation annulée.',
    cancelled:      'Annulé',
    label_from:     'De',   label_to:    'À',
    label_date:     'Date', label_time:  'Départ',
    label_seat:     'Siège', label_bus:  'Bus',
    label_book:     'PROPOSITION DE RÉSERVATION',
    label_cancl:    "PROPOSITION D'ANNULATION",
    err:            'Erreur',
  },
};

function detectArabic(s) {
  return /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/.test(String(s || ''));
}
function detectFrench(s) {
  return /[àâçéèêëîïôûùüÿœæ]/i.test(String(s || ''))
      || /\b(bonjour|merci|réservation|voyage|votre|siège|annul)/i.test(String(s || ''));
}
function langOf(s) {
  if (detectArabic(s)) return 'ar';
  if (detectFrench(s)) return 'fr';
  return 'en';
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function addBubble(role, text, rtl=false) {
  const div = document.createElement('div');
  div.className = 'b ' + role;
  if (rtl) div.setAttribute('dir', 'rtl');
  div.innerHTML = escapeHtml(text).replace(/\n/g, '<br>');
  chat.appendChild(div);
  chat.scrollTop = chat.scrollHeight;
  return div;
}

function addTyping() {
  const div = document.createElement('div');
  div.className = 'b typing';
  div.innerHTML = '<span></span><span></span><span></span>';
  chat.appendChild(div);
  chat.scrollTop = chat.scrollHeight;
  return div;
}

function useExample(btn) {
  const text = btn.querySelector('.text').textContent.trim();
  msgEl.value = text;
  msgEl.dir = detectArabic(text) ? 'rtl' : 'ltr';
  msgEl.focus();
}

function newSession() {
  history.length = 0;
  chat.innerHTML = '';
  addBubble('bot', 'Session reset. How can I help?  /  تمت إعادة الجلسة. كيف يمكنني المساعدة؟  /  Session réinitialisée.');
}

msgEl.addEventListener('input', () => {
  msgEl.dir = detectArabic(msgEl.value) ? 'rtl' : 'ltr';
});

// === Render a proposal card ==========================================
function renderProposal(proposal, replyText) {
  const lang = langOf(replyText || '');
  const t = I18N[lang];
  const rtl = lang === 'ar';

  const card = document.createElement('div');
  card.className = 'proposal-card' + (proposal.type === 'cancellation' ? ' cancellation' : '');
  if (rtl) card.setAttribute('dir', 'rtl');

  const trip = proposal.trip || (proposal.reservation || {});
  const headLabel = proposal.type === 'booking' ? t.label_book : t.label_cancl;
  const confirmLabel = proposal.type === 'booking' ? t.confirm_book : t.confirm_cancel;
  const isCancel = proposal.type === 'cancellation';

  const seat = proposal.seat_number || trip.Seat_Number || '—';
  const time = (trip.Departure_Time || '').slice(0,5);

  card.innerHTML = `
    <div class="proposal-head">${headLabel}</div>
    <div class="route">
      <span class="city">${escapeHtml(trip.Source || '')}</span>
      <span class="arrow">→</span>
      <span class="city">${escapeHtml(trip.Destination || '')}</span>
    </div>
    <div class="detail-grid">
      <div class="item"><span class="label">${t.label_date}</span><span class="value">${escapeHtml(trip.Date || '')}</span></div>
      <div class="item"><span class="label">${t.label_time}</span><span class="value">${escapeHtml(time)}</span></div>
      <div class="item"><span class="label">${t.label_bus}</span><span class="value">${escapeHtml(trip.Bus_Number || '')}</span></div>
      <div class="item"><span class="label">${t.label_seat}</span><span class="value">${escapeHtml(String(seat))}</span></div>
    </div>
    <div class="proposal-actions">
      <button class="btn-confirm${isCancel ? ' danger' : ''}">${confirmLabel}</button>
      <button class="btn-cancel">${t.cancel}</button>
    </div>
  `;

  const confirmBtn = card.querySelector('.btn-confirm');
  const cancelBtn  = card.querySelector('.btn-cancel');
  confirmBtn.addEventListener('click', () => commitProposal(card, proposal, t, lang));
  cancelBtn.addEventListener('click',  () => rejectProposal(card, t));

  chat.appendChild(card);
  chat.scrollTop = chat.scrollHeight;
}

async function commitProposal(card, proposal, t, lang) {
  const confirmBtn = card.querySelector('.btn-confirm');
  const cancelBtn  = card.querySelector('.btn-cancel');
  const actionsRow = card.querySelector('.proposal-actions');
  confirmBtn.disabled = true; cancelBtn.disabled = true;
  confirmBtn.textContent = t.confirming;

  try {
    const res = await fetch('user_agent_commit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ proposal }),
    });
    const data = await res.json();

    if (data.status === 'success') {
      const msg = (proposal.type === 'booking' ? t.booked_success : t.cancel_success)
                + (data.reservation_id ? `  #${data.reservation_id}` : '');
      actionsRow.innerHTML = `<span class="flag-success">✓ ${msg}</span>`;
      addBubble('success', msg, lang === 'ar');
    } else {
      actionsRow.innerHTML = `<span class="flag-error">${escapeHtml(data.error || t.err)}</span>`;
      addBubble('error', data.error || t.err);
      confirmBtn.disabled = false; cancelBtn.disabled = false;
    }
  } catch (e) {
    actionsRow.innerHTML = `<span class="flag-error">Network error</span>`;
  }
}

function rejectProposal(card, t) {
  const actionsRow = card.querySelector('.proposal-actions');
  actionsRow.innerHTML = `<span class="flag-cancelled">${t.cancelled}</span>`;
}

// === Main send =======================================================
async function send() {
  const text = msgEl.value.trim();
  if (!text) return;
  const userIsArabic = detectArabic(text);
  addBubble('user', text, userIsArabic);
  msgEl.value = ''; msgEl.dir = 'ltr';
  sendBtn.disabled = true;
  const typing = addTyping();

  history.push({ role: 'user', content: text });

  try {
    const res = await fetch('user_agent_ai.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ messages: history }),
    });
    const data = await res.json();
    typing.remove();

    if (data.error) {
      addBubble('error', 'Error: ' + data.error);
      history.pop();
    } else {
      if (data.reply) {
        addBubble('bot', data.reply, !!data.rtl);
        history.push({ role: 'assistant', content: data.reply });
      }
      if (data.proposal) {
        renderProposal(data.proposal, data.reply);
      }
    }
  } catch (e) {
    typing.remove();
    addBubble('error', 'Network error: ' + e.message);
    history.pop();
  } finally {
    sendBtn.disabled = false;
  }
}

msgEl.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
});
</script>
</body>
</html>

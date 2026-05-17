<?php
/**
 * LebanEASE Operations AI Agent - "The Bridge" repaint
 * Cool slate palette, signal amber for action, per-agent color semantics.
 *
 * Drop in:  C:\xampp\htdocs\LebanEase\LebanEase\admin\operations_agent.php
 */

require_once __DIR__ . '/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>LebanEASE | Dispatch Bridge</title>
<link rel="stylesheet" href="../css/style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,700;9..144,900&family=JetBrains+Mono:wght@400;500;600;700&family=Noto+Sans+Arabic:wght@400;500;700&display=swap" rel="stylesheet">
<style>
  /* =================================================================
     THE BRIDGE  -  cool slate, signal amber
     ================================================================= */
  :root {
    --ink:        #0a0d12;
    --paper:      #141923;
    --paper-2:    #1c2330;
    --paper-3:    #242c3c;
    --text:       #e8eef4;
    --text-2:     #9aa5b8;
    --text-3:     #5b6577;
    --rule:       rgba(232,238,244,.08);
    --rule-strong:rgba(232,238,244,.18);

    --amber:      #ffb020;
    --amber-deep: #b87a10;
    --mint:       #5fd17e;
    --cyan:       #6ec5d8;
    --lavender:   #b993ff;
    --peach:      #ffa066;
    --coral:      #ff5d5d;

    --grain: url("data:image/svg+xml;utf8,<svg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 0.91 0 0 0 0 0.93 0 0 0 0 0.96 0 0 0 0.035 0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
  }

  body {
    background:
      radial-gradient(1100px 700px at 80% -10%, rgba(255,176,32,.05), transparent 60%),
      radial-gradient(900px 600px at -5% 110%, rgba(110,197,216,.04), transparent 55%),
      var(--ink);
    color: var(--text);
    font-family: "JetBrains Mono", ui-monospace, monospace;
  }
  body::before {
    content:""; position: fixed; inset: 0; pointer-events: none;
    background: var(--grain); opacity: .35; mix-blend-mode: overlay; z-index: 0;
  }

  .terminal {
    max-width: 1180px; margin: 28px auto 80px; padding: 0 24px;
    position: relative; z-index: 1;
  }

  /* ==== Masthead ============================================ */
  .masthead {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: end;
    gap: 24px;
    padding: 28px 32px 22px;
    background:
      linear-gradient(180deg, rgba(232,238,244,.03), transparent 70%),
      var(--paper);
    border: 1px solid var(--rule);
    border-radius: 4px;
    position: relative;
    animation: fade-up .55s cubic-bezier(.2,.7,.3,1) backwards;
  }
  .masthead::before {
    content: "EST. 2026  ·  BEIRUT  ·  DISPATCH BRIDGE No.1";
    position: absolute; top: 8px; left: 32px; right: 32px;
    font-size: 10px; letter-spacing: .35em;
    color: var(--text-3);
    border-bottom: 1px dashed var(--rule);
    padding-bottom: 4px;
  }
  .masthead h1 {
    margin: 18px 0 0;
    font-family: "Fraunces", serif;
    font-variation-settings: "opsz" 144;
    font-weight: 900;
    font-size: clamp(38px, 5.5vw, 64px);
    line-height: .95; letter-spacing: -.015em;
    color: var(--text);
  }
  .masthead h1 em {
    font-style: italic; font-weight: 500;
    color: var(--amber);
    font-variation-settings: "opsz" 144;
  }
  .masthead .lede {
    margin: 10px 0 0; max-width: 620px;
    color: var(--text-2); font-size: 13.5px; line-height: 1.55;
  }
  .masthead .seal {
    display: flex; flex-direction: column; align-items: flex-end; gap: 6px;
    font-size: 10px; letter-spacing: .2em; color: var(--text-3);
  }
  .masthead .seal .pulse {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 12px; border: 1px solid var(--mint);
    color: var(--mint);
    font-weight: 600; letter-spacing: .18em;
  }
  .masthead .seal .pulse::before {
    content:""; width: 8px; height: 8px; border-radius: 50%;
    background: var(--mint); box-shadow: 0 0 12px var(--mint);
    animation: ping 1.6s ease-in-out infinite;
  }
  @keyframes ping {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%      { opacity: .35; transform: scale(.7); }
  }

  /* Route ornament */
  .route-line {
    display: flex; align-items: center; gap: 0;
    margin: 22px 0 28px;
    animation: fade-up .55s .15s cubic-bezier(.2,.7,.3,1) backwards;
  }
  .route-line .stop {
    width: 10px; height: 10px; border: 1.5px solid var(--amber);
    transform: rotate(45deg); flex: none;
  }
  .route-line .stop.filled { background: var(--amber); }
  .route-line .seg {
    flex: 1; height: 1px;
    background-image: linear-gradient(90deg, var(--amber) 50%, transparent 0);
    background-size: 8px 1px; opacity: .55;
  }
  .route-line .label {
    font-size: 9.5px; letter-spacing: .35em;
    color: var(--text-3); padding: 0 12px;
  }

  /* ==== Agent roster ========================================= */
  .roster {
    display: flex; flex-wrap: wrap; gap: 10px;
    margin: 0 0 22px;
    animation: fade-up .55s .25s cubic-bezier(.2,.7,.3,1) backwards;
  }
  .badge {
    font-size: 10.5px; letter-spacing: .18em;
    padding: 5px 11px 5px 9px;
    border: 1px solid var(--rule);
    color: var(--text-2);
    display: inline-flex; align-items: center; gap: 8px;
  }
  .badge::before {
    content: ""; width: 6px; height: 6px; border-radius: 50%;
    background: var(--text-3);
  }
  .badge.on { color: var(--text); }
  .badge.dispatcher::before    { background: var(--mint);     box-shadow: 0 0 8px var(--mint); }
  .badge.customer_care::before { background: var(--lavender); }
  .badge.maintenance::before   { background: var(--peach); }
  .badge.analyst::before       { background: var(--cyan); }
  .badge .pending { color: var(--text-3); margin-left: 4px; font-size: 9px; }

  /* ==== Console / chat surface =============================== */
  .console {
    background:
      linear-gradient(180deg, rgba(232,238,244,.02), transparent 30%),
      var(--paper);
    border: 1px solid var(--rule);
    border-radius: 4px;
    padding: 20px;
    position: relative;
    animation: fade-up .55s .35s cubic-bezier(.2,.7,.3,1) backwards;
  }
  .console::before {
    content: "TRANSCRIPT  ·  LIVE";
    position: absolute; top: -10px; left: 22px;
    font-size: 9.5px; letter-spacing: .35em;
    color: var(--amber); background: var(--ink); padding: 0 10px;
  }
  .console::after {
    content: "AGENTS ARE NOT HUMAN  ·  REVIEW BEFORE APPROVAL";
    position: absolute; bottom: -10px; right: 22px;
    font-size: 9.5px; letter-spacing: .35em;
    color: var(--text-3); background: var(--ink); padding: 0 10px;
  }
  .chat-window {
    min-height: 420px; max-height: 62vh; overflow-y: auto;
    display: flex; flex-direction: column; gap: 18px;
    padding: 8px 4px;
  }
  .chat-window::-webkit-scrollbar { width: 8px; }
  .chat-window::-webkit-scrollbar-track { background: transparent; }
  .chat-window::-webkit-scrollbar-thumb { background: rgba(232,238,244,.1); border-radius: 4px; }

  /* ==== Boarding-pass ticket bubble ========================= */
  @keyframes ticket-in {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: none; }
  }
  .bubble {
    max-width: 86%;
    padding: 12px 16px 14px;
    border: 1px solid var(--rule);
    background: rgba(232,238,244,.015);
    color: var(--text);
    position: relative;
    animation: ticket-in .35s cubic-bezier(.2,.7,.3,1) backwards;
    box-shadow: 0 1px 0 rgba(0,0,0,.4);
  }
  /* Arabic content: switch font + direction */
  .bubble[dir="rtl"] .body,
  .bubble[dir="rtl"] .rat {
    font-family: "Noto Sans Arabic", "JetBrains Mono", sans-serif;
    font-size: 14px;
    line-height: 1.7;
    text-align: right;
    direction: rtl;
  }
  .bubble[dir="rtl"] h4 {
    font-family: "Noto Sans Arabic", "Fraunces", serif;
    text-align: right;
    direction: rtl;
  }
  /* Plan card stays LTR for action labels; only the prose fields flip */
  .bubble[dir="rtl"] {
    text-align: right;
  }
  .bubble::before {
    content: ""; position: absolute; top: -1px; left: -1px;
    width: 14px; height: 14px;
    background: linear-gradient(135deg, transparent 50%, var(--paper) 50%);
    border-right: 1px solid var(--rule);
    border-bottom: 1px solid var(--rule);
  }
  .bubble .who {
    font-size: 9.5px; letter-spacing: .35em; font-weight: 700;
    padding: 0 0 8px;
    border-bottom: 1px dashed var(--rule);
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 10px;
    color: var(--text-2);
  }
  .bubble .who::after {
    content: ""; flex: 1; height: 1px;
    background-image: linear-gradient(90deg, var(--rule) 50%, transparent 0);
    background-size: 6px 1px;
  }
  .bubble .body {
    font-family: "JetBrains Mono", monospace;
    font-size: 13px; line-height: 1.6;
    white-space: pre-wrap; word-wrap: break-word;
  }

  .bubble.user {
    align-self: flex-end;
    background: rgba(255,176,32,.06);
    border-color: rgba(255,176,32,.4);
    color: var(--text);
  }
  .bubble.user::before {
    left: auto; right: -1px;
    background: linear-gradient(225deg, transparent 50%, var(--paper) 50%);
    border-right: 0; border-left: 1px solid rgba(255,176,32,.4);
  }
  .bubble.user .who { color: var(--amber); }

  .bubble.orchestrator { border-color: rgba(232,238,244,.18); }
  .bubble.orchestrator .who { color: var(--text); }

  .bubble.dispatcher    { border-color: rgba(95,209,126,.35);
                          background: linear-gradient(180deg, rgba(95,209,126,.05), transparent 80%); }
  .bubble.dispatcher .who    { color: var(--mint); }

  .bubble.customer_care { border-color: rgba(185,147,255,.35);
                          background: linear-gradient(180deg, rgba(185,147,255,.05), transparent 80%); }
  .bubble.customer_care .who { color: var(--lavender); }

  .bubble.maintenance   { border-color: rgba(255,160,102,.35);
                          background: linear-gradient(180deg, rgba(255,160,102,.05), transparent 80%); }
  .bubble.maintenance .who   { color: var(--peach); }

  .bubble.analyst       { border-color: rgba(110,197,216,.35);
                          background: linear-gradient(180deg, rgba(110,197,216,.05), transparent 80%); }
  .bubble.analyst .who       { color: var(--cyan); }

  .bubble.error  { border-color: rgba(255,93,93,.45); background: rgba(255,93,93,.06); }
  .bubble.error .who  { color: var(--coral); }

  .bubble.success  { border-color: rgba(95,209,126,.45); background: rgba(95,209,126,.06); }
  .bubble.success .who  { color: var(--mint); }

  /* Collapsible specialist bubbles - compact when collapsed, full when expanded */
  .bubble.collapsible {
    padding: 0;
    overflow: hidden;
    transition: all .2s ease;
  }
  .bubble.collapsible .who {
    padding: 12px 18px;
    margin: 0;
    user-select: none;
    transition: background .15s;
    display: flex; align-items: center; gap: 8px;
    font-size: 11px;
  }
  .bubble.collapsible .who:hover { background: rgba(255,255,255,.03); }
  .bubble.collapsible .who::after { display: none; }
  .bubble.collapsible .who .caret {
    font-size: 10px;
    color: var(--text-3);
    transition: transform .15s;
    width: 12px;
    display: inline-block;
  }
  .bubble.collapsible .bubble-summary {
    color: var(--text-2);
    font-size: 11px;
    font-weight: 400;
    letter-spacing: 0;
    text-transform: none;
    margin-left: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
  }
  .bubble.collapsible.collapsed .body {
    max-height: 0;
    padding: 0 18px;
    opacity: 0;
    pointer-events: none;
  }
  .bubble.collapsible:not(.collapsed) .body {
    max-height: 2000px;
    padding: 4px 18px 16px;
    opacity: 1;
    transition: max-height .25s ease, padding .15s, opacity .2s;
  }

  /* Trace expander */
  .trace-toggle {
    display: inline-flex; align-items: center; gap: 8px;
    margin-top: 12px; padding: 5px 12px;
    font-size: 9.5px; letter-spacing: .25em;
    color: var(--text-2);
    border: 1px solid var(--rule);
    cursor: pointer; user-select: none;
    transition: all .12s;
  }
  .trace-toggle::before { content: "▸"; }
  .trace-toggle:hover { border-color: var(--amber); color: var(--amber); }
  .trace-toggle.open::before { content: "▾"; }
  .trace-details {
    display: none; margin-top: 10px;
    background: var(--ink);
    border: 1px solid var(--rule);
    padding: 14px;
    font-family: "JetBrains Mono", monospace;
    font-size: 11.5px; line-height: 1.6;
    color: var(--text-2);
    max-height: 300px; overflow: auto;
    white-space: pre;
  }
  .trace-details.open { display: block; }

  /* Plan card */
  .bubble.plan {
    align-self: stretch; max-width: none;
    background:
      linear-gradient(180deg, rgba(255,176,32,.08), rgba(255,176,32,.02) 70%, transparent),
      var(--paper);
    border: 1px solid var(--amber);
    padding: 22px 26px 24px;
    box-shadow:
      0 0 0 4px rgba(255,176,32,.04),
      0 16px 40px rgba(0,0,0,.5);
  }
  .bubble.plan::before {
    background: linear-gradient(135deg, transparent 50%, var(--paper) 50%);
    border-right: 1px solid var(--amber); border-bottom: 1px solid var(--amber);
  }
  .bubble.plan .who { color: var(--amber); border-bottom-color: var(--amber); }
  .bubble.plan h4 {
    margin: 4px 0 14px;
    font-family: "Fraunces", serif;
    font-weight: 700; font-size: 22px; line-height: 1.25;
    color: var(--text);
  }
  .bubble.plan .actions-list { margin: 10px 0 14px; display: grid; gap: 8px; }
  .bubble.plan .action-item {
    padding: 10px 14px;
    background: rgba(10,13,18,.5);
    border-left: 2px solid var(--amber);
    font-size: 13px;
  }
  .bubble.plan .action-item .label {
    font-size: 9.5px; letter-spacing: .3em; color: var(--amber-deep);
    font-weight: 700; margin-bottom: 3px;
  }
  .bubble.plan .action-item .verb { color: var(--text); font-weight: 600; }
  .bubble.plan .action-item .params {
    font-size: 11px; color: var(--text-3); margin: 3px 0 4px;
  }
  .bubble.plan .action-item .rat {
    font-size: 12px; color: var(--text-2);
    font-style: italic;
    font-family: "Fraunces", serif;
  }
  .bubble.plan .risks {
    margin: 14px 0 4px;
    padding: 10px 14px;
    background: rgba(255,93,93,.06);
    border-left: 2px solid var(--coral);
    font-size: 12px;
    color: var(--text-2);
  }
  .bubble.plan .risks strong {
    color: var(--coral); font-weight: 700;
    letter-spacing: .2em; font-size: 9.5px;
    display: block; margin-bottom: 4px;
  }
  .bubble.plan .approve-row {
    display: flex; gap: 12px; margin-top: 18px; flex-wrap: wrap;
    align-items: center;
  }

  /* Buttons */
  .stamp {
    background: var(--amber);
    color: var(--ink);
    border: 0;
    padding: 12px 22px;
    font-family: "JetBrains Mono", monospace;
    font-size: 11px; letter-spacing: .3em; font-weight: 700;
    cursor: pointer; position: relative;
    box-shadow:
      0 0 0 1px var(--amber-deep),
      4px 4px 0 0 var(--amber-deep);
    transition: all .12s;
  }
  .stamp:hover {
    transform: translate(-1px,-1px);
    box-shadow: 0 0 0 1px var(--amber-deep), 5px 5px 0 0 var(--amber-deep);
  }
  .stamp:active { transform: translate(2px,2px); box-shadow: 0 0 0 1px var(--amber-deep), 2px 2px 0 0 var(--amber-deep); }
  .stamp:disabled { opacity: .5; cursor: wait; transform: none; box-shadow: 0 0 0 1px var(--amber-deep), 4px 4px 0 0 var(--amber-deep); }

  .stamp.reject {
    background: transparent; color: var(--coral);
    box-shadow: 0 0 0 1px var(--coral);
  }
  .stamp.reject:hover { background: rgba(255,93,93,.08); box-shadow: 0 0 0 1px var(--coral); transform: none; }

  /* Composer */
  .composer {
    display: grid; grid-template-columns: 1fr auto; gap: 12px;
    margin-top: 22px;
    padding: 16px;
    background: rgba(232,238,244,.02);
    border: 1px solid var(--rule);
  }
  .composer textarea {
    background: var(--ink); color: var(--text);
    border: 1px solid var(--rule);
    padding: 12px 14px;
    font-family: "JetBrains Mono", "Noto Sans Arabic", monospace;
    font-size: 13px; line-height: 1.5;
    resize: vertical; min-height: 70px;
    outline: none;
  }
  .composer textarea[dir="rtl"] {
    font-family: "Noto Sans Arabic", "JetBrains Mono", monospace;
    text-align: right;
    direction: rtl;
    font-size: 14.5px;
  }
  .composer textarea:focus { border-color: var(--amber); }
  .composer textarea::placeholder { color: var(--text-3); }

  .stubs {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 8px; margin-top: 14px;
  }
  .stub {
    background: transparent;
    border: 1px dashed var(--rule);
    color: var(--text-2);
    padding: 12px 14px 12px 36px;
    font-family: "JetBrains Mono", monospace;
    font-size: 11.5px; line-height: 1.5;
    text-align: left; cursor: pointer;
    position: relative;
    transition: all .15s;
  }
  .stub::before {
    content: attr(data-num);
    position: absolute; top: 50%; left: 12px;
    transform: translateY(-50%);
    font-family: "Fraunces", serif;
    font-weight: 900; font-size: 18px;
    color: var(--amber); opacity: .7;
  }
  .stub:hover {
    border-color: var(--amber); color: var(--text);
    background: rgba(255,176,32,.04);
  }

  @keyframes fade-up {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: none; }
  }

  @media (max-width: 720px) {
    .masthead { grid-template-columns: 1fr; }
    .masthead .seal { align-items: flex-start; }
    .composer { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="terminal">

  <header class="masthead">
    <div>
      <h1>Dispatch <em>Bridge</em></h1>
      <p class="lede">A multi-agent operations co-pilot. Describe a real situation — a breakdown, a sick driver, a complaint. Specialist agents investigate against the live database, ground every proposal in real records, and present a plan you can stamp <strong>Approved</strong>.</p>
    </div>
    <div class="seal">
      <span class="pulse">AGENTS ONLINE</span>
      <span>OPS / DISPATCH</span>
      <span>v1.5</span>
      <button id="newConvoBtn" onclick="newConversation()" style="margin-top:6px; background:transparent; border:1px solid var(--rule); color:var(--text-2); padding:5px 11px; font-family:'JetBrains Mono',monospace; font-size:10px; letter-spacing:.2em; cursor:pointer;">↻ NEW SESSION</button>
    </div>
  </header>

  <div class="route-line">
    <span class="stop filled"></span>
    <span class="seg"></span><span class="label">REQUEST</span>
    <span class="seg"></span><span class="stop"></span>
    <span class="seg"></span><span class="label">INVESTIGATE</span>
    <span class="seg"></span><span class="stop"></span>
    <span class="seg"></span><span class="label">PROPOSE</span>
    <span class="seg"></span><span class="stop"></span>
    <span class="seg"></span><span class="label">APPROVE</span>
    <span class="seg"></span><span class="stop filled"></span>
  </div>

  <div class="roster">
    <span class="badge dispatcher on">DISPATCHER</span>
    <span class="badge customer_care">CUSTOMER CARE</span>
    <span class="badge maintenance">MAINTENANCE <span class="pending">PHASE 2</span></span>
    <span class="badge analyst">ANALYST <span class="pending">PHASE 2</span></span>
  </div>

  <section class="console">
    <div id="chat" class="chat-window">
      <div class="bubble orchestrator">
        <div class="who">Orchestrator</div>
        <div class="body">Describe the situation. I will consult the right specialist agents and bring you a plan grounded in your live database.</div>
      </div>
    </div>

    <div class="composer">
      <textarea id="msg" placeholder="EN: BUS-001 broke down on schedule 13…   ·   AR: تعطل الباص…   ·   FR: BUS-001 est tombé en panne…"></textarea>
      <button id="send" class="stamp" onclick="send()">DISPATCH ↗</button>
    </div>

    <div class="stubs">
      <button class="stub" data-num="01" onclick="useExample(this)">BUS-001 just broke down on schedule 13 — find a replacement and propose the swap</button>
      <button class="stub" data-num="02" onclick="useExample(this)">Check if driver Maya Younes is double-booked anywhere in the next 14 days</button>
      <button class="stub" data-num="03" onclick="useExample(this)">What would happen if I cancel schedule 13?</button>
      <button class="stub" data-num="04" onclick="useExample(this)">Find an available bus with capacity at least 40 for 2026-05-17 from 09:00 to 12:00</button>
    </div>
  </section>
</div>

<script>
const chat   = document.getElementById('chat');
const sendBtn = document.getElementById('send');
const msgEl   = document.getElementById('msg');

const conversationHistory = [];

function newConversation() {
  conversationHistory.length = 0;
  chat.innerHTML = '';
  const div = document.createElement('div');
  div.className = 'bubble orchestrator';
  div.innerHTML = '<div class="who">Orchestrator</div><div class="body">New session started. Describe the situation.</div>';
  chat.appendChild(div);
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function detectArabic(s) {
  return /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/.test(String(s || ''));
}

function addBubble(role, text, traceHtml = '') {
  const div = document.createElement('div');
  div.className = 'bubble ' + role;
  if (detectArabic(text)) div.setAttribute('dir', 'rtl');
  const label = role.replace('_', ' ');

  // Specialist bubbles (dispatcher, maintenance, analyst, customer_care)
  // render COLLAPSED by default so the chat isn't dominated by raw specialist
  // output. The orchestrator's synthesized reply is the primary message.
  const specialists = ['dispatcher', 'maintenance', 'analyst', 'customer_care'];
  if (specialists.includes(role)) {
    const firstLine = (text || '').trim().split('\n')[0] || '';
    const summary = firstLine.length > 90 ? firstLine.substring(0, 87) + '…' : firstLine;
    div.classList.add('collapsible', 'collapsed');
    div.innerHTML = `
      <div class="who">
        <span class="caret">▶</span>
        <span>${escapeHtml(label)}</span>
        <span class="bubble-summary">— ${escapeHtml(summary)}</span>
      </div>
      <div class="body">${escapeHtml(text)}${traceHtml}</div>`;
    const head = div.querySelector('.who');
    head.style.cursor = 'pointer';
    head.addEventListener('click', () => {
      div.classList.toggle('collapsed');
      const caret = div.querySelector('.caret');
      if (caret) caret.textContent = div.classList.contains('collapsed') ? '▶' : '▼';
    });
  } else {
    div.innerHTML = `<div class="who">${escapeHtml(label)}</div><div class="body">${escapeHtml(text)}${traceHtml}</div>`;
  }

  chat.appendChild(div);
  chat.scrollTop = chat.scrollHeight;
  return div;
}

function addPlan(plan) {
  const div = document.createElement('div');
  div.className = 'bubble plan';
  // Detect language from the prose fields (summary + first rationale + risks)
  const sample = (plan.summary || '') + ' '
               + ((plan.actions && plan.actions[0] && plan.actions[0].rationale) || '') + ' '
               + (plan.risks || '');
  if (detectArabic(sample)) div.setAttribute('dir', 'rtl');
  const actionsHtml = (plan.actions || []).map((a, i) =>
    `<div class="action-item">
       <div class="label">ACTION ${String(i+1).padStart(2,'0')}</div>
       <div class="verb">${escapeHtml(a.action || 'action')}</div>
       ${a.parameters ? `<div class="params">${escapeHtml(JSON.stringify(a.parameters))}</div>` : ''}
       <div class="rat">${escapeHtml(a.rationale || '')}</div>
     </div>`
  ).join('');
  div.innerHTML = `
    <div class="who">Proposed Plan</div>
    <h4>${escapeHtml(plan.summary || 'Proposed plan')}</h4>
    <div class="actions-list">${actionsHtml}</div>
    ${plan.risks ? `<div class="risks"><strong>RISKS</strong>${escapeHtml(plan.risks)}</div>` : ''}
    <div class="approve-row">
      <button class="stamp">STAMP APPROVED</button>
      <button class="stamp reject">REJECT</button>
    </div>`;
  const approveBtn = div.querySelector('.stamp:not(.reject)');
  const rejectBtn  = div.querySelector('.stamp.reject');
  approveBtn.addEventListener('click', () => approvePlan(div, plan));
  rejectBtn.addEventListener('click',  () => rejectPlan(div));
  chat.appendChild(div);
  chat.scrollTop = chat.scrollHeight;
}

function renderTrace(trace) {
  if (!trace || !trace.length) return '';
  const id = 't' + Math.random().toString(36).slice(2);
  const json = escapeHtml(JSON.stringify(trace, null, 2));
  return `
    <span class="trace-toggle" onclick="this.classList.toggle('open'); document.getElementById('${id}').classList.toggle('open')">
      ${trace.length} TOOL CALL${trace.length>1?'S':''}
    </span>
    <div id="${id}" class="trace-details">${json}</div>`;
}

function useExample(btn) {
  msgEl.value = btn.textContent.trim();
  msgEl.dir = detectArabic(msgEl.value) ? 'rtl' : 'ltr';
  msgEl.focus();
}

// Flip textarea direction as user types Arabic
msgEl.addEventListener('input', () => {
  msgEl.dir = detectArabic(msgEl.value) ? 'rtl' : 'ltr';
});

async function send() {
  const text = msgEl.value.trim();
  if (!text) return;
  addBubble('user', text);
  msgEl.value = '';
  sendBtn.disabled = true; sendBtn.textContent = 'PROCESSING…';

  conversationHistory.push({ role: 'user', content: text });

  try {
    const res = await fetch('agents/orchestrator.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ messages: conversationHistory })
    });
    const data = await res.json();

    if (data.error) {
      addBubble('error', 'Error: ' + data.error);
      conversationHistory.pop();
    } else {
      (data.trace || []).forEach(step => {
        if (step.tool && step.tool.startsWith('consult_') && step.result) {
          const agent = step.result.agent || step.tool.replace('consult_', '');
          const ans   = step.result.answer || '';
          addBubble(agent, ans, renderTrace(step.result.trace || []));
        }
        if (step.tool === 'execute_plan' && step.result && step.result.plan) {
          addPlan(step.result.plan);
        }
      });
      if (data.reply) {
        addBubble('orchestrator', data.reply);
        conversationHistory.push({ role: 'assistant', content: data.reply });
      }
    }
  } catch (e) {
    addBubble('error', 'Network error: ' + e.message);
    conversationHistory.pop();
  } finally {
    sendBtn.disabled = false; sendBtn.textContent = 'DISPATCH ↗';
  }
}

async function approvePlan(bubble, plan) {
  const approveBtn = bubble.querySelector('.stamp:not(.reject)');
  const rejectBtn  = bubble.querySelector('.stamp.reject');
  approveBtn.disabled = true; rejectBtn.disabled = true;
  approveBtn.textContent = 'EXECUTING…';

  try {
    const res = await fetch('agents/commit.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ plan })
    });
    const data = await res.json();
    const approveRow = bubble.querySelector('.approve-row');

    if (data.status === 'success') {
      approveRow.innerHTML = `<span style="color:var(--mint);font-size:11px;letter-spacing:.3em;font-weight:700;">✓ STAMPED · EXECUTED</span>`;
      const lines = (data.results || []).map(r =>
        '• ' + r.action + ' — rows affected: ' + (r.rows_affected ?? 0)
      ).join('\n');
      addBubble('success', `PLAN COMMITTED\n\n${lines}\n\nVerify on admin/schedules.php and admin/activity_log.php.`);
    } else {
      approveRow.innerHTML = `<span style="color:var(--coral);font-size:11px;letter-spacing:.3em;font-weight:700;">✗ EXECUTION FAILED</span>`;
      addBubble('error', 'Commit failed: ' + (data.error || 'unknown error'));
    }
  } catch (e) {
    addBubble('error', 'Network error during commit: ' + e.message);
  }
}

function rejectPlan(bubble) {
  const approveRow = bubble.querySelector('.approve-row');
  approveRow.innerHTML = '<span style="color:var(--text-3);font-size:11px;letter-spacing:.3em;">PLAN REJECTED · NO CHANGES</span>';
}

msgEl.addEventListener('keydown', e => {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send();
});
</script>
</body>
</html>

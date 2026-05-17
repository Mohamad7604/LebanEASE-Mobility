<?php
require "db/config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Beirut');

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION["passenger_id"])) {
    header("Location: login.php");
    exit;
}

$passengerName = $_SESSION["passenger_name"] ?? "Passenger";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Passenger AI Assistant | LebanEASE</title>
<link rel="stylesheet" href="css/style.css">
<style>
.assistant-layout {
    display: grid;
    grid-template-columns: 1.1fr .8fr;
    gap: 18px;
    align-items: start;
}

.chat-card {
    min-height: 520px;
}

.chat-window {
    min-height: 330px;
    max-height: 440px;
    overflow-y: auto;
    padding: 14px;
    border-radius: 18px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.1);
}

.msg {
    margin-bottom: 12px;
    padding: 12px 14px;
    border-radius: 16px;
    line-height: 1.6;
    white-space: pre-wrap;
}

.msg-user {
    background: rgba(59,130,246,.18);
    border: 1px solid rgba(59,130,246,.25);
    margin-left: 55px;
}

.msg-ai {
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    margin-right: 55px;
}

.chat-form {
    display: flex;
    gap: 10px;
    margin-top: 12px;
}

.chat-form input {
    flex: 1;
}

.quick-grid {
    display: grid;
    gap: 10px;
}

.quick-btn {
    width: 100%;
    text-align: left;
}

.demo-note {
    line-height: 1.8;
}

@media(max-width:900px) {
    .assistant-layout {
        grid-template-columns: 1fr;
    }

    .msg-user,
    .msg-ai {
        margin-left: 0;
        margin-right: 0;
    }

    .chat-form {
        flex-direction: column;
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('agent'); ?>

<main class="section">
<div class="container">

<h2 class="section-title">Passenger AI Assistant</h2>
<p class="muted mb-14">
    Ask questions about your reservations, tickets, available trips, cancellations, and payment status.
</p>

<div class="assistant-layout">

    <div class="card glass chat-card">
        <h3>Ask LebanEASE Assistant</h3>
        <p class="muted tiny">
            The assistant reads your reservation and trip data, then gives a short helpful answer.
        </p>

        <div class="h-12"></div>

        <div id="chatWindow" class="chat-window">
            <div class="msg msg-ai">
Hello <?= h($passengerName) ?>. I can help you with reservations, tickets, available trips, payment status, and cancellations.
            </div>
        </div>

        <form id="chatForm" class="chat-form">
            <input
                id="questionInput"
                class="admin-search"
                type="text"
                placeholder="Ask: Do I have active reservations?"
                maxlength="500"
                autocomplete="off"
            >
            <button class="btn btn-primary" type="submit">Ask</button>
        </form>
    </div>

    <div class="card glass">
        <h3>Quick Questions</h3>
        <p class="muted tiny">Click one to test the assistant quickly.</p>

        <div class="h-12"></div>

        <div class="quick-grid">
            <button class="btn btn-ghost quick-btn" data-q="Do I have any active reservations?">
                Do I have any active reservations?
            </button>

            <button class="btn btn-ghost quick-btn" data-q="How can I print my ticket as PDF?">
                How can I print my ticket as PDF?
            </button>

            <button class="btn btn-ghost quick-btn" data-q="What upcoming trips are available?">
                What upcoming trips are available?
            </button>

            <button class="btn btn-ghost quick-btn" data-q="Can I cancel my reservation?">
                Can I cancel my reservation?
            </button>

            <button class="btn btn-ghost quick-btn" data-q="What does pending payment mean?">
                What does pending payment mean?
            </button>
        </div>

        <div class="h-12"></div>

        <h3>Demo Talking Point</h3>
        <p class="muted demo-note">
            This feature improves usability by allowing passengers to ask natural-language questions instead of searching through many pages manually.
            If the AI service is unavailable, the system still provides a fallback answer.
        </p>
    </div>

</div>

</div>
</main>

<script>
const chatWindow = document.getElementById("chatWindow");
const chatForm = document.getElementById("chatForm");
const questionInput = document.getElementById("questionInput");
const quickButtons = document.querySelectorAll(".quick-btn");

function addMessage(text, type) {
    const div = document.createElement("div");
    div.className = "msg " + (type === "user" ? "msg-user" : "msg-ai");
    div.textContent = text;
    chatWindow.appendChild(div);
    chatWindow.scrollTop = chatWindow.scrollHeight;
    return div;
}

async function askAssistant(question) {
    const cleanQuestion = question.trim();

    if (!cleanQuestion) {
        return;
    }

    addMessage(cleanQuestion, "user");
    const thinkingNode = addMessage("Thinking...", "ai");

    try {
        const response = await fetch("user_ai.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                question: cleanQuestion
            })
        });

        const data = await response.json();

        thinkingNode.textContent = data.answer || "No answer was returned.";
    } catch (error) {
        thinkingNode.textContent = "Could not connect to the assistant right now.";
    }
}

chatForm.addEventListener("submit", function(e) {
    e.preventDefault();

    const question = questionInput.value;
    questionInput.value = "";

    askAssistant(question);
});

quickButtons.forEach(button => {
    button.addEventListener("click", function() {
        askAssistant(this.dataset.q);
    });
});
</script>

</body>
</html>
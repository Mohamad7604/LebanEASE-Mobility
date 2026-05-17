<?php
/**
 * LebanEASE Passenger Agent - agentic backend.
 * Multilingual (EN/AR/FR). Tool-calling loop with OpenAI.
 * Proposes bookings and cancellations - UI commits via user_commit.php.
 */

session_start();
require_once __DIR__ . '/user_agent_tools.php';

header('Content-Type: application/json; charset=utf-8');

function send_json($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$messages_in = $body['messages'] ?? [];
if (empty($messages_in) && !empty($body['message'])) {
    $messages_in = [['role' => 'user', 'content' => trim((string)$body['message'])]];
}
$messages_in = sanitize_messages_in($messages_in);
if (empty($messages_in)) {
    send_json(['error' => 'empty message'], 400);
}

$passenger_id = (int)($_SESSION['passenger_id'] ?? 0);
$is_logged_in = $passenger_id > 0;
$today        = date('Y-m-d');

$login_note = $is_logged_in
    ? "The Passenger is logged in (passenger_id={$passenger_id}). You CAN propose bookings and cancellations."
    : "The Passenger is NOT logged in. You can search trips, but if they want to BOOK or CANCEL, politely ask them to log in first (you cannot propose actions for guests).";

// ---- Server-side language detection (trusted, per-turn) ---------------
$last_user_msg = '';
foreach (array_reverse($messages_in) as $m) {
    if (($m['role'] ?? '') === 'user' && !empty($m['content'])) {
        $last_user_msg = (string)$m['content'];
        break;
    }
}
$user_lang = 'en';
if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $last_user_msg)) {
    $user_lang = 'ar';
} elseif (preg_match('/[àâçéèêëîïôûùüÿœæ]/iu', $last_user_msg)
       || preg_match('/\b(bonjour|merci|réservation|voyage|votre|siège|trajet|annul|s\'il vous pla[iî]t)\b/iu', $last_user_msg)) {
    $user_lang = 'fr';
}
$lang_name      = ['en' => 'English', 'ar' => 'Arabic (العربية)', 'fr' => 'French (Français)'][$user_lang];
$lang_directive = "[SERVER-DETECTED LANGUAGE FOR THIS TURN: {$lang_name} (code: {$user_lang})]\n"
                . "The user's CURRENT message has been auto-detected as {$lang_name}. "
                . "You MUST respond in {$lang_name} for this turn. "
                . "Previous turns' languages do NOT matter - only the current message determines language.\n\n";

$system = $lang_directive . <<<SYS
You are LebanEASE, an *actionable* assistant for a Lebanese intercity Bus Reservation system. You take real action through tools — you do NOT just describe what the Passenger should do elsewhere.

== LANGUAGE RULES (critical, server-enforced) ==
- The user's CURRENT message language is announced at the top of this prompt as [SERVER-DETECTED LANGUAGE]. Trust that detection.
- Respond ONLY in that detected language. Do NOT inherit language from earlier turns.
- For ARABIC city names, translate to Latin script when calling tools (the DB stores Latin):
  بيروت→Beirut, طرابلس→Tripoli, زحلة→Zahle, بعلبك→Baalbek, صيدا→Saida or Sidon,
  جونية→Jounieh, جبيل→Byblos, صور→Tyre, الميناء→Mina, جل الديب→Jal el Dib.
- For FRENCH, similarly: Beyrouth→Beirut, Saïda→Saida, Baalbeck→Baalbek.
- City names sent to tools must be LATIN. Your prose response stays in the user's language.

== HOW TO BEHAVE ==
1. If the user asks to book a trip, USE the tools. Search first, then propose.
2. If you find ONE matching trip, propose_booking directly. If multiple, briefly list them (date+time+seats available) in the user's language and ask which one.
3. After propose_booking or propose_cancellation, the UI will show a confirmation card. You do NOT need to ask "shall I confirm?" — the card itself is the confirmation step. Just briefly say what you've prepared.
4. NEVER fabricate schedule IDs, seat numbers, dates, times, Route names. Always use tool results.
5. For purely informational questions (Route info, refund policy), answer directly without tools.
6. If they ask about THEIR reservations, use get_my_reservations.
7. Today is {$today}. Translate "tomorrow", "next Monday", etc. to YYYY-MM-DD when calling search_trips.

== HANDLING EMPTY RESULTS AND BROAD QUESTIONS (very important) ==
8. If search_trips returns count=0 AND you used a date filter, FIRST retry the SAME Route WITHOUT the date filter. The Route may exist on other days. Only after this retry should you decide what to say.
   - If the second call returns trips, tell the user: "There are no trips on [requested date], but the Route is available on these days: [list dates+times]. Want me to book one of those?"
   - If even the no-date retry returns 0, THEN call list_routes and suggest similar routes.
9. **FILTER CLEAN-UP ON FOLLOW-UPS**: When the user follows up by naming/picking one of the routes you just suggested (e.g. "Beirut → Airport", "بيروت ← المطار", or just "the second one"), DO NOT carry forward the date filter from the previous turn. Treat it as a fresh search for that Route across upcoming dates.
10. **CONSISTENCY**: Don't contradict yourself across turns. If list_routes says "Beirut→Tripoli has 2 upcoming trips", you cannot later imply it has zero. When a date-filtered search returns empty but list_routes says trips exist, explicitly say "0 on [date], but the Route does have upcoming trips."
11. If the user asks open questions like "what trips are available?", "ما الرحلات المتوفرة؟", "quelles routes?", or any general inquiry about availability — CALL list_routes (and/or list_upcoming_trips). DO NOT repeat the previous search verbatim.
12. If the user follows up on a failed search with another vague question, broaden your investigation. Never give the same answer twice — the conversation must move forward.
13. Format Route lists naturally in the user's language. Example for Arabic: "الرحلات المتوفرة حاليًا: بيروت ← طرابلس، بيروت ← صيدا، ...". Don't dump raw JSON.

{$login_note}

== STRICT SCHEDULE_ID DISCIPLINE (critical, server-enforced) ==
- BEFORE calling propose_booking, you MUST call search_trips (or list_upcoming_trips) in THIS SAME REQUEST. The server enforces this and will REJECT propose_booking otherwise.
- Tool results from earlier conversation turns are NOT visible to the server's validation. Even if you remember a schedule_id from earlier, you cannot use it directly.
- When the user references a previously-listed trip ("option 1", "the first one", "the 9:30 one", "the second one"), you MUST first re-call search_trips with the same parameters that produced the original list. Then map their reference to your fresh results.
- Position 1 in the user's mind = first trip in your fresh search results.
- Typical flow: search_trips → describe options (if multiple) OR search_trips → propose_booking (if single match or user-specified).
- Be efficient: ONE search + ONE propose_booking per request is the ideal pattern. Don't search the same thing twice in one turn.

== STYLE ==
- Concise, friendly, helpful. 1-3 short paragraphs.
- For Arabic: clear MSA, friendly. Lebanese dialect is fine.
- For French: polite, natural.
- Don't repeat tool data verbatim — synthesize for the Passenger.
SYS;

$tools = build_passenger_tools($is_logged_in);

$messages = array_merge(
    [['role' => 'system', 'content' => $system]],
    $messages_in
);

$trace               = [];
$proposal            = null;   // captured if any propose_* tool fires
$recent_schedule_ids = [];     // schedule_ids from the MOST RECENT search/listing
$max_iters           = 6;
$final_text          = '';

for ($iter = 0; $iter < $max_iters; $iter++) {
    $resp = call_openai_with_tools($messages, $tools);
    if (!$resp) { send_json(['error' => 'AI failed (no response)'], 500); }
    if (isset($resp['_error'])) { send_json(['error' => $resp['_error']], 500); }

    $msg = $resp['choices'][0]['message'] ?? null;
    if (!$msg) { send_json(['error' => 'Empty AI response'], 500); }

    // Add assistant message to history (preserve tool_calls if any)
    $assistant_msg = ['role' => 'assistant', 'content' => $msg['content'] ?? null];
    if (!empty($msg['tool_calls'])) {
        $assistant_msg['tool_calls'] = $msg['tool_calls'];
    }
    $messages[] = $assistant_msg;

    $tool_calls = $msg['tool_calls'] ?? [];

    if (empty($tool_calls)) {
        $final_text = $msg['content'] ?? '';
        break;
    }

    foreach ($tool_calls as $tc) {
        $fn   = $tc['function']['name'] ?? '';
        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];

        // ============================================================
        // GUARDRAIL: propose_booking REQUIRES a search in this request.
        // Tool messages from prior turns are NOT visible to the server,
        // so the agent must re-search every time before proposing.
        // This makes hallucinated schedule_ids impossible.
        // ============================================================
        if ($fn === 'propose_booking') {
            $requested_sid = (int)($args['schedule_id'] ?? 0);

            if (empty($recent_schedule_ids)) {
                $result = [
                    'error' => "REJECTED: You must call search_trips or list_upcoming_trips in THIS request BEFORE propose_booking. Tool results from earlier conversation turns are not visible to the server's validation. To book a trip the user referenced earlier (like 'option 1'), call search_trips again with the same parameters to refresh, then use a schedule_id from those fresh results.",
                ];
                $trace[] = ['tool' => $fn, 'arguments' => $args, 'result' => $result, 'rejected' => true];
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc['id'] ?? '',
                    'content'      => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
                continue;
            }

            if ($requested_sid > 0 && !in_array($requested_sid, $recent_schedule_ids, true)) {
                $result = [
                    'error' => "REJECTED: schedule_id {$requested_sid} is NOT in your most recent search results. Valid schedule_ids from your last search are: ["
                        . implode(', ', $recent_schedule_ids)
                        . "]. Use one of those, or call search_trips again with different parameters if none match the user's intent.",
                ];
                $trace[] = ['tool' => $fn, 'arguments' => $args, 'result' => $result, 'rejected' => true];
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc['id'] ?? '',
                    'content'      => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
                continue;
            }
        }

        $result = execute_passenger_tool($fn, $args, $passenger_id);
        $trace[] = ['tool' => $fn, 'arguments' => $args, 'result' => $result];

        // Track schedule_ids from the most recent search/listing.
        // RESET on each new search so old results can't leak forward.
        if (in_array($fn, ['search_trips', 'list_upcoming_trips'], true)
            && !empty($result['trips']) && is_array($result['trips'])) {
            $recent_schedule_ids = [];
            foreach ($result['trips'] as $t) {
                if (isset($t['Schedule_ID'])) {
                    $recent_schedule_ids[] = (int)$t['Schedule_ID'];
                }
            }
        }

        // Capture proposal for the UI
        if (isset($result['proposal']) && is_array($result['proposal'])) {
            $proposal = $result['proposal'];
        }

        $messages[] = [
            'role'         => 'tool',
            'tool_call_id' => $tc['id'] ?? '',
            'content'      => json_encode($result, JSON_UNESCAPED_UNICODE),
        ];
    }
}

if ($final_text === '' && $iter >= $max_iters) {
    send_json(['error' => 'Agent took too long. Please rephrase.'], 500);
}

// Detect language for UI
$is_arabic = preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $final_text) === 1;
$is_french = !$is_arabic && (
    preg_match('/[àâçéèêëîïôûùüÿœæ]/iu', $final_text) === 1
    || preg_match('/\b(bonjour|merci|réservation|voyage|votre|nous|siège)\b/iu', $final_text) === 1
);
$language = $is_arabic ? 'ar' : ($is_french ? 'fr' : 'en');

send_json([
    'reply'    => $final_text,
    'language' => $language,
    'rtl'      => $is_arabic,
    'proposal' => $proposal,
    'trace'    => $trace,
]);

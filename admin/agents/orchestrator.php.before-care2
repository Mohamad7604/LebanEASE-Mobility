<?php
/**
 * LebanEASE Orchestrator Agent
 * The brain. Receives the admin's natural-language request, decides which
 * specialist agents to consult, sequences their work, and synthesizes a plan.
 *
 * Drop in:  C:\xampp\htdocs\LebanEase\LebanEase\admin\agents\orchestrator.php
 */

session_start();
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/dispatcher_agent.php';
require_once __DIR__ . '/customer_care_agent.php';
// Future: customer_care_agent.php, maintenance_agent.php, analyst_agent.php

// -------------------------------------------------------------------------
// Auth gate - only logged-in admins
// -------------------------------------------------------------------------
if (empty($_SESSION['admin_id'])) {
    // In dev you may want to temporarily comment this out for testing
    send_json(['error' => 'admin login required'], 403);
}

// -------------------------------------------------------------------------
// Tool runner for the Orchestrator. Each "tool" is a call to a specialist
// agent (or, for execute_plan, a write-action proposal that surfaces to UI).
// -------------------------------------------------------------------------
function orchestrator_tool(string $name, array $args) {
    switch ($name) {

        case 'consult_dispatcher':
            $q = $args['question'] ?? '';
            return run_dispatcher_agent($q);

        case 'consult_customer_care':
            $q = $args['question'] ?? '';
            return run_customer_care_agent($q);

        case 'consult_maintenance':
            return [
                'agent'  => 'maintenance',
                'answer' => '[not yet implemented - Phase 2.]',
                'trace'  => [],
            ];

        case 'consult_analyst':
            return [
                'agent'  => 'analyst',
                'answer' => '[not yet implemented - Phase 2.]',
                'trace'  => [],
            ];

        case 'execute_plan':
            // Surface a proposed plan to the UI for admin approval.
            // We do NOT write to the DB here - the UI shows an Approve button
            // that POSTs to a separate commit endpoint (Phase 1.5).
            log_agent_action('orchestrator', 'plan_proposed', $args);
            return [
                'status'         => 'pending_admin_approval',
                'plan'           => $args,
                'approval_token' => bin2hex(random_bytes(8)),
            ];
    }
    return ['error' => "unknown orchestrator tool: {$name}"];
}

// -------------------------------------------------------------------------
// Orchestrator's tool schema (delegating to specialists)
// -------------------------------------------------------------------------
function orchestrator_tools(): array {
    return [
        ['type' => 'function', 'function' => [
            'name' => 'consult_dispatcher',
            'description' => 'Ask the Dispatcher Agent about schedules, buses, drivers, conflicts, or replacements. Pass a clear natural-language question.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['question' => ['type' => 'string']],
                'required' => ['question'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'consult_customer_care',
            'description' => 'Ask the Customer Care Agent about reservations, refunds, passenger notifications, or rebookings.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['question' => ['type' => 'string']],
                'required' => ['question'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'consult_maintenance',
            'description' => 'Ask the Maintenance Agent about bus health, service history, or service tickets.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['question' => ['type' => 'string']],
                'required' => ['question'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'consult_analyst',
            'description' => 'Ask the Analyst Agent for data exploration, metrics, comparisons, or what-if reasoning.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['question' => ['type' => 'string']],
                'required' => ['question'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'execute_plan',
            'description' => 'Surface a final action plan to the admin for approval. Use this only after you have all the information needed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'one-line summary of what will happen'],
                    'actions' => [
                        'type' => 'array',
                        'description' => 'ordered list of concrete actions',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'agent'       => ['type' => 'string'],
                                'action'      => ['type' => 'string'],
                                'parameters'  => ['type' => 'object'],
                                'rationale'   => ['type' => 'string'],
                            ],
                            'required' => ['agent', 'action', 'rationale'],
                        ],
                    ],
                    'risks' => ['type' => 'string'],
                ],
                'required' => ['summary', 'actions'],
            ],
        ]],
    ];
}

// -------------------------------------------------------------------------
// Server-side language detection. Trusted source of truth - we do NOT rely
// on the LLM to figure out language from history because it drifts.
// -------------------------------------------------------------------------
function detect_user_language(string $text): string {
    if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text)) {
        return 'ar';
    }
    // French detection: any accented char, OR any distinctive French word.
    // Expanded so that even unaccented French ("je veux reserver") triggers.
    if (preg_match('/[àâçéèêëîïôûùüÿœæÀÂÇÉÈÊËÎÏÔÛÙÜŸŒÆ]/u', $text)
        || preg_match(
            '/\b(je|tu|vous|nous|votre|notre|mes|mon|ma|ses|est|sont|etes|êtes|veux|voudrais|voudriez|avec|dans|pour|sans|aussi|alors|donc|mais|tres|très|toujours|jamais|bonjour|salut|bonsoir|merci|svp|aujourd\'hui|demain|hier|maintenant|prochain|prochaine|chercher|trouver|montrer|afficher|aller|venir|partir|reserver|réserver|annuler|annulation|beyrouth|saida|saïda|trajet|trajets|voyage|voyages|reservation|réservation|siege|siège|panne|billet|billets|combien|quel|quelle|quels|quelles|comment|pourquoi|quand|ou|où|s\'il|plait|plaît|d\'accord|c\'est|j\'ai|j\'aimerais)\b/iu',
            $text
        )) {
        return 'fr';
    }
    return 'en';
}

// -------------------------------------------------------------------------
// Run the orchestrator on a full conversation history
// -------------------------------------------------------------------------
function run_orchestrator(array $history): array {
    $today = date('Y-m-d');

    // Find the user's MOST RECENT message and detect its language.
    $last_user_msg = '';
    foreach (array_reverse($history) as $m) {
        if (($m['role'] ?? '') === 'user' && !empty($m['content'])) {
            $last_user_msg = (string)$m['content'];
            break;
        }
    }
    $user_lang   = detect_user_language($last_user_msg);
    $lang_name   = ['en' => 'English', 'ar' => 'Arabic (العربية)', 'fr' => 'French (Français)'][$user_lang];
    $directive   = "[SERVER-DETECTED LANGUAGE FOR THIS TURN: {$lang_name} (code: {$user_lang})]\n"
                 . "The user's CURRENT message has been auto-detected as {$lang_name}. "
                 . "You MUST respond in {$lang_name} for this turn. "
                 . "Previous turns' languages are IRRELEVANT for this response - only the current message determines the language. "
                 . "When you call consult_dispatcher (or any consult_*), the question parameter MUST begin with [respond_in: {$user_lang}] so the specialist mirrors the language.\n\n";

    $system = $directive . <<<SYS
You are the LebanEASE Orchestrator. You coordinate a team of specialist agents to handle a Lebanese bus operator's operational requests.

== LANGUAGE RULES (critical, server-enforced) ==
- The user's CURRENT message language is announced at the top of this prompt as [SERVER-DETECTED LANGUAGE FOR THIS TURN]. Trust that detection.
- Respond ONLY in that detected language. Do NOT inherit language from earlier turns.
- When you consult specialists, prefix your question with the exact [respond_in: en|ar|fr] tag matching the detection.
- Code identifiers inside execute_plan (swap_bus, schedule_id, new_bus_id, etc.) stay English regardless - they are code, not natural language. Only prose fields (summary, rationale, risks) follow the user's language.
- For Arabic: use clear Modern Standard Arabic, friendly professional tone. Lebanese dialect acceptable if the user uses it.
- For French: polite, natural French.

Available specialists (call them via tools):
- Dispatcher: schedules, buses, drivers, conflicts, replacements
- Customer Care: identify passengers affected by changes, draft multilingual notifications, propose refunds
- Maintenance: bus health, service (Phase 2 - stub only)
- Analyst: data exploration, what-if (Phase 2 - stub only)

When to consult Customer Care:
- Any time a schedule is CANCELLED, a bus is SWAPPED on an active trip, or a trip is RETIMED. The dispatcher handles the operational change; Customer Care handles the human side.
- For breakdowns specifically, consult BOTH in the same turn: dispatcher to swap+pull the bus, customer_care to notify the passengers on the affected schedules and propose refunds for cancelled ones.
- Pass the relevant schedule_id(s), bus_id (if applicable), and a brief description of what changed in your consult_customer_care question.

How to work:
1. Read the WHOLE conversation history before responding. The admin's latest message may be a short follow-up that only makes sense in context (e.g. "bus 6", "the second one", "yes do it"). Resolve references using prior turns.
2. If the user's intent is still ambiguous after considering history, ask ONE focused clarifying question and stop.
3. Decompose the request into specialist consultations. Consult multiple agents when needed.
4. NEVER invent IDs, names, or facts - everything must come from a specialist's tool results.
5. When proposing an executable plan, call execute_plan with actions in EXACTLY this shape so commit.php can run them:
   {
     "agent": "dispatcher" | "customer_care",
     "action": "swap_bus" | "reassign_driver" | "retime" | "cancel" | "mark_bus_status" | "notify_passenger" | "refund_reservation",
     "parameters": {
       // dispatcher actions:
       "schedule_id": <int>,
       "new_bus_id": <int>,             // for swap_bus
       "new_driver_id": <int>,          // for reassign_driver
       "new_date": "YYYY-MM-DD",        // for retime
       "new_departure_time": "HH:MM:SS",
       "new_arrival_time": "HH:MM:SS",
       "bus_id": <int>,                 // for mark_bus_status
       "new_status": "Maintenance"|"Offline"|"Active",
       // customer_care actions:
       "reservation_id": <int>,         // for refund_reservation
       "reason": "...",                 // for refund_reservation
       "passenger_id": <int>,           // for notify_passenger
       "channel": "email"|"sms"|"both",
       "language": "en"|"ar"|"fr",
       "message": "<the rendered notification text>"
     },
     "rationale": "..."   // in the USER'S language
   }

5a. **mark_bus_status** is for changing a bus's operational state itself (NOT just a single schedule). Use it when the admin says a bus broke down, needs maintenance, is back from service, etc. CRITICAL ORDERING: if the bus has future schedules, you must FIRST chain swap_bus actions for those schedules in the SAME plan, THEN mark_bus_status at the end. The commit endpoint will reject mark_bus_status to Maintenance/Offline if any future Scheduled trips still reference that bus. Example plan for "BUS-001 broke down, it had schedules 13 and 14 today":
   [
     { action: "swap_bus",        parameters: { schedule_id: 13, new_bus_id: 5 }, rationale: "..." },
     { action: "swap_bus",        parameters: { schedule_id: 14, new_bus_id: 6 }, rationale: "..." },
     { action: "mark_bus_status", parameters: { bus_id: 1, new_status: "Maintenance" }, rationale: "BUS-001 pulled from service pending inspection." }
   ]
6. If a request is purely informational (no DB writes needed), DO NOT call execute_plan.
7. **DO NOT RELAY OR REPEAT specialist data.** The UI shows each specialist's response as a collapsible bubble above your message. The user can expand it to see full lists, IDs, counts, etc. Your job is to SYNTHESIZE — give the high-level takeaway, the cross-domain insight, or the next step. Examples:
   - WRONG: "Here is the information you requested: ### All Drivers 1. Ali Khalil... 2. Omar Haddad..." [repeats the entire list]
   - RIGHT: "13 drivers and 27 routes available. No scheduled trips between May 16–31 — you may want to extend the planning window. Expand specialist results above for full details."
   - WRONG (after one consult): copying the specialist's full answer.
   - RIGHT: "Done — replacement found. See dispatcher above for the bus details, plan card below to approve."
8. Keep your reply to 2–4 short sentences for read-only/informational queries. Longer is acceptable only when the user needs cross-domain reasoning or a plan summary.
9. If only one specialist was consulted and you have nothing to add, your reply should be ONE sentence acknowledging the result and pointing to the expanded view — not a recap.
10. Today's date is {$today}. Use it for any "tomorrow", "this Friday", etc.

Be decisive. Specialists are cheap to consult. Your message is a TAKEAWAY, not a TRANSCRIPT.
SYS;

    // Start the agent loop with system + the full prior conversation
    $messages = array_merge(
        [['role' => 'system', 'content' => $system]],
        $history
    );
    $trace = [];

    for ($step = 0; $step < 8; $step++) {
        $msg = call_openai($messages, orchestrator_tools());
        $messages[] = $msg;

        // Plain text -> done
        if (empty($msg['tool_calls'])) {
            log_agent_action('orchestrator', 'request_handled', [
                'turn_user_message' => end($history)['content'] ?? '',
                'steps'             => count($trace),
            ]);
            return ['reply' => $msg['content'] ?? '', 'trace' => $trace];
        }

        // Execute every tool call
        foreach ($msg['tool_calls'] as $call) {
            $name   = $call['function']['name'];
            $args   = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];
            $result = orchestrator_tool($name, $args);
            $trace[] = ['tool' => $name, 'args' => $args, 'result' => $result];

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $call['id'],
                'content'      => is_string($result) ? $result : json_encode($result),
            ];
        }
    }

    return ['reply' => '[agent stopped: max steps reached]', 'trace' => $trace];
}

// -------------------------------------------------------------------------
// HTTP entrypoint
// -------------------------------------------------------------------------
$body = json_decode(file_get_contents('php://input'), true) ?: [];

// New format: { messages: [{role,content},...] }
$history = $body['messages'] ?? [];

// Backwards-compat: { message: "..." } -> wrap as a single user turn
if (empty($history) && !empty($body['message'])) {
    $history = [['role' => 'user', 'content' => trim($body['message'])]];
}

// Sanitize: keep only role+content, drop empties, only allow user/assistant
$history = array_values(array_filter(array_map(function ($m) {
    if (!is_array($m)) return null;
    $role = $m['role'] ?? '';
    $content = trim((string)($m['content'] ?? ''));
    if (!in_array($role, ['user', 'assistant'], true)) return null;
    if ($content === '') return null;
    return ['role' => $role, 'content' => $content];
}, $history)));

if (empty($history)) {
    send_json(['error' => 'empty conversation history'], 400);
}

try {
    $response = run_orchestrator($history);
    send_json($response);
} catch (Throwable $e) {
    error_log('orchestrator error: ' . $e->getMessage());
    send_json(['error' => $e->getMessage()], 500);
}

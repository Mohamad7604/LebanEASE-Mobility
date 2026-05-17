<?php
/**
 * LebanEASE Multi-Agent Toolbox
 * Shared helpers for all agents: DB access, OpenAI calls, activity logging.
 *
 * Drop this in:  C:\xampp\htdocs\LebanEase\LebanEase\admin\agents\tools.php
 */

// -------------------------------------------------------------------------
// Config bootstrap
// -------------------------------------------------------------------------
require_once __DIR__ . '/../../db/config.php';   // exposes $host, $dbname, $user, $pass
require_once __DIR__ . '/../ai_config.php';      // exposes LEBANEASE_AI_API_KEY, LEBANEASE_AI_MODEL

// -------------------------------------------------------------------------
// Database
// -------------------------------------------------------------------------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        global $host, $dbname, $user, $pass;
        $pdo = new PDO(
            "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

// -------------------------------------------------------------------------
// OpenAI HTTP wrapper (Chat Completions + function calling)
// -------------------------------------------------------------------------
/**
 * Call OpenAI chat completions with optional tool definitions.
 *
 * @param array  $messages  full conversation history (role/content/tool_calls/tool_call_id)
 * @param array  $tools     OpenAI-style tool definitions, or [] to disable
 * @param string $model     model override
 * @return array            decoded response (the "message" object)
 */
function call_openai(array $messages, array $tools = [], string $model = ''): array {
    $model = $model ?: LEBANEASE_AI_MODEL;

    $payload = [
        'model'    => $model,
        'messages' => $messages,
        'temperature' => 0.3,
    ];
    if (!empty($tools)) {
        $payload['tools']       = $tools;
        $payload['tool_choice'] = 'auto';
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LEBANEASE_AI_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("OpenAI cURL error: {$err}");
    }
    $decoded = json_decode($raw, true);
    if ($code !== 200 || !isset($decoded['choices'][0]['message'])) {
        $msg = $decoded['error']['message'] ?? $raw;
        throw new RuntimeException("OpenAI API error ({$code}): {$msg}");
    }

    return $decoded['choices'][0]['message'];
}

// -------------------------------------------------------------------------
// Activity logging - every agent decision is auditable
// -------------------------------------------------------------------------
function log_agent_action(string $agent, string $action, $details = null): void {
    try {
        $admin_id = $_SESSION['admin_id'] ?? null;
        $stmt = db()->prepare(
            "INSERT INTO Admin_Activity_Log
               (Admin_ID, Event_Type, Entity_Name, Description, Created_At)
             VALUES
               (:admin_id, :event_type, :entity_name, :description, NOW())"
        );
        $stmt->execute([
            ':admin_id'    => $admin_id,
            ':event_type'  => $action,
            ':entity_name' => $agent,
            ':description' => is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // logging must never break the agent loop
        error_log("log_agent_action failed: " . $e->getMessage());
    }
}

// -------------------------------------------------------------------------
// JSON output helper for AJAX endpoints
// -------------------------------------------------------------------------
function send_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------------------------------------------------
// Run a generic agent loop (used by every specialist agent)
// -------------------------------------------------------------------------
/**
 * Generic agent loop: keeps calling OpenAI and executing tools until the
 * model returns a plain-text message (no more tool_calls) or hits maxSteps.
 *
 * @param string   $system        system prompt
 * @param string   $user          the question from the orchestrator
 * @param array    $tools         tool definitions
 * @param callable $tool_runner   fn(string $name, array $args): string
 * @param int      $maxSteps      safety cap on tool-use rounds
 * @return array                  ['answer' => string, 'trace' => array]
 */
function run_agent_loop(
    string $system,
    string $user,
    array $tools,
    callable $tool_runner,
    int $maxSteps = 6
): array {
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
    $trace = [];

    for ($step = 0; $step < $maxSteps; $step++) {
        $msg = call_openai($messages, $tools);
        $messages[] = $msg;   // assistant turn

        // Plain text response = we are done
        if (empty($msg['tool_calls'])) {
            return [
                'answer' => $msg['content'] ?? '',
                'trace'  => $trace,
            ];
        }

        // Execute every tool call the model asked for
        foreach ($msg['tool_calls'] as $call) {
            $name   = $call['function']['name'];
            $args   = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];
            $result = $tool_runner($name, $args);
            $trace[] = ['tool' => $name, 'args' => $args, 'result' => $result];

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $call['id'],
                'content'      => is_string($result) ? $result : json_encode($result),
            ];
        }
    }

    return [
        'answer' => '[agent stopped: max steps reached]',
        'trace'  => $trace,
    ];
}

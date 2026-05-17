<?php
/**
 * LebanEASE Passenger Agent Tools
 * Shared SQL-backed tool functions + OpenAI function-calling helpers.
 */

require_once __DIR__ . '/db/config.php';
require_once __DIR__ . '/admin/ai_config.php';

/* =========================================================================
   DB
   ========================================================================= */
function passenger_pdo(): PDO {
    static $pdo = null;
    global $host, $dbname, $user, $pass;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

/* =========================================================================
   TOOL FUNCTIONS
   ========================================================================= */

function tool_search_trips(array $args): array {
    $source      = trim((string)($args['source'] ?? ''));
    $destination = trim((string)($args['destination'] ?? ''));
    $date        = trim((string)($args['date'] ?? ''));

    $sql = "SELECT s.Schedule_ID, s.Date, s.Departure_Time, s.Arrival_Time,
                   r.Source, r.Destination,
                   b.Bus_Number, b.Capacity, b.Type,
                   (SELECT COUNT(*) FROM Reservation
                    WHERE Schedule_ID = s.Schedule_ID
                      AND Status NOT IN ('Cancelled','Refunded')) AS booked
            FROM Bus_Schedule s
            JOIN Route r ON s.Route_ID = r.Route_ID
            JOIN Bus   b ON s.Bus_ID   = b.Bus_ID
            WHERE s.Status = 'Scheduled'";
    $params = [];

    if ($source !== '') {
        $sql .= " AND r.Source LIKE :src";
        $params[':src'] = "%{$source}%";
    }
    if ($destination !== '') {
        $sql .= " AND r.Destination LIKE :dst";
        $params[':dst'] = "%{$destination}%";
    }
    if ($date !== '') {
        $sql .= " AND s.Date = :d";
        $params[':d'] = $date;
    } else {
        $sql .= " AND s.Date >= CURDATE()";
    }
    $sql .= " ORDER BY s.Date, s.Departure_Time LIMIT 15";

    try {
        $stmt = passenger_pdo()->prepare($sql);
        $stmt->execute($params);
        $trips = $stmt->fetchAll();
        foreach ($trips as &$t) {
            $t['available_seats'] = max(0, (int)$t['Capacity'] - (int)$t['booked']);
        }
        if (empty($trips)) {
            $filter_desc = '';
            if ($source && $destination) $filter_desc = " from {$source} to {$destination}";
            else if ($source)            $filter_desc = " from {$source}";
            else if ($destination)       $filter_desc = " to {$destination}";
            if ($date) $filter_desc .= " on {$date}";
            return [
                'trips' => [],
                'count' => 0,
                'note'  => "No trips found{$filter_desc}. Consider calling list_routes() to see what routes exist, or search_trips() with no filter to see all upcoming trips.",
            ];
        }
        return ['trips' => $trips, 'count' => count($trips)];
    } catch (Throwable $e) {
        return ['error' => 'DB error: ' . $e->getMessage()];
    }
}

function tool_list_routes(): array {
    try {
        $stmt = passenger_pdo()->query(
            "SELECT DISTINCT r.Source, r.Destination,
                    COUNT(s.Schedule_ID) AS upcoming_trips
             FROM Route r
             LEFT JOIN Bus_Schedule s
               ON s.Route_ID = r.Route_ID
               AND s.Status = 'Scheduled'
               AND s.Date >= CURDATE()
             GROUP BY r.Source, r.Destination
             ORDER BY r.Source, r.Destination"
        );
        $routes = $stmt->fetchAll();
        return [
            'routes' => $routes,
            'count'  => count($routes),
        ];
    } catch (Throwable $e) {
        return ['error' => 'DB error: ' . $e->getMessage()];
    }
}


function tool_list_upcoming_trips(array $args): array {
    $limit = (int)($args['limit'] ?? 8);
    if ($limit < 1 || $limit > 20) $limit = 8;
    try {
        $stmt = passenger_pdo()->prepare(
            "SELECT s.Schedule_ID, s.Date, s.Departure_Time, s.Arrival_Time,
                    r.Source, r.Destination,
                    b.Bus_Number, b.Capacity,
                    (SELECT COUNT(*) FROM Reservation
                     WHERE Schedule_ID = s.Schedule_ID
                       AND Status NOT IN ('Cancelled','Refunded')) AS booked
             FROM Bus_Schedule s
             JOIN Route r ON s.Route_ID = r.Route_ID
             JOIN Bus   b ON s.Bus_ID   = b.Bus_ID
             WHERE s.Date >= CURDATE()
               AND s.Status = 'Scheduled'
             ORDER BY s.Date, s.Departure_Time
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $trips = $stmt->fetchAll();
        foreach ($trips as &$t) {
            $t['available_seats'] = max(0, (int)$t['Capacity'] - (int)$t['booked']);
        }
        return ['trips' => $trips, 'count' => count($trips)];
    } catch (Throwable $e) {
        return ['error' => 'DB error: ' . $e->getMessage()];
    }
}

function tool_get_my_reservations(int $passenger_id): array {
    if ($passenger_id <= 0) {
        return ['error' => 'You must be logged in to view your reservations'];
    }
    try {
        $stmt = passenger_pdo()->prepare(
            "SELECT r.Reservation_ID, r.Seat_Number, r.Status, r.Booking_Date,
                    s.Schedule_ID, s.Date, s.Departure_Time, s.Arrival_Time,
                    rt.Source, rt.Destination, b.Bus_Number
             FROM Reservation r
             JOIN Bus_Schedule s ON r.Schedule_ID = s.Schedule_ID
             JOIN Route rt       ON s.Route_ID    = rt.Route_ID
             JOIN Bus b          ON s.Bus_ID      = b.Bus_ID
             WHERE r.Passenger_ID = :pid
             ORDER BY s.Date DESC, s.Departure_Time DESC
             LIMIT 20"
        );
        $stmt->execute([':pid' => $passenger_id]);
        return ['reservations' => $stmt->fetchAll()];
    } catch (Throwable $e) {
        return ['error' => 'DB error: ' . $e->getMessage()];
    }
}

function tool_propose_booking(array $args, int $passenger_id): array {
    if ($passenger_id <= 0) {
        return ['error' => 'You must be logged in to book a trip. Please log in first.'];
    }
    $sid  = (int)($args['schedule_id'] ?? 0);
    $seat = isset($args['seat_number']) ? (int)$args['seat_number'] : null;
    if ($sid <= 0) return ['error' => 'schedule_id is required'];

    try {
        $stmt = passenger_pdo()->prepare(
            "SELECT s.Schedule_ID, s.Date, s.Departure_Time, s.Arrival_Time,
                    r.Source, r.Destination, b.Bus_Number, b.Capacity
             FROM Bus_Schedule s
             JOIN Route r ON s.Route_ID = r.Route_ID
             JOIN Bus   b ON s.Bus_ID   = b.Bus_ID
             WHERE s.Schedule_ID = :id"
        );
        $stmt->execute([':id' => $sid]);
        $trip = $stmt->fetch();
        if (!$trip) return ['error' => "Schedule {$sid} not found"];

        $stmt = passenger_pdo()->prepare(
            "SELECT Seat_Number FROM Reservation
             WHERE Schedule_ID = :sid
               AND Status NOT IN ('Cancelled','Refunded')"
        );
        $stmt->execute([':sid' => $sid]);
        $booked = array_map('intval', array_column($stmt->fetchAll(), 'Seat_Number'));

        if ($seat === null || $seat <= 0) {
            for ($i = 1; $i <= (int)$trip['Capacity']; $i++) {
                if (!in_array($i, $booked, true)) { $seat = $i; break; }
            }
        }
        if (!$seat) return ['error' => 'No seats available on this trip'];
        if ($seat > (int)$trip['Capacity']) return ['error' => "Seat {$seat} exceeds Bus capacity"];
        if (in_array($seat, $booked, true)) return ['error' => "Seat {$seat} is already booked"];

        return [
            'proposal' => [
                'type'         => 'booking',
                'schedule_id'  => (int)$sid,
                'seat_number'  => (int)$seat,
                'trip'         => $trip,
            ],
            'ok' => true,
            'note' => "Booking prepared. Seat {$seat} on {$trip['Source']}->{$trip['Destination']}, {$trip['Date']} {$trip['Departure_Time']}. Passenger must confirm.",
        ];
    } catch (Throwable $e) {
        return ['error' => 'DB error: ' . $e->getMessage()];
    }
}

function tool_propose_cancellation(array $args, int $passenger_id): array {
    if ($passenger_id <= 0) {
        return ['error' => 'You must be logged in to cancel a Reservation'];
    }
    $rid = (int)($args['reservation_id'] ?? 0);
    if ($rid <= 0) return ['error' => 'reservation_id is required'];

    try {
        $stmt = passenger_pdo()->prepare(
            "SELECT r.Reservation_ID, r.Seat_Number, r.Status, r.Booking_Date,
                    s.Date, s.Departure_Time,
                    rt.Source, rt.Destination, b.Bus_Number
             FROM Reservation r
             JOIN Bus_Schedule s ON r.Schedule_ID = s.Schedule_ID
             JOIN Route rt       ON s.Route_ID    = rt.Route_ID
             JOIN Bus b          ON s.Bus_ID      = b.Bus_ID
             WHERE r.Reservation_ID = :rid AND r.Passenger_ID = :pid"
        );
        $stmt->execute([':rid' => $rid, ':pid' => $passenger_id]);
        $res = $stmt->fetch();
        if (!$res) return ['error' => "Reservation {$rid} not found or not yours"];
        if (in_array(strtolower($res['Status']), ['cancelled', 'refunded'], true)) {
            return ['error' => 'This Reservation is already cancelled'];
        }

        return [
            'proposal' => [
                'type'           => 'cancellation',
                'reservation_id' => (int)$rid,
                'Reservation'    => $res,
            ],
            'ok' => true,
            'note' => "Cancellation prepared for Reservation {$rid}. Passenger must confirm.",
        ];
    } catch (Throwable $e) {
        return ['error' => 'DB error: ' . $e->getMessage()];
    }
}

/* =========================================================================
   OPENAI FUNCTION-CALLING DEFINITIONS
   ========================================================================= */
function build_passenger_tools(bool $is_logged_in): array {
    $tools = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'search_trips',
                'description' => 'Search upcoming Bus trips. ALL parameters are optional. Call with no parameters to see all upcoming trips across the system. Call with just source for "trips leaving from X". Call with both for a specific Route. Use LATIN city names (Beirut, Tripoli, Zahle, Baalbek, Saida, Jounieh, Byblos, Tyre) even if the user wrote Arabic or French.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'source'      => ['type' => 'string', 'description' => 'Optional departure city, Latin script'],
                        'destination' => ['type' => 'string', 'description' => 'Optional arrival city, Latin script'],
                        'date'        => ['type' => 'string', 'description' => 'Optional date YYYY-MM-DD'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_routes',
                'description' => 'List all routes (source-destination pairs) currently configured in the system, with how many upcoming scheduled trips each has. Use this when (a) the user asks generally "what trips are available" / "what routes do you have" / "ما الرحلات المتوفرة", or (b) a previous search_trips returned 0 results and you need to show the Passenger what IS available instead.',
                'parameters' => ['type' => 'object', 'properties' => new stdClass()],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_upcoming_trips',
                'description' => 'List the next N upcoming scheduled trips across all routes. Use when the user wants to browse what is leaving soon without specifying cities.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'How many trips to return (1-20, default 8)'],
                    ],
                ],
            ],
        ],
    ];
    if ($is_logged_in) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'get_my_reservations',
                'description' => "Get the logged-in Passenger's reservations (most recent 20).",
                'parameters' => ['type' => 'object', 'properties' => new stdClass()],
            ],
        ];
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'propose_booking',
                'description' => 'Prepare a booking on a specific schedule. The Passenger will then confirm or cancel in the UI. Does NOT commit on its own.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'schedule_id' => ['type' => 'integer', 'description' => 'ID from search_trips results'],
                        'seat_number' => ['type' => 'integer', 'description' => 'Optional preferred seat. Lowest available is auto-picked if omitted.'],
                    ],
                    'required' => ['schedule_id'],
                ],
            ],
        ];
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'propose_cancellation',
                'description' => "Prepare cancellation of one of the Passenger's reservations. The Passenger must confirm in the UI.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reservation_id' => ['type' => 'integer'],
                    ],
                    'required' => ['reservation_id'],
                ],
            ],
        ];
    }
    return $tools;
}

function execute_passenger_tool(string $fn, array $args, int $passenger_id): array {
    switch ($fn) {
        case 'search_trips':         return tool_search_trips($args);
        case 'list_routes':          return tool_list_routes();
        case 'list_upcoming_trips':  return tool_list_upcoming_trips($args);
        case 'get_my_reservations':  return tool_get_my_reservations($passenger_id);
        case 'propose_booking':      return tool_propose_booking($args, $passenger_id);
        case 'propose_cancellation': return tool_propose_cancellation($args, $passenger_id);
        default:                     return ['error' => "Unknown tool: {$fn}"];
    }
}

/* =========================================================================
   OPENAI CALL
   ========================================================================= */
function call_openai_with_tools(array $messages, array $tools): ?array {
    $payload = [
        'model'       => LEBANEASE_AI_MODEL,
        'messages'    => $messages,
        'temperature' => 0.3,
    ];
    if (!empty($tools)) {
        $payload['tools'] = $tools;
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
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT    => 60,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['_error' => "AI service unreachable: {$err}"];
    }
    $decoded = json_decode($raw, true);
    if ($code !== 200) {
        $msg = $decoded['error']['message'] ?? $raw;
        return ['_error' => "AI error ({$code}): {$msg}"];
    }
    return $decoded;
}

function sanitize_messages_in(array $in): array {
    $out = [];
    foreach ($in as $m) {
        if (!is_array($m)) continue;
        $role    = $m['role'] ?? '';
        $content = trim((string)($m['content'] ?? ''));
        if (!in_array($role, ['user', 'assistant'], true)) continue;
        if ($content === '') continue;
        $out[] = ['role' => $role, 'content' => $content];
    }
    return $out;
}

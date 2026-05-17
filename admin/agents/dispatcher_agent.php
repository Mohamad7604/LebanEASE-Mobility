<?php
/**
 * LebanEASE Dispatcher Agent (v2 - schema-corrected)
 *
 * Schema reality:
 *   Bus_Schedule: Schedule_ID, Departure_Time(time), Arrival_Time(time), Date(date),
 *                 Bus_ID, Route_ID, Driver_ID, Status
 *   Bus:          Bus_ID, Bus_Number, Capacity, Type, Status, Driver_ID, Admin_ID
 *   Driver:       Driver_ID, First_Name, Last_Name, License_Number, ...
 *   Route:        Route_ID, Route_Name, Source, Destination, Distance, ...
 *   Reservation:  Reservation_ID, Seat_Number, Booking_Date, Status, Passenger_ID, Schedule_ID, ...
 *
 * Datetimes are reconstructed as TIMESTAMP(Date, Departure_Time/Arrival_Time).
 */

require_once __DIR__ . '/tools.php';

function dispatcher_tool(string $name, array $args) {
    try {
        switch ($name) {

            case 'get_schedules_in_window': {
                $from = $args['from'] ?? date('Y-m-d 00:00:00');
                $to   = $args['to']   ?? date('Y-m-d 23:59:59', strtotime('+1 day'));
                $stmt = db()->prepare(
                    "SELECT s.Schedule_ID,
                            s.Date,
                            s.Departure_Time,
                            s.Arrival_Time,
                            TIMESTAMP(s.Date, s.Departure_Time) AS Departure_DateTime,
                            TIMESTAMP(s.Date, s.Arrival_Time)   AS Arrival_DateTime,
                            b.Bus_ID, b.Bus_Number, b.Status AS Bus_Status,
                            d.Driver_ID,
                            CONCAT(d.First_Name, ' ', d.Last_Name) AS Driver_Name,
                            r.Route_ID, r.Source, r.Destination
                     FROM Bus_Schedule s
                     JOIN Bus    b ON s.Bus_ID    = b.Bus_ID
                     JOIN Driver d ON s.Driver_ID = d.Driver_ID
                     JOIN Route  r ON s.Route_ID  = r.Route_ID
                     WHERE TIMESTAMP(s.Date, s.Departure_Time) BETWEEN :from AND :to
                     ORDER BY s.Date, s.Departure_Time"
                );
                $stmt->execute([':from' => $from, ':to' => $to]);
                return $stmt->fetchAll();
            }

            case 'get_schedule_details': {
                $id = (int)($args['schedule_id'] ?? 0);
                $stmt = db()->prepare(
                    "SELECT s.*,
                            TIMESTAMP(s.Date, s.Departure_Time) AS Departure_DateTime,
                            TIMESTAMP(s.Date, s.Arrival_Time)   AS Arrival_DateTime,
                            b.Bus_Number, b.Capacity, b.Status AS Bus_Status,
                            CONCAT(d.First_Name, ' ', d.Last_Name) AS Driver_Name,
                            r.Source, r.Destination,
                            (SELECT COUNT(*) FROM Reservation
                             WHERE Schedule_ID = s.Schedule_ID
                               AND Status NOT IN ('Cancelled','Refunded')) AS Reserved_Seats
                     FROM Bus_Schedule s
                     JOIN Bus    b ON s.Bus_ID    = b.Bus_ID
                     JOIN Driver d ON s.Driver_ID = d.Driver_ID
                     JOIN Route  r ON s.Route_ID  = r.Route_ID
                     WHERE s.Schedule_ID = :id"
                );
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                return $row ?: ['error' => "schedule {$id} not found"];
            }

            case 'find_replacement_bus': {
                $dep = $args['departure_time'] ?? null;
                $arr = $args['arrival_time']   ?? null;
                $cap = (int)($args['min_capacity'] ?? 0);
                if (!$dep || !$arr) return ['error' => 'departure_time and arrival_time required as full datetimes'];

                $stmt = db()->prepare(
                    "SELECT b.Bus_ID, b.Bus_Number, b.Type, b.Capacity, b.Status
                     FROM Bus b
                     WHERE b.Status = 'Active'
                       AND b.Capacity >= :cap
                       AND b.Bus_ID NOT IN (
                           SELECT s.Bus_ID FROM Bus_Schedule s
                           WHERE TIMESTAMP(s.Date, s.Departure_Time) < :arr
                             AND TIMESTAMP(s.Date, s.Arrival_Time)   > :dep
                       )
                     ORDER BY b.Capacity ASC
                     LIMIT 5"
                );
                $stmt->execute([':cap' => $cap, ':dep' => $dep, ':arr' => $arr]);
                $rows = $stmt->fetchAll();
                return $rows ?: ['note' => 'no eligible replacement buses found'];
            }

            case 'find_replacement_driver': {
                $dep = $args['departure_time'] ?? null;
                $arr = $args['arrival_time']   ?? null;
                if (!$dep || !$arr) return ['error' => 'departure_time and arrival_time required as full datetimes'];

                // No Status filter on Driver - column existence not confirmed in this schema
                $stmt = db()->prepare(
                    "SELECT d.Driver_ID,
                            CONCAT(d.First_Name, ' ', d.Last_Name) AS Driver_Name,
                            d.License_Number
                     FROM Driver d
                     WHERE d.Driver_ID NOT IN (
                         SELECT s.Driver_ID FROM Bus_Schedule s
                         WHERE TIMESTAMP(s.Date, s.Departure_Time) < :arr
                           AND TIMESTAMP(s.Date, s.Arrival_Time)   > :dep
                     )
                     LIMIT 5"
                );
                $stmt->execute([':dep' => $dep, ':arr' => $arr]);
                $rows = $stmt->fetchAll();
                return $rows ?: ['note' => 'no available drivers in that window'];
            }

            case 'check_schedule_conflicts': {
                $bus_id    = (int)($args['bus_id']    ?? 0);
                $driver_id = (int)($args['driver_id'] ?? 0);
                $dep       = $args['departure_time'] ?? null;
                $arr       = $args['arrival_time']   ?? null;

                $stmt = db()->prepare(
                    "SELECT Schedule_ID, Bus_ID, Driver_ID, Date, Departure_Time, Arrival_Time
                     FROM Bus_Schedule
                     WHERE TIMESTAMP(Date, Departure_Time) < :arr
                       AND TIMESTAMP(Date, Arrival_Time)   > :dep
                       AND (Bus_ID = :Bus OR Driver_ID = :Driver)"
                );
                $stmt->execute([
                    ':Bus' => $bus_id, ':Driver' => $driver_id,
                    ':dep' => $dep,    ':arr' => $arr,
                ]);
                $conflicts = $stmt->fetchAll();
                return [
                    'has_conflict' => !empty($conflicts),
                    'conflicts'    => $conflicts,
                ];
            }

            case 'count_affected_reservations': {
                $id = (int)($args['schedule_id'] ?? 0);
                $stmt = db()->prepare(
                    "SELECT COUNT(*) AS cnt FROM Reservation
                     WHERE Schedule_ID = :id AND Status NOT IN ('Cancelled','Refunded')"
                );
                $stmt->execute([':id' => $id]);
                return $stmt->fetch();
            }

            case 'propose_schedule_change': {
                log_agent_action('dispatcher', 'proposed_change', $args);
                return [
                    'status'   => 'proposed',
                    'proposal' => $args,
                    'note'     => 'requires admin approval before commit',
                ];
            }
            case 'list_fleet': {
                $status_filter = $args['status'] ?? null;  // 'Active', 'Maintenance', 'Offline', or null=all
                $on_date       = $args['available_on_date'] ?? null;  // 'YYYY-MM-DD' filters out buses scheduled that day

                $sql = "SELECT b.Bus_ID, b.Bus_Number, b.Type, b.Capacity, b.Status,
                               CONCAT(d.First_Name, ' ', d.Last_Name) AS Default_Driver
                        FROM Bus b
                        LEFT JOIN Driver d ON b.Driver_ID = d.Driver_ID
                        WHERE 1=1";
                $params = [];
                if ($status_filter) {
                    $sql .= " AND b.Status = :st";
                    $params[':st'] = $status_filter;
                }
                if ($on_date) {
                    // Exclude buses already scheduled on this date
                    $sql .= " AND b.Bus_ID NOT IN (
                                SELECT DISTINCT Bus_ID FROM Bus_Schedule
                                WHERE Date = :od AND Status = 'Scheduled'
                              )";
                    $params[':od'] = $on_date;
                }
                $sql .= " ORDER BY b.Bus_ID";
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                return ['fleet' => $rows, 'count' => count($rows)];
            }

            case 'list_drivers': {
                $on_date = $args['available_on_date'] ?? null;
                $sql = "SELECT d.Driver_ID, d.First_Name, d.Last_Name,
                               CONCAT(d.First_Name,' ',d.Last_Name) AS Full_Name,
                               d.License_Number
                        FROM Driver d
                        WHERE 1=1";
                $params = [];
                if ($on_date) {
                    $sql .= " AND d.Driver_ID NOT IN (
                                SELECT DISTINCT Driver_ID FROM Bus_Schedule
                                WHERE Date = :od AND Status = 'Scheduled'
                              )";
                    $params[':od'] = $on_date;
                }
                $sql .= " ORDER BY d.Driver_ID";
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                return ['drivers' => $rows, 'count' => count($rows)];
            }

            case 'list_routes': {
                $stmt = db()->query(
                    "SELECT Route_ID, Route_Name, Source, Destination, Distance
                     FROM Route ORDER BY Source, Destination"
                );
                $rows = $stmt->fetchAll();
                return ['routes' => $rows, 'count' => count($rows)];
            }
        }
        return ['error' => "unknown dispatcher tool: {$name}"];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

function dispatcher_tools(): array {
    return [
        ['type' => 'function', 'function' => [
            'name' => 'get_schedules_in_window',
            'description' => 'List Bus schedules whose departure datetime falls between :from and :to. Both are full datetimes "YYYY-MM-DD HH:MM:SS".',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM:SS'],
                    'to'   => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM:SS'],
                ],
                'required' => ['from', 'to'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'get_schedule_details',
            'description' => 'Full details for one schedule: Route, Bus, Driver, capacity, current Reservation count.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['schedule_id' => ['type' => 'integer']],
                'required' => ['schedule_id'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'find_replacement_bus',
            'description' => 'Find Active buses with sufficient capacity that have no schedule overlap in the given datetime window.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'departure_time' => ['type' => 'string', 'description' => 'full datetime YYYY-MM-DD HH:MM:SS'],
                    'arrival_time'   => ['type' => 'string', 'description' => 'full datetime YYYY-MM-DD HH:MM:SS'],
                    'min_capacity'   => ['type' => 'integer'],
                ],
                'required' => ['departure_time', 'arrival_time'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'find_replacement_driver',
            'description' => 'Find drivers with no schedule conflict in the given datetime window.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'departure_time' => ['type' => 'string', 'description' => 'full datetime YYYY-MM-DD HH:MM:SS'],
                    'arrival_time'   => ['type' => 'string', 'description' => 'full datetime YYYY-MM-DD HH:MM:SS'],
                ],
                'required' => ['departure_time', 'arrival_time'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'check_schedule_conflicts',
            'description' => 'Validate whether a proposed Bus+Driver+time combo conflicts with existing schedules.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'bus_id'         => ['type' => 'integer'],
                    'driver_id'      => ['type' => 'integer'],
                    'departure_time' => ['type' => 'string'],
                    'arrival_time'   => ['type' => 'string'],
                ],
                'required' => ['bus_id', 'driver_id', 'departure_time', 'arrival_time'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'count_affected_reservations',
            'description' => 'Count active (non-cancelled) reservations tied to a schedule.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['schedule_id' => ['type' => 'integer']],
                'required' => ['schedule_id'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'propose_schedule_change',
            'description' => 'Record a proposed change (swap Bus, reassign Driver, retime, cancel). Does NOT commit.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'schedule_id'  => ['type' => 'integer'],
                    'change_type'  => ['type' => 'string', 'enum' => ['swap_bus','reassign_driver','retime','cancel']],
                    'new_bus_id'   => ['type' => 'integer'],
                    'new_driver_id'=> ['type' => 'integer'],
                    'new_departure'=> ['type' => 'string'],
                    'new_arrival'  => ['type' => 'string'],
                    'reason'       => ['type' => 'string'],
                ],
                'required' => ['schedule_id', 'change_type', 'reason'],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'list_fleet',
            'description' => 'List buses in the fleet. Use this for general "what buses do we have / which buses are available" questions. Optionally filter by status, or by which buses are NOT scheduled on a given date.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status'            => ['type' => 'string', 'enum' => ['Active','Maintenance','Offline'], 'description' => 'optional, filter by Bus status'],
                    'available_on_date' => ['type' => 'string', 'description' => 'optional YYYY-MM-DD; returns only buses that do NOT already have a schedule on that date'],
                ],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'list_drivers',
            'description' => 'List drivers in the system. Optionally filter by which drivers are NOT scheduled on a given date.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'available_on_date' => ['type' => 'string', 'description' => 'optional YYYY-MM-DD'],
                ],
            ],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'list_routes',
            'description' => 'List all routes (Source -> Destination pairs) in the system.',
            'parameters' => ['type' => 'object', 'properties' => new stdClass()],
        ]],
    ];
}

function run_dispatcher_agent(string $question): array {
    $today = date('Y-m-d');
    $system = <<<SYS
You are the LebanEASE Dispatcher Agent. You own schedules, buses, and drivers.

== LANGUAGE RULES ==
- The orchestrator may prefix its question with "[respond_in: en|ar|fr]". Honor that exactly.
- If no prefix, mirror the language of the question itself (English, Arabic, or French).
- Keywords inside tool calls and proposed_change parameters stay in English (they are code).
- Your prose response (findings, summary, risks) goes in the requested language.

Schema notes:
- Schedules use a Date field plus separate Departure_Time and Arrival_Time (time of day).
- Tools accept and return full datetimes "YYYY-MM-DD HH:MM:SS".
- Driver name is concatenated by the tool from First_Name + Last_Name.

Today is {$today}.

Your job:
1. Use tools to investigate. Avoid redundant calls.
2. **TOOL SELECTION**:
   - For "what buses do we have / which buses are available [on date]?" → use **list_fleet** (optional status filter, optional available_on_date filter). Do NOT use find_replacement_bus for general fleet listing.
   - For "what drivers / which drivers are available [on date]?" → use **list_drivers**.
   - For "what routes do we have?" → use **list_routes**.
   - For DISRUPTION HANDLING (a specific schedule needs a replacement), use **find_replacement_bus** / **find_replacement_driver** with the exact time window.
   - For listing what's running on a date, use **get_schedules_in_window**.
3. When asked to handle a disruption, find concrete replacement Bus/Driver candidates with real IDs from your tools.
4. When proposing a change, ALWAYS call propose_schedule_change with structured parameters:
   - schedule_id (integer)
   - change_type (one of: swap_bus, reassign_driver, retime, cancel)
   - For swap_bus include new_bus_id (integer)
   - For reassign_driver include new_driver_id (integer)
   - For retime include new_departure and new_arrival as full datetimes
   - reason (string, in the requested language)
5. NEVER fabricate IDs, names, or times - every fact must come from a tool result. If list_fleet returns 11 buses, list ALL 11 (not a guess of 5).
6. NEVER commit changes - you propose, the admin approves.
7. Return a tight, decision-ready summary.

Be concise. The Orchestrator will turn your proposal into an executable plan.
SYS;

    $result = run_agent_loop(
        $system,
        $question,
        dispatcher_tools(),
        'dispatcher_tool'
    );

    log_agent_action('dispatcher', 'consulted', [
        'question' => $question,
        'steps'    => count($result['trace']),
    ]);

    return [
        'agent'  => 'dispatcher',
        'answer' => $result['answer'],
        'trace'  => $result['trace'],
    ];
}

if (php_sapi_name() !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    session_start();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $q = $body['question'] ?? ($_GET['q'] ?? '');
    if ($q === '') {
        send_json(['error' => 'pass {question: "..."} as JSON body or ?q=...'], 400);
    }
    send_json(run_dispatcher_agent($q));
}

<?php
/**
 * LebanEASE Plan Commit Endpoint
 * Receives an approved plan from the admin UI, re-validates each action
 * against current schedule state, then executes inside a single transaction.
 * Every action is logged. Failures roll back atomically.
 *
 * Drop in:  C:\xampp\htdocs\LebanEase\LebanEase\admin\agents\commit.php
 */

session_start();
require_once __DIR__ . '/tools.php';

// Auth gate
if (empty($_SESSION['admin_id'])) {
    send_json(['error' => 'admin login required'], 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$plan = $body['plan'] ?? null;

if (!$plan || empty($plan['actions']) || !is_array($plan['actions'])) {
    send_json(['error' => 'plan with non-empty actions[] required'], 400);
}

$pdo = db();
$results = [];
$pdo->beginTransaction();

try {
    foreach ($plan['actions'] as $i => $action) {
        $type   = strtolower($action['action'] ?? '');
        $params = $action['parameters'] ?? [];
        $rationale = $action['rationale'] ?? '';

        $outcome = ['index' => $i, 'action' => $type, 'rationale' => $rationale];

        switch ($type) {

            case 'swap_bus': {
                $sid = (int)($params['schedule_id'] ?? 0);
                $bus = (int)($params['new_bus_id']  ?? 0);
                if ($sid <= 0 || $bus <= 0) throw new RuntimeException("swap_bus #{$i}: schedule_id and new_bus_id required");

                // Re-validate: bus exists and is active
                $chk = $pdo->prepare("SELECT Status, Capacity FROM bus WHERE Bus_ID = :b");
                $chk->execute([':b' => $bus]);
                $busRow = $chk->fetch();
                if (!$busRow)                           throw new RuntimeException("bus {$bus} not found");
                if ($busRow['Status'] !== 'Active')     throw new RuntimeException("bus {$bus} is {$busRow['Status']}, not Active");

                // Fetch the current schedule's time window (lookup, not check)
                $cur = $pdo->prepare("SELECT Date, Departure_Time, Arrival_Time FROM bus_schedule WHERE Schedule_ID = :sid");
                $cur->execute([':sid' => $sid]);
                $curRow = $cur->fetch();
                if (!$curRow) throw new RuntimeException("schedule {$sid} not found");
                $depTs = $curRow['Date'] . ' ' . $curRow['Departure_Time'];
                $arrTs = $curRow['Date'] . ' ' . $curRow['Arrival_Time'];

                // Conflict check on the candidate bus
                $conf = $pdo->prepare(
                    "SELECT Schedule_ID FROM bus_schedule
                     WHERE Bus_ID = :b
                       AND Schedule_ID != :sid
                       AND TIMESTAMP(Date, Departure_Time) < :arr
                       AND TIMESTAMP(Date, Arrival_Time)   > :dep"
                );
                $conf->execute([':b' => $bus, ':sid' => $sid, ':dep' => $depTs, ':arr' => $arrTs]);
                if ($conf->fetch()) throw new RuntimeException("bus {$bus} now has a conflicting schedule (changed since proposal)");

                // Execute
                $upd = $pdo->prepare("UPDATE bus_schedule SET Bus_ID = :b WHERE Schedule_ID = :sid");
                $upd->execute([':b' => $bus, ':sid' => $sid]);
                $outcome['rows_affected'] = $upd->rowCount();
                $outcome['status'] = 'committed';
                break;
            }

            case 'reassign_driver': {
                $sid = (int)($params['schedule_id']   ?? 0);
                $drv = (int)($params['new_driver_id'] ?? 0);
                if ($sid <= 0 || $drv <= 0) throw new RuntimeException("reassign_driver #{$i}: schedule_id and new_driver_id required");

                $chk = $pdo->prepare("SELECT Driver_ID FROM driver WHERE Driver_ID = :d");
                $chk->execute([':d' => $drv]);
                if (!$chk->fetch()) throw new RuntimeException("driver {$drv} not found");

                $cur = $pdo->prepare("SELECT Date, Departure_Time, Arrival_Time FROM bus_schedule WHERE Schedule_ID = :sid");
                $cur->execute([':sid' => $sid]);
                $curRow = $cur->fetch();
                if (!$curRow) throw new RuntimeException("schedule {$sid} not found");
                $depTs = $curRow['Date'] . ' ' . $curRow['Departure_Time'];
                $arrTs = $curRow['Date'] . ' ' . $curRow['Arrival_Time'];

                $conf = $pdo->prepare(
                    "SELECT Schedule_ID FROM bus_schedule
                     WHERE Driver_ID = :d
                       AND Schedule_ID != :sid
                       AND TIMESTAMP(Date, Departure_Time) < :arr
                       AND TIMESTAMP(Date, Arrival_Time)   > :dep"
                );
                $conf->execute([':d' => $drv, ':sid' => $sid, ':dep' => $depTs, ':arr' => $arrTs]);
                if ($conf->fetch()) throw new RuntimeException("driver {$drv} now has a conflicting schedule (changed since proposal)");

                $upd = $pdo->prepare("UPDATE bus_schedule SET Driver_ID = :d WHERE Schedule_ID = :sid");
                $upd->execute([':d' => $drv, ':sid' => $sid]);
                $outcome['rows_affected'] = $upd->rowCount();
                $outcome['status'] = 'committed';
                break;
            }

            case 'retime': {
                $sid  = (int)($params['schedule_id']    ?? 0);
                $date = $params['new_date']             ?? null;
                $dep  = $params['new_departure_time']   ?? null;
                $arr  = $params['new_arrival_time']     ?? null;
                if ($sid <= 0 || !$date || !$dep || !$arr) {
                    throw new RuntimeException("retime #{$i}: schedule_id, new_date, new_departure_time, new_arrival_time required");
                }
                $upd = $pdo->prepare(
                    "UPDATE bus_schedule
                     SET Date = :d, Departure_Time = :dep, Arrival_Time = :arr
                     WHERE Schedule_ID = :sid"
                );
                $upd->execute([':d' => $date, ':dep' => $dep, ':arr' => $arr, ':sid' => $sid]);
                $outcome['rows_affected'] = $upd->rowCount();
                $outcome['status'] = 'committed';
                break;
            }

            case 'cancel': {
                $sid = (int)($params['schedule_id'] ?? 0);
                if ($sid <= 0) throw new RuntimeException("cancel #{$i}: schedule_id required");
                $upd = $pdo->prepare("UPDATE bus_schedule SET Status = 'Cancelled' WHERE Schedule_ID = :sid");
                $upd->execute([':sid' => $sid]);
                $outcome['rows_affected'] = $upd->rowCount();
                $outcome['status'] = 'committed';
                break;
            }

            case 'mark_bus_status': {
                // Update bus operational status. Used when a bus is pulled
                // from service (e.g. after a breakdown). Allowed values match
                // the bus.Status enum: Active, Maintenance, Offline.
                $bus       = (int)($params['bus_id']     ?? 0);
                $newStatus =      ($params['new_status'] ?? '');
                $allowed   = ['Active', 'Maintenance', 'Offline'];

                if ($bus <= 0)                            throw new RuntimeException("mark_bus_status #{$i}: bus_id required");
                if (!in_array($newStatus, $allowed, true)) throw new RuntimeException("mark_bus_status #{$i}: new_status must be one of " . implode(', ', $allowed));

                // Validate bus exists, get old status
                $chk = $pdo->prepare("SELECT Bus_Number, Status FROM bus WHERE Bus_ID = :b");
                $chk->execute([':b' => $bus]);
                $busRow = $chk->fetch();
                if (!$busRow) throw new RuntimeException("bus {$bus} not found");

                // If pulling a bus offline/maintenance, fail if it still has
                // FUTURE active schedules — those must be reassigned first.
                if ($newStatus !== 'Active') {
                    $conf = $pdo->prepare(
                        "SELECT Schedule_ID FROM bus_schedule
                         WHERE Bus_ID = :b
                           AND Status = 'Scheduled'
                           AND TIMESTAMP(Date, Departure_Time) >= NOW()
                         LIMIT 5"
                    );
                    $conf->execute([':b' => $bus]);
                    $stillBooked = $conf->fetchAll();
                    if ($stillBooked) {
                        $ids = array_map(fn($r) => $r['Schedule_ID'], $stillBooked);
                        throw new RuntimeException("bus {$bus} ({$busRow['Bus_Number']}) still has future schedules: " . implode(', ', $ids) . ". Reassign them first, then mark the bus.");
                    }
                }

                $upd = $pdo->prepare("UPDATE bus SET Status = :s WHERE Bus_ID = :b");
                $upd->execute([':s' => $newStatus, ':b' => $bus]);
                $outcome['bus_number'] = $busRow['Bus_Number'];
                $outcome['old_status'] = $busRow['Status'];
                $outcome['new_status'] = $newStatus;
                $outcome['rows_affected'] = $upd->rowCount();
                $outcome['status'] = 'committed';
                break;
            }

            // -----------------------------------------------------------------
            // CUSTOMER CARE ACTIONS
            // -----------------------------------------------------------------

            case 'notify_passenger': {
                // Records a notification draft in the audit log. We don't
                // actually send SMS/email in this version - that's a future
                // integration. But the draft + recipient is persisted so it
                // could be picked up by a sending worker later.
                $pid     = (int)($params['passenger_id'] ?? 0);
                $rid     = (int)($params['reservation_id'] ?? 0);
                $message =      ($params['message']      ?? '');
                $channel =      ($params['channel']      ?? 'email');
                $language=      ($params['language']     ?? 'en');

                if ($pid <= 0 && $rid <= 0) throw new RuntimeException("notify_passenger #{$i}: passenger_id or reservation_id required");
                if (!$message)              throw new RuntimeException("notify_passenger #{$i}: message required");

                // Resolve passenger if only reservation given
                if ($pid <= 0 && $rid > 0) {
                    $chk = $pdo->prepare("SELECT Passenger_ID FROM reservation WHERE Reservation_ID = :r");
                    $chk->execute([':r' => $rid]);
                    $row = $chk->fetch();
                    if (!$row) throw new RuntimeException("reservation {$rid} not found");
                    $pid = (int)$row['Passenger_ID'];
                }

                $outcome['passenger_id']   = $pid;
                $outcome['channel']        = $channel;
                $outcome['language']       = $language;
                $outcome['message_length'] = strlen($message);
                $outcome['status']         = 'queued';   // queued for delivery, not actually sent
                $outcome['note']           = 'Notification recorded in admin_activity_log. Delivery worker integration is Phase 2+.';
                // (Detailed message captured by log_agent_action below.)
                break;
            }

            case 'refund_reservation': {
                // Set reservation status to Refunded and NULL the denormalized
                // active columns so the seat is released for re-booking.
                $rid    = (int)($params['reservation_id'] ?? 0);
                $reason =      ($params['reason']         ?? 'schedule change');
                if ($rid <= 0) throw new RuntimeException("refund_reservation #{$i}: reservation_id required");

                $chk = $pdo->prepare(
                    "SELECT Status, Seat_Number, Passenger_ID, Schedule_ID
                     FROM reservation WHERE Reservation_ID = :r"
                );
                $chk->execute([':r' => $rid]);
                $row = $chk->fetch();
                if (!$row) throw new RuntimeException("reservation {$rid} not found");
                if (strtolower($row['Status']) === 'refunded') {
                    $outcome['status'] = 'noop';
                    $outcome['note']   = "reservation {$rid} was already Refunded";
                    break;
                }

                $upd = $pdo->prepare(
                    "UPDATE reservation
                     SET Status = 'Refunded',
                         Active_Seat_Number = NULL,
                         Active_Passenger_ID = NULL
                     WHERE Reservation_ID = :r"
                );
                $upd->execute([':r' => $rid]);
                $outcome['reservation_id'] = $rid;
                $outcome['previous_status']= $row['Status'];
                $outcome['new_status']     = 'Refunded';
                $outcome['rows_affected']  = $upd->rowCount();
                $outcome['status']         = 'committed';
                $outcome['reason']         = $reason;
                break;
            }

            default:
                throw new RuntimeException("action #{$i}: unsupported type '{$type}'. Supported: swap_bus, reassign_driver, retime, cancel, mark_bus_status, notify_passenger, refund_reservation.");
        }

        $results[] = $outcome;
        log_agent_action('commit', $type, [
            'action_index' => $i,
            'parameters'   => $params,
            'rationale'    => $rationale,
            'rows'         => $outcome['rows_affected'] ?? 0,
        ]);
    }

    $pdo->commit();
    log_agent_action('commit', 'plan_executed', [
        'summary'      => $plan['summary'] ?? '',
        'action_count' => count($results),
    ]);

    send_json([
        'status'  => 'success',
        'summary' => $plan['summary'] ?? '',
        'results' => $results,
    ]);

} catch (Throwable $e) {
    $pdo->rollBack();
    log_agent_action('commit', 'plan_failed', [
        'error'           => $e->getMessage(),
        'completed_steps' => $results,
    ]);
    send_json([
        'status'           => 'failed',
        'error'            => $e->getMessage(),
        'completed_before_failure' => $results,
    ], 500);
}

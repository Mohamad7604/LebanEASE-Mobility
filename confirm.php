<?php
require "db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: search.php");
    exit;
}

$schedule_id = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;
$seat_number = isset($_POST['seat_number']) ? (int)$_POST['seat_number'] : 0; // 0 means auto-assign
$errors = [];
$success = false;
$reservationId = null;
$schedule = null;
$capacity = 0;
$usedAutoSeat = false;
$isLoggedIn = !empty($_SESSION['passenger_id']);
if (!$isLoggedIn) {
    header("Location: login.php?login_required=1&next=" . urlencode("search.php"));
    exit;
}

function trip_completed_from_schedule(array $schedule): bool {
    try {
        $dep = new DateTime($schedule['date'] . ' ' . $schedule['departure_time']);
        $arr = new DateTime($schedule['date'] . ' ' . $schedule['arrival_time']);
        if ($arr <= $dep) $arr->modify('+1 day');
        return (new DateTime()) >= $arr;
    } catch (Exception $e) {
        return true;
    }
}

function next_available_seat(PDO $pdo, int $schedule_id, int $capacity): int {
    $stmt = $pdo->prepare("SELECT Seat_Number FROM Reservation WHERE Schedule_ID = ? AND Status = 'Active' ORDER BY Seat_Number ASC");
    $stmt->execute([$schedule_id]);
    $taken = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $takenSet = array_flip($taken);
    for ($i = 1; $i <= $capacity; $i++) {
        if (!isset($takenSet[$i])) return $i;
    }
    return 0;
}

function create_reservation(PDO $pdo, int $schedule_id, int $seat_number, int $passenger_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Reservation WHERE Schedule_ID = ? AND Seat_Number = ? AND Status = 'Active'");
    $stmt->execute([$schedule_id, $seat_number]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new RuntimeException('This seat is already reserved. Please choose another seat.');
    }

    $dup = $pdo->prepare("SELECT COUNT(*) FROM Reservation WHERE Schedule_ID = ? AND Passenger_ID = ? AND Status = 'Active'");
    $dup->execute([$schedule_id, $passenger_id]);
    if ((int)$dup->fetchColumn() > 0) {
        throw new RuntimeException('You already have an active reservation for this trip.');
    }

    $insert = $pdo->prepare("INSERT INTO Reservation (Seat_Number, Booking_Date, Status, Passenger_ID, Schedule_ID) VALUES (?, CURDATE(), 'Active', ?, ?)");
    $insert->execute([$seat_number, $passenger_id, $schedule_id]);
    return (int)$pdo->lastInsertId();
}

if ($schedule_id <= 0) $errors[] = "Invalid schedule.";

if (empty($errors)) {
    $stmt = $pdo->prepare("SELECT Schedule_ID AS schedule_id, Date AS date, Departure_Time AS departure_time, Arrival_Time AS arrival_time, Bus_Number AS bus_number, Bus_Type AS bus_type, Capacity AS capacity, Source AS source, Destination AS destination, Distance AS distance, Available_Seats AS available_seats, Trip_Status AS trip_status FROM v_schedule_overview WHERE Schedule_ID = ? LIMIT 1");
    $stmt->execute([$schedule_id]);
    $schedule = $stmt->fetch();
    if (!$schedule) {
        $errors[] = "Schedule not found.";
    } else {
        $capacity = (int)$schedule['capacity'];
        if ((string)$schedule['trip_status'] !== 'Scheduled' || trip_completed_from_schedule($schedule)) {
            $errors[] = "This trip is not available for reservation.";
        }
        if ((int)$schedule['available_seats'] <= 0) {
            $errors[] = "No available seats remain on this trip.";
        }
        if ($seat_number === 0 && empty($errors)) {
            $seat_number = next_available_seat($pdo, $schedule_id, $capacity);
            $usedAutoSeat = true;
            if ($seat_number <= 0) $errors[] = "No available seats remain on this trip.";
        }
        if ($seat_number < 0 || $seat_number > $capacity) {
            $errors[] = "Invalid seat number. Max seat: $capacity.";
        }
    }
}

// Logged-in passengers can confirm immediately without retyping their information.
if ($isLoggedIn && empty($errors)) {
    try {
        $pdo->beginTransaction();
        $reservationId = create_reservation($pdo, $schedule_id, $seat_number, (int)$_SESSION['passenger_id']);
        $pdo->commit();
        $success = true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) {
            $errors[] = $e->getMessage();
        } elseif ($e instanceof PDOException && $e->getCode() === '23000') {
            $errors[] = "This seat is no longer available, or you already have an active reservation for this trip.";
        } else {
            $errors[] = "Booking failed. Please try again.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><title>Confirm Reservation | LebanEASE</title><link rel="stylesheet" href="css/style.css" /></head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('search'); ?>
<main class="section"><div class="container">
<h2 class="section-title">Confirm Reservation</h2>
<?php if (!empty($errors)): ?>
  <div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px; margin-top:8px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><div class="h-12"></div><a class="btn btn-primary" href="search.php">Back to Search</a></div>
<?php elseif ($success): ?>
  <div class="card glass"><h3>✅ Reservation Confirmed</h3>
    <p class="muted" style="line-height:1.8; margin-top:10px;">
      <strong>Reservation ID:</strong> <?= (int)$reservationId ?><br/>
      <strong>Route:</strong> <?= h($schedule['source']) ?> → <?= h($schedule['destination']) ?><br/>
      <strong>Date:</strong> <?= h($schedule['date']) ?><br/>
      <strong>Departure:</strong> <?= h($schedule['departure_time']) ?> | <strong>Arrival:</strong> <?= h($schedule['arrival_time']) ?><br/>
      <strong>Bus:</strong> <?= h($schedule['bus_number']) ?> (<?= h($schedule['bus_type']) ?>)<br/>
      <strong>Seat:</strong> <?= (int)$seat_number ?><?= $usedAutoSeat ? ' (auto-assigned)' : '' ?><br/>
      <strong>Estimated Fare:</strong> <span class="fare-badge"><?= h(lebanease_fare_label($schedule['distance'])) ?></span>
    </p>
    <div class="h-12"></div><div class="flex gap-10 flex-wrap"><a class="btn btn-primary" href="my_reservations.php">My Reservations</a><a class="btn btn-ghost" href="search.php">Book Another Trip</a></div>
  </div>
<?php else: ?>
  <div class="card glass"><h3>Login Required</h3><p class="muted">Please log in as a passenger before confirming a reservation.</p><div class="h-12"></div><a class="btn btn-primary" href="login.php?next=<?= urlencode('seats.php?schedule_id='.(int)$schedule_id) ?>">Login</a></div>
<?php endif; ?>
</div></main>
</body></html>

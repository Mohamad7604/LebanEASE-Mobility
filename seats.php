<?php
require "db/config.php";
date_default_timezone_set('Asia/Beirut');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;

$schedule = null;
$reservedSeats = [];
$capacity = 0;

if ($schedule_id > 0) {
    // 1) Get schedule details from VIEW (instead of joins)
    $stmt = $pdo->prepare("
        SELECT
          Schedule_ID     AS schedule_id,
          Date            AS date,
          Departure_Time  AS departure_time,
          Arrival_Time    AS arrival_time,
          Bus_Number      AS bus_number,
          Bus_Type        AS bus_type,
          Capacity        AS capacity,
          Source          AS source,
          Destination     AS destination,
          Distance        AS distance,
          Trip_Status     AS trip_status,
          Bus_Status      AS bus_status
        FROM v_schedule_overview
        WHERE Schedule_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$schedule_id]);
    $schedule = $stmt->fetch();

    if ($schedule) {
        $capacity = (int)$schedule['capacity'];

        // 2) Get reserved seats for this schedule
        $stmt2 = $pdo->prepare("SELECT Seat_Number FROM Reservation WHERE Schedule_ID = ? AND Status = 'Active'");
        $stmt2->execute([$schedule_id]);
        $reservedSeats = array_map('intval', $stmt2->fetchAll(PDO::FETCH_COLUMN));
    }
}

// Trip completion check
$isCompleted = false;
if ($schedule) {
    $dep = new DateTime($schedule['date'] . ' ' . $schedule['departure_time']);
    $arr = new DateTime($schedule['date'] . ' ' . $schedule['arrival_time']);

    // If arrival <= departure, assume arrival is next day (overnight trip)
    if ($arr <= $dep) {
        $arr->modify('+1 day');
    }

    $now = new DateTime();
    if ($now >= $arr || ($schedule['trip_status'] ?? 'Scheduled') !== 'Scheduled' || ($schedule['bus_status'] ?? 'Active') !== 'Active') {
        $isCompleted = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Select Seats | LebanEASE</title>
    <link rel="stylesheet" href="css/style.css" />
</head>
<body>

<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('search'); ?>

<main class="section">
  <div class="container">

    <h2 class="section-title">Select Your Seat</h2>

    <?php if ($schedule_id <= 0): ?>
      <div class="card glass">
        <h3>No schedule selected</h3>
        <p class="muted">Please go back to Search and choose a schedule first.</p>
        <div style="height:12px;"></div>
        <a class="btn btn-primary" href="search.php">Go to Search</a>
      </div>

    <?php elseif (!$schedule): ?>
      <div class="card glass">
        <h3>Schedule not found</h3>
        <p class="muted">This schedule ID does not exist in the database.</p>
        <div style="height:12px;"></div>
        <a class="btn btn-primary" href="search.php">Go to Search</a>
      </div>

    <?php else: ?>
      <!-- Schedule details -->
      <div class="card glass">
        <div style="display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap;">
          <div>
            <h3 style="margin-bottom:8px;">
              <?= h($schedule['source']) ?> → <?= h($schedule['destination']) ?>
            </h3>

            <p class="muted" style="line-height:1.75;">
              <strong>Date:</strong> <?= h($schedule['date']) ?><br/>
              <strong>Departure:</strong> <?= h($schedule['departure_time']) ?> |
              <strong>Arrival:</strong> <?= h($schedule['arrival_time']) ?><br/>
              <strong>Bus:</strong> <?= h($schedule['bus_number']) ?> (<?= h($schedule['bus_type']) ?>)<br/>
              <strong>Distance:</strong> <?= h($schedule['distance']) ?> km<br/>
              <strong>Estimated Fare:</strong> <span class="fare-badge"><?= h(lebanease_fare_label($schedule['distance'])) ?></span><br/>
              <strong>Status:</strong> <span class="status-pill status-<?= strtolower($schedule['trip_status']) ?>"><?= h($schedule['trip_status']) ?></span><br/>
              <strong>Available Seats:</strong> <?= max(0, $capacity - count($reservedSeats)) ?>
            </p>
          </div>

          <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
            <?php
              $mapUrl = "map.php?from=" . urlencode($schedule['source']) .
                        "&to=" . urlencode($schedule['destination']) .
                        "&schedule_id=" . (int)$schedule_id;
            ?>
            <a class="btn btn-ghost" href="<?= h($mapUrl) ?>">View Route</a>
            <a class="btn btn-ghost" href="search.php">Back to Search</a>
          </div>
        </div>
      </div>

      <?php if ($isCompleted): ?>
        <div class="card glass">
          <h3>Reservation Closed</h3>
          <p class="muted">This trip is completed, cancelled, or not available. Seat reservation is closed.</p>
          <div style="height:12px;"></div>
          <a class="btn btn-primary" href="search.php">Book Another Trip</a>
        </div>
      <?php else: ?>
        <div class="card glass">
          <div class="seat-layout">
            <div class="bus-front">
              <div class="driver">Driver</div>
            </div>

            <div class="bus-grid">
              <?php
                $seatNumber = 1;
                $rows = (int)ceil($capacity / 4);

                for ($r = 1; $r <= $rows; $r++) {

                  // left 2 seats
                  for ($i = 0; $i < 2; $i++) {
                    if ($seatNumber <= $capacity) {
                      if (in_array($seatNumber, $reservedSeats, true)) {
                        echo "<div class='seat reserved' title='Reserved'>{$seatNumber}</div>";
                      } else {
                        echo "<div class='seat available' data-seat='{$seatNumber}' title='Available'>{$seatNumber}</div>";
                      }
                      $seatNumber++;
                    }
                  }

                  echo "<div class='aisle'></div>";

                  // right 2 seats
                  for ($i = 0; $i < 2; $i++) {
                    if ($seatNumber <= $capacity) {
                      if (in_array($seatNumber, $reservedSeats, true)) {
                        echo "<div class='seat reserved' title='Reserved'>{$seatNumber}</div>";
                      } else {
                        echo "<div class='seat available' data-seat='{$seatNumber}' title='Available'>{$seatNumber}</div>";
                      }
                      $seatNumber++;
                    }
                  }
                }
              ?>
            </div>

            <div class="legend">
              <div><span class="legend-box available"></span> Available</div>
              <div><span class="legend-box reserved"></span> Reserved</div>
              <div><span class="legend-box selected"></span> Selected</div>
            </div>

            <?php if (empty($_SESSION['passenger_id'])): ?>
              <div class="alert" style="margin-top:12px;">
                <strong>Login required:</strong> You can view seats, but you must log in as a passenger before confirming a reservation.
              </div>
              <div class="flex gap-10 flex-wrap" style="margin-top:12px;">
                <a class="btn btn-primary" href="login.php?next=<?= urlencode('seats.php?schedule_id='.(int)$schedule_id) ?>">Login to Reserve</a>
                <a class="btn btn-ghost" href="register.php?next=<?= urlencode('seats.php?schedule_id='.(int)$schedule_id) ?>">Create Account</a>
              </div>
            <?php else: ?>
              <p id="selectedSeat" class="muted" style="margin-top:10px;">Selected Seat: <strong>None</strong></p>

              <form id="reservationForm" action="confirm.php" method="POST" style="margin-top:10px;">
                <input type="hidden" name="schedule_id" value="<?= (int)$schedule_id ?>">
                <input type="hidden" name="seat_number" id="seat_number">

                <button id="confirmBtn" type="submit" class="btn btn-primary" disabled>
                  Confirm Selected Seat
                </button>
              </form>

              <form action="confirm.php" method="POST" style="margin-top:10px;">
                <input type="hidden" name="schedule_id" value="<?= (int)$schedule_id ?>">
                <input type="hidden" name="seat_number" value="0">
                <button type="submit" class="btn btn-secondary">
                  Auto Assign Next Available Seat
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</main>

<script src="js/seats.js"></script>
</body>
</html>

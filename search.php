<?php
require "db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$sources = $pdo->query("SELECT DISTINCT Source FROM Route WHERE Status='Active' ORDER BY Source")->fetchAll(PDO::FETCH_COLUMN);
$destinations = $pdo->query("SELECT DISTINCT Destination FROM Route WHERE Status='Active' ORDER BY Destination")->fetchAll(PDO::FETCH_COLUMN);

$source = trim($_GET['source'] ?? '');
$destination = trim($_GET['destination'] ?? '');
$date = trim($_GET['date'] ?? '');
$errors = [];
$schedules = [];
$searched = array_key_exists('source', $_GET) || array_key_exists('destination', $_GET) || array_key_exists('date', $_GET);

if ($searched && ($source === '' || $destination === '' || $date === '')) {
    $errors[] = "Source, destination, and travel date are required.";
}
if ($date !== '' && $date < date('Y-m-d')) {
    $errors[] = "Travel date cannot be earlier than today.";
}

if ($searched && empty($errors)) {
    $sql = "
        SELECT *
        FROM v_schedule_overview
        WHERE Schedule_Status = 'Scheduled'
          AND Bus_Status = 'Active'
          AND (Driver_Status IS NULL OR Driver_Status = 'Active')
          AND Source = ?
          AND Destination = ?
          AND Date = ?
        ORDER BY Date ASC, Departure_Time ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$source,$destination,$date]);
    $schedules = $stmt->fetchAll();
}

function is_trip_completed(array $s): bool {
  try {
    $dep = new DateTime($s['Date'].' '.$s['Departure_Time']);
    $arr = new DateTime($s['Date'].' '.$s['Arrival_Time']);
    if ($arr <= $dep) $arr->modify('+1 day');
    return (new DateTime()) >= $arr;
  } catch(Exception $e) { return false; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><title>Search Trips | LebanEASE</title><link rel="stylesheet" href="css/style.css" /></head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('search'); ?>
<main class="section"><div class="container">
<h2 class="section-title">Find Your Trip</h2>
<p class="muted">Choose source, destination, and date. All three fields are required before the system searches.</p>

<?php if ($errors): ?><div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px; margin-top:8px;"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div></div><div class="h-12"></div><?php endif; ?>

<div class="card glass">
<form method="GET" class="form-grid">
  <div class="control"><label>From</label><select name="source" required><option value="">Select source</option><?php foreach($sources as $s): ?><option value="<?= h($s) ?>" <?= $s===$source?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
  <div class="control"><label>To</label><select name="destination" required><option value="">Select destination</option><?php foreach($destinations as $d): ?><option value="<?= h($d) ?>" <?= $d===$destination?'selected':'' ?>><?= h($d) ?></option><?php endforeach; ?></select></div>
  <div class="control"><label>Date</label><input type="date" name="date" min="<?= h(date('Y-m-d')) ?>" value="<?= h($date) ?>" required /></div>
  <div class="control span-all flex gap-10 flex-wrap"><button class="btn btn-primary" type="submit">Search</button><a class="btn btn-ghost" href="search.php">Clear</a></div>
</form>
</div>
<div class="h-12"></div>

<?php if ($searched && empty($errors) && empty($schedules)): ?><div class="card glass"><p class="muted">No trips found for your selection.</p></div><?php endif; ?>

<?php if (!empty($schedules)): ?>
<div class="card glass"><h3>Available Trips</h3><p class="muted tiny">Choose a schedule to view the map, select seats, or use automatic seat assignment.</p></div><div class="h-12"></div>
<?php foreach ($schedules as $s): ?>
<?php
$completed = is_trip_completed($s);
$dep = substr((string)$s['Departure_Time'], 0, 5);
$arr = substr((string)$s['Arrival_Time'], 0, 5);
$available = (int)$s['Available_Seats'];
?>
<div class="card glass">
  <div class="flex justify-between items-center flex-wrap gap-10">
    <div>
      <h3 style="margin:0;"><?= h($s['Source']) ?> → <?= h($s['Destination']) ?></h3>
      <p class="muted" style="margin-top:6px; line-height:1.75;">
        <strong>Date:</strong> <?= h($s['Date']) ?> | <strong>Time:</strong> <?= h($dep) ?> → <?= h($arr) ?><br/>
        <strong>Bus:</strong> <?= h($s['Bus_Number']) ?> (<?= h($s['Bus_Type']) ?>) — <strong>Driver:</strong> <?= h($s['Driver_Name'] ?? 'Not assigned') ?><br/>
        <strong>Status:</strong> <span class="status-pill status-<?= strtolower($s['Trip_Status']) ?>"><?= h($s['Trip_Status']) ?></span><br/>
        <strong>Seats:</strong> <?= $available ?> available out of <?= (int)$s['Capacity'] ?> | <strong>Estimated Fare:</strong> <span class="fare-badge"><?= h(lebanease_fare_label($s['Distance'])) ?></span>
      </p>
      <?php if ($completed): ?><p class="tiny"><strong>✅ Trip Completed</strong></p><?php elseif ($available <= 0): ?><p class="tiny"><strong>Fully booked</strong></p><?php endif; ?>
    </div>
    <div class="flex gap-10 flex-wrap items-center">
      <a class="btn btn-ghost" href="map.php?schedule_id=<?= (int)$s['Schedule_ID'] ?>">View Map</a>
      <?php if ($completed || $available <= 0): ?>
        <button class="btn btn-disabled" disabled>Reserve</button>
      <?php else: ?>
        <a class="btn btn-secondary" href="seats.php?schedule_id=<?= (int)$s['Schedule_ID'] ?>">View / Select Seat</a>
        <?php if (!empty($_SESSION['passenger_id'])): ?>
          <form method="POST" action="confirm.php" style="display:inline;">
            <input type="hidden" name="schedule_id" value="<?= (int)$s['Schedule_ID'] ?>">
            <input type="hidden" name="seat_number" value="0">
            <button class="btn btn-primary" type="submit">Auto Assign Seat</button>
          </form>
        <?php else: ?>
          <a class="btn btn-primary" href="login.php?next=<?= urlencode('seats.php?schedule_id='.(int)$s['Schedule_ID']) ?>">Login to Reserve</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div><div class="h-12"></div>
<?php endforeach; ?>
<?php endif; ?>
</div></main>
</body></html>

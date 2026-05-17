<?php
require "db/config.php";

$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
$scheduleId = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;

$scheduleDate = '';
$departureTime = '';
$arrivalTime = '';

if ($scheduleId > 0) {
    $stmt = $pdo->prepare("
    SELECT Date AS date,
           Departure_Time AS departure_time,
           Arrival_Time AS arrival_time,
           Source AS source,
           Destination AS destination
    FROM v_schedule_overview
    WHERE Schedule_ID = ?
    LIMIT 1
");

    $stmt->execute([$scheduleId]);
    $row = $stmt->fetch();

    if ($row) {
        $scheduleDate   = $row['date'];
        $departureTime  = $row['departure_time'];
        $arrivalTime    = $row['arrival_time'];

        // Use DB source/destination if you want them to be the source of truth:
        if ($from === '') $from = $row['source'];
        if ($to === '')   $to   = $row['destination'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Map | LebanEASE</title>

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.css">

    <!-- Your theme -->
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<!-- Navbar -->
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('map'); ?>

<!-- Page Layout -->
<div class="map-layout">
  <aside class="card glass map-panel">
    <h3 style="margin-bottom:10px;">Trip Details</h3>

    <p class="muted" style="line-height:1.8;">
      <strong>From:</strong> <?= htmlspecialchars($from) ?><br>
      <strong>To:</strong> <?= htmlspecialchars($to) ?><br>
      <strong>Schedule ID:</strong> <?= (int)$scheduleId ?>
    </p>

    <div style="height:10px;"></div>

    <p id="distance" class="muted">Distance: -</p>
    <p id="duration" class="muted">Duration: -</p>

    <div style="height:14px;"></div>

    <a id="reserveBtn" class="btn btn-primary w-full" href="#">
      Reserve Seats
    </a>

    <p id="geoStatus" class="tiny muted" style="margin-top:10px;"></p>
  </aside>

  <div id="map" class="map-canvas"></div>
</div>

<script>
  const fromPlace = <?= json_encode($from) ?>;
  const toPlace   = <?= json_encode($to) ?>;
  const scheduleId = <?= json_encode($scheduleId) ?>;

  // NEW: schedule timing info
  const scheduleDate  = <?= json_encode($scheduleDate) ?>;      // "YYYY-MM-DD"
  const departureTime = <?= json_encode($departureTime) ?>;     // "HH:MM:SS"
  const arrivalTime   = <?= json_encode($arrivalTime) ?>;       // "HH:MM:SS"
</script>


<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.js"></script>

<script src="js/map.js"></script>
</body>
</html>

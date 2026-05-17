<?php
require "admin/auth.php";
require_admin();
require "db/config.php";
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

date_default_timezone_set('Asia/Beirut');

// 1) JOIN query #1: Schedule overview (Bus + Route + Schedule)
$q1 = $pdo->query("
  SELECT 
    bs.Schedule_ID AS schedule_id,
    bs.Date AS date,
    bs.Departure_Time AS departure_time,
    bs.Arrival_Time AS arrival_time,
    b.Bus_Number AS bus_number,
    b.Type AS bus_type,
    bs.Status AS status,
    r.Source AS source,
    r.Destination AS destination,
    r.Distance AS distance
  FROM Bus_Schedule bs
  JOIN Bus b ON bs.Bus_ID = b.Bus_ID
  JOIN Route r ON bs.Route_ID = r.Route_ID
  ORDER BY bs.Date DESC, bs.Departure_Time ASC
  LIMIT 20
")->fetchAll();

// 2) JOIN query #2: Reservation + Passenger + Route + Schedule
$q2 = $pdo->query("
  SELECT
    res.Reservation_ID AS reservation_id,
    res.Booking_Date AS booking_date,
    res.Status AS reservation_status,
    res.Seat_Number AS seat_number,
    p.First_Name AS first_name,
    p.Last_Name AS last_name,
    p.Email AS email,
    bs.Date AS trip_date,
    bs.Departure_Time AS departure_time,
    r.Source AS source,
    r.Destination AS destination,
    r.Distance AS distance
  FROM Reservation res
  JOIN Passenger p ON res.Passenger_ID = p.Passenger_ID
  JOIN Bus_Schedule bs ON res.Schedule_ID = bs.Schedule_ID
  JOIN Route r ON bs.Route_ID = r.Route_ID
  ORDER BY res.Reservation_ID DESC
  LIMIT 20
")->fetchAll();

// 3) Nested query: Buses whose maintenance total cost is above average bus maintenance cost
$q3 = $pdo->query("
  SELECT
    b.Bus_ID AS bus_id,
    b.Bus_Number AS bus_number,
    IFNULL(SUM(m.Cost), 0) AS total_maintenance_cost
  FROM Bus b
  LEFT JOIN Bus_Maintenance m ON m.Bus_ID = b.Bus_ID
  GROUP BY b.Bus_ID, b.Bus_Number
  HAVING total_maintenance_cost >
    (
      SELECT AVG(bus_total) FROM (
        SELECT IFNULL(SUM(m2.Cost), 0) AS bus_total
        FROM Bus b2
        LEFT JOIN Bus_Maintenance m2 ON m2.Bus_ID = b2.Bus_ID
        GROUP BY b2.Bus_ID
      ) t
    )
  ORDER BY total_maintenance_cost DESC
")->fetchAll();

// 4) Aggregate with GROUP BY: active reservations and estimated fare value per route
$q4 = $pdo->query("
  SELECT
    r.Source AS source,
    r.Destination AS destination,
    COUNT(res.Reservation_ID) AS active_reservations,
    SUM(GREATEST(3.00, 2.00 + (r.Distance * 0.15))) AS estimated_value
  FROM Reservation res
  JOIN Bus_Schedule bs ON res.Schedule_ID = bs.Schedule_ID
  JOIN Route r ON bs.Route_ID = r.Route_ID
  WHERE res.Status = 'Active'
  GROUP BY r.Source, r.Destination
  ORDER BY active_reservations DESC, estimated_value DESC
  LIMIT 15
")->fetchAll();

// 5) Set operation (UNION): All unique served locations (sources + destinations)
$q5 = $pdo->query("
  SELECT DISTINCT Source AS location FROM Route
  UNION
  SELECT DISTINCT Destination AS location FROM Route
  ORDER BY location
")->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reports | LebanEASE</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>

<?php require_once __DIR__ . "/admin/nav.php"; render_admin_nav('reports'); ?>

<main class="section">
  <div class="container">
    <h2 class="section-title">Admin Reports</h2>
    <p class="muted" style="margin-bottom:14px;">
      Admin-only operational reports for schedules, reservations, maintenance, and locations. Payment processing was removed from this MVP; fare values shown here are estimated trip fares only.
    </p>

    <div class="card glass">
      <h3>1) Schedule Overview (JOIN)</h3>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>ID</th><th>Date</th><th>Depart</th><th>Arrive</th>
              <th>Bus</th><th>Type</th><th>From</th><th>To</th><th>KM</th><th>Status</th><th>Fare</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($q1 as $row): ?>
              <tr>
                <td><?= (int)$row['schedule_id'] ?></td>
                <td><?= h($row['date']) ?></td>
                <td><?= h($row['departure_time']) ?></td>
                <td><?= h($row['arrival_time']) ?></td>
                <td><?= h($row['bus_number']) ?></td>
                <td><?= h($row['bus_type']) ?></td>
                <td><?= h($row['source']) ?></td>
                <td><?= h($row['destination']) ?></td>
                <td><?= h($row['distance']) ?></td>
                <td><span class="status-pill status-<?= strtolower($row['status']) ?>"><?= h($row['status']) ?></span></td>
                <td><span class="fare-badge"><?= h(lebanease_fare_label($row['distance'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card glass">
      <h3>2) Latest Reservations (JOIN)</h3>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Res ID</th><th>Passenger</th><th>Email</th><th>Trip</th>
              <th>Seat</th><th>Status</th><th>Estimated Fare</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($q2 as $row): ?>
              <tr>
                <td><?= (int)$row['reservation_id'] ?></td>
                <td><?= h($row['first_name']." ".$row['last_name']) ?></td>
                <td><?= h($row['email']) ?></td>
                <td><?= h($row['source']." → ".$row['destination']." (".$row['trip_date'].")") ?></td>
                <td><?= (int)$row['seat_number'] ?></td>
                <td><span class="status-pill status-<?= strtolower($row['reservation_status']) ?>"><?= h($row['reservation_status']) ?></span></td>
                <td><span class="fare-badge"><?= h(lebanease_fare_label($row['distance'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card glass">
      <h3>3) Buses With Above-Average Maintenance Cost (Nested Query)</h3>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Bus ID</th><th>Bus Number</th><th>Total Maintenance</th></tr>
          </thead>
          <tbody>
            <?php foreach($q3 as $row): ?>
              <tr>
                <td><?= (int)$row['bus_id'] ?></td>
                <td><?= h($row['bus_number']) ?></td>
                <td>$<?= number_format((float)$row['total_maintenance_cost'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card glass">
      <h3>4) Active Reservations by Route (GROUP BY Aggregate)</h3>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Route</th><th>Active Reservations</th><th>Estimated Fare Value</th></tr>
          </thead>
          <tbody>
            <?php foreach($q4 as $row): ?>
              <tr>
                <td><?= h($row['source']." → ".$row['destination']) ?></td>
                <td><?= (int)$row['active_reservations'] ?></td>
                <td>$<?= number_format((float)$row['estimated_value'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card glass">
      <h3>5) All Served Locations (UNION Set Operation)</h3>
      <p class="muted tiny">Total locations: <?= count($q5) ?></p>
      <div class="chips">
        <?php foreach($q5 as $loc): ?>
          <span class="chip"><?= h($loc) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</main>

</body>
</html>

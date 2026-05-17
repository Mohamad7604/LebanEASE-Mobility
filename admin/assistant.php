<?php
require "auth.php";
require_admin();
require "../db/config.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function one(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
function all_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

$totalActiveReservations = one($pdo, "SELECT COUNT(*) FROM Reservation WHERE Status='Active'");
$totalCancelledReservations = one($pdo, "SELECT COUNT(*) FROM Reservation WHERE Status='Cancelled'");
$maintenanceBuses = one($pdo, "SELECT COUNT(*) FROM Bus WHERE Status IN ('Maintenance','Unavailable','Offline')");
$openMaintenance = one($pdo, "SELECT COUNT(*) FROM Bus_Maintenance WHERE Maintenance_Status='Open'");
$scheduledTrips = one($pdo, "SELECT COUNT(*) FROM v_schedule_overview WHERE Trip_Status='Scheduled'");
$lowSeatTripsCount = one($pdo, "SELECT COUNT(*) FROM v_schedule_overview WHERE Trip_Status='Scheduled' AND Available_Seats <= 5");

$busyRoutes = all_rows($pdo, "
    SELECT r.Source, r.Destination, COUNT(res.Reservation_ID) AS reservations
    FROM Reservation res
    JOIN Bus_Schedule bs ON res.Schedule_ID = bs.Schedule_ID
    JOIN Route r ON bs.Route_ID = r.Route_ID
    WHERE res.Status='Active'
    GROUP BY r.Route_ID, r.Source, r.Destination
    ORDER BY reservations DESC
    LIMIT 5
");

$almostFullTrips = all_rows($pdo, "
    SELECT Schedule_ID, Date, Departure_Time, Source, Destination, Bus_Number, Capacity, Reserved_Seats, Available_Seats
    FROM v_schedule_overview
    WHERE Trip_Status='Scheduled' AND Available_Seats <= 5
    ORDER BY Date ASC, Departure_Time ASC
    LIMIT 5
");

$maintenanceAlerts = all_rows($pdo, "
    SELECT b.Bus_Number, b.Status, bm.Date, bm.Description, bm.Maintenance_Status
    FROM Bus b
    LEFT JOIN Bus_Maintenance bm ON b.Bus_ID = bm.Bus_ID AND bm.Maintenance_Status='Open'
    WHERE b.Status IN ('Maintenance','Unavailable','Offline') OR bm.Maintenance_Status='Open'
    ORDER BY FIELD(b.Status,'Maintenance','Unavailable','Offline','Active'), bm.Date DESC
    LIMIT 6
");

$driverAlerts = all_rows($pdo, "
    SELECT First_Name, Last_Name, Driver_Status
    FROM Driver
    WHERE Driver_Status <> 'Active'
    ORDER BY Driver_Status, Last_Name
    LIMIT 5
");

$cancellationHotspots = all_rows($pdo, "
    SELECT r.Source, r.Destination, COUNT(res.Reservation_ID) AS cancelled_count
    FROM Reservation res
    JOIN Bus_Schedule bs ON res.Schedule_ID = bs.Schedule_ID
    JOIN Route r ON bs.Route_ID = r.Route_ID
    WHERE res.Status='Cancelled'
    GROUP BY r.Route_ID, r.Source, r.Destination
    HAVING cancelled_count > 0
    ORDER BY cancelled_count DESC
    LIMIT 5
");

$utilizationRows = all_rows($pdo, "
    SELECT Schedule_ID, Date, Departure_Time, Source, Destination, Bus_Number, Capacity, Reserved_Seats,
           ROUND((Reserved_Seats / NULLIF(Capacity,0)) * 100, 1) AS utilization
    FROM v_schedule_overview
    WHERE Trip_Status='Scheduled'
    ORDER BY utilization DESC, Reserved_Seats DESC
    LIMIT 5
");

$question = trim($_POST['question'] ?? $_GET['ask'] ?? '');
$answerTitle = 'Ask the Smart Operations Agent';
$answerLines = [];

if ($question !== '') {
    $q = strtolower($question);
    if (str_contains($q, 'route') || str_contains($q, 'busy') || str_contains($q, 'demand')) {
        $answerTitle = 'Route Demand Analysis';
        if ($busyRoutes) {
            foreach ($busyRoutes as $r) {
                $answerLines[] = $r['Source'].' → '.$r['Destination'].' has '.$r['reservations'].' active reservation(s).';
            }
            $answerLines[] = 'Recommendation: add another trip for the highest-demand route if seats become limited.';
        } else {
            $answerLines[] = 'No active reservation demand was detected yet.';
        }
    } elseif (str_contains($q, 'bus') || str_contains($q, 'maintenance') || str_contains($q, 'fleet')) {
        $answerTitle = 'Fleet and Maintenance Analysis';
        if ($maintenanceAlerts) {
            foreach ($maintenanceAlerts as $m) {
                $line = $m['Bus_Number'].' is currently '.$m['Status'];
                if (!empty($m['Description'])) { $line .= ' — '.$m['Description']; }
                $answerLines[] = $line.'.';
            }
            $answerLines[] = 'Recommendation: do not assign buses under maintenance, unavailable, or offline status to new schedules.';
        } else {
            $answerLines[] = 'No fleet maintenance risk was detected. All buses look usable based on current records.';
        }
    } elseif (str_contains($q, 'seat') || str_contains($q, 'full') || str_contains($q, 'reservation')) {
        $answerTitle = 'Reservation and Seat Availability Analysis';
        if ($almostFullTrips) {
            foreach ($almostFullTrips as $t) {
                $answerLines[] = 'Trip #'.$t['Schedule_ID'].' '.$t['Source'].' → '.$t['Destination'].' on '.$t['Date'].' has only '.$t['Available_Seats'].' seat(s) left.';
            }
            $answerLines[] = 'Recommendation: monitor these trips closely and consider adding capacity on high-demand routes.';
        } else {
            $answerLines[] = 'No scheduled trip is close to full capacity right now.';
        }
    } elseif (str_contains($q, 'driver')) {
        $answerTitle = 'Driver Availability Analysis';
        if ($driverAlerts) {
            foreach ($driverAlerts as $d) {
                $answerLines[] = $d['First_Name'].' '.$d['Last_Name'].' is marked as '.$d['Driver_Status'].'.';
            }
            $answerLines[] = 'Recommendation: avoid assigning unavailable or inactive drivers to new trips.';
        } else {
            $answerLines[] = 'No driver availability issue was detected.';
        }
    } else {
        $answerTitle = 'General Operations Summary';
        $answerLines[] = 'There are '.$scheduledTrips.' scheduled trip(s), '.$totalActiveReservations.' active reservation(s), and '.$lowSeatTripsCount.' trip(s) close to full capacity.';
        $answerLines[] = 'There are '.$maintenanceBuses.' bus(es) marked as Maintenance, Unavailable, or Offline.';
        $answerLines[] = 'Recommendation: check low-seat trips first, then review open maintenance records before adding new schedules.';
    }
}

$agentScore = 100;
if ($maintenanceBuses > 0) $agentScore -= 20;
if ($openMaintenance > 0) $agentScore -= 15;
if ($lowSeatTripsCount > 0) $agentScore -= 10;
if ($totalCancelledReservations > 3) $agentScore -= 10;
$agentScore = max(30, $agentScore);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Smart Operations Agent | LebanEASE</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    .agent-hero{display:grid;grid-template-columns:1.2fr .8fr;gap:14px;align-items:stretch;}
    .agent-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(138,211,255,.12);border:1px solid rgba(138,211,255,.22);font-weight:900;font-size:13px;}
    .agent-score{font-size:48px;font-weight:1000;line-height:1;margin:10px 0;}
    .agent-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:14px;}
    .agent-card{position:relative;overflow:hidden;}
    .agent-card:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at top right,rgba(138,211,255,.13),transparent 40%);pointer-events:none;}
    .agent-number{font-size:30px;font-weight:1000;margin-bottom:4px;}
    .insight-list{display:grid;gap:10px;margin-top:12px;}
    .insight-item{padding:12px;border-radius:16px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);}
    .chat-box{display:grid;grid-template-columns:1fr auto;gap:10px;margin-top:12px;}
    .quick-prompts{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;}
    .recommendation{border-left:4px solid rgba(138,211,255,.7);padding-left:12px;}
    @media(max-width:950px){.agent-hero,.agent-grid{grid-template-columns:1fr}.chat-box{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('assistant'); ?>

<main class="section">
  <div class="container">
    <div class="agent-hero">
      <div class="card glass">
        <span class="agent-badge">Smart Operations Agent</span>
        <h2 class="section-title" style="margin-top:12px;">LebanEASE AI-Style Admin Assistant</h2>
        <p class="muted">This assistant reads the current reservations, trips, bus statuses, and maintenance records, then gives the admin automatic operational recommendations.</p>
        <div class="quick-prompts">
          <a class="btn btn-primary" href="assistant_ai.php">Generate Real AI Summary</a>
          <a class="btn btn-ghost" href="assistant.php?ask=busy routes">Busy routes</a>
          <a class="btn btn-ghost" href="assistant.php?ask=seat availability">Seat risks</a>
          <a class="btn btn-ghost" href="assistant.php?ask=maintenance buses">Maintenance risks</a>
          <a class="btn btn-ghost" href="assistant.php?ask=driver availability">Driver issues</a>
        </div>
      </div>
      <div class="card glass agent-card">
        <p class="muted tiny">Operations Health Score</p>
        <div class="agent-score"><?= (int)$agentScore ?>%</div>
        <p class="muted tiny">Calculated from maintenance status, open issues, low-seat trips, and cancelled reservations.</p>
      </div>
    </div>

    <div class="agent-grid">
      <div class="card glass agent-card"><div class="agent-number"><?= (int)$scheduledTrips ?></div><div class="muted tiny">Scheduled Trips</div></div>
      <div class="card glass agent-card"><div class="agent-number"><?= (int)$totalActiveReservations ?></div><div class="muted tiny">Active Reservations</div></div>
      <div class="card glass agent-card"><div class="agent-number"><?= (int)$lowSeatTripsCount ?></div><div class="muted tiny">Almost Full Trips</div></div>
      <div class="card glass agent-card"><div class="agent-number"><?= (int)$maintenanceBuses ?></div><div class="muted tiny">Fleet Alerts</div></div>
    </div>

    <div class="h-12"></div>
    <div class="admin-grid-2">
      <div class="card glass">
        <h3>Ask the Assistant</h3>
        <p class="muted tiny">Try: “Which routes are busy?”, “Which buses need attention?”, or “Are there reservation problems?”</p>
        <form method="post" class="chat-box">
          <input class="admin-search" type="text" name="question" placeholder="Ask about routes, buses, seats, drivers..." value="<?= h($question) ?>" />
          <button class="btn btn-primary" type="submit">Ask</button>
        </form>

        <div class="h-12"></div>
        <h3><?= h($answerTitle) ?></h3>
        <div class="insight-list">
          <?php if ($answerLines): ?>
            <?php foreach ($answerLines as $line): ?>
              <div class="insight-item recommendation"><?= h($line) ?></div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="insight-item recommendation">Ask a question or click one of the quick prompts above to generate an operations insight.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card glass">
        <h3>Automatic Insights</h3>
        <div class="insight-list">
          <?php if ($almostFullTrips): ?>
            <?php foreach ($almostFullTrips as $t): ?>
              <div class="insight-item">Trip #<?= (int)$t['Schedule_ID'] ?>, <?= h($t['Source'].' → '.$t['Destination']) ?>, has only <b><?= (int)$t['Available_Seats'] ?></b> seat(s) left.</div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="insight-item">No trip is close to full capacity right now.</div>
          <?php endif; ?>

          <?php if ($maintenanceAlerts): ?>
            <?php foreach ($maintenanceAlerts as $m): ?>
              <div class="insight-item"><b><?= h($m['Bus_Number']) ?></b> needs attention. Current status: <?= h($m['Status']) ?><?= !empty($m['Description']) ? ' — '.h($m['Description']) : '' ?>.</div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="insight-item">No open maintenance issue detected.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="h-12"></div>
    <div class="admin-grid-2">
      <div class="card glass">
        <h3>Top Route Demand</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Route</th><th>Active Reservations</th><th>Agent Suggestion</th></tr></thead>
            <tbody>
              <?php foreach($busyRoutes as $r): ?>
                <tr><td><?= h($r['Source'].' → '.$r['Destination']) ?></td><td><?= (int)$r['reservations'] ?></td><td>Monitor demand</td></tr>
              <?php endforeach; ?>
              <?php if(empty($busyRoutes)): ?><tr><td colspan="3" class="muted">No active reservations yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card glass">
        <h3>Trip Utilization</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Trip</th><th>Route</th><th>Bus</th><th>Usage</th></tr></thead>
            <tbody>
              <?php foreach($utilizationRows as $u): ?>
                <tr><td>#<?= (int)$u['Schedule_ID'] ?><br><span class="tiny muted"><?= h($u['Date'].' '.substr($u['Departure_Time'],0,5)) ?></span></td><td><?= h($u['Source'].' → '.$u['Destination']) ?></td><td><?= h($u['Bus_Number']) ?></td><td><?= h($u['utilization']) ?>%</td></tr>
              <?php endforeach; ?>
              <?php if(empty($utilizationRows)): ?><tr><td colspan="4" class="muted">No scheduled trips found.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>

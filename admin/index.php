<?php
require "auth.php";
require_admin();
require "../db/config.php";
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$cards = [
  ['Total Buses', 'SELECT COUNT(*) FROM Bus'],
  ['Available Buses', "SELECT COUNT(*) FROM Bus WHERE Status='Active'"],
  ['Total Drivers', 'SELECT COUNT(*) FROM Driver'],
  ['Scheduled Trips', "SELECT COUNT(*) FROM Bus_Schedule WHERE Status='Scheduled'"],
  ['Active Reservations', "SELECT COUNT(*) FROM Reservation WHERE Status='Active'"],
  ['Under Maintenance', "SELECT COUNT(*) FROM Bus WHERE Status='Maintenance'"]
];
$counts = [];
foreach ($cards as [$label,$sql]) {
  try { $counts[$label] = (int)$pdo->query($sql)->fetchColumn(); }
  catch(Exception $e) { $counts[$label] = 0; }
}

$latestSchedules = [];
try {
  $latestSchedules = $pdo->query("SELECT Schedule_ID, Date, Departure_Time, Arrival_Time, Bus_Number, Source, Destination, Available_Seats, Trip_Status FROM v_schedule_overview ORDER BY Date DESC, Departure_Time DESC LIMIT 6")->fetchAll();
} catch (Exception $e) {}

$adminName = $_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><title>Admin Dashboard | LebanEASE</title><link rel="stylesheet" href="../css/style.css" /></head>
<body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('dashboard'); ?>
<main class="section"><div class="container">
<h2 class="section-title">Admin Dashboard</h2>
<p class="muted mb-14">System overview with the summary cards listed in the progress report.</p>
<div class="admin-grid">
<?php foreach ($counts as $label => $value): ?>
  <div class="card glass admin-stat"><div class="admin-stat-num"><?= (int)$value ?></div><div class="admin-stat-label"><?= h($label) ?></div></div>
<?php endforeach; ?>
</div>
<div class="h-12"></div>
<div class="admin-grid-2">
  <div class="card glass"><h3>Management Modules</h3><p class="muted tiny">Use these pages to manage the MVP entities.</p><div class="h-12"></div><div class="flex gap-10 flex-wrap"><a class="btn btn-primary" href="buses.php">Buses</a><a class="btn btn-secondary" href="drivers.php">Drivers</a><a class="btn btn-secondary" href="routes.php">Routes</a><a class="btn btn-secondary" href="schedules.php">Trips</a><a class="btn btn-secondary" href="reservations.php">Reservations</a><a class="btn btn-secondary" href="maintenance.php">Maintenance</a></div><div class="h-12"></div><h3>Admin Tools</h3><p class="muted tiny">Quick access to agents, reports, exports, and health checks.</p><div class="h-12"></div><div class="flex gap-10 flex-wrap"><a class="btn btn-agent" href="operations_agent.php">Operations Agent</a><a class="btn btn-primary" href="assistant.php">Smart Assistant</a><a class="btn btn-secondary" href="analytics.php">Analytics</a><a class="btn btn-secondary" href="system_health.php">System Health</a><a class="btn btn-secondary" href="backup.php">Export / Backup</a><a class="btn btn-secondary" href="dispatch_engine.php">Dispatch Engine</a><a class="btn btn-ghost" href="../reports.php">Reports</a></div></div>
  <div class="card glass"><h3>Latest Trips</h3><p class="muted tiny">Includes seat availability and status.</p><div class="h-12"></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Date</th><th>Trip</th><th>Bus</th><th>Seats</th><th>Status</th></tr></thead><tbody><?php foreach($latestSchedules as $s): ?><tr><td><?= (int)$s['Schedule_ID'] ?></td><td><?= h($s['Date']) ?><br><span class="tiny muted"><?= h(substr($s['Departure_Time'],0,5)) ?> → <?= h(substr($s['Arrival_Time'],0,5)) ?></span></td><td><?= h($s['Source'].' → '.$s['Destination']) ?></td><td><?= h($s['Bus_Number']) ?></td><td><?= (int)$s['Available_Seats'] ?></td><td><span class="status-pill status-<?= strtolower($s['Trip_Status']) ?>"><?= h($s['Trip_Status']) ?></span></td></tr><?php endforeach; ?><?php if(empty($latestSchedules)): ?><tr><td colspan="6" class="muted">No schedules found.</td></tr><?php endif; ?></tbody></table></div></div>
</div>
</div></main>
</body></html>

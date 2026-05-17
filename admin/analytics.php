<?php
require "auth.php";
require_admin();
require "../db/config.php";

date_default_timezone_set('Asia/Beirut');

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money($n) {
    return number_format((float)$n, 2);
}

function scalar(PDO $pdo, string $sql) {
    try {
        return $pdo->query($sql)->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function rows(PDO $pdo, string $sql) {
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}


$totalReservations = (int) scalar($pdo, "SELECT COUNT(*) FROM Reservation");
$activeReservations = (int) scalar($pdo, "SELECT COUNT(*) FROM Reservation WHERE Status='Active'");
$cancelledReservations = (int) scalar($pdo, "SELECT COUNT(*) FROM Reservation WHERE Status='Cancelled'");
$scheduledTrips = (int) scalar($pdo, "SELECT COUNT(*) FROM Bus_Schedule");
$paymentTableExists = table_exists($pdo, 'Payment');
$totalRevenue = $paymentTableExists ? (float) scalar($pdo, "SELECT IFNULL(SUM(Amount),0) FROM Payment") : 0.0;
$maintenanceCost = (float) scalar($pdo, "SELECT IFNULL(SUM(Cost),0) FROM Bus_Maintenance");

$routeDemand = rows($pdo, "
    SELECT
        CONCAT(r.Source, ' → ', r.Destination) AS route_name,
        COUNT(res.Reservation_ID) AS total_reservations,
        IFNULL(SUM(CASE WHEN res.Status='Active' THEN 1 ELSE 0 END),0) AS active_reservations
    FROM Route r
    LEFT JOIN Bus_Schedule bs ON bs.Route_ID = r.Route_ID
    LEFT JOIN Reservation res ON res.Schedule_ID = bs.Schedule_ID
    GROUP BY r.Route_ID, r.Source, r.Destination
    ORDER BY active_reservations DESC, total_reservations DESC
    LIMIT 8
");

$busStatus = rows($pdo, "
    SELECT Status, COUNT(*) AS total
    FROM Bus
    GROUP BY Status
    ORDER BY total DESC
");

$reservationStatus = rows($pdo, "
    SELECT Status, COUNT(*) AS total
    FROM Reservation
    GROUP BY Status
    ORDER BY total DESC
");

$maintenanceByBus = rows($pdo, "
    SELECT
        b.Bus_Number,
        IFNULL(SUM(m.Cost),0) AS total_cost,
        COUNT(m.Maintenance_ID) AS maintenance_count
    FROM Bus b
    LEFT JOIN Bus_Maintenance m ON m.Bus_ID = b.Bus_ID
    GROUP BY b.Bus_ID, b.Bus_Number
    HAVING total_cost > 0 OR maintenance_count > 0
    ORDER BY total_cost DESC, maintenance_count DESC
    LIMIT 8
");

$almostFullTrips = rows($pdo, "
    SELECT
        bs.Schedule_ID,
        bs.Date,
        bs.Departure_Time,
        bs.Arrival_Time,
        r.Source,
        r.Destination,
        b.Bus_Number,
        b.Capacity,
        IFNULL(SUM(CASE WHEN res.Status='Active' THEN 1 ELSE 0 END),0) AS Reserved_Seats,
        b.Capacity - IFNULL(SUM(CASE WHEN res.Status='Active' THEN 1 ELSE 0 END),0) AS Available_Seats
    FROM Bus_Schedule bs
    JOIN Route r ON r.Route_ID = bs.Route_ID
    JOIN Bus b ON b.Bus_ID = bs.Bus_ID
    LEFT JOIN Reservation res ON res.Schedule_ID = bs.Schedule_ID
    GROUP BY bs.Schedule_ID, bs.Date, bs.Departure_Time, bs.Arrival_Time, r.Source, r.Destination, b.Bus_Number, b.Capacity
    ORDER BY Available_Seats ASC, bs.Date ASC
    LIMIT 5
");

$dailyReservations = rows($pdo, "
    SELECT Booking_Date, COUNT(*) AS total
    FROM Reservation
    GROUP BY Booking_Date
    ORDER BY Booking_Date DESC
    LIMIT 7
");

$recentPayments = $paymentTableExists ? rows($pdo, "
    SELECT Payment_ID, Amount, Payment_Method, Payment_Date, Reservation_ID
    FROM Payment
    ORDER BY Payment_Date DESC, Payment_ID DESC
    LIMIT 5
") : [];

$maxRoute = 1;
foreach ($routeDemand as $r) {
    $maxRoute = max($maxRoute, (int)$r['active_reservations']);
}

$maxMaint = 1;
foreach ($maintenanceByBus as $m) {
    $maxMaint = max($maxMaint, (float)$m['total_cost']);
}

$maxDaily = 1;
foreach ($dailyReservations as $d) {
    $maxDaily = max($maxDaily, (int)$d['total']);
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Analytics Dashboard | LebanEASE</title>
<link rel="stylesheet" href="../css/style.css">
<style>
.analytics-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-top: 14px;
}
.analytics-card {
    min-height: 110px;
}
.kpi-number {
    font-size: 30px;
    font-weight: 900;
    margin-top: 8px;
}
.kpi-label {
    color: rgba(234,240,255,.72);
    font-weight: 700;
}
.chart-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 16px;
}
.bar-row {
    margin: 13px 0;
}
.bar-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-size: 13px;
    margin-bottom: 6px;
}
.bar-track {
    height: 12px;
    background: rgba(255,255,255,.08);
    border-radius: 999px;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.08);
}
.bar-fill {
    height: 100%;
    background: linear-gradient(135deg,#35a7ff,#a855f7);
    border-radius: 999px;
}
.badge-soft {
    display: inline-flex;
    padding: 6px 9px;
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    font-size: 12px;
    font-weight: 800;
    margin: 4px 4px 0 0;
}
.alert-line {
    padding: 10px 0;
    border-bottom: 1px solid rgba(255,255,255,.08);
    line-height: 1.6;
}
.alert-line:last-child {
    border-bottom: none;
}
@media(max-width:900px) {
    .analytics-grid,
    .chart-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('analytics'); ?>

<main class="section">
<div class="container">

<h2 class="section-title">Analytics Dashboard</h2>
<p class="muted mb-14">
    Visual overview of reservations, payments, route demand, fleet status, maintenance cost, and operational risks.
</p>

<div class="analytics-grid">
    <div class="card glass analytics-card">
        <div class="kpi-label">Total Reservations</div>
        <div class="kpi-number"><?= $totalReservations ?></div>
        <span class="badge-soft"><?= $activeReservations ?> active</span>
        <span class="badge-soft"><?= $cancelledReservations ?> cancelled</span>
    </div>

    <div class="card glass analytics-card">
        <div class="kpi-label">Scheduled Trips</div>
        <div class="kpi-number"><?= $scheduledTrips ?></div>
        <span class="badge-soft">Trip planning</span>
    </div>

    <div class="card glass analytics-card">
        <div class="kpi-label">Total Revenue</div>
        <div class="kpi-number">$<?= money($totalRevenue) ?></div>
        <?php if ($paymentTableExists): ?><span class="badge-soft">From payment records</span><?php else: ?><span class="badge-soft">Payment table not enabled in MVP</span><?php endif; ?>
    </div>

    <div class="card glass analytics-card">
        <div class="kpi-label">Maintenance Cost</div>
        <div class="kpi-number">$<?= money($maintenanceCost) ?></div>
        <span class="badge-soft">Fleet reliability</span>
    </div>

    <div class="card glass analytics-card">
        <div class="kpi-label">Reservation Health</div>
        <div class="kpi-number"><?= $totalReservations > 0 ? round(($activeReservations / $totalReservations) * 100) : 0 ?>%</div>
        <span class="badge-soft">Active ratio</span>
    </div>

    <div class="card glass analytics-card">
        <div class="kpi-label">Decision Support</div>
        <div class="kpi-number">Live</div>
        <span class="badge-soft">Works with AI Advisor</span>
    </div>
</div>

<div class="chart-grid">

    <div class="card glass">
        <h3>Most Demanded Routes</h3>
        <p class="muted tiny">Based on active reservations per route.</p>

        <?php foreach ($routeDemand as $r): ?>
            <?php
            $value = (int)$r['active_reservations'];
            $width = round(($value / $maxRoute) * 100);
            ?>
            <div class="bar-row">
                <div class="bar-head">
                    <strong><?= h($r['route_name']) ?></strong>
                    <span><?= $value ?> active / <?= (int)$r['total_reservations'] ?> total</span>
                </div>
                <div class="bar-track">
                    <div class="bar-fill" style="width: <?= $width ?>%;"></div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($routeDemand)): ?>
            <p class="muted">No route data found.</p>
        <?php endif; ?>
    </div>

    <div class="card glass">
        <h3>Fleet Status Distribution</h3>
        <p class="muted tiny">Shows how many buses are active or under maintenance.</p>

        <?php foreach ($busStatus as $s): ?>
            <span class="badge-soft"><?= h($s['Status']) ?>: <?= (int)$s['total'] ?></span>
        <?php endforeach; ?>

        <div class="h-12"></div>

        <h3>Reservation Status</h3>
        <?php foreach ($reservationStatus as $s): ?>
            <span class="badge-soft"><?= h($s['Status']) ?>: <?= (int)$s['total'] ?></span>
        <?php endforeach; ?>
    </div>

    <div class="card glass">
        <h3>Maintenance Cost by Bus</h3>

        <?php foreach ($maintenanceByBus as $m): ?>
            <?php
            $value = (float)$m['total_cost'];
            $width = round(($value / $maxMaint) * 100);
            ?>
            <div class="bar-row">
                <div class="bar-head">
                    <strong><?= h($m['Bus_Number']) ?></strong>
                    <span>$<?= money($value) ?> / <?= (int)$m['maintenance_count'] ?> records</span>
                </div>
                <div class="bar-track">
                    <div class="bar-fill" style="width: <?= $width ?>%;"></div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($maintenanceByBus)): ?>
            <p class="muted">No maintenance costs recorded yet.</p>
        <?php endif; ?>
    </div>

    <div class="card glass">
        <h3>Almost-Full Trip Alerts</h3>

        <?php foreach ($almostFullTrips as $t): ?>
            <div class="alert-line">
                <strong>Trip #<?= (int)$t['Schedule_ID'] ?>:</strong>
                <?= h($t['Source'] . ' → ' . $t['Destination']) ?>
                on <?= h($t['Date']) ?> at <?= h(substr($t['Departure_Time'], 0, 5)) ?>
                <br>
                <span class="muted tiny">
                    Bus <?= h($t['Bus_Number']) ?> —
                    <?= (int)$t['Available_Seats'] ?> seats left out of <?= (int)$t['Capacity'] ?>.
                </span>
            </div>
        <?php endforeach; ?>

        <?php if (empty($almostFullTrips)): ?>
            <p class="muted">No scheduled trip data found.</p>
        <?php endif; ?>
    </div>

    <div class="card glass">
        <h3>Recent Reservation Activity</h3>

        <?php foreach ($dailyReservations as $d): ?>
            <?php
            $value = (int)$d['total'];
            $width = round(($value / $maxDaily) * 100);
            ?>
            <div class="bar-row">
                <div class="bar-head">
                    <strong><?= h($d['Booking_Date']) ?></strong>
                    <span><?= $value ?> reservations</span>
                </div>
                <div class="bar-track">
                    <div class="bar-fill" style="width: <?= $width ?>%;"></div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($dailyReservations)): ?>
            <p class="muted">No reservation activity found.</p>
        <?php endif; ?>
    </div>

    <div class="card glass">
        <h3>Recent Payments</h3>

        <?php foreach ($recentPayments as $p): ?>
            <div class="alert-line">
                <strong>Payment #<?= (int)$p['Payment_ID'] ?></strong>
                — $<?= money($p['Amount']) ?>
                <br>
                <span class="muted tiny">
                    Reservation #<?= (int)$p['Reservation_ID'] ?> /
                    <?= h($p['Payment_Method']) ?> /
                    <?= h($p['Payment_Date']) ?>
                </span>
            </div>
        <?php endforeach; ?>

        <?php if (empty($recentPayments)): ?>
            <p class="muted">No payments recorded yet.</p>
        <?php endif; ?>
    </div>

</div>

</div>
</main>

</body>
</html>
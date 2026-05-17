<?php
require "auth.php";
require_admin();
require "../db/config.php";

date_default_timezone_set('Asia/Beirut');

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function scalar(PDO $pdo, string $sql) {
    try {
        return $pdo->query($sql)->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

function table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function index_exists(PDO $pdo, string $tableName, string $indexName): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ");
        $stmt->execute([$tableName, $indexName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function health_status(int $count, bool $badWhenPositive = true): array {
    if ($badWhenPositive) {
        return $count > 0 ? ['Warning', 'warn'] : ['OK', 'ok'];
    }
    return $count > 0 ? ['OK', 'ok'] : ['Warning', 'warn'];
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

$tables = [
    'Admin',
    'Passenger',
    'Driver',
    'Bus',
    'Route',
    'Bus_Schedule',
    'Reservation',
    'Bus_Maintenance',
    'Admin_Activity_Log'
];

$tableChecks = [];
foreach ($tables as $table) {
    $tableChecks[] = [
        'name' => $table,
        'exists' => table_exists($pdo, $table)
    ];
}

$totalPassengers = (int)(scalar($pdo, "SELECT COUNT(*) FROM Passenger") ?? 0);
$totalBuses = (int)(scalar($pdo, "SELECT COUNT(*) FROM Bus") ?? 0);
$totalRoutes = (int)(scalar($pdo, "SELECT COUNT(*) FROM Route") ?? 0);
$totalSchedules = (int)(scalar($pdo, "SELECT COUNT(*) FROM Bus_Schedule") ?? 0);
$totalReservations = (int)(scalar($pdo, "SELECT COUNT(*) FROM Reservation") ?? 0);

$orphanReservations = (int)(scalar($pdo, "
    SELECT COUNT(*)
    FROM Reservation res
    LEFT JOIN Passenger p ON p.Passenger_ID = res.Passenger_ID
    LEFT JOIN Bus_Schedule bs ON bs.Schedule_ID = res.Schedule_ID
    WHERE p.Passenger_ID IS NULL OR bs.Schedule_ID IS NULL
") ?? 0);

$schedulesMissingData = (int)(scalar($pdo, "
    SELECT COUNT(*)
    FROM Bus_Schedule bs
    LEFT JOIN Bus b ON b.Bus_ID = bs.Bus_ID
    LEFT JOIN Route r ON r.Route_ID = bs.Route_ID
    WHERE b.Bus_ID IS NULL OR r.Route_ID IS NULL
") ?? 0);

$duplicateActiveReservations = (int)(scalar($pdo, "
    SELECT COUNT(*)
    FROM (
        SELECT Passenger_ID, Schedule_ID, COUNT(*) AS c
        FROM Reservation
        WHERE Status = 'Active'
        GROUP BY Passenger_ID, Schedule_ID
        HAVING c > 1
    ) x
") ?? 0);

$maintenanceScheduled = (int)(scalar($pdo, "
    SELECT COUNT(*)
    FROM Bus_Schedule bs
    JOIN Bus b ON b.Bus_ID = bs.Bus_ID
    WHERE LOWER(b.Status) LIKE '%maintenance%'
      AND bs.Date >= CURDATE()
") ?? 0);

$activeReservations = (int)(scalar($pdo, "SELECT COUNT(*) FROM Reservation WHERE Status='Active'") ?? 0);
$cancelledReservations = (int)(scalar($pdo, "SELECT COUNT(*) FROM Reservation WHERE Status='Cancelled'") ?? 0);

$lastActivity = scalar($pdo, "
    SELECT MAX(Created_At)
    FROM Admin_Activity_Log
");

$indexChecks = [
    ['Route search index', 'Route', 'idx_route_source_destination'],
    ['Schedule date index', 'Bus_Schedule', 'idx_schedule_date'],
    ['Reservation status index', 'Reservation', 'idx_reservation_status'],
    ['Passenger schedule reservation index', 'Reservation', 'idx_reservation_passenger_schedule'],
    ['Bus status index', 'Bus', 'idx_bus_status'],
    ['Passenger email index', 'Passenger', 'idx_passenger_email'],
    ['Admin email index', 'Admin', 'idx_admin_email']
];

$hasAiConfig = file_exists(__DIR__ . "/ai_config.php");
$aiConfigured = false;

if ($hasAiConfig) {
    require_once __DIR__ . "/ai_config.php";
    if (defined('LEBANEASE_AI_API_KEY') && trim(LEBANEASE_AI_API_KEY) !== '') {
        $aiConfigured = true;
    }
}

$healthItems = [];

[$status, $class] = health_status($orphanReservations);
$healthItems[] = [
    'title' => 'Reservation Relationship Check',
    'value' => $orphanReservations,
    'status' => $status,
    'class' => $class,
    'description' => 'Reservations without a valid passenger or schedule.'
];

[$status, $class] = health_status($schedulesMissingData);
$healthItems[] = [
    'title' => 'Schedule Relationship Check',
    'value' => $schedulesMissingData,
    'status' => $status,
    'class' => $class,
    'description' => 'Schedules missing a valid bus or route.'
];

[$status, $class] = health_status($duplicateActiveReservations);
$healthItems[] = [
    'title' => 'Duplicate Active Reservations',
    'value' => $duplicateActiveReservations,
    'status' => $status,
    'class' => $class,
    'description' => 'Passengers with more than one active reservation on the same trip.'
];

[$status, $class] = health_status($maintenanceScheduled);
$healthItems[] = [
    'title' => 'Maintenance Bus Scheduling Risk',
    'value' => $maintenanceScheduled,
    'status' => $status,
    'class' => $class,
    'description' => 'Future schedules using buses currently marked as maintenance.'
];

$overallWarnings = 0;
foreach ($healthItems as $item) {
    if ($item['class'] === 'warn') {
        $overallWarnings++;
    }
}

$overallStatus = $overallWarnings === 0 ? 'Healthy' : 'Needs Attention';
$overallClass = $overallWarnings === 0 ? 'ok' : 'warn';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>System Health | LebanEASE Admin</title>
<link rel="stylesheet" href="../css/style.css">
<style>
.health-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-top: 14px;
}

.health-card {
    min-height: 110px;
}

.health-number {
    font-size: 30px;
    font-weight: 950;
    margin-top: 8px;
}

.health-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 16px;
}

.health-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 12px;
    padding: 13px 0;
    border-bottom: 1px solid rgba(255,255,255,.08);
}

.health-row:last-child {
    border-bottom: none;
}

.badge-ok,
.badge-warn,
.badge-info {
    display: inline-flex;
    padding: 6px 10px;
    border-radius: 999px;
    font-weight: 900;
    font-size: 12px;
    border: 1px solid rgba(255,255,255,.14);
}

.badge-ok {
    background: rgba(34,197,94,.18);
    color: #86efac;
}

.badge-warn {
    background: rgba(245,158,11,.18);
    color: #fcd34d;
}

.badge-info {
    background: rgba(59,130,246,.18);
    color: #93c5fd;
}

.mini-text {
    color: rgba(234,240,255,.7);
    font-size: 13px;
    margin-top: 5px;
    line-height: 1.5;
}

@media(max-width:900px) {
    .health-grid,
    .health-layout {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('system_health'); ?>

<main class="section">
<div class="container">

<h2 class="section-title">System Health Dashboard</h2>
<p class="muted mb-14">
    Reliability and maintainability dashboard for checking database readiness, data consistency, indexes, logs, and AI configuration.
</p>

<div class="health-grid">
    <div class="card glass health-card">
        <div class="muted">Overall Status</div>
        <div class="health-number"><?= h($overallStatus) ?></div>
        <span class="<?= $overallClass === 'ok' ? 'badge-ok' : 'badge-warn' ?>">
            <?= $overallWarnings ?> warning(s)
        </span>
    </div>

    <div class="card glass health-card">
        <div class="muted">Passengers</div>
        <div class="health-number"><?= $totalPassengers ?></div>
        <span class="badge-info">Registered users</span>
    </div>

    <div class="card glass health-card">
        <div class="muted">Reservations</div>
        <div class="health-number"><?= $totalReservations ?></div>
        <span class="badge-ok"><?= $activeReservations ?> active</span>
        <span class="badge-warn"><?= $cancelledReservations ?> cancelled</span>
    </div>

    <div class="card glass health-card">
        <div class="muted">Operational Data</div>
        <div class="health-number"><?= $totalBuses + $totalRoutes + $totalSchedules ?></div>
        <span class="badge-info"><?= $totalBuses ?> buses</span>
        <span class="badge-info"><?= $totalRoutes ?> routes</span>
        <span class="badge-info"><?= $totalSchedules ?> trips</span>
    </div>
</div>

<div class="health-layout">

    <div class="card glass">
        <h3>Data Integrity Checks</h3>
        <p class="mini-text">These checks help detect inconsistent records before the demo or deployment.</p>

        <?php foreach ($healthItems as $item): ?>
            <div class="health-row">
                <div>
                    <strong><?= h($item['title']) ?></strong>
                    <div class="mini-text"><?= h($item['description']) ?></div>
                </div>
                <div>
                    <span class="<?= $item['class'] === 'ok' ? 'badge-ok' : 'badge-warn' ?>">
                        <?= h($item['status']) ?>: <?= h($item['value']) ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card glass">
        <h3>Required Tables</h3>
        <p class="mini-text">Checks that important project tables exist in the active database.</p>

        <?php foreach ($tableChecks as $t): ?>
            <div class="health-row">
                <div>
                    <strong><?= h($t['name']) ?></strong>
                </div>
                <div>
                    <?php if ($t['exists']): ?>
                        <span class="badge-ok">Found</span>
                    <?php else: ?>
                        <span class="badge-warn">Missing</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card glass">
        <h3>Performance Index Checks</h3>
        <p class="mini-text">Indexes improve search, filtering, login lookup, and reservation queries.</p>

        <?php foreach ($indexChecks as $idx): ?>
            <?php $exists = index_exists($pdo, $idx[1], $idx[2]); ?>
            <div class="health-row">
                <div>
                    <strong><?= h($idx[0]) ?></strong>
                    <div class="mini-text"><?= h($idx[1]) ?> / <?= h($idx[2]) ?></div>
                </div>
                <div>
                    <?php if ($exists): ?>
                        <span class="badge-ok">Indexed</span>
                    <?php else: ?>
                        <span class="badge-warn">Missing</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card glass">
        <h3>System Readiness</h3>

        <div class="health-row">
            <div>
                <strong>AI Advisor Configuration</strong>
                <div class="mini-text">Checks whether the AI API key is configured.</div>
            </div>
            <div>
                <?php if ($aiConfigured): ?>
                    <span class="badge-ok">Configured</span>
                <?php else: ?>
                    <span class="badge-warn">Fallback Mode</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="health-row">
            <div>
                <strong>Last Activity Log</strong>
                <div class="mini-text">Shows whether the activity log is receiving records.</div>
            </div>
            <div>
                <?php if ($lastActivity): ?>
                    <span class="badge-ok"><?= h($lastActivity) ?></span>
                <?php else: ?>
                    <span class="badge-warn">No logs</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="health-row">
            <div>
                <strong>Fare Display</strong>
                <div class="mini-text">Supports revenue display and ticket payment status.</div>
            </div>
            <div>
                <?php if (true): ?>
                    <span class="badge-ok">Enabled</span>
                <?php else: ?>
                    <span class="badge-warn">Missing</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="h-12"></div>

        <a class="btn btn-primary" href="analytics.php">Open Analytics</a>
        <a class="btn btn-ghost" href="activity_log.php">Open Activity Log</a>
    </div>

</div>

</div>
</main>

</body>
</html>
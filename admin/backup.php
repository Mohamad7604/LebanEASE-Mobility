<?php
require "auth.php";
require_admin();
require "../db/config.php";

$adminName = $_SESSION['admin_name'] ?? 'Admin';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
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
$paymentTableExists = table_exists($pdo, 'Payment');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Data Export | LebanEASE Admin</title>
<link rel="stylesheet" href="../css/style.css">
<style>
.export-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
    margin-top: 18px;
}

.export-card {
    padding: 20px;
}

.export-card h3 {
    margin-bottom: 8px;
}

.export-card p {
    line-height: 1.7;
}

@media(max-width:800px) {
    .export-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('backup'); ?>

<main class="section">
<div class="container">

<h2 class="section-title">CSV Export / Backup Center</h2>
<p class="muted mb-14">
    Export important operational data as CSV files for backup, reporting, auditing, and portability.
</p>

<div class="export-grid">

    <div class="card glass export-card">
        <h3>Reservations Export</h3>
        <p class="muted">
            Exports passenger reservations with route, date, bus, seat, booking date, and status.
        </p>
        <a class="btn btn-primary" href="export.php?type=reservations">Download Reservations CSV</a>
    </div>

    <div class="card glass export-card">
        <h3>Passenger Export</h3>
        <p class="muted">
            Exports passenger records for backup and administrative review.
        </p>
        <a class="btn btn-primary" href="export.php?type=passengers">Download Passengers CSV</a>
    </div>

    <div class="card glass export-card">
        <h3>Activity Log Export</h3>
        <p class="muted">
            Exports the audit trail for traceability and accountability.
        </p>
        <a class="btn btn-primary" href="export.php?type=activity_log">Download Activity Log CSV</a>
    </div>

    <div class="card glass export-card">
        <h3>Maintenance Export</h3>
        <p class="muted">
            Exports maintenance records and costs for fleet monitoring.
        </p>
        <a class="btn btn-primary" href="export.php?type=maintenance">Download Maintenance CSV</a>
    </div>

    <div class="card glass export-card">
        <h3>Fare / Payment Export</h3>
        <?php if ($paymentTableExists): ?>
            <p class="muted">Exports payment records for revenue tracking and financial reporting.</p>
            <a class="btn btn-primary" href="export.php?type=payments">Download Payments CSV</a>
        <?php else: ?>
            <p class="muted">Online payment is not enabled in this MVP. Estimated fare is displayed in the reservation flow, so no payment CSV is generated.</p>
            <span class="btn btn-disabled">Payment CSV Unavailable</span>
        <?php endif; ?>
    </div>

    <div class="card glass export-card">
        <h3>Why this matters</h3>
        <p class="muted">
            This feature supports recoverability, portability, maintainability, and auditing.
            It allows administrators to keep external backups and review system data outside the web application.
        </p>
    </div>

</div>

</div>
</main>

</body>
</html>
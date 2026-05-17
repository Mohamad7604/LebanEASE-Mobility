<?php
require "auth.php";
require_admin();
require "../db/config.php";

date_default_timezone_set('Asia/Beirut');

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

$event = trim($_GET['event'] ?? '');
$entity = trim($_GET['entity'] ?? '');
$q = trim($_GET['q'] ?? '');

$logs = [];
$error = '';

try {
    $sql = "
        SELECT
            l.*,
            CONCAT(a.First_Name, ' ', a.Last_Name) AS Admin_Name
        FROM Admin_Activity_Log l
        LEFT JOIN Admin a ON a.Admin_ID = l.Admin_ID
        WHERE
            (? = '' OR l.Event_Type = ?)
            AND (? = '' OR l.Entity_Name = ?)
            AND (
                ? = ''
                OR l.Description LIKE CONCAT('%', ?, '%')
                OR l.Entity_Name LIKE CONCAT('%', ?, '%')
                OR l.Event_Type LIKE CONCAT('%', ?, '%')
            )
        ORDER BY l.Created_At DESC, l.Log_ID DESC
        LIMIT 150
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$event, $event, $entity, $entity, $q, $q, $q, $q]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Activity log table was not found. Run the SQL setup first.";
}

$events = ['CREATE', 'UPDATE', 'STATUS_CHANGE', 'CANCEL', 'PAYMENT'];
$entities = ['Bus', 'Schedule', 'Reservation', 'Maintenance', 'Payment', 'System'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Activity Log | LebanEASE</title>
<link rel="stylesheet" href="../css/style.css">
<style>
.log-row {
    display: grid;
    grid-template-columns: 150px 130px 130px 1fr;
    gap: 12px;
    padding: 14px 0;
    border-bottom: 1px solid rgba(255,255,255,.08);
    align-items: start;
}
.log-row:last-child {
    border-bottom: none;
}
.log-chip {
    display: inline-flex;
    padding: 6px 9px;
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    font-size: 12px;
    font-weight: 800;
}
.log-desc {
    line-height: 1.6;
    color: rgba(234,240,255,.86);
}
@media(max-width:800px) {
    .log-row {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('activity_log'); ?>

<main class="section">
<div class="container">

<h2 class="section-title">Admin Activity Log</h2>
<p class="muted mb-14">
    Audit trail for important actions such as bus changes, reservations, payments, schedules, and maintenance updates.
</p>

<?php if ($error): ?>

    <div class="card glass">
        <div class="alert">
            <strong>Setup needed:</strong> <?= h($error) ?>
        </div>
    </div>

<?php else: ?>

    <div class="card glass">
        <form method="GET" class="flex gap-10 flex-wrap">
            <select class="admin-search" name="event">
                <option value="">All events</option>
                <?php foreach ($events as $e): ?>
                    <option value="<?= h($e) ?>" <?= $event === $e ? 'selected' : '' ?>>
                        <?= h($e) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select class="admin-search" name="entity">
                <option value="">All entities</option>
                <?php foreach ($entities as $en): ?>
                    <option value="<?= h($en) ?>" <?= $entity === $en ? 'selected' : '' ?>>
                        <?= h($en) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input
                class="admin-search"
                type="text"
                name="q"
                placeholder="Search description..."
                value="<?= h($q) ?>"
            >

            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-ghost" href="activity_log.php">Clear</a>
        </form>
    </div>

    <div class="h-12"></div>

    <div class="card glass">
        <?php foreach ($logs as $log): ?>
            <div class="log-row">
                <div>
                    <strong><?= h(date('Y-m-d', strtotime($log['Created_At']))) ?></strong>
                    <br>
                    <span class="tiny muted"><?= h(date('H:i', strtotime($log['Created_At']))) ?></span>
                </div>

                <div>
                    <span class="log-chip"><?= h($log['Event_Type']) ?></span>
                </div>

                <div>
                    <span class="log-chip">
                        <?= h($log['Entity_Name']) ?> #<?= h($log['Entity_ID'] ?? '-') ?>
                    </span>
                </div>

                <div class="log-desc">
                    <?= h($log['Description']) ?>
                    <br>
                    <span class="tiny muted">
                        By: <?= h($log['Admin_Name'] ?? 'System / automatic trigger') ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($logs)): ?>
            <p class="muted">
                No activity records found yet. Add/update a bus, reservation, payment, schedule, or maintenance record and refresh this page.
            </p>
        <?php endif; ?>
    </div>

<?php endif; ?>

</div>
</main>

</body>
</html>
<?php
require "db/config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Beirut');

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money($n) {
    return number_format((float)$n, 2);
}

$reservation_id = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : 0;

$error = '';
$ticket = null;

if ($reservation_id <= 0) {
    $error = "Invalid reservation ID.";
} else {
    $stmt = $pdo->prepare("
        SELECT
            res.Reservation_ID,
            res.Seat_Number,
            res.Booking_Date,
            res.Status AS Reservation_Status,

            p.Passenger_ID,
            CONCAT(p.First_Name, ' ', p.Last_Name) AS Passenger_Name,
            p.Email,
            p.Phone,

            bs.Schedule_ID,
            bs.Date,
            bs.Departure_Time,
            bs.Arrival_Time,

            b.Bus_Number,
            b.Type AS Bus_Type,

            r.Source,
            r.Destination,
            r.Distance,

            CONCAT(d.First_Name, ' ', d.Last_Name) AS Driver_Name

        FROM Reservation res
        JOIN Passenger p ON p.Passenger_ID = res.Passenger_ID
        JOIN Bus_Schedule bs ON bs.Schedule_ID = res.Schedule_ID
        JOIN Bus b ON b.Bus_ID = bs.Bus_ID
        JOIN Route r ON r.Route_ID = bs.Route_ID
        LEFT JOIN Driver d ON d.Driver_ID = b.Driver_ID
        WHERE res.Reservation_ID = ?
        LIMIT 1
    ");

    $stmt->execute([$reservation_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        $error = "Reservation not found.";
    } else {
        $isOwner = !empty($_SESSION['passenger_id']) && (int)$_SESSION['passenger_id'] === (int)$ticket['Passenger_ID'];
        $isAdmin = !empty($_SESSION['admin_id']);

        if (!$isOwner && !$isAdmin) {
            $error = "Please log in using the passenger account that owns this reservation to view the ticket.";
            $ticket = null;
        }
    }
}

$reference = $ticket ? ('LEB-' . str_pad((string)$ticket['Reservation_ID'], 6, '0', STR_PAD_LEFT)) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Printable Ticket | LebanEASE</title>
<link rel="stylesheet" href="css/style.css">
<style>
.ticket-wrap {
    max-width: 850px;
    margin: 0 auto;
}
.ticket {
    background: #fff;
    color: #172033;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
}
.ticket-top {
    background: linear-gradient(135deg,#35a7ff,#a855f7);
    color: #07111f;
    padding: 26px;
    display: flex;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
    align-items: flex-start;
}
.ticket-brand {
    font-size: 26px;
    font-weight: 950;
    letter-spacing: .5px;
}
.ticket-ref {
    font-weight: 900;
    background: rgba(255,255,255,.38);
    padding: 10px 14px;
    border-radius: 14px;
}
.ticket-body {
    padding: 26px;
    display: grid;
    grid-template-columns: 1.3fr .7fr;
    gap: 22px;
}
.ticket-section {
    border: 1px solid #e8edf7;
    border-radius: 18px;
    padding: 16px;
    margin-bottom: 14px;
    background: #fbfdff;
}
.ticket-section h3 {
    margin-bottom: 10px;
    color: #172033;
}
.ticket-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    border-bottom: 1px dashed #d7deec;
    padding: 9px 0;
}
.ticket-row:last-child {
    border-bottom: none;
}
.ticket-label {
    color: #657085;
    font-weight: 700;
}
.ticket-value {
    font-weight: 900;
    text-align: right;
}
.qr-fake {
    height: 190px;
    border-radius: 18px;
    background: repeating-linear-gradient(45deg,#172033 0 8px,#fff 8px 16px);
    border: 10px solid #fff;
    box-shadow: 0 0 0 1px #dfe6f3;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qr-inner {
    background: #fff;
    color: #172033;
    font-weight: 950;
    padding: 8px 10px;
    border-radius: 10px;
    font-size: 13px;
}
.print-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 18px;
}
.status-pill {
    display: inline-flex;
    padding: 7px 10px;
    border-radius: 999px;
    background: #edf4ff;
    color: #1c4d8f;
    font-weight: 900;
}
@media(max-width:760px) {
    .ticket-body {
        grid-template-columns: 1fr;
    }
}
@media print {
    .nav,
    .print-actions {
        display: none !important;
    }
    body {
        background: #fff;
    }
    .ticket {
        box-shadow: none;
        border: 1px solid #ddd;
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('reservations'); ?>

<main class="section">
<div class="container ticket-wrap">

<?php if ($error): ?>

    <div class="card glass">
        <div class="alert">
            <strong>Cannot show ticket:</strong> <?= h($error) ?>
        </div>
        <div class="h-12"></div>
        <a class="btn btn-primary" href="login.php">Login</a>
        <a class="btn btn-ghost" href="search.php">Back to Search</a>
    </div>

<?php else: ?>

    <div class="ticket">
        <div class="ticket-top">
            <div>
                <div class="ticket-brand">LebanEASE Mobility</div>
                <div style="font-weight:800;margin-top:6px;">Printable Bus Reservation Ticket</div>
            </div>

            <div class="ticket-ref"><?= h($reference) ?></div>
        </div>

        <div class="ticket-body">
            <div>
                <div class="ticket-section">
                    <h3>Trip Details</h3>

                    <div class="ticket-row">
                        <span class="ticket-label">Route</span>
                        <span class="ticket-value"><?= h($ticket['Source'] . ' → ' . $ticket['Destination']) ?></span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Date</span>
                        <span class="ticket-value"><?= h($ticket['Date']) ?></span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Time</span>
                        <span class="ticket-value">
                            <?= h(substr($ticket['Departure_Time'], 0, 5) . ' → ' . substr($ticket['Arrival_Time'], 0, 5)) ?>
                        </span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Distance</span>
                        <span class="ticket-value"><?= h($ticket['Distance']) ?> km</span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Bus</span>
                        <span class="ticket-value"><?= h($ticket['Bus_Number'] . ' / ' . $ticket['Bus_Type']) ?></span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Driver</span>
                        <span class="ticket-value"><?= h($ticket['Driver_Name'] ?? 'Not assigned') ?></span>
                    </div>
                </div>

                <div class="ticket-section">
                    <h3>Passenger Details</h3>

                    <div class="ticket-row">
                        <span class="ticket-label">Passenger</span>
                        <span class="ticket-value"><?= h($ticket['Passenger_Name']) ?></span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Email</span>
                        <span class="ticket-value"><?= h($ticket['Email']) ?></span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Phone</span>
                        <span class="ticket-value"><?= h($ticket['Phone']) ?></span>
                    </div>
                </div>
            </div>

            <div>
                <div class="ticket-section">
                    <h3>Reservation</h3>

                    <div class="ticket-row">
                        <span class="ticket-label">Seat</span>
                        <span class="ticket-value"><?= (int)$ticket['Seat_Number'] ?></span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Status</span>
                        <span class="ticket-value">
                            <span class="status-pill"><?= h($ticket['Reservation_Status']) ?></span>
                        </span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Booked</span>
                        <span class="ticket-value"><?= h($ticket['Booking_Date']) ?></span>
                    </div>

                    <div class="ticket-row">
                        <span class="ticket-label">Estimated Fare</span>
                        <span class="ticket-value"><?= h(lebanease_fare_label($ticket['Distance'])) ?></span>
                    </div>
                </div>

                <div class="ticket-section">
                    <h3>Scan / Reference</h3>
                    <div class="qr-fake">
                        <div class="qr-inner"><?= h($reference) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="print-actions">
        <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
        <a class="btn btn-ghost" href="my_reservations.php">Back to My Reservations</a>
    </div>

<?php endif; ?>

</div>
</main>

</body>
</html>
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

if (empty($_SESSION['passenger_id'])) {
    header("Location: login.php");
    exit;
}

$passenger_id = (int)$_SESSION['passenger_id'];
$passengerName = $_SESSION['passenger_name'] ?? 'Passenger';

$message = '';
$error = '';

$paymentTableExists = table_exists($pdo, "Payment");
$scheduleHasAvailableSeats = column_exists($pdo, "Bus_Schedule", "Available_Seats");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reservation'])) {
    $reservation_id = (int)($_POST['reservation_id'] ?? 0);

    if ($reservation_id <= 0) {
        $error = "Invalid reservation selected.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT
                    res.Reservation_ID,
                    res.Status,
                    res.Schedule_ID,
                    bs.Date,
                    bs.Departure_Time
                FROM Reservation res
                JOIN Bus_Schedule bs ON bs.Schedule_ID = res.Schedule_ID
                WHERE res.Reservation_ID = ?
                  AND res.Passenger_ID = ?
                LIMIT 1
            ");
            $stmt->execute([$reservation_id, $passenger_id]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reservation) {
                $error = "Reservation not found.";
            } elseif ($reservation['Status'] !== 'Active') {
                $error = "Only active reservations can be cancelled.";
            } else {
                $departureTimestamp = strtotime($reservation['Date'] . ' ' . $reservation['Departure_Time']);

                if ($departureTimestamp <= time()) {
                    $error = "This reservation cannot be cancelled because the trip already started or passed.";
                } else {
                    $update = $pdo->prepare("
                        UPDATE Reservation
                        SET Status = 'Cancelled'
                        WHERE Reservation_ID = ?
                          AND Passenger_ID = ?
                          AND Status = 'Active'
                    ");
                    $update->execute([$reservation_id, $passenger_id]);

                    if ($scheduleHasAvailableSeats) {
                        $seatUpdate = $pdo->prepare("
                            UPDATE Bus_Schedule
                            SET Available_Seats = Available_Seats + 1
                            WHERE Schedule_ID = ?
                        ");
                        $seatUpdate->execute([(int)$reservation['Schedule_ID']]);
                    }

                    $message = "Reservation cancelled successfully.";
                }
            }

            if ($error) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Could not cancel reservation. Please try again.";
        }
    }
}

if ($paymentTableExists) {
    $sql = "
        SELECT
            res.Reservation_ID,
            res.Seat_Number,
            res.Booking_Date,
            res.Status AS Reservation_Status,

            bs.Schedule_ID,
            bs.Date,
            bs.Departure_Time,
            bs.Arrival_Time,

            r.Source,
            r.Destination,
            r.Distance,

            b.Bus_Number,
            b.Type AS Bus_Type,

            pay.Payment_ID,
            pay.Amount,
            pay.Payment_Method,
            pay.Payment_Date

        FROM Reservation res
        JOIN Bus_Schedule bs ON bs.Schedule_ID = res.Schedule_ID
        JOIN Route r ON r.Route_ID = bs.Route_ID
        JOIN Bus b ON b.Bus_ID = bs.Bus_ID
        LEFT JOIN Payment pay ON pay.Payment_ID = (
            SELECT p2.Payment_ID
            FROM Payment p2
            WHERE p2.Reservation_ID = res.Reservation_ID
            ORDER BY p2.Payment_ID DESC
            LIMIT 1
        )
        WHERE res.Passenger_ID = ?
        ORDER BY bs.Date DESC, bs.Departure_Time DESC, res.Reservation_ID DESC
    ";
} else {
    $sql = "
        SELECT
            res.Reservation_ID,
            res.Seat_Number,
            res.Booking_Date,
            res.Status AS Reservation_Status,

            bs.Schedule_ID,
            bs.Date,
            bs.Departure_Time,
            bs.Arrival_Time,

            r.Source,
            r.Destination,
            r.Distance,

            b.Bus_Number,
            b.Type AS Bus_Type,

            NULL AS Payment_ID,
            NULL AS Amount,
            NULL AS Payment_Method,
            NULL AS Payment_Date

        FROM Reservation res
        JOIN Bus_Schedule bs ON bs.Schedule_ID = res.Schedule_ID
        JOIN Route r ON r.Route_ID = bs.Route_ID
        JOIN Bus b ON b.Bus_ID = bs.Bus_ID
        WHERE res.Passenger_ID = ?
        ORDER BY bs.Date DESC, bs.Departure_Time DESC, res.Reservation_ID DESC
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute([$passenger_id]);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Reservations | LebanEASE</title>
<link rel="stylesheet" href="css/style.css">
<style>
.res-page {
    max-width: 1150px;
    margin: 0 auto;
}

.res-grid {
    display: grid;
    gap: 16px;
}

.res-card {
    padding: 20px;
}

.res-top {
    display: flex;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
    align-items: flex-start;
}

.res-route {
    font-size: 23px;
    font-weight: 950;
    margin-bottom: 8px;
}

.res-sub {
    color: rgba(234,240,255,.72);
    line-height: 1.6;
}

.res-meta {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
}

.pill {
    display: inline-flex;
    align-items: center;
    padding: 7px 10px;
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    font-weight: 800;
    font-size: 12px;
}

.pill-active {
    background: rgba(34,197,94,.18);
    border-color: rgba(34,197,94,.35);
}

.pill-cancelled {
    background: rgba(239,68,68,.16);
    border-color: rgba(239,68,68,.35);
}

.pill-completed {
    background: rgba(59,130,246,.16);
    border-color: rgba(59,130,246,.35);
}

.res-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.cancel-form {
    display: inline;
}

.payment-box {
    min-width: 190px;
    padding: 14px;
    border-radius: 16px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
}

.payment-title {
    font-weight: 900;
    margin-bottom: 6px;
}

.empty-box {
    text-align: center;
    padding: 35px;
}

@media(max-width:750px) {
    .res-top {
        display: block;
    }

    .payment-box {
        margin-top: 14px;
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('reservations'); ?>

<main class="section">
<div class="container res-page">

    <h2 class="section-title">My Reservations</h2>
    <p class="muted mb-14">
        View your current and past reservations, cancel eligible trips, and print your reservation ticket.
    </p>

    <?php if ($message): ?>
        <div class="card glass mb-14">
            <div class="success"><?= h($message) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="card glass mb-14">
            <div class="alert"><?= h($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if (empty($reservations)): ?>

        <div class="card glass empty-box">
            <h3>No reservations yet</h3>
            <p class="muted">
                You have not booked any trips yet. Search for available trips and reserve your seat.
            </p>
            <div class="h-12"></div>
            <a class="btn btn-primary" href="search.php">Search Trips</a>
        </div>

    <?php else: ?>

        <div class="res-grid">

            <?php foreach ($reservations as $r): ?>
                <?php
                    $departureTime = strtotime($r['Date'] . ' ' . $r['Departure_Time']);
                    $isPastTrip = $departureTime <= time();

                    $displayStatus = $r['Reservation_Status'];
                    if ($displayStatus === 'Active' && $isPastTrip) {
                        $displayStatus = 'Completed';
                    }

                    $statusClass = 'pill-active';
                    if ($displayStatus === 'Cancelled') {
                        $statusClass = 'pill-cancelled';
                    } elseif ($displayStatus === 'Completed') {
                        $statusClass = 'pill-completed';
                    }

                    $canCancel = $r['Reservation_Status'] === 'Active' && !$isPastTrip;
                ?>

                <div class="card glass res-card">

                    <div class="res-top">

                        <div>
                            <div class="res-route">
                                <?= h($r['Source']) ?> → <?= h($r['Destination']) ?>
                            </div>

                            <div class="res-sub">
                                Reservation #<?= (int)$r['Reservation_ID'] ?> |
                                Schedule #<?= (int)$r['Schedule_ID'] ?> |
                                Booked on <?= h($r['Booking_Date']) ?>
                            </div>

                            <div class="res-meta">
                                <span class="pill <?= h($statusClass) ?>"><?= h($displayStatus) ?></span>
                                <span class="pill">Seat <?= (int)$r['Seat_Number'] ?></span>
                                <span class="pill"><?= h($r['Date']) ?></span>
                                <span class="pill">
                                    <?= h(substr($r['Departure_Time'], 0, 5)) ?>
                                    →
                                    <?= h(substr($r['Arrival_Time'], 0, 5)) ?>
                                </span>
                                <span class="pill">Bus <?= h($r['Bus_Number']) ?></span>
                                <span class="pill"><?= h($r['Bus_Type']) ?></span>
                                <span class="pill"><?= h($r['Distance']) ?> km</span>
                            </div>
                        </div>

                        <div class="payment-box">
                            <div class="payment-title">Estimated Fare</div>
                            <div class="res-sub">
                                <strong><?= h(lebanease_fare_label($r['Distance'])) ?></strong><br>
                                Fare display only — no online payment in this MVP.
                            </div>
                        </div>

                    </div>

                    <div class="res-actions">

                        <a
                            class="btn btn-primary"
                            target="_blank"
                            href="ticket.php?reservation_id=<?= (int)$r['Reservation_ID'] ?>"
                        >
                            Ticket
                        </a>

                        <?php if ($canCancel): ?>
                            <form
                                method="POST"
                                class="cancel-form"
                                onsubmit="return confirm('Are you sure you want to cancel this reservation?');"
                            >
                                <input
                                    type="hidden"
                                    name="reservation_id"
                                    value="<?= (int)$r['Reservation_ID'] ?>"
                                >

                                <button
                                    class="btn btn-ghost"
                                    type="submit"
                                    name="cancel_reservation"
                                >
                                    Cancel Reservation
                                </button>
                            </form>
                        <?php endif; ?>

                        <a class="btn btn-ghost" href="search.php">
                            Book Another Trip
                        </a>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
</main>

</body>
</html>
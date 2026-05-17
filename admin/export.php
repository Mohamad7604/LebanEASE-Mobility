<?php
require "auth.php";
require_admin();
require "../db/config.php";

date_default_timezone_set('Asia/Beirut');

$type = $_GET['type'] ?? 'reservations';

$allowed = [
    'reservations',
    'passengers',
    'activity_log',
    'maintenance',
    'payments'
];

if (!in_array($type, $allowed, true)) {
    die("Invalid export type.");
}

function export_table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function export_csv(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    fputcsv($output, $headers);

    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $header) {
            $line[] = $row[$header] ?? '';
        }
        fputcsv($output, $line);
    }

    fclose($output);
    exit;
}

try {
    if ($type === 'reservations') {
        $stmt = $pdo->query("
            SELECT
                res.Reservation_ID,
                CONCAT(p.First_Name, ' ', p.Last_Name) AS Passenger_Name,
                p.Email,
                r.Source,
                r.Destination,
                bs.Date,
                bs.Departure_Time,
                bs.Arrival_Time,
                b.Bus_Number,
                res.Seat_Number,
                res.Booking_Date,
                res.Status
            FROM Reservation res
            JOIN Passenger p ON p.Passenger_ID = res.Passenger_ID
            JOIN Bus_Schedule bs ON bs.Schedule_ID = res.Schedule_ID
            JOIN Route r ON r.Route_ID = bs.Route_ID
            JOIN Bus b ON b.Bus_ID = bs.Bus_ID
            ORDER BY res.Reservation_ID DESC
        ");

        export_csv(
            'lebanease_reservations_' . date('Y-m-d') . '.csv',
            [
                'Reservation_ID',
                'Passenger_Name',
                'Email',
                'Source',
                'Destination',
                'Date',
                'Departure_Time',
                'Arrival_Time',
                'Bus_Number',
                'Seat_Number',
                'Booking_Date',
                'Status'
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    if ($type === 'passengers') {
        $stmt = $pdo->query("
            SELECT
                Passenger_ID,
                First_Name,
                Last_Name,
                Email,
                Phone,
                Gender
            FROM Passenger
            ORDER BY Passenger_ID DESC
        ");

        export_csv(
            'lebanease_passengers_' . date('Y-m-d') . '.csv',
            [
                'Passenger_ID',
                'First_Name',
                'Last_Name',
                'Email',
                'Phone',
                'Gender'
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    if ($type === 'activity_log') {
        if (!export_table_exists($pdo, 'Admin_Activity_Log')) {
            export_csv('lebanease_activity_log_' . date('Y-m-d') . '.csv', ['Log_ID','Admin_ID','Admin_Name','Event_Type','Entity_Name','Entity_ID','Description','Created_At'], []);
        }
        $stmt = $pdo->query("
            SELECT
                l.Log_ID,
                l.Admin_ID,
                CONCAT(a.First_Name, ' ', a.Last_Name) AS Admin_Name,
                l.Event_Type,
                l.Entity_Name,
                l.Entity_ID,
                l.Description,
                l.Created_At
            FROM Admin_Activity_Log l
            LEFT JOIN Admin a ON a.Admin_ID = l.Admin_ID
            ORDER BY l.Created_At DESC, l.Log_ID DESC
        ");

        export_csv(
            'lebanease_activity_log_' . date('Y-m-d') . '.csv',
            [
                'Log_ID',
                'Admin_ID',
                'Admin_Name',
                'Event_Type',
                'Entity_Name',
                'Entity_ID',
                'Description',
                'Created_At'
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    if ($type === 'maintenance') {
        $stmt = $pdo->query("
            SELECT
                m.Maintenance_ID,
                b.Bus_Number,
                m.Date,
                m.Description,
                m.Cost
            FROM Bus_Maintenance m
            JOIN Bus b ON b.Bus_ID = m.Bus_ID
            ORDER BY m.Date DESC, m.Maintenance_ID DESC
        ");

        export_csv(
            'lebanease_maintenance_' . date('Y-m-d') . '.csv',
            [
                'Maintenance_ID',
                'Bus_Number',
                'Date',
                'Description',
                'Cost'
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    if ($type === 'payments') {
        if (!export_table_exists($pdo, 'Payment')) {
            export_csv('lebanease_payments_' . date('Y-m-d') . '.csv', ['Payment_ID','Amount','Payment_Date','Payment_Method','Reservation_ID'], []);
        }
        $stmt = $pdo->query("
            SELECT
                p.Payment_ID,
                p.Amount,
                p.Payment_Date,
                p.Payment_Method,
                p.Reservation_ID
            FROM Payment p
            ORDER BY p.Payment_Date DESC, p.Payment_ID DESC
        ");

        export_csv(
            'lebanease_payments_' . date('Y-m-d') . '.csv',
            [
                'Payment_ID',
                'Amount',
                'Payment_Date',
                'Payment_Method',
                'Reservation_ID'
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }
} catch (Exception $e) {
    die("Export failed: " . htmlspecialchars($e->getMessage()));
}
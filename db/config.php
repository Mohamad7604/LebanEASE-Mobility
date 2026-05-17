<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host   = "localhost";
$dbname = "LebanEase";   // Import database/LebanEase_updated.sql into this database.
$user   = "root";
$pass   = "";         // XAMPP default is usually empty: "". Change this if your MySQL uses another password.

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit("Database connection failed. Check db/config.php and make sure you imported database/LebanEase_updated.sql.");
}


if (!function_exists('lebanease_estimated_fare')) {
    function lebanease_estimated_fare($distance): float {
        $distance = max(0.0, (float)$distance);
        return round(max(3.00, 2.00 + ($distance * 0.15)), 2);
    }
}

if (!function_exists('lebanease_fare_label')) {
    function lebanease_fare_label($distance): string {
        return '$' . number_format(lebanease_estimated_fare($distance), 2);
    }
}

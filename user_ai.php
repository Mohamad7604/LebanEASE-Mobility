<?php
require "db/config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");
date_default_timezone_set('Asia/Beirut');

function json_reply(bool $ok, string $answer): void {
    echo json_encode([
        "ok" => $ok,
        "answer" => $answer
    ]);
    exit;
}

function contains_any(string $text, array $words): bool {
    foreach ($words as $word) {
        if (str_contains($text, $word)) {
            return true;
        }
    }
    return false;
}

function extract_openai_text(array $response): string {
    if (!empty($response["output_text"])) {
        return trim((string)$response["output_text"]);
    }

    if (!empty($response["output"]) && is_array($response["output"])) {
        $parts = [];

        foreach ($response["output"] as $item) {
            if (!empty($item["content"]) && is_array($item["content"])) {
                foreach ($item["content"] as $content) {
                    if (!empty($content["text"])) {
                        $parts[] = $content["text"];
                    }
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    return "";
}

function format_trip_list(array $trips, string $intro): string {
    if (empty($trips)) {
        return "I could not find upcoming trips right now. The database may contain schedules, but they may be old dates or not available for future booking.";
    }

    $answer = $intro . "\n\n";

    foreach ($trips as $trip) {
        $answer .= "- Trip #" . $trip["Schedule_ID"] . ": "
            . $trip["Source"] . " → " . $trip["Destination"]
            . " on " . $trip["Date"]
            . " at " . substr($trip["Departure_Time"], 0, 5)
            . " to " . substr($trip["Arrival_Time"], 0, 5)
            . ", Bus " . $trip["Bus_Number"]
            . ", Available seats: " . $trip["Available_Seats"]
            . "\n";
    }

    $answer .= "\nTo reserve one, go to the Search page and enter the same source, destination, and date.";

    return $answer;
}

function fallback_answer(string $question, array $snapshot): string {
    $q = strtolower($question);

    if (contains_any($q, ["reservation", "booking", "bookings"])) {
        if (!empty($snapshot["recent_reservations"])) {
            $answer = "You currently have " . $snapshot["active_reservations"] . " active reservation(s).\n\nYour recent reservations:\n\n";

            foreach ($snapshot["recent_reservations"] as $r) {
                $answer .= "- Reservation #" . $r["Reservation_ID"] . ": "
                    . $r["Source"] . " → " . $r["Destination"]
                    . " on " . $r["Date"]
                    . " at " . substr($r["Departure_Time"], 0, 5)
                    . ", Seat " . $r["Seat_Number"]
                    . ", Status: " . $r["Reservation_Status"]
                    . "\n";
            }

            $answer .= "\nYou can open My Reservations to view, cancel eligible reservations, or print your ticket.";
            return $answer;
        }

        return "You currently have no reservations. You can search for trips from the Search page and reserve an available seat.";
    }

    if (contains_any($q, ["ticket", "pdf", "print", "receipt"])) {
        return "To print your ticket, go to My Reservations, click the Ticket button next to your reservation, then choose Print / Save as PDF.";
    }

    if (contains_any($q, ["cancel", "cancellation"])) {
        return "You can cancel a reservation only if it is still active and the trip has not started yet. Go to My Reservations and click Cancel Reservation if the button is available.";
    }

    if (contains_any($q, ["trip", "trips", "route", "routes", "available", "schedule", "schedules"])) {
        if (!empty($snapshot["upcoming_trips"])) {
            return format_trip_list(
                $snapshot["upcoming_trips"],
                "Here are some upcoming available trips:"
            );
        }

        if (!empty($snapshot["all_recent_trips"])) {
            return format_trip_list(
                $snapshot["all_recent_trips"],
                "I did not find future trips, but these schedules exist in the database:"
            );
        }

        return "I could not find any trip schedules in the database. Ask an admin to add schedules first.";
    }

    if (contains_any($q, ["payment", "paid", "pay", "price", "cost"])) {
        return "The current MVP does not include online payment. Reservations and tickets show an estimated fare only.";
    }

    if (contains_any($q, ["not show", "doesn't show", "dont show", "can't see", "cannot see"])) {
        return "Trips may exist in the database, but the Search page only shows trips that match the exact source, destination, and date you enter. Try using the same source, destination, and date shown in the assistant trip list.";
    }

    return "I can help you with reservations, tickets, cancellations, available trips, and payment status. Try asking: What upcoming trips are available?";
}

if (empty($_SESSION["passenger_id"])) {
    json_reply(false, "Please log in as a passenger first.");
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$question = trim($data["question"] ?? "");

if ($question === "") {
    json_reply(false, "Please type a question first.");
}

if (strlen($question) > 500) {
    json_reply(false, "Please keep your question under 500 characters.");
}

$passengerId = (int)$_SESSION["passenger_id"];

try {
    $stmt = $pdo->prepare("
        SELECT Passenger_ID, First_Name, Last_Name, Email, Phone, Gender
        FROM Passenger
        WHERE Passenger_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$passengerId]);
    $passenger = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$passenger) {
        json_reply(false, "Passenger account not found.");
    }

    $activeStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM Reservation
        WHERE Passenger_ID = ?
          AND Status = 'Active'
    ");
    $activeStmt->execute([$passengerId]);
    $activeReservations = (int)$activeStmt->fetchColumn();

    $scheduledTrips = (int)$pdo->query("
        SELECT COUNT(*)
        FROM Bus_Schedule
        WHERE Date >= CURDATE()
    ")->fetchColumn();

    $reservationsStmt = $pdo->prepare("
        SELECT
            res.Reservation_ID,
            res.Seat_Number,
            res.Booking_Date,
            res.Status AS Reservation_Status,
            bs.Date,
            bs.Departure_Time,
            bs.Arrival_Time,
            r.Source,
            r.Destination,
            b.Bus_Number
        FROM Reservation res
        JOIN Bus_Schedule bs ON bs.Schedule_ID = res.Schedule_ID
        JOIN Route r ON r.Route_ID = bs.Route_ID
        JOIN Bus b ON b.Bus_ID = bs.Bus_ID
        WHERE res.Passenger_ID = ?
        ORDER BY bs.Date DESC, bs.Departure_Time DESC
        LIMIT 8
    ");
    $reservationsStmt->execute([$passengerId]);
    $recentReservations = $reservationsStmt->fetchAll(PDO::FETCH_ASSOC);

    $upcomingTripsStmt = $pdo->query("
        SELECT
            bs.Schedule_ID,
            bs.Date,
            bs.Departure_Time,
            bs.Arrival_Time,
            r.Source,
            r.Destination,
            b.Bus_Number,
            b.Capacity,
            b.Capacity - IFNULL(SUM(CASE WHEN res.Status = 'Active' THEN 1 ELSE 0 END), 0) AS Available_Seats
        FROM Bus_Schedule bs
        JOIN Route r ON r.Route_ID = bs.Route_ID
        JOIN Bus b ON b.Bus_ID = bs.Bus_ID
        LEFT JOIN Reservation res ON res.Schedule_ID = bs.Schedule_ID
        WHERE bs.Date >= CURDATE()
        GROUP BY
            bs.Schedule_ID,
            bs.Date,
            bs.Departure_Time,
            bs.Arrival_Time,
            r.Source,
            r.Destination,
            b.Bus_Number,
            b.Capacity
        ORDER BY bs.Date ASC, bs.Departure_Time ASC
        LIMIT 8
    ");
    $upcomingTrips = $upcomingTripsStmt->fetchAll(PDO::FETCH_ASSOC);

    $allRecentTripsStmt = $pdo->query("
        SELECT
            bs.Schedule_ID,
            bs.Date,
            bs.Departure_Time,
            bs.Arrival_Time,
            r.Source,
            r.Destination,
            b.Bus_Number,
            b.Capacity,
            b.Capacity - IFNULL(SUM(CASE WHEN res.Status = 'Active' THEN 1 ELSE 0 END), 0) AS Available_Seats
        FROM Bus_Schedule bs
        JOIN Route r ON r.Route_ID = bs.Route_ID
        JOIN Bus b ON b.Bus_ID = bs.Bus_ID
        LEFT JOIN Reservation res ON res.Schedule_ID = bs.Schedule_ID
        GROUP BY
            bs.Schedule_ID,
            bs.Date,
            bs.Departure_Time,
            bs.Arrival_Time,
            r.Source,
            r.Destination,
            b.Bus_Number,
            b.Capacity
        ORDER BY bs.Date DESC, bs.Departure_Time DESC
        LIMIT 8
    ");
    $allRecentTrips = $allRecentTripsStmt->fetchAll(PDO::FETCH_ASSOC);

    $snapshot = [
        "passenger_name" => $passenger["First_Name"] . " " . $passenger["Last_Name"],
        "active_reservations" => $activeReservations,
        "scheduled_trips" => $scheduledTrips,
        "recent_reservations" => $recentReservations,
        "upcoming_trips" => $upcomingTrips,
        "all_recent_trips" => $allRecentTrips
    ];

    $q = strtolower($question);

    /*
      Important:
      For trip-listing questions, we answer directly from the database.
      This avoids vague AI answers like "there are 8 trips" without showing them.
    */
    if (contains_any($q, ["trip", "trips", "route", "routes", "available", "schedule", "schedules"])) {
        json_reply(true, fallback_answer($question, $snapshot));
    }

    $aiConfigPath = __DIR__ . "/admin/ai_config.php";

    if (!file_exists($aiConfigPath)) {
        json_reply(true, fallback_answer($question, $snapshot));
    }

    require_once $aiConfigPath;

    if (!defined("LEBANEASE_AI_API_KEY") || trim(LEBANEASE_AI_API_KEY) === "") {
        json_reply(true, fallback_answer($question, $snapshot));
    }

    if (!function_exists("curl_init")) {
        json_reply(true, fallback_answer($question, $snapshot));
    }

    $model = defined("LEBANEASE_AI_MODEL") && trim(LEBANEASE_AI_MODEL) !== ""
        ? LEBANEASE_AI_MODEL
        : "gpt-4o-mini";

    $systemPrompt = "
You are the LebanEASE Passenger AI Assistant.
You help passengers with reservations, tickets, trip search, cancellations, and payment status.
Use only the database snapshot provided.
Do not invent trips, reservations, prices, or policies.
Keep the answer short, friendly, and practical.

Rules:
1. If the user asks about reservations, mention their active reservations and recent reservations.
2. If the user asks about tickets or PDF, explain that they should go to My Reservations and click Ticket.
3. If the user asks about cancellation, explain that only active future reservations can be cancelled.
4. If the user asks about payment, explain whether payment info is pending or shown on ticket.
5. Do not perform admin-only actions.
";

    $inputText =
        "Passenger question:\n" . $question .
        "\n\nDatabase snapshot:\n" .
        json_encode($snapshot, JSON_PRETTY_PRINT);

    $payload = [
        "model" => $model,
        "input" => [
            [
                "role" => "system",
                "content" => $systemPrompt
            ],
            [
                "role" => "user",
                "content" => $inputText
            ]
        ]
    ];

    $ch = curl_init("https://api.openai.com/v1/responses");

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . LEBANEASE_AI_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30
    ]);

    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $curlError || $statusCode < 200 || $statusCode >= 300) {
        json_reply(true, fallback_answer($question, $snapshot));
    }

    $decoded = json_decode($result, true);

    if (!is_array($decoded)) {
        json_reply(true, fallback_answer($question, $snapshot));
    }

    $answer = extract_openai_text($decoded);

    if ($answer === "") {
        $answer = fallback_answer($question, $snapshot);
    }

    json_reply(true, $answer);

} catch (Exception $e) {
    json_reply(false, "Something went wrong while reading your account data.");
}
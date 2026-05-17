<?php
require "auth.php";
require_admin();
require "../db/config.php";

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

function rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function scalar(PDO $pdo, string $sql, array $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
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

function ai_dispatch_summary(array $snapshot): string {
    $configPath = __DIR__ . "/ai_config.php";

    if (!file_exists($configPath)) {
        return "";
    }

    require_once $configPath;

    if (!defined("LEBANEASE_AI_API_KEY") || trim(LEBANEASE_AI_API_KEY) === "") {
        return "";
    }

    if (!function_exists("curl_init")) {
        return "";
    }

    $model = defined("LEBANEASE_AI_MODEL") && trim(LEBANEASE_AI_MODEL) !== ""
        ? LEBANEASE_AI_MODEL
        : "gpt-4o-mini";

    $systemPrompt = "
You are the LebanEASE Smart Dispatch Engine.
You help a bus transportation admin make scheduling and bus assignment decisions.
Use only the provided database snapshot.
Give clear, practical dispatch recommendations.
Do not invent routes, buses, or reservations.
Answer in 5 concise bullet points maximum.
";

    $inputText = "Dispatch data snapshot:\n" . json_encode($snapshot, JSON_PRETTY_PRINT);

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
        return "";
    }

    $decoded = json_decode($result, true);

    if (!is_array($decoded)) {
        return "";
    }

    return extract_openai_text($decoded);
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

$routeDemand = rows($pdo, "
    SELECT
        r.Route_ID,
        r.Source,
        r.Destination,
        r.Distance,
        COUNT(s.Schedule_ID) AS Total_Trips,
        SUM(CASE WHEN s.Date >= CURDATE() THEN 1 ELSE 0 END) AS Upcoming_Trips,
        IFNULL(SUM(s.Capacity), 0) AS Total_Capacity,
        IFNULL(SUM(s.Active_Reservations), 0) AS Active_Reservations,
        IFNULL(SUM(s.Cancelled_Reservations), 0) AS Cancelled_Reservations
    FROM Route r
    LEFT JOIN (
        SELECT
            bs.Schedule_ID,
            bs.Route_ID,
            bs.Date,
            b.Capacity,
            IFNULL(SUM(CASE WHEN res.Status = 'Active' THEN 1 ELSE 0 END), 0) AS Active_Reservations,
            IFNULL(SUM(CASE WHEN res.Status = 'Cancelled' THEN 1 ELSE 0 END), 0) AS Cancelled_Reservations
        FROM Bus_Schedule bs
        JOIN Bus b ON b.Bus_ID = bs.Bus_ID
        LEFT JOIN Reservation res ON res.Schedule_ID = bs.Schedule_ID
        GROUP BY bs.Schedule_ID, bs.Route_ID, bs.Date, b.Capacity
    ) s ON s.Route_ID = r.Route_ID
    GROUP BY r.Route_ID, r.Source, r.Destination, r.Distance
");

foreach ($routeDemand as &$route) {
    $capacity = (float)$route["Total_Capacity"];
    $active = (int)$route["Active_Reservations"];
    $cancelled = (int)$route["Cancelled_Reservations"];
    $upcoming = (int)$route["Upcoming_Trips"];

    $utilization = $capacity > 0 ? round(($active / $capacity) * 100, 1) : 0;

    $route["Utilization"] = $utilization;

    $route["Demand_Score"] = round(
        ($active * 12) +
        ($utilization * 1.4) +
        ($upcoming * 3) -
        ($cancelled * 1.5),
        1
    );
}
unset($route);

usort($routeDemand, function($a, $b) {
    return $b["Demand_Score"] <=> $a["Demand_Score"];
});

$topRoute = $routeDemand[0] ?? null;

$nextSuggestedDate = date("Y-m-d", strtotime("+1 day"));

if ($topRoute) {
    $possibleDate = scalar($pdo, "
        SELECT MIN(Date)
        FROM Bus_Schedule
        WHERE Route_ID = ?
          AND Date >= CURDATE()
    ", [(int)$topRoute["Route_ID"]]);

    if ($possibleDate) {
        $nextSuggestedDate = $possibleDate;
    }
}

$hasBusDriver = column_exists($pdo, "Bus", "Driver_ID");
$hasMaintenanceStatus = column_exists($pdo, "Bus_Maintenance", "Maintenance_Status");

$driverSelect = $hasBusDriver
    ? "CONCAT(d.First_Name, ' ', d.Last_Name) AS Driver_Name"
    : "NULL AS Driver_Name";

$driverJoin = $hasBusDriver
    ? "LEFT JOIN Driver d ON d.Driver_ID = b.Driver_ID"
    : "";

$maintenanceSubquery = table_exists($pdo, "Bus_Maintenance")
    ? (
        $hasMaintenanceStatus
            ? "
                SELECT Bus_ID, COUNT(*) AS Open_Maintenance
                FROM Bus_Maintenance
                WHERE LOWER(Maintenance_Status) NOT IN ('completed', 'closed', 'done')
                GROUP BY Bus_ID
              "
            : "
                SELECT Bus_ID, COUNT(*) AS Open_Maintenance
                FROM Bus_Maintenance
                GROUP BY Bus_ID
              "
    )
    : "
        SELECT NULL AS Bus_ID, 0 AS Open_Maintenance
      ";

$buses = rows($pdo, "
    SELECT
        b.Bus_ID,
        b.Bus_Number,
        b.Capacity,
        b.Type,
        b.Status,
        $driverSelect,
        IFNULL(m.Open_Maintenance, 0) AS Open_Maintenance,
        IFNULL(a.Future_Assignments, 0) AS Future_Assignments
    FROM Bus b
    $driverJoin
    LEFT JOIN ($maintenanceSubquery) m ON m.Bus_ID = b.Bus_ID
    LEFT JOIN (
        SELECT Bus_ID, COUNT(*) AS Future_Assignments
        FROM Bus_Schedule
        WHERE Date >= CURDATE()
        GROUP BY Bus_ID
    ) a ON a.Bus_ID = b.Bus_ID
    ORDER BY b.Capacity DESC, b.Bus_Number ASC
");

$eligibleBuses = [];
$avoidBuses = [];

foreach ($buses as $bus) {
    $status = strtolower((string)$bus["Status"]);
    $openMaintenance = (int)$bus["Open_Maintenance"];

    $isActive = str_contains($status, "active");
    $isMaintenance = str_contains($status, "maintenance") || str_contains($status, "unavailable") || str_contains($status, "inactive");

    if ($isActive && !$isMaintenance && $openMaintenance === 0) {
        $eligibleBuses[] = $bus;
    } else {
        $avoidBuses[] = $bus;
    }
}

usort($eligibleBuses, function($a, $b) {
    if ((int)$a["Future_Assignments"] === (int)$b["Future_Assignments"]) {
        return (int)$b["Capacity"] <=> (int)$a["Capacity"];
    }

    return (int)$a["Future_Assignments"] <=> (int)$b["Future_Assignments"];
});

$recommendedBus = $eligibleBuses[0] ?? null;

$futureTrips = rows($pdo, "
    SELECT
        bs.Schedule_ID,
        bs.Date,
        bs.Departure_Time,
        bs.Arrival_Time,
        r.Source,
        r.Destination,
        b.Bus_Number,
        b.Capacity,
        IFNULL(SUM(CASE WHEN res.Status = 'Active' THEN 1 ELSE 0 END), 0) AS Reserved_Seats,
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
    ORDER BY
        (IFNULL(SUM(CASE WHEN res.Status = 'Active' THEN 1 ELSE 0 END), 0) / NULLIF(b.Capacity, 0)) DESC,
        Available_Seats ASC
    LIMIT 8
");

if (empty($futureTrips)) {
    $futureTrips = rows($pdo, "
        SELECT
            bs.Schedule_ID,
            bs.Date,
            bs.Departure_Time,
            bs.Arrival_Time,
            r.Source,
            r.Destination,
            b.Bus_Number,
            b.Capacity,
            IFNULL(SUM(CASE WHEN res.Status = 'Active' THEN 1 ELSE 0 END), 0) AS Reserved_Seats,
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
}

$lowDemandRoutes = [];

foreach ($routeDemand as $route) {
    if ((int)$route["Total_Trips"] > 0 && ((int)$route["Active_Reservations"] === 0 || (float)$route["Utilization"] < 10)) {
        $lowDemandRoutes[] = $route;
    }
}

$recommendationTitle = "No strong dispatch action needed";
$recommendationText = "Current demand is stable. Continue monitoring reservations and maintenance status.";

if ($topRoute) {
    if ((float)$topRoute["Demand_Score"] > 20 || (int)$topRoute["Active_Reservations"] >= 3) {
        $recommendationTitle = "Add or monitor an extra trip";
        $recommendationText =
            "The route " . $topRoute["Source"] . " → " . $topRoute["Destination"] .
            " has the strongest demand signal. Consider adding another trip on " .
            $nextSuggestedDate . " around peak hours, especially if reservations continue increasing.";
    } else {
        $recommendationTitle = "Monitor demand before adding trips";
        $recommendationText =
            "The highest-demand route is " . $topRoute["Source"] . " → " . $topRoute["Destination"] .
            ", but the current demand score is still moderate. Monitor reservations before assigning extra buses.";
    }
}

$busRecommendation = "No eligible bus recommendation available.";

if ($recommendedBus) {
    $busRecommendation =
        "Recommended bus: " . $recommendedBus["Bus_Number"] .
        " (" . $recommendedBus["Type"] . ", capacity " . $recommendedBus["Capacity"] . "). " .
        "It is active, has no open maintenance warning, and currently has " .
        $recommendedBus["Future_Assignments"] . " upcoming assignment(s).";
}

$dispatchSnapshot = [
    "top_route" => $topRoute,
    "recommended_bus" => $recommendedBus,
    "avoid_buses" => array_slice($avoidBuses, 0, 5),
    "almost_full_or_most_used_trips" => $futureTrips,
    "low_demand_routes" => array_slice($lowDemandRoutes, 0, 5),
    "core_recommendation" => $recommendationText,
    "bus_recommendation" => $busRecommendation
];

$aiSummary = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["generate_ai_summary"])) {
    $aiSummary = ai_dispatch_summary($dispatchSnapshot);
}

if ($aiSummary === "") {
    $aiSummary =
        "Recommended action: " . $recommendationText . "\n\n" .
        $busRecommendation . "\n\n" .
        "Avoid assigning buses marked as Maintenance, Unavailable, or with open maintenance records.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Smart Dispatch Engine | LebanEASE Admin</title>
<link rel="stylesheet" href="../css/style.css">
<style>
.dispatch-grid {
    display: grid;
    grid-template-columns: 1.2fr .8fr;
    gap: 16px;
    align-items: start;
}

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}

.kpi-card {
    min-height: 115px;
}

.kpi-number {
    font-size: 30px;
    font-weight: 950;
    margin-top: 8px;
}

.engine-card {
    margin-bottom: 16px;
}

.score-pill,
.ok-pill,
.warn-pill,
.info-pill {
    display: inline-flex;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    border: 1px solid rgba(255,255,255,.14);
    margin: 3px;
}

.score-pill {
    background: rgba(168,85,247,.18);
    color: #d8b4fe;
}

.ok-pill {
    background: rgba(34,197,94,.18);
    color: #86efac;
}

.warn-pill {
    background: rgba(245,158,11,.18);
    color: #fcd34d;
}

.info-pill {
    background: rgba(59,130,246,.18);
    color: #93c5fd;
}

.route-row,
.bus-row,
.trip-row {
    padding: 13px 0;
    border-bottom: 1px solid rgba(255,255,255,.08);
    line-height: 1.65;
}

.route-row:last-child,
.bus-row:last-child,
.trip-row:last-child {
    border-bottom: none;
}

.progress-track {
    height: 10px;
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    overflow: hidden;
    margin-top: 8px;
}

.progress-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(135deg, #35a7ff, #a855f7);
}

.big-rec {
    padding: 20px;
    border-radius: 24px;
    background: linear-gradient(135deg, rgba(53,167,255,.18), rgba(168,85,247,.18));
    border: 1px solid rgba(255,255,255,.14);
    line-height: 1.8;
}

.ai-output {
    white-space: pre-wrap;
    line-height: 1.8;
}

@media(max-width:1000px) {
    .dispatch-grid,
    .kpi-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('dispatch'); ?>

<main class="section">
<div class="container">

<h2 class="section-title">LebanEASE Smart Dispatch Engine</h2>
<p class="muted mb-14">
    AI-style decision support for route demand, bus assignment, seat pressure, maintenance risk, and scheduling recommendations.
</p>

<div class="kpi-grid">
    <div class="card glass kpi-card">
        <div class="muted">Top Demand Route</div>
        <div class="kpi-number">
            <?= $topRoute ? h($topRoute["Source"] . " → " . $topRoute["Destination"]) : "N/A" ?>
        </div>
        <?php if ($topRoute): ?>
            <span class="score-pill">Score <?= h($topRoute["Demand_Score"]) ?></span>
        <?php endif; ?>
    </div>

    <div class="card glass kpi-card">
        <div class="muted">Recommended Bus</div>
        <div class="kpi-number">
            <?= $recommendedBus ? h($recommendedBus["Bus_Number"]) : "N/A" ?>
        </div>
        <?php if ($recommendedBus): ?>
            <span class="ok-pill">Capacity <?= (int)$recommendedBus["Capacity"] ?></span>
        <?php endif; ?>
    </div>

    <div class="card glass kpi-card">
        <div class="muted">Buses to Avoid</div>
        <div class="kpi-number"><?= count($avoidBuses) ?></div>
        <span class="warn-pill">Maintenance / unavailable</span>
    </div>

    <div class="card glass kpi-card">
        <div class="muted">Low-Demand Routes</div>
        <div class="kpi-number"><?= count($lowDemandRoutes) ?></div>
        <span class="info-pill">Optimization opportunity</span>
    </div>
</div>

<div class="dispatch-grid">

    <div>

        <div class="card glass engine-card">
            <h3>Main Dispatch Recommendation</h3>
            <div class="h-12"></div>

            <div class="big-rec">
                <h3><?= h($recommendationTitle) ?></h3>
                <p><?= h($recommendationText) ?></p>

                <div class="h-12"></div>

                <strong>Bus recommendation:</strong>
                <p><?= h($busRecommendation) ?></p>
            </div>
        </div>

        <div class="card glass engine-card">
            <h3>Route Demand Ranking</h3>
            <p class="muted tiny">
                Demand score combines active reservations, utilization, upcoming trips, and cancellations.
            </p>

            <div class="h-12"></div>

            <?php foreach (array_slice($routeDemand, 0, 8) as $route): ?>
                <?php
                    $score = max(0, min(100, (float)$route["Demand_Score"]));
                    $utilization = max(0, min(100, (float)$route["Utilization"]));
                ?>
                <div class="route-row">
                    <strong><?= h($route["Source"]) ?> → <?= h($route["Destination"]) ?></strong>
                    <br>
                    <span class="score-pill">Demand score: <?= h($route["Demand_Score"]) ?></span>
                    <span class="info-pill"><?= (int)$route["Active_Reservations"] ?> active reservations</span>
                    <span class="info-pill"><?= h($route["Utilization"]) ?>% utilization</span>
                    <span class="info-pill"><?= (int)$route["Upcoming_Trips"] ?> upcoming trip(s)</span>

                    <div class="progress-track">
                        <div class="progress-fill" style="width: <?= $score ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($routeDemand)): ?>
                <p class="muted">No route data available.</p>
            <?php endif; ?>
        </div>

        <div class="card glass engine-card">
            <h3>Seat Pressure / Most Used Trips</h3>
            <p class="muted tiny">
                Trips with higher seat usage appear first. These are the trips the admin should monitor.
            </p>

            <div class="h-12"></div>

            <?php foreach ($futureTrips as $trip): ?>
                <?php
                    $capacity = (int)$trip["Capacity"];
                    $reserved = (int)$trip["Reserved_Seats"];
                    $available = (int)$trip["Available_Seats"];
                    $occupancy = $capacity > 0 ? round(($reserved / $capacity) * 100, 1) : 0;
                ?>
                <div class="trip-row">
                    <strong>Trip #<?= (int)$trip["Schedule_ID"] ?>:</strong>
                    <?= h($trip["Source"]) ?> → <?= h($trip["Destination"]) ?>
                    <br>
                    <span class="info-pill"><?= h($trip["Date"]) ?> at <?= h(substr($trip["Departure_Time"], 0, 5)) ?></span>
                    <span class="info-pill">Bus <?= h($trip["Bus_Number"]) ?></span>
                    <span class="<?= $available <= 5 ? 'warn-pill' : 'ok-pill' ?>"><?= $available ?> seats left</span>
                    <span class="score-pill"><?= $occupancy ?>% occupied</span>

                    <div class="progress-track">
                        <div class="progress-fill" style="width: <?= min(100, $occupancy) ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($futureTrips)): ?>
                <p class="muted">No trip data available.</p>
            <?php endif; ?>
        </div>

    </div>

    <div>

        <div class="card glass engine-card">
            <h3>Smart Dispatch Summary</h3>
            <p class="muted tiny">
                Generated from route demand, bus capacity, seat usage, and maintenance risk.
            </p>

            <div class="h-12"></div>

            <div class="ai-output"><?= h($aiSummary) ?></div>

            <div class="h-12"></div>

            <form method="POST">
                <button class="btn btn-primary" type="submit" name="generate_ai_summary">
                    Generate AI Dispatch Summary
                </button>
            </form>
        </div>

        <div class="card glass engine-card">
            <h3>Best Available Buses</h3>
            <p class="muted tiny">
                Active buses with no open maintenance warning, sorted by lower assignment load and higher capacity.
            </p>

            <div class="h-12"></div>

            <?php foreach (array_slice($eligibleBuses, 0, 6) as $bus): ?>
                <div class="bus-row">
                    <strong><?= h($bus["Bus_Number"]) ?></strong>
                    <br>
                    <span class="ok-pill"><?= h($bus["Status"]) ?></span>
                    <span class="info-pill"><?= h($bus["Type"]) ?></span>
                    <span class="info-pill">Capacity <?= (int)$bus["Capacity"] ?></span>
                    <span class="info-pill"><?= (int)$bus["Future_Assignments"] ?> upcoming assignment(s)</span>
                    <?php if (!empty($bus["Driver_Name"])): ?>
                        <br><span class="muted tiny">Driver: <?= h($bus["Driver_Name"]) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (empty($eligibleBuses)): ?>
                <p class="muted">No eligible buses found.</p>
            <?php endif; ?>
        </div>

        <div class="card glass engine-card">
            <h3>Buses to Avoid</h3>
            <p class="muted tiny">
                These buses should not be assigned until status or maintenance is reviewed.
            </p>

            <div class="h-12"></div>

            <?php foreach (array_slice($avoidBuses, 0, 6) as $bus): ?>
                <div class="bus-row">
                    <strong><?= h($bus["Bus_Number"]) ?></strong>
                    <br>
                    <span class="warn-pill"><?= h($bus["Status"]) ?></span>
                    <span class="info-pill"><?= h($bus["Type"]) ?></span>
                    <span class="info-pill">Open maintenance: <?= (int)$bus["Open_Maintenance"] ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (empty($avoidBuses)): ?>
                <p class="muted">No buses currently need avoidance.</p>
            <?php endif; ?>
        </div>

        <div class="card glass engine-card">
            <h3>Low-Demand Routes</h3>
            <p class="muted tiny">
                These routes may not need extra trips right now.
            </p>

            <div class="h-12"></div>

            <?php foreach (array_slice($lowDemandRoutes, 0, 6) as $route): ?>
                <div class="route-row">
                    <strong><?= h($route["Source"]) ?> → <?= h($route["Destination"]) ?></strong>
                    <br>
                    <span class="info-pill"><?= (int)$route["Active_Reservations"] ?> active reservations</span>
                    <span class="info-pill"><?= h($route["Utilization"]) ?>% utilization</span>
                    <span class="warn-pill">No extra trip recommended</span>
                </div>
            <?php endforeach; ?>

            <?php if (empty($lowDemandRoutes)): ?>
                <p class="muted">No clearly low-demand routes found.</p>
            <?php endif; ?>
        </div>

    </div>

</div>

</div>
</main>

</body>
</html>
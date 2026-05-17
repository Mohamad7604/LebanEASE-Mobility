<?php
require "auth.php";
require_admin();
require "../db/config.php";
require "ai_config.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function safe_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function safe_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function get_api_key(): string {
    $fromConfig = trim((string)(defined('LEBANEASE_AI_API_KEY') ? LEBANEASE_AI_API_KEY : ''));
    if ($fromConfig !== '') return $fromConfig;
    $fromEnv = trim((string)getenv('OPENAI_API_KEY'));
    return $fromEnv;
}

function build_operations_context(PDO $pdo): array {
    $summary = [
        'scheduled_trips' => safe_count($pdo, "SELECT COUNT(*) FROM v_schedule_overview WHERE Trip_Status='Scheduled'"),
        'active_reservations' => safe_count($pdo, "SELECT COUNT(*) FROM Reservation WHERE Status='Active'"),
        'cancelled_reservations' => safe_count($pdo, "SELECT COUNT(*) FROM Reservation WHERE Status='Cancelled'"),
        'completed_reservations' => safe_count($pdo, "SELECT COUNT(*) FROM Reservation WHERE Status='Completed'"),
        'fleet_alerts' => safe_count($pdo, "SELECT COUNT(*) FROM Bus WHERE Status IN ('Maintenance','Unavailable','Offline')"),
        'open_maintenance' => safe_count($pdo, "SELECT COUNT(*) FROM Bus_Maintenance WHERE Maintenance_Status='Open'"),
        'almost_full_trips' => safe_count($pdo, "SELECT COUNT(*) FROM v_schedule_overview WHERE Trip_Status='Scheduled' AND Available_Seats <= 5"),
        'active_buses' => safe_count($pdo, "SELECT COUNT(*) FROM Bus WHERE Status='Active'"),
        'total_buses' => safe_count($pdo, "SELECT COUNT(*) FROM Bus"),
        'active_drivers' => safe_count($pdo, "SELECT COUNT(*) FROM Driver WHERE Driver_Status='Active'"),
        'total_drivers' => safe_count($pdo, "SELECT COUNT(*) FROM Driver"),
    ];

    $busyRoutes = safe_rows($pdo, "
        SELECT r.Source, r.Destination, COUNT(res.Reservation_ID) AS active_reservations
        FROM Reservation res
        JOIN Bus_Schedule bs ON res.Schedule_ID = bs.Schedule_ID
        JOIN Route r ON bs.Route_ID = r.Route_ID
        WHERE res.Status='Active'
        GROUP BY r.Route_ID, r.Source, r.Destination
        ORDER BY active_reservations DESC
        LIMIT 5
    ");

    $seatRisks = safe_rows($pdo, "
        SELECT Schedule_ID, Date, Departure_Time, Source, Destination, Bus_Number, Capacity, Reserved_Seats, Available_Seats
        FROM v_schedule_overview
        WHERE Trip_Status='Scheduled' AND Available_Seats <= 5
        ORDER BY Date ASC, Departure_Time ASC
        LIMIT 5
    ");

    $maintenanceRisks = safe_rows($pdo, "
        SELECT b.Bus_Number, b.Status, bm.Date, bm.Description, bm.Maintenance_Status
        FROM Bus b
        LEFT JOIN Bus_Maintenance bm ON b.Bus_ID = bm.Bus_ID AND bm.Maintenance_Status='Open'
        WHERE b.Status IN ('Maintenance','Unavailable','Offline') OR bm.Maintenance_Status='Open'
        ORDER BY FIELD(b.Status,'Maintenance','Unavailable','Offline','Active'), bm.Date DESC
        LIMIT 8
    ");

    $driverRisks = safe_rows($pdo, "
        SELECT First_Name, Last_Name, Driver_Status
        FROM Driver
        WHERE Driver_Status <> 'Active'
        ORDER BY Driver_Status, Last_Name
        LIMIT 6
    ");

    $highUtilization = safe_rows($pdo, "
        SELECT Schedule_ID, Date, Departure_Time, Source, Destination, Bus_Number, Capacity, Reserved_Seats, Available_Seats,
               ROUND((Reserved_Seats / NULLIF(Capacity,0)) * 100, 1) AS utilization_percent
        FROM v_schedule_overview
        WHERE Trip_Status='Scheduled'
        ORDER BY utilization_percent DESC, Reserved_Seats DESC
        LIMIT 5
    ");

    return [
        'summary' => $summary,
        'busy_routes' => $busyRoutes,
        'seat_risks' => $seatRisks,
        'maintenance_risks' => $maintenanceRisks,
        'driver_risks' => $driverRisks,
        'high_utilization_trips' => $highUtilization,
    ];
}

function fallback_ai_summary(array $context): string {
    $s = $context['summary'];
    $lines = [];
    $lines[] = "AI Operations Summary\n";
    $lines[] = "The system currently has {$s['scheduled_trips']} scheduled trip(s), {$s['active_reservations']} active reservation(s), and {$s['almost_full_trips']} almost-full trip(s).";
    $lines[] = "Fleet status: {$s['active_buses']} out of {$s['total_buses']} buses are active, with {$s['fleet_alerts']} fleet alert(s) and {$s['open_maintenance']} open maintenance record(s).";
    $lines[] = "Driver status: {$s['active_drivers']} out of {$s['total_drivers']} drivers are active.";
    $lines[] = "\nRecommendations:";

    if (!empty($context['maintenance_risks'])) {
        $lines[] = "1. Review buses under maintenance, unavailable, or offline before creating new schedules.";
    } else {
        $lines[] = "1. No critical fleet maintenance risk was detected from the current records.";
    }

    if (!empty($context['seat_risks'])) {
        $lines[] = "2. Monitor almost-full trips and consider adding another trip on the same route if demand continues.";
    } else {
        $lines[] = "2. Seat availability is currently stable because no scheduled trip is close to full capacity.";
    }

    if (!empty($context['busy_routes'])) {
        $top = $context['busy_routes'][0];
        $lines[] = "3. The busiest route appears to be {$top['Source']} → {$top['Destination']} with {$top['active_reservations']} active reservation(s).";
    } else {
        $lines[] = "3. No route demand hotspot was detected yet because there are not enough active reservations.";
    }

    if (!empty($context['driver_risks'])) {
        $lines[] = "4. Check inactive or unavailable drivers before assigning them to future trips.";
    } else {
        $lines[] = "4. Driver availability looks acceptable based on current records.";
    }

    $lines[] = "\nDemo note: This fallback summary appears because no AI API key is configured. After adding the key, the page will generate a live AI-written analysis.";
    return implode("\n", $lines);
}

function call_openai_ai(array $context, string $question): array {
    $apiKey = get_api_key();
    if ($apiKey === '') {
        return [false, 'No API key configured. Add it in admin/ai_config.php or set OPENAI_API_KEY.', null];
    }
    if (!function_exists('curl_init')) {
        return [false, 'PHP cURL is not enabled in XAMPP. Enable extension=curl in php.ini, then restart Apache.', null];
    }

    $model = defined('LEBANEASE_AI_MODEL') ? LEBANEASE_AI_MODEL : 'gpt-5.5';

    $instruction = "You are the LebanEASE Mobility AI Operations Advisor. " .
        "Analyze the provided bus management database snapshot and answer as a professional admin recommendation. " .
        "Keep the answer practical, concise, and demo-friendly. Use headings and bullet points. " .
        "Do not invent data that is not present. If data is missing, say so clearly.";

    $payload = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => $instruction
            ],
            [
                'role' => 'user',
                'content' => "Admin question: " . ($question !== '' ? $question : 'Generate a complete operations summary for today.') . "\n\nDatabase snapshot JSON:\n" . json_encode($context, JSON_PRETTY_PRINT)
            ]
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 35,
    ]);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $curlErr) {
        return [false, 'Network/API request failed: ' . $curlErr, null];
    }

    $data = json_decode($raw, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = $data['error']['message'] ?? ('API returned HTTP ' . $httpCode);
        return [false, $msg, $raw];
    }

    $text = $data['output_text'] ?? '';

    if ($text === '' && isset($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $item) {
            if (!empty($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $content) {
                    if (isset($content['text'])) $text .= $content['text'] . "\n";
                }
            }
        }
    }

    $text = trim($text);
    if ($text === '') {
        return [false, 'AI response was received but no readable text was found.', $raw];
    }

    return [true, $text, $raw];
}

$adminName = $_SESSION['admin_name'] ?? 'System Admin';
$context = build_operations_context($pdo);
$question = trim($_POST['question'] ?? '');
$aiText = '';
$aiError = '';
$usedFallback = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $result, $raw] = call_openai_ai($context, $question);
    if ($ok) {
        $aiText = $result;
    } else {
        $aiError = $result;
        $aiText = fallback_ai_summary($context);
        $usedFallback = true;
    }
}

function status_badge_class(string $status): string {
    $status = strtolower($status);
    if (str_contains($status, 'active')) return 'badge green';
    if (str_contains($status, 'maintenance') || str_contains($status, 'open')) return 'badge orange';
    if (str_contains($status, 'offline') || str_contains($status, 'unavailable')) return 'badge red';
    return 'badge';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Real AI Advisor | LebanEASE</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    /* Compact AI page fix: keeps the page inside the screen and reduces card sizes */
    body{overflow-x:hidden;}
    .container{width:min(1050px, calc(100% - 28px));}
    .section{padding:26px 0 42px;}
    .section-title{font-size:22px;margin-bottom:8px;line-height:1.2;}

    /* Smaller navbar on this AI page */
    .nav-inner{gap:10px;padding:10px 0;}
    .brand{font-size:17px;white-space:nowrap;}
    .nav-links{gap:6px;flex-wrap:wrap;}
    .nav-links a{padding:7px 8px;font-size:14px;border-radius:10px;}
    .nav .btn{padding:8px 10px;border-radius:12px;}
    .nav .tiny{margin-top:0;}

    .ai-layout{
      display:grid;
      grid-template-columns:minmax(0,1fr) minmax(280px,.72fr);
      gap:14px;
      align-items:start;
    }
    .ai-panel{position:relative;overflow:hidden;}
    .ai-panel:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at top right,rgba(138,211,255,.12),transparent 34%);pointer-events:none;}
    .ai-title-row{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;}
    .ai-title-row .muted{font-size:14px;line-height:1.45;max-width:720px;}
    .card{padding:14px;border-radius:18px;margin-bottom:12px;}
    .card h3{font-size:17px;margin-bottom:6px;line-height:1.2;}

    .ai-form{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:12px;}
    .admin-search{min-width:0;padding:10px 11px;font-size:14px;}
    .btn{padding:9px 12px;border-radius:13px;font-size:14px;}
    .quick-ai{display:flex;flex-wrap:wrap;gap:7px;margin-top:9px;}
    .quick-ai .btn{padding:8px 10px;font-size:13px;}

    .ai-output{
      white-space:pre-wrap;
      line-height:1.5;
      font-size:14px;
      background:rgba(255,255,255,.06);
      border:1px solid rgba(255,255,255,.10);
      border-radius:16px;
      padding:13px;
      margin-top:12px;
      max-height:310px;
      overflow:auto;
    }

    .metric-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:10px;}
    .metric{padding:12px;border-radius:16px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);}
    .metric strong{display:block;font-size:24px;margin-bottom:2px;}

    .badge{display:inline-block;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.10);font-size:12px;font-weight:800;}
    .badge.green{background:rgba(50,220,150,.14);border:1px solid rgba(50,220,150,.25);}
    .badge.orange{background:rgba(255,190,80,.14);border:1px solid rgba(255,190,80,.25);}
    .badge.red{background:rgba(255,90,120,.14);border:1px solid rgba(255,90,120,.25);}
    .note{padding:10px 12px;border-radius:14px;background:rgba(138,211,255,.10);border:1px solid rgba(138,211,255,.20);margin-top:10px;font-size:13px;line-height:1.45;}

    /* Make right-side tables fit instead of forcing horizontal page scroll */
    .ai-layout .table-wrap{overflow:auto;max-width:100%;}
    .ai-layout .table{min-width:0;width:100%;font-size:13px;}
    .ai-layout .table th,
    .ai-layout .table td{padding:9px 10px;white-space:normal;line-height:1.25;}

    @media(max-width:1150px){
      .ai-layout{grid-template-columns:1fr;}
      .container{width:min(920px, calc(100% - 24px));}
    }
    @media(max-width:800px){
      .nav-links{display:none;}
      .ai-form,.metric-grid{grid-template-columns:1fr;}
      .ai-title-row{display:block;}
      .ai-title-row .btn{margin-top:10px;}
      .section{padding:20px 0 34px;}
      .section-title{font-size:20px;}
      .card{padding:12px;}
      .ai-output{max-height:280px;}
    }
  </style>
</head>
<body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('assistant'); ?>

<main class="section">
  <div class="container">
    <div class="ai-title-row">
      <div>
        <span class="badge green">Real AI Add-on</span>
        <h2 class="section-title" style="margin-top:12px;">LebanEASE AI Operations Advisor</h2>
        <p class="muted">This page sends a live database snapshot to an AI model and asks it to generate a professional admin recommendation.</p>
      </div>
      <a class="btn btn-ghost" href="assistant.php">Back to Smart Agent</a>
    </div>

    <?php if (get_api_key() === ''): ?>
      <div class="note">
        <b>AI key not configured yet.</b><br>
        Open <code>admin/ai_config.php</code> and paste your API key in <code>LEBANEASE_AI_API_KEY</code>. Until then, this page will still show a fallback AI-style summary after you click Generate.
      </div>
    <?php endif; ?>

    <div class="h-12"></div>
    <div class="ai-layout">
      <div class="card glass ai-panel">
        <h3>Ask Real AI About the Current Operations</h3>
        <p class="muted tiny">The AI receives only operational data such as route demand, reservation counts, bus status, and maintenance alerts.</p>
        <form method="post" class="ai-form">
          <input class="admin-search" type="text" name="question" value="<?= h($question) ?>" placeholder="Example: What should the admin fix before the final demo?" />
          <button class="btn btn-primary" type="submit">Generate AI Advice</button>
        </form>
        <div class="quick-ai">
          <button class="btn btn-ghost" type="button" onclick="document.querySelector('[name=question]').value='Generate a complete operations summary for the admin.'">Operations summary</button>
          <button class="btn btn-ghost" type="button" onclick="document.querySelector('[name=question]').value='Which buses or trips need urgent attention?'">Urgent issues</button>
          <button class="btn btn-ghost" type="button" onclick="document.querySelector('[name=question]').value='What should we say about this AI feature in the professor demo?'">Demo script</button>
        </div>

        <?php if ($aiError): ?>
          <div class="note"><b>AI API note:</b> <?= h($aiError) ?></div>
        <?php endif; ?>

        <?php if ($aiText): ?>
          <div class="ai-output"><?= h($aiText) ?></div>
          <?php if ($usedFallback): ?>
            <p class="muted tiny">The fallback appeared because the live AI call could not run. The page is still safe for demo, but configure the API key for real AI output.</p>
          <?php endif; ?>
        <?php else: ?>
          <div class="ai-output">Click “Generate AI Advice” to create a live AI recommendation from your current database.</div>
        <?php endif; ?>
      </div>

      <div class="card glass">
        <h3>Database Snapshot Sent to AI</h3>
        <div class="metric-grid">
          <div class="metric"><strong><?= (int)$context['summary']['scheduled_trips'] ?></strong><span class="muted tiny">Scheduled Trips</span></div>
          <div class="metric"><strong><?= (int)$context['summary']['active_reservations'] ?></strong><span class="muted tiny">Active Reservations</span></div>
          <div class="metric"><strong><?= (int)$context['summary']['almost_full_trips'] ?></strong><span class="muted tiny">Almost-Full Trips</span></div>
          <div class="metric"><strong><?= (int)$context['summary']['fleet_alerts'] ?></strong><span class="muted tiny">Fleet Alerts</span></div>
        </div>

        <div class="h-12"></div>
        <h3>Top Busy Routes</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Route</th><th>Active Reservations</th></tr></thead>
            <tbody>
              <?php foreach ($context['busy_routes'] as $r): ?>
                <tr><td><?= h($r['Source'].' → '.$r['Destination']) ?></td><td><?= (int)$r['active_reservations'] ?></td></tr>
              <?php endforeach; ?>
              <?php if (empty($context['busy_routes'])): ?><tr><td colspan="2" class="muted">No active route demand yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="h-12"></div>
        <h3>Fleet Alerts</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Bus</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($context['maintenance_risks'] as $m): ?>
                <tr><td><?= h($m['Bus_Number']) ?></td><td><span class="<?= h(status_badge_class($m['Status'] ?: $m['Maintenance_Status'])) ?>"><?= h($m['Status'] ?: $m['Maintenance_Status']) ?></span></td></tr>
              <?php endforeach; ?>
              <?php if (empty($context['maintenance_risks'])): ?><tr><td colspan="2" class="muted">No maintenance risk found.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>

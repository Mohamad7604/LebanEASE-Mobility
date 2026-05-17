<?php
require "db/config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Beirut');

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$routes = [];

try {
    $stmt = $pdo->query("
        SELECT
            Route_ID,
            Source,
            Destination,
            Distance
        FROM Route
        ORDER BY Route_ID ASC
    ");
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $routes = [];
}

/*
  Add city names here exactly as they appear in your Route table.
  If a route does not appear, its city name probably needs to be added here.
*/
$cityCoordinates = [
    "Beirut" => ["lat" => 33.8938, "lng" => 35.5018],
    "Tripoli" => ["lat" => 34.4367, "lng" => 35.8497],
    "Saida" => ["lat" => 33.5631, "lng" => 35.3689],
    "Sidon" => ["lat" => 33.5631, "lng" => 35.3689],
    "Zahle" => ["lat" => 33.8467, "lng" => 35.9020],
    "Baalbek" => ["lat" => 34.0058, "lng" => 36.2181],
    "Byblos" => ["lat" => 34.1230, "lng" => 35.6519],
    "Jbeil" => ["lat" => 34.1230, "lng" => 35.6519],
    "Jounieh" => ["lat" => 33.9808, "lng" => 35.6178],
    "Batroun" => ["lat" => 34.2553, "lng" => 35.6581],
    "Tyre" => ["lat" => 33.2705, "lng" => 35.2038],
    "Sour" => ["lat" => 33.2705, "lng" => 35.2038],
    "Airport" => ["lat" => 33.8209, "lng" => 35.4884],
    "Aley" => ["lat" => 33.8106, "lng" => 35.5975],
    "Chouf" => ["lat" => 33.6956, "lng" => 35.5808],
    "Nabatieh" => ["lat" => 33.3772, "lng" => 35.4839]
];

$mapRoutes = [];

foreach ($routes as $route) {
    $source = trim($route["Source"]);
    $destination = trim($route["Destination"]);

    if (isset($cityCoordinates[$source]) && isset($cityCoordinates[$destination])) {
        $mapRoutes[] = [
            "route_id" => (int)$route["Route_ID"],
            "source" => $source,
            "destination" => $destination,
            "database_distance" => (float)$route["Distance"],
            "from" => $cityCoordinates[$source],
            "to" => $cityCoordinates[$destination]
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Open Route Planner | LebanEASE</title>
<link rel="stylesheet" href="css/style.css">

<!-- Leaflet CSS -->
<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
/>

<style>
.route-page {
    max-width: 1250px;
    margin: 0 auto;
}

.route-layout {
    display: grid;
    grid-template-columns: 1fr 390px;
    gap: 16px;
    align-items: stretch;
}

#routeMap {
    height: 680px;
    width: 100%;
    border-radius: 24px;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.12);
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}

.route-panel {
    max-height: 680px;
    overflow-y: auto;
}

.control-group {
    display: grid;
    gap: 10px;
}

.control-group select {
    width: 100%;
}

.action-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.badge-soft {
    display: inline-flex;
    padding: 6px 9px;
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    font-size: 12px;
    font-weight: 800;
    margin: 4px 4px 0 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.stat-box {
    border-radius: 16px;
    padding: 12px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
}

.stat-number {
    font-size: 22px;
    font-weight: 950;
}

.route-result {
    padding: 12px;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,.1);
    background: rgba(255,255,255,.055);
    margin-bottom: 10px;
    cursor: pointer;
    line-height: 1.6;
}

.route-result:hover {
    background: rgba(255,255,255,.09);
}

.route-result.active {
    border-color: rgba(53,167,255,.7);
    box-shadow: 0 0 0 1px rgba(53,167,255,.3);
}

.route-title {
    font-weight: 950;
    margin-bottom: 4px;
}

.route-small {
    font-size: 13px;
    color: rgba(234,240,255,.72);
}

.warning-box {
    line-height: 1.8;
}

.leaflet-control-attribution {
    font-size: 10px;
}

@media(max-width:1000px) {
    .route-layout {
        grid-template-columns: 1fr;
    }

    #routeMap {
        height: 520px;
    }

    .route-panel {
        max-height: none;
    }
}
</style>
</head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('traffic'); ?>

<main class="section">
<div class="container route-page">

<h2 class="section-title">Open-Source Shortest Route Planner</h2>
<p class="muted mb-14">
    Select a route from the LebanEASE database and calculate driving alternatives using OpenStreetMap and OSRM.
</p>

<?php if (empty($mapRoutes)): ?>

    <div class="card glass warning-box">
        <h3>No mappable routes found</h3>
        <p class="muted">
            Your routes exist, but their city names may not match the coordinate list in this page.
            Add your cities to the <strong>$cityCoordinates</strong> array inside <strong>traffic_map.php</strong>.
        </p>
    </div>

<?php else: ?>

<div class="route-layout">

    <div id="routeMap"></div>

    <div class="card glass route-panel">
        <h3>Route Planner</h3>
        <p class="muted tiny">
            The system compares route alternatives and highlights the shortest one.
        </p>

        <div class="h-12"></div>

        <div class="control-group">
            <label class="tiny muted">Select route from database</label>

            <select id="routeSelect" class="admin-search">
                <?php foreach ($mapRoutes as $index => $route): ?>
                    <option value="<?= (int)$index ?>">
                        <?= h($route["source"]) ?> → <?= h($route["destination"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="action-row">
                <button class="btn btn-primary" type="button" onclick="calculateSelectedRoute()">
                    Find Shortest Route
                </button>

                <button class="btn btn-ghost" type="button" onclick="fitAllMarkers()">
                    Fit Map
                </button>
            </div>
        </div>

        <div class="h-12"></div>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="tiny muted">Shortest Distance</div>
                <div id="shortestDistance" class="stat-number">--</div>
            </div>

            <div class="stat-box">
                <div class="tiny muted">Estimated Duration</div>
                <div id="shortestDuration" class="stat-number">--</div>
            </div>
        </div>

        <div class="h-12"></div>

        <div>
            <span class="badge-soft">No API key</span>
            <span class="badge-soft">OpenStreetMap</span>
            <span class="badge-soft">OSRM routing</span>
            <span class="badge-soft">Shortest route</span>
            <span class="badge-soft">Alternatives</span>
        </div>

        <div class="h-12"></div>

        <h3>Route Alternatives</h3>
        <p class="muted tiny">
            Click an alternative to highlight it on the map.
        </p>

        <div class="h-12"></div>

        <div id="routeResults">
            <p class="muted">Select a route and click Find Shortest Route.</p>
        </div>

        <div class="h-12"></div>

        <h3>Demo Talking Point</h3>
        <p class="muted" style="line-height:1.8;">
            This feature improves usability and decision support by helping passengers visualize routes and compare driving alternatives before travelling.
        </p>
    </div>

</div>

<?php endif; ?>

</div>
</main>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<?php if (!empty($mapRoutes)): ?>
<script>
const mapRoutes = <?= json_encode($mapRoutes, JSON_PRETTY_PRINT) ?>;

let map;
let markersLayer;
let routeLayers = [];
let allMarkerBounds = null;

function initMap() {
    map = L.map("routeMap").setView([33.8938, 35.5018], 9);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap contributors"
    }).addTo(map);

    markersLayer = L.layerGroup().addTo(map);

    drawAllDatabaseMarkers();
    calculateSelectedRoute();
}

function drawAllDatabaseMarkers() {
    markersLayer.clearLayers();
    allMarkerBounds = L.latLngBounds();

    mapRoutes.forEach(route => {
        const fromLatLng = [route.from.lat, route.from.lng];
        const toLatLng = [route.to.lat, route.to.lng];

        L.marker(fromLatLng)
            .bindPopup("<strong>" + route.source + "</strong><br>Source")
            .addTo(markersLayer);

        L.marker(toLatLng)
            .bindPopup("<strong>" + route.destination + "</strong><br>Destination")
            .addTo(markersLayer);

        allMarkerBounds.extend(fromLatLng);
        allMarkerBounds.extend(toLatLng);
    });

    fitAllMarkers();
}

function fitAllMarkers() {
    if (allMarkerBounds && allMarkerBounds.isValid()) {
        map.fitBounds(allMarkerBounds, { padding: [30, 30] });
    }
}

function clearRoutes() {
    routeLayers.forEach(layer => {
        map.removeLayer(layer);
    });

    routeLayers = [];
}

function calculateSelectedRoute() {
    const selectedIndex = parseInt(document.getElementById("routeSelect").value, 10);
    const selectedRoute = mapRoutes[selectedIndex];

    if (!selectedRoute) {
        return;
    }

    clearRoutes();

    document.getElementById("routeResults").innerHTML =
        "<p class='muted'>Calculating route alternatives...</p>";

    const start = selectedRoute.from.lng + "," + selectedRoute.from.lat;
    const end = selectedRoute.to.lng + "," + selectedRoute.to.lat;

    const osrmUrl =
        "https://router.project-osrm.org/route/v1/driving/" +
        start + ";" + end +
        "?alternatives=true&overview=full&geometries=geojson&steps=true";

    fetch(osrmUrl)
        .then(response => response.json())
        .then(data => {
            if (!data.routes || data.routes.length === 0) {
                document.getElementById("routeResults").innerHTML =
                    "<p class='muted'>No route alternatives were found.</p>";
                return;
            }

            renderRoutes(data.routes);
        })
        .catch(() => {
            document.getElementById("routeResults").innerHTML =
                "<p class='muted'>Could not connect to OSRM route service. Check your internet connection.</p>";
        });
}

function formatDistance(meters) {
    return (meters / 1000).toFixed(1) + " km";
}

function formatDuration(seconds) {
    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return minutes + " min";
    }

    const hours = Math.floor(minutes / 60);
    const remaining = minutes % 60;

    return hours + "h " + remaining + "m";
}

function renderRoutes(routes) {
    clearRoutes();

    const sortedRoutes = routes
        .map((route, originalIndex) => ({
            originalIndex,
            route,
            distance: route.distance,
            duration: route.duration
        }))
        .sort((a, b) => a.distance - b.distance);

    const shortest = sortedRoutes[0];

    document.getElementById("shortestDistance").textContent =
        formatDistance(shortest.distance);

    document.getElementById("shortestDuration").textContent =
        formatDuration(shortest.duration);

    const routeBounds = L.latLngBounds();

    sortedRoutes.forEach((item, rank) => {
        const coordinates = item.route.geometry.coordinates.map(coord => {
            const latLng = [coord[1], coord[0]];
            routeBounds.extend(latLng);
            return latLng;
        });

        const isShortest = rank === 0;

        const layer = L.polyline(coordinates, {
            weight: isShortest ? 7 : 5,
            opacity: isShortest ? 0.95 : 0.35
        }).addTo(map);

        layer.bindPopup(
            "<strong>" + (isShortest ? "Shortest Route" : "Alternative Route " + (rank + 1)) + "</strong><br>" +
            "Distance: " + formatDistance(item.distance) + "<br>" +
            "Duration: " + formatDuration(item.duration)
        );

        routeLayers.push(layer);
    });

    if (routeBounds.isValid()) {
        map.fitBounds(routeBounds, { padding: [30, 30] });
    }

    const results = document.getElementById("routeResults");
    results.innerHTML = "";

    sortedRoutes.forEach((item, rank) => {
        const div = document.createElement("div");
        div.className = "route-result " + (rank === 0 ? "active" : "");

        const title = rank === 0 ? "Shortest Route" : "Alternative Route " + (rank + 1);

        div.innerHTML = `
            <div class="route-title">${title}</div>
            <div class="route-small">Distance: ${formatDistance(item.distance)}</div>
            <div class="route-small">Estimated duration: ${formatDuration(item.duration)}</div>
            <div class="route-small">Main roads: ${item.route.legs?.[0]?.summary || "Available route"}</div>
        `;

        div.addEventListener("click", function() {
            highlightRoute(rank);
        });

        results.appendChild(div);
    });
}

function highlightRoute(selectedRank) {
    const resultItems = document.querySelectorAll(".route-result");

    resultItems.forEach((item, index) => {
        item.classList.toggle("active", index === selectedRank);
    });

    routeLayers.forEach((layer, index) => {
        const selected = index === selectedRank;

        layer.setStyle({
            weight: selected ? 7 : 5,
            opacity: selected ? 0.95 : 0.25
        });

        if (selected) {
            layer.bringToFront();
        }
    });
}

document.addEventListener("DOMContentLoaded", initMap);
</script>
<?php endif; ?>

</body>
</html>
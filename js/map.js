// Reserve button should always carry the correct schedule id
const reserveBtn = document.getElementById("reserveBtn");
reserveBtn.href = scheduleId ? `seats.php?schedule_id=${scheduleId}` : "seats.php";

const distanceEl = document.getElementById("distance");
const durationEl = document.getElementById("duration");
const geoStatus  = document.getElementById("geoStatus");

function setStatus(msg) {
  if (geoStatus) geoStatus.textContent = msg || "";
}

// Initialize map (centered on Lebanon)
const map = L.map("map").setView([33.9, 35.7], 9);

// Dark tiles (matches your theme)
L.tileLayer("https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png", {
  attribution: "© OpenStreetMap © CartoDB"
}).addTo(map);

// Bus icon
const busIcon = L.icon({
  iconUrl: "https://cdn-icons-png.flaticon.com/512/3448/3448339.png",
  iconSize: [40, 40],
  iconAnchor: [20, 20]
});

/* =========================
   Geocoding helpers
========================= */
function normalizePlace(p) {
  return (p || "").trim().toLowerCase().replace(/\s+/g, " ");
}
function cacheKey(place) {
  return `lebanEase_geo_${normalizePlace(place)}`;
}
function getCached(place) {
  try {
    const raw = localStorage.getItem(cacheKey(place));
    if (!raw) return null;
    return JSON.parse(raw);
  } catch {
    return null;
  }
}
function setCached(place, latlng) {
  try {
    localStorage.setItem(cacheKey(place), JSON.stringify(latlng));
  } catch {}
}

async function geocode(place) {
  const cleaned = (place || "").trim();
  if (!cleaned) throw new Error("Empty place name");

  const cached = getCached(cleaned);
  if (cached && cached.lat && cached.lng) return cached;

  let query = cleaned;
  if (normalizePlace(cleaned) === "airport") query = "Beirut Airport";

  const tryQueries = [
    `${query}, Lebanon`,
    `${query}, Beirut, Lebanon`,
    `${query}`
  ];

  for (const q of tryQueries) {
    setStatus(`Finding location: ${q} ...`);
    const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(q)}`;
    const res = await fetch(url);
    const data = await res.json();

    if (Array.isArray(data) && data.length > 0) {
      const lat = parseFloat(data[0].lat);
      const lng = parseFloat(data[0].lon);
      if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
        const latlng = { lat, lng, display: data[0].display_name };
        setCached(cleaned, latlng);
        return latlng;
      }
    }
  }

  throw new Error(`Could not locate: ${cleaned}`);
}

/* =========================
   Time-aware bus logic
========================= */
function parseDateTime(dateStr, timeStr) {
  // Expects date: YYYY-MM-DD, time: HH:MM or HH:MM:SS
  if (!dateStr || !timeStr) return null;

  const [y, m, d] = dateStr.split("-").map(Number);
  const parts = timeStr.split(":").map(Number);
  const hh = parts[0] ?? 0;
  const mm = parts[1] ?? 0;
  const ss = parts[2] ?? 0;

  if (!y || !m || !d) return null;
  return new Date(y, m - 1, d, hh, mm, ss);
}

function ensureArrivalAfterDeparture(dep, arr) {
  // If arrival time is <= departure time, treat arrival as next day (handles overnight trips)
  if (!dep || !arr) return { dep, arr };
  if (arr.getTime() <= dep.getTime()) {
    arr = new Date(arr.getTime() + 24 * 60 * 60 * 1000);
  }
  return { dep, arr };
}

function formatKm(meters) {
  const km = meters / 1000;
  return `${km.toFixed(1)} km`;
}
function formatMinutes(seconds) {
  const min = Math.round(seconds / 60);
  if (min < 60) return `${min} min`;
  const h = Math.floor(min / 60);
  const r = min % 60;
  return `${h} h ${r} min`;
}

function getTripState(dep, arr, now) {
  if (!dep || !arr) return "unknown";
  if (now.getTime() < dep.getTime()) return "not_started";
  if (now.getTime() >= arr.getTime()) return "finished";
  return "in_transit";
}

function positionMarkerByTime(busMarker, coords, dep, arr) {
  const now = new Date();
  const state = getTripState(dep, arr, now);

  if (state === "not_started") {
    busMarker.setLatLng(coords[0]);
    setStatus("Bus hasn't departed yet.");
    return state;
  }

  if (state === "finished") {
    busMarker.setLatLng(coords[coords.length - 1]);
    setStatus("Trip completed (bus reached destination).");
    return state;
  }

  // in_transit: place marker based on progress ratio
  const ratio = (now.getTime() - dep.getTime()) / (arr.getTime() - dep.getTime());
  const idx = Math.max(0, Math.min(coords.length - 1, Math.floor(ratio * (coords.length - 1))));
  busMarker.setLatLng(coords[idx]);
  setStatus("Bus is currently in transit.");
  return state;
}

/* =========================
   Draw route + place bus
========================= */
async function drawRoute() {
  try {
    if (!fromPlace || !toPlace) {
      setStatus("Missing from/to values.");
      return;
    }

    setStatus("Geocoding locations...");
    const start = await geocode(fromPlace);
    const end   = await geocode(toPlace);

    setStatus("");
    map.setView([start.lat, start.lng], 12);

    const control = L.Routing.control({
      waypoints: [L.latLng(start.lat, start.lng), L.latLng(end.lat, end.lng)],
      routeWhileDragging: false,
      addWaypoints: false,
      draggableWaypoints: false,
      show: false
    }).addTo(map);

    control.on("routesfound", function (e) {
      const route = e.routes[0];
      const coords = route.coordinates;

      // Update distance + duration of the route itself
      distanceEl.textContent = `Distance: ${formatKm(route.summary.totalDistance)}`;
      durationEl.textContent = `Duration: ${formatMinutes(route.summary.totalTime)}`;

      // Create marker
      const busMarker = L.marker(coords[0], { icon: busIcon }).addTo(map);

      // If we have schedule timing, position bus based on real time
      const dep0 = parseDateTime(scheduleDate, departureTime);
      const arr0 = parseDateTime(scheduleDate, arrivalTime);
      const { dep, arr } = ensureArrivalAfterDeparture(dep0, arr0);

      if (!dep || !arr) {
        // fallback: just do a simple animation if schedule is missing
        setStatus("No schedule time found; showing default animation.");
        let index = 0;
        const interval = setInterval(() => {
          busMarker.setLatLng(coords[index]);
          index++;
          if (index >= coords.length) clearInterval(interval);
        }, 500);
        return;
      }

      // Initial placement based on time
      const state = positionMarkerByTime(busMarker, coords, dep, arr);

      // If in transit, update marker every few seconds (real-time progress)
      if (state === "in_transit") {
        const tick = setInterval(() => {
          const newState = positionMarkerByTime(busMarker, coords, dep, arr);
          if (newState === "finished" || newState === "not_started") {
            clearInterval(tick);
          }
        }, 5000);
      }
    });

  } catch (err) {
    console.error(err);
    setStatus(err.message || "Could not draw route.");
  }
}

drawRoute();

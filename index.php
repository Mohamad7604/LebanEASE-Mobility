<?php require "db/config.php"; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>LebanEASE | Bus Reservation</title>

  <!-- Google Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="css/style.css" />
</head>
<body>

  <!-- Navbar -->
  <?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('home'); ?>

  <!-- Hero -->
  <main class="hero">
    <div class="container hero-grid">

      <div class="hero-left">
        <p class="badge">Fast • Simple • Reliable</p>
        <h1>Find your bus route and reserve your seat in minutes.</h1>
        <p class="sub">
          LebanEASE helps you search schedules, view routes on a live map,
          and reserve seats with a smooth booking flow.
        </p>

        <div class="hero-actions">
          <a class="btn btn-primary" href="search.php">Search Schedules</a>
          <a class="btn btn-secondary" href="user_agent.php">Ask Passenger Agent</a>
          <a class="btn btn-ghost" href="#how">How it works</a>
        </div>

        <div class="stats">
          <div class="stat">
            <div class="stat-num">7+</div>
            <div class="stat-label">Bus Types</div>
          </div>
          <div class="stat">
            <div class="stat-num">Live</div>
            <div class="stat-label">Route Map</div>
          </div>
          <div class="stat">
            <div class="stat-num">Easy</div>
            <div class="stat-label">Seat Selection</div>
          </div>
        </div>
      </div>

      <div class="hero-right">
        <div class="card glass">
          <h3>Quick Search</h3>
          <p class="muted">Jump directly to the schedule search page.</p>

          <div class="quick">
            <div class="chip">Beirut</div>
            <div class="chip">Tripoli</div>
            <div class="chip">Saida</div>
            <div class="chip">Zahle</div>
          </div>

          <a class="btn btn-primary w-full" href="search.php">Start Booking</a>
          <a class="btn btn-secondary w-full mt-10" href="user_agent.php">Ask the Agent</a>
          <p class="tiny muted">Tip: pick a schedule, view route, then reserve seats.</p>
        </div>

        <div class="card">
          <h3>What you can do</h3>
          <ul class="list">
            <li>Search buses by route & date</li>
            <li>View route on an interactive map</li>
            <li>Select seats and confirm reservation</li>
          </ul>
        </div>
      </div>

    </div>
  </main>

  <!-- How it works -->
  <section class="section" id="how">
    <div class="container">
      <h2 class="section-title">How it works</h2>

      <div class="steps">
        <div class="step">
          <div class="step-icon">1</div>
          <h4>Search</h4>
          <p>Choose your route and travel date.</p>
        </div>

        <div class="step">
          <div class="step-icon">2</div>
          <h4>Map</h4>
          <p>See the route visually before booking.</p>
        </div>

        <div class="step">
          <div class="step-icon">3</div>
          <h4>Reserve</h4>
          <p>Select a seat and confirm your reservation.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer">
    <div class="container footer-inner">
      <div>
        <div class="brand small"><span class="brand-dot"></span> Leban<span>EASE</span></div>
        <p class="muted tiny">Database Systems Final Project • MariaDB • PHP</p>
      </div>
      <div class="footer-links">
        <a href="search.php">Search</a>
        <a href="map.php">Map</a>
        <a href="seats.php">Seats</a>
      </div>
    </div>
  </footer>

</body>
</html>

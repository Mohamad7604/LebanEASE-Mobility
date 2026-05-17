<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('nav_active')) {
    function nav_active(string $key, string $active): string {
        return $key === $active ? ' class="active"' : '';
    }
}

if (!function_exists('render_public_nav')) {
    function render_public_nav(string $active = ''): void {
?>
<header class="nav">
  <div class="container nav-inner">
    <a class="brand" href="index.php">
      <span class="brand-dot"></span>Leban<span>EASE</span>
    </a>

    <nav class="nav-links" aria-label="Main navigation">
      <a href="index.php"<?= nav_active('home', $active) ?>>Home</a>
      <a href="search.php"<?= nav_active('search', $active) ?>>Search</a>
      <a href="map.php"<?= nav_active('map', $active) ?>>Map</a>
      <a href="traffic_map.php"<?= nav_active('traffic', $active) ?>>Route Planner</a>
      <a href="user_agent.php"<?= nav_active('agent', $active) ?>>Passenger Agent</a>
      <?php if (!empty($_SESSION['passenger_id'])): ?>
        <a href="my_reservations.php"<?= nav_active('reservations', $active) ?>>My Reservations</a>
        <a href="profile.php"<?= nav_active('profile', $active) ?>>Profile</a>
      <?php else: ?>
        <a href="login.php"<?= nav_active('login', $active) ?>>Login</a>
        <a href="register.php"<?= nav_active('register', $active) ?>>Register</a>
      <?php endif; ?>
    </nav>

    <div class="nav-actions">
      <a class="btn btn-agent btn-sm" href="user_agent.php">Ask Agent</a>
      <?php if (!empty($_SESSION['passenger_id'])): ?>
        <a class="btn btn-ghost btn-sm" href="logout.php">Logout</a>
      <?php else: ?>
        <a class="btn btn-ghost btn-sm" href="admin/login.php">Admin</a>
      <?php endif; ?>
      <a class="btn btn-primary btn-sm" href="search.php">Book a Trip</a>
    </div>
  </div>
</header>
<?php
    }
}

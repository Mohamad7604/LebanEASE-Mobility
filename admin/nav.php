<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('admin_nav_active')) {
    function admin_nav_active(string $key, string $active): string {
        return $key === $active ? ' class="active"' : '';
    }
}

if (!function_exists('render_admin_nav')) {
    function render_admin_nav(string $active = ''): void {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $inAdmin = strpos($script, '/admin/') !== false;
        $publicPrefix = $inAdmin ? '../' : '';
        $adminPrefix = $inAdmin ? '' : 'admin/';
        $reportsHref = $inAdmin ? '../reports.php' : 'reports.php';
        $adminName = $_SESSION['admin_name'] ?? 'Admin';
?>
<header class="nav">
  <div class="container nav-inner">
    <a class="brand" href="<?= $adminPrefix ?>index.php"><span class="brand-dot"></span>Leban<span>EASE</span></a>

    <nav class="nav-links admin-nav-links" aria-label="Admin navigation">
      <a href="<?= $adminPrefix ?>index.php"<?= admin_nav_active('dashboard', $active) ?>>Dashboard</a>
      <a href="<?= $adminPrefix ?>activity_log.php"<?= admin_nav_active('activity_log', $active) ?>>Activity Log</a>
      <a href="<?= $adminPrefix ?>buses.php"<?= admin_nav_active('buses', $active) ?>>Buses</a>
      <a href="<?= $adminPrefix ?>drivers.php"<?= admin_nav_active('drivers', $active) ?>>Drivers</a>
      <a href="<?= $adminPrefix ?>routes.php"<?= admin_nav_active('routes', $active) ?>>Routes</a>
      <a href="<?= $adminPrefix ?>schedules.php"<?= admin_nav_active('schedules', $active) ?>>Trips</a>
      <a href="<?= $adminPrefix ?>reservations.php"<?= admin_nav_active('reservations', $active) ?>>Reservations</a>
      <a href="<?= $adminPrefix ?>maintenance.php"<?= admin_nav_active('maintenance', $active) ?>>Maintenance</a>
      <a href="<?= $reportsHref ?>"<?= admin_nav_active('reports', $active) ?>>Reports</a>
      <a href="<?= $adminPrefix ?>operations_agent.php"<?= admin_nav_active('operations_agent', $active) ?>>Operations Agent</a>
      <a href="<?= $adminPrefix ?>analytics.php"<?= admin_nav_active('analytics', $active) ?>>Analytics</a>
    </nav>

    <div class="nav-actions">
      <a class="btn btn-agent btn-sm" href="<?= $adminPrefix ?>operations_agent.php">Ops Agent</a>
      <span class="tiny muted nav-user"><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></span>
      <a class="btn btn-ghost btn-sm" href="<?= $adminPrefix ?>logout.php">Logout</a>
    </div>
  </div>
</header>
<?php
    }
}

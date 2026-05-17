<?php
// admin/auth.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_admin(): void {
  if (empty($_SESSION['admin_id'])) {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $loginPath = (strpos($script, '/admin/') !== false) ? 'login.php' : 'admin/login.php';
    header("Location: " . $loginPath);
    exit;
  }
}

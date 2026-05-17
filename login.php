<?php
require "db/config.php";
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$error = "";
$next = trim($_GET['next'] ?? $_POST['next'] ?? 'profile.php');
if ($next === '' || preg_match('/^https?:\/\//i', $next) || substr($next, 0, 2) === '//') {
    $next = 'profile.php';
}
if (!empty($_GET['login_required'])) {
    $error = "Please log in before reserving a seat.";
}
if (!empty($_SESSION['passenger_id'])) {
    header("Location: " . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($identifier === '' || $password === '') {
        $error = "Please enter your email/username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT Passenger_ID, First_Name, Last_Name, Email, Username, Password FROM Passenger WHERE Email = ? OR Username = ? LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $passenger = $stmt->fetch();
        if ($passenger && !empty($passenger['Password']) && password_verify($password, $passenger['Password'])) {
            $_SESSION['passenger_id'] = (int)$passenger['Passenger_ID'];
            $_SESSION['passenger_name'] = trim(($passenger['First_Name'] ?? '') . ' ' . ($passenger['Last_Name'] ?? ''));
            header("Location: " . $next);
            exit;
        }
        $error = "Invalid credentials.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><title>Passenger Login | LebanEASE</title><link rel="stylesheet" href="css/style.css" /></head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('login'); ?>
<main class="section"><div class="container" style="max-width:560px;">
<h2 class="section-title">Passenger Login</h2>
<div class="card glass">
<?php if ($error): ?><div class="alert"><strong><?= h($error) ?></strong></div><div class="h-12"></div><?php endif; ?>
<form method="POST" class="form-grid" style="grid-template-columns:1fr;">
      <input type="hidden" name="next" value="<?= h($next) ?>" />
  <div class="control"><label>Email or Username</label><input type="text" name="identifier" required /></div>
  <div class="control"><label>Password</label><input type="password" name="password" required /></div>
  <div class="control flex gap-10 flex-wrap"><button class="btn btn-primary" type="submit">Login</button><a class="btn btn-ghost" href="register.php?next=<?= urlencode($next) ?>">Create Account</a></div>
  <p class="tiny muted">Demo passenger: hassane / pass123</p>
</form>
</div>
</div></main>
</body></html>

<?php
declare(strict_types=1);
require "../db/config.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$error = "";

if (!empty($_SESSION['admin_id'])) {
  header("Location: index.php");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $error = "Please enter email and password.";
  } else {
    $stmt = $pdo->prepare("SELECT Admin_ID, Email, Password, First_Name, Last_Name FROM Admin WHERE Email = ? LIMIT 1");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($pass, $admin['Password'])) {
      $_SESSION['admin_id'] = (int)$admin['Admin_ID'];
      $_SESSION['admin_name'] = trim(($admin['First_Name'] ?? '').' '.($admin['Last_Name'] ?? ''));
      header("Location: index.php");
      exit;
    } else {
      $error = "Invalid credentials.";
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Login | LebanEASE</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>

<header class="nav">
  <div class="container nav-inner">
    <a class="brand" href="../index.php">
      <span class="brand-dot"></span>
      Leban<span>EASE</span>
    </a>

    <nav class="nav-links">
      <a href="../index.php">Home</a>
      <a href="../search.php">Search</a>
      <a href="login.php" class="active">Admin</a>
    </nav>

    <a class="btn btn-primary" href="../search.php">Book a Trip</a>
  </div>
</header>

<main class="section">
  <div class="container" style="max-width:560px;">
    <h2 class="section-title">Admin Login</h2>

    <div class="card glass">
      <?php if ($error): ?>
        <div class="alert">
          <strong><?= h($error) ?></strong>
        </div>
        <div style="height:12px;"></div>
      <?php endif; ?>

      <form method="POST" class="form-grid" style="grid-template-columns:1fr;">
        <div class="control">
          <label>Email</label>
          <input type="email" name="email" required />
        </div>

        <div class="control">
          <label>Password</label>
          <input type="password" name="password" required />
        </div>

        <div class="control" style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-primary" type="submit">Login</button>
          <a class="btn btn-ghost" href="../index.php">Back</a>
        </div>

        <p class="tiny muted" style="margin-top:10px;">
          Tip: Make sure you inserted at least one admin user in the database.
        </p>
      </form>
    </div>
  </div>
</main>

</body>
</html>

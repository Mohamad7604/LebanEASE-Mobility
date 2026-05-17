<?php
require "db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$first = trim($_POST['first_name'] ?? '');
$last = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$username = trim($_POST['username'] ?? '');
$next = trim($_GET['next'] ?? $_POST['next'] ?? 'profile.php');
if ($next === '' || preg_match('/^https?:\/\//i', $next) || substr($next, 0, 2) === '//') {
    $next = 'profile.php';
}

if (!empty($_SESSION['passenger_id'])) {
    header("Location: " . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($first === '' || $last === '' || $email === '' || $phone === '' || $gender === '' || $username === '' || $password === '' || $confirm === '') {
        $errors[] = "Please fill all fields.";
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Enter a valid email address.";
    if ($username !== '' && !preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) $errors[] = "Username must be 3-30 characters and use letters, numbers, or underscore only.";
    if (!in_array($gender, ['Male','Female','Other'], true)) $errors[] = "Select a valid gender.";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters.";
    if ($password !== $confirm) $errors[] = "Passwords do not match.";

    if (empty($errors)) {
        try {
            $check = $pdo->prepare("SELECT COUNT(*) FROM Passenger WHERE Email = ? OR Username = ?");
            $check->execute([$email, $username]);
            if ((int)$check->fetchColumn() > 0) {
                $errors[] = "Email or username already exists.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO Passenger (First_Name, Last_Name, Email, Phone, Gender, Username, Password) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$first, $last, $email, $phone, $gender, $username, password_hash($password, PASSWORD_DEFAULT)]);
                $_SESSION['passenger_id'] = (int)$pdo->lastInsertId();
                $_SESSION['passenger_name'] = trim($first . ' ' . $last);
                header("Location: " . $next);
                exit;
            }
        } catch (Exception $e) {
            $errors[] = "Registration failed. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Passenger Register | LebanEASE</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('register'); ?>
<main class="section"><div class="container" style="max-width:760px;">
  <h2 class="section-title">Create Passenger Account</h2>
  <p class="muted mb-14">Use this account to reserve seats, cancel eligible reservations, and update your profile.</p>
  <?php if ($errors): ?><div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px; margin-top:8px;"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div></div><div class="h-12"></div><?php endif; ?>
  <div class="card glass">
    <form method="POST" class="form-grid">
      <input type="hidden" name="next" value="<?= h($next) ?>" />
      <div class="control"><label>First Name</label><input type="text" name="first_name" value="<?= h($first) ?>" required /></div>
      <div class="control"><label>Last Name</label><input type="text" name="last_name" value="<?= h($last) ?>" required /></div>
      <div class="control"><label>Email</label><input type="email" name="email" value="<?= h($email) ?>" required /></div>
      <div class="control"><label>Phone</label><input type="text" name="phone" value="<?= h($phone) ?>" required /></div>
      <div class="control"><label>Gender</label><select name="gender" required><option value="">Select</option><?php foreach(['Male','Female','Other'] as $g): ?><option value="<?= h($g) ?>" <?= $gender===$g?'selected':'' ?>><?= h($g) ?></option><?php endforeach; ?></select></div>
      <div class="control"><label>Username</label><input type="text" name="username" value="<?= h($username) ?>" required /></div>
      <div class="control"><label>Password</label><input type="password" name="password" required /></div>
      <div class="control"><label>Confirm Password</label><input type="password" name="confirm_password" required /></div>
      <div class="control span-all flex gap-10 flex-wrap"><button class="btn btn-primary" type="submit">Register</button><a class="btn btn-ghost" href="login.php?next=<?= urlencode($next) ?>">Already have an account?</a></div>
    </form>
  </div>
</div></main>
</body>
</html>

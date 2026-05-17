<?php
require "db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['passenger_id'])) {
    header("Location: login.php");
    exit;
}

$passenger_id = (int)$_SESSION['passenger_id'];
$errors = [];
$success = isset($_GET['created']) ? "Account created successfully." : "";

$stmt = $pdo->prepare("SELECT * FROM Passenger WHERE Passenger_ID = ? LIMIT 1");
$stmt->execute([$passenger_id]);
$passenger = $stmt->fetch();
if (!$passenger) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$first = trim($_POST['first_name'] ?? $passenger['First_Name']);
$last = trim($_POST['last_name'] ?? $passenger['Last_Name']);
$email = trim($_POST['email'] ?? $passenger['Email']);
$phone = trim($_POST['phone'] ?? $passenger['Phone']);
$gender = trim($_POST['gender'] ?? $passenger['Gender']);
$username = trim($_POST['username'] ?? ($passenger['Username'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($first === '' || $last === '' || $email === '' || $phone === '' || $gender === '' || $username === '') {
        $errors[] = "Please fill all required profile fields.";
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Enter a valid email address.";
    if ($username !== '' && !preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) $errors[] = "Username must be 3-30 characters and use letters, numbers, or underscore only.";
    if (!in_array($gender, ['Male','Female','Other'], true)) $errors[] = "Select a valid gender.";
    if ($newPassword !== '' && strlen($newPassword) < 6) $errors[] = "New password must be at least 6 characters.";
    if ($newPassword !== $confirmPassword) $errors[] = "Password confirmation does not match.";

    if (empty($errors)) {
        try {
            $check = $pdo->prepare("SELECT COUNT(*) FROM Passenger WHERE (Email = ? OR Username = ?) AND Passenger_ID <> ?");
            $check->execute([$email, $username, $passenger_id]);
            if ((int)$check->fetchColumn() > 0) {
                $errors[] = "Email or username is already used by another passenger.";
            } else {
                if ($newPassword !== '') {
                    $stmt = $pdo->prepare("UPDATE Passenger SET First_Name=?, Last_Name=?, Email=?, Phone=?, Gender=?, Username=?, Password=? WHERE Passenger_ID=?");
                    $stmt->execute([$first, $last, $email, $phone, $gender, $username, password_hash($newPassword, PASSWORD_DEFAULT), $passenger_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE Passenger SET First_Name=?, Last_Name=?, Email=?, Phone=?, Gender=?, Username=? WHERE Passenger_ID=?");
                    $stmt->execute([$first, $last, $email, $phone, $gender, $username, $passenger_id]);
                }
                $_SESSION['passenger_name'] = trim($first . ' ' . $last);
                $success = "Profile updated successfully.";
            }
        } catch (Exception $e) {
            $errors[] = "Profile update failed. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><title>Profile | LebanEASE</title><link rel="stylesheet" href="css/style.css" /></head>
<body>
<?php require_once __DIR__ . "/partials/nav.php"; render_public_nav('profile'); ?>
<main class="section"><div class="container" style="max-width:820px;">
<h2 class="section-title">Passenger Profile</h2>
<p class="muted mb-14">View and update your basic information and password.</p>
<?php if ($errors): ?><div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px; margin-top:8px;"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div></div><div class="h-12"></div><?php endif; ?>
<?php if ($success): ?><div class="card glass"><p style="font-weight:800;">✅ <?= h($success) ?></p></div><div class="h-12"></div><?php endif; ?>
<div class="card glass">
<form method="POST" class="form-grid">
  <div class="control"><label>First Name</label><input type="text" name="first_name" value="<?= h($first) ?>" required /></div>
  <div class="control"><label>Last Name</label><input type="text" name="last_name" value="<?= h($last) ?>" required /></div>
  <div class="control"><label>Email</label><input type="email" name="email" value="<?= h($email) ?>" required /></div>
  <div class="control"><label>Phone</label><input type="text" name="phone" value="<?= h($phone) ?>" required /></div>
  <div class="control"><label>Gender</label><select name="gender" required><option value="">Select</option><?php foreach(['Male','Female','Other'] as $g): ?><option value="<?= h($g) ?>" <?= $gender===$g?'selected':'' ?>><?= h($g) ?></option><?php endforeach; ?></select></div>
  <div class="control"><label>Username</label><input type="text" name="username" value="<?= h($username) ?>" required /></div>
  <div class="control"><label>New Password</label><input type="password" name="new_password" placeholder="Leave empty to keep old password" /></div>
  <div class="control"><label>Confirm Password</label><input type="password" name="confirm_password" placeholder="Repeat new password" /></div>
  <div class="control span-all flex gap-10 flex-wrap"><button class="btn btn-primary" type="submit">Save Profile</button><a class="btn btn-ghost" href="my_reservations.php">View My Reservations</a></div>
</form>
</div>
</div></main>
</body></html>

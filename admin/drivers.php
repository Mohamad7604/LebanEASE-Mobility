<?php
require "auth.php";
require_admin();
require "../db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$errors=[]; $success="";
$driver_id = isset($_POST['driver_id']) ? (int)$_POST['driver_id'] : 0;
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$license_number = trim($_POST['license_number'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$experience_years = trim($_POST['experience_years'] ?? '');
$status = trim($_POST['driver_status'] ?? 'Active');
$validStatuses = ['Active','Unavailable','Inactive'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($first_name==='' || $last_name==='' || $license_number==='' || $phone==='' || $experience_years==='' || $status==='') $errors[] = "All fields are required.";
  if ($experience_years !== '' && (!ctype_digit((string)$experience_years) || (int)$experience_years < 0)) $errors[] = "Experience years must be a non-negative integer.";
  if (!in_array($status, $validStatuses, true)) $errors[] = "Invalid driver status.";
  if (empty($errors)) {
    try {
      if ($action === 'create') {
        $stmt=$pdo->prepare("INSERT INTO Driver (First_Name, Last_Name, License_Number, Phone, Experience_Years, Driver_Status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$first_name,$last_name,$license_number,$phone,(int)$experience_years,$status]);
        $success="Driver created successfully.";
      } elseif ($action === 'update' && $driver_id>0) {
        $stmt=$pdo->prepare("UPDATE Driver SET First_Name=?, Last_Name=?, License_Number=?, Phone=?, Experience_Years=?, Driver_Status=? WHERE Driver_ID=?");
        $stmt->execute([$first_name,$last_name,$license_number,$phone,(int)$experience_years,$status,$driver_id]);
        $success="Driver updated successfully.";
      } else $errors[]="Invalid action.";
      if ($success) { $driver_id=0; $first_name=$last_name=$license_number=$phone=$experience_years=''; $status='Active'; }
    } catch(Exception $e) { $errors[]="Database error while saving driver. Make sure the license number is unique."; }
  }
}

if (isset($_GET['delete'])) {
  $delId=(int)$_GET['delete'];
  if ($delId>0) {
    try { $pdo->prepare("DELETE FROM Driver WHERE Driver_ID=?")->execute([$delId]); $success="Driver deleted successfully."; }
    catch(Exception $e){ $errors[]="Cannot delete this driver because it may be assigned to buses or schedules. Mark it unavailable instead."; }
  }
}

if (isset($_GET['edit'])) {
  $stmt=$pdo->prepare("SELECT * FROM Driver WHERE Driver_ID=? LIMIT 1"); $stmt->execute([(int)$_GET['edit']]); $row=$stmt->fetch();
  if ($row) { $driver_id=(int)$row['Driver_ID']; $first_name=$row['First_Name']; $last_name=$row['Last_Name']; $license_number=$row['License_Number']; $phone=$row['Phone']; $experience_years=(string)$row['Experience_Years']; $status=$row['Driver_Status'] ?? 'Active'; }
}

$q=trim($_GET['q'] ?? ''); $filterStatus=trim($_GET['status'] ?? '');
$stmt=$pdo->prepare("SELECT Driver_ID, First_Name, Last_Name, License_Number, Phone, Experience_Years, Driver_Status FROM Driver WHERE (?='' OR Driver_Status=?) AND (?='' OR First_Name LIKE CONCAT('%',?,'%') OR Last_Name LIKE CONCAT('%',?,'%') OR License_Number LIKE CONCAT('%',?,'%') OR Phone LIKE CONCAT('%',?,'%')) ORDER BY Driver_ID DESC");
$stmt->execute([$filterStatus,$filterStatus,$q,$q,$q,$q,$q]);
$drivers=$stmt->fetchAll();
$adminName=$_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/><title>Drivers | LebanEASE Admin</title><link rel="stylesheet" href="../css/style.css"/></head><body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('drivers'); ?>
<main class="section"><div class="container"><h2 class="section-title">Drivers Management</h2><p class="muted mb-14">Add, update, search, and manage driver availability.</p>
<?php if($errors): ?><div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px;margin-top:8px;"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div></div><div class="h-12"></div><?php endif; ?>
<?php if($success): ?><div class="card glass"><p style="font-weight:800;">✅ <?= h($success) ?></p></div><div class="h-12"></div><?php endif; ?>
<div class="admin-grid-2"><div class="card glass"><h3><?= $driver_id>0?'Edit Driver':'Add New Driver' ?></h3><form method="POST" class="form-grid mt-12"><input type="hidden" name="driver_id" value="<?= (int)$driver_id ?>"><input type="hidden" name="action" value="<?= $driver_id>0?'update':'create' ?>"><div class="control"><label>First Name</label><input type="text" name="first_name" value="<?= h($first_name) ?>" required/></div><div class="control"><label>Last Name</label><input type="text" name="last_name" value="<?= h($last_name) ?>" required/></div><div class="control span-all"><label>License Number</label><input type="text" name="license_number" value="<?= h($license_number) ?>" required/></div><div class="control"><label>Phone</label><input type="text" name="phone" value="<?= h($phone) ?>" required/></div><div class="control"><label>Experience Years</label><input type="number" min="0" name="experience_years" value="<?= h($experience_years) ?>" required/></div><div class="control span-all"><label>Status</label><select name="driver_status" required><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select></div><div class="control span-all flex gap-10 flex-wrap"><button class="btn btn-primary" type="submit"><?= $driver_id>0?'Update Driver':'Create Driver' ?></button><?php if($driver_id>0): ?><a class="btn btn-ghost" href="drivers.php">Cancel Edit</a><?php endif; ?><a class="btn btn-ghost" href="index.php">Back</a></div></form></div>
<div class="card glass"><h3>All Drivers</h3><form method="GET" class="flex gap-10 flex-wrap mt-10"><input class="admin-search" type="text" name="q" placeholder="Search drivers..." value="<?= h($q) ?>"/><select class="admin-search" name="status"><option value="">All statuses</option><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $filterStatus===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-ghost" href="drivers.php">Clear</a></form><div class="h-12"></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Name</th><th>License</th><th>Phone</th><th>Exp</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($drivers as $d): ?><tr><td><?= (int)$d['Driver_ID'] ?></td><td><?= h($d['First_Name'].' '.$d['Last_Name']) ?></td><td><?= h($d['License_Number']) ?></td><td><?= h($d['Phone']) ?></td><td><?= (int)$d['Experience_Years'] ?></td><td><span class="status-pill status-<?= strtolower($d['Driver_Status']) ?>"><?= h($d['Driver_Status']) ?></span></td><td class="flex gap-8 flex-wrap"><a class="btn btn-ghost" href="drivers.php?edit=<?= (int)$d['Driver_ID'] ?>">Edit</a><a class="btn btn-danger" href="drivers.php?delete=<?= (int)$d['Driver_ID'] ?>" onclick="return confirm('Delete this driver? If used in schedules, it may fail.');">Delete</a></td></tr><?php endforeach; ?><?php if(empty($drivers)): ?><tr><td colspan="7" class="muted">No drivers found.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div></main></body></html>

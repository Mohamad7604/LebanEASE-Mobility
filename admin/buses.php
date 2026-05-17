<?php
require "auth.php";
require_admin();
require "../db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$errors=[]; $success="";
$bus_id=(int)($_POST['bus_id'] ?? 0);
$bus_number=trim($_POST['bus_number'] ?? '');
$capacity=trim($_POST['capacity'] ?? '');
$type=trim($_POST['type'] ?? '');
$status=trim($_POST['status'] ?? 'Active');
$driver_id=(int)($_POST['driver_id'] ?? 0);
$admin_id=(int)($_SESSION['admin_id'] ?? 0);
$validTypes=['Standard','MiniBus','Van','AirportShuttle','VIP','Luxury','LongDistance'];
$validStatuses=['Active','Maintenance','Offline','Unavailable'];
$drivers=$pdo->query("SELECT Driver_ID, First_Name, Last_Name, Driver_Status FROM Driver ORDER BY First_Name, Last_Name")->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action=$_POST['action'] ?? '';
  if($bus_number==='' || $capacity==='' || $type==='' || $status==='') $errors[]="All fields are required.";
  if($capacity!=='' && (!ctype_digit($capacity) || (int)$capacity<=0)) $errors[]="Capacity must be a positive integer.";
  if(!in_array($type,$validTypes,true)) $errors[]="Invalid bus type.";
  if(!in_array($status,$validStatuses,true)) $errors[]="Invalid bus status.";
  if($driver_id>0){ $st=$pdo->prepare("SELECT COUNT(*) FROM Driver WHERE Driver_ID=?"); $st->execute([$driver_id]); if((int)$st->fetchColumn()===0) $errors[]="Selected driver does not exist."; }
  if(empty($errors)){
    try{
      $driverValue=$driver_id>0?$driver_id:null;
      if($action==='create'){
        $stmt=$pdo->prepare("INSERT INTO Bus (Bus_Number, Capacity, Type, Status, Driver_ID, Admin_ID) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$bus_number,(int)$capacity,$type,$status,$driverValue,$admin_id ?: null]);
        $success="Bus created successfully.";
      }elseif($action==='update' && $bus_id>0){
        $stmt=$pdo->prepare("UPDATE Bus SET Bus_Number=?, Capacity=?, Type=?, Status=?, Driver_ID=? WHERE Bus_ID=?");
        $stmt->execute([$bus_number,(int)$capacity,$type,$status,$driverValue,$bus_id]);
        $success="Bus updated successfully.";
      }else $errors[]="Invalid action.";
      if($success){$bus_id=0;$bus_number=$capacity=$type='';$status='Active';$driver_id=0;}
    }catch(Exception $e){$errors[]="Database error while saving bus. Make sure the bus number is unique.";}
  }
}

if(isset($_GET['delete'])){
  $delId=(int)$_GET['delete'];
  if($delId>0){
    try{
      $future=$pdo->prepare("SELECT COUNT(*) FROM Bus_Schedule WHERE Bus_ID=? AND Status='Scheduled' AND TIMESTAMP(Date, Departure_Time) > NOW()");
      $future->execute([$delId]);
      if((int)$future->fetchColumn()>0){
        $errors[]="Cannot permanently delete this bus because it is assigned to an uncancelled future trip. Reassign or cancel the trip first, or mark the bus unavailable.";
      }else{
        $pdo->prepare("DELETE FROM Bus WHERE Bus_ID=?")->execute([$delId]);
        $success="Bus deleted successfully.";
      }
    }catch(Exception $e){
      $errors[]="Cannot delete this bus because it is still referenced by existing records. Mark it unavailable instead.";
    }
  }
}
if(isset($_GET['edit'])){
  $stmt=$pdo->prepare("SELECT * FROM Bus WHERE Bus_ID=? LIMIT 1");$stmt->execute([(int)$_GET['edit']]);$row=$stmt->fetch();
  if($row){$bus_id=(int)$row['Bus_ID'];$bus_number=$row['Bus_Number'];$capacity=(string)$row['Capacity'];$type=$row['Type'];$status=$row['Status'];$driver_id=(int)($row['Driver_ID'] ?? 0);}
}
$q=trim($_GET['q'] ?? '');$filterStatus=trim($_GET['status'] ?? '');
$stmt=$pdo->prepare("SELECT b.Bus_ID,b.Bus_Number,b.Capacity,b.Type,b.Status,CONCAT(d.First_Name,' ',d.Last_Name) AS Driver_Name FROM Bus b LEFT JOIN Driver d ON b.Driver_ID=d.Driver_ID WHERE (?='' OR b.Status=?) AND (?='' OR b.Bus_Number LIKE CONCAT('%',?,'%') OR b.Type LIKE CONCAT('%',?,'%') OR b.Status LIKE CONCAT('%',?,'%') OR d.First_Name LIKE CONCAT('%',?,'%') OR d.Last_Name LIKE CONCAT('%',?,'%')) ORDER BY b.Bus_ID DESC");
$stmt->execute([$filterStatus,$filterStatus,$q,$q,$q,$q,$q,$q]);$buses=$stmt->fetchAll();
$adminName=$_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/><title>Buses | LebanEASE Admin</title><link rel="stylesheet" href="../css/style.css"/></head><body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('buses'); ?>
<main class="section"><div class="container"><h2 class="section-title">Buses Management</h2><p class="muted mb-14">Manage buses, statuses, and assigned drivers.</p>
<?php if($errors): ?><div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px;margin-top:8px;"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div></div><div class="h-12"></div><?php endif; ?>
<?php if($success): ?><div class="card glass"><p style="font-weight:800;">✅ <?= h($success) ?></p></div><div class="h-12"></div><?php endif; ?>
<div class="admin-grid-2"><div class="card glass"><h3><?= $bus_id>0?'Edit Bus':'Add New Bus' ?></h3><form method="POST" class="form-grid mt-12"><input type="hidden" name="bus_id" value="<?= (int)$bus_id ?>"><input type="hidden" name="action" value="<?= $bus_id>0?'update':'create' ?>"><div class="control span-all"><label>Bus Number</label><input type="text" name="bus_number" value="<?= h($bus_number) ?>" required/></div><div class="control"><label>Capacity</label><input type="number" min="1" name="capacity" value="<?= h($capacity) ?>" required/></div><div class="control"><label>Type</label><select name="type" required><option value="">Select</option><?php foreach($validTypes as $t): ?><option value="<?= h($t) ?>" <?= $type===$t?'selected':'' ?>><?= h($t) ?></option><?php endforeach; ?></select></div><div class="control"><label>Status</label><select name="status" required><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select></div><div class="control"><label>Assigned Driver</label><select name="driver_id"><option value="0">No driver</option><?php foreach($drivers as $d): ?><option value="<?= (int)$d['Driver_ID'] ?>" <?= (int)$d['Driver_ID']===$driver_id?'selected':'' ?>><?= h($d['First_Name'].' '.$d['Last_Name'].' — '.$d['Driver_Status']) ?></option><?php endforeach; ?></select></div><div class="control span-all flex gap-10 flex-wrap"><button class="btn btn-primary" type="submit"><?= $bus_id>0?'Update Bus':'Create Bus' ?></button><?php if($bus_id>0): ?><a class="btn btn-ghost" href="buses.php">Cancel Edit</a><?php endif; ?><a class="btn btn-ghost" href="index.php">Back</a></div></form></div>
<div class="card glass"><h3>All Buses</h3><form method="GET" class="flex gap-10 flex-wrap mt-10"><input class="admin-search" type="text" name="q" placeholder="Search bus, type, driver..." value="<?= h($q) ?>"/><select class="admin-search" name="status"><option value="">All statuses</option><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $filterStatus===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-ghost" href="buses.php">Clear</a></form><div class="h-12"></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Bus #</th><th>Capacity</th><th>Type</th><th>Status</th><th>Driver</th><th>Actions</th></tr></thead><tbody><?php foreach($buses as $b): ?><tr><td><?= (int)$b['Bus_ID'] ?></td><td><?= h($b['Bus_Number']) ?></td><td><?= (int)$b['Capacity'] ?></td><td><?= h($b['Type']) ?></td><td><span class="status-pill status-<?= strtolower($b['Status']) ?>"><?= h($b['Status']) ?></span></td><td><?= h($b['Driver_Name'] ?? 'Not assigned') ?></td><td class="flex gap-8 flex-wrap"><a class="btn btn-ghost" href="buses.php?edit=<?= (int)$b['Bus_ID'] ?>">Edit</a><a class="btn btn-danger" href="buses.php?delete=<?= (int)$b['Bus_ID'] ?>" onclick="return confirm('Delete this bus? If it is used in schedules/maintenance, it may fail.');">Delete</a></td></tr><?php endforeach; ?><?php if(empty($buses)): ?><tr><td colspan="7" class="muted">No buses found.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div></main></body></html>

<?php
require "auth.php";
require_admin();
require "../db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$errors=[]; $success="";
$route_id=(int)($_POST['route_id'] ?? 0);
$route_name=trim($_POST['route_name'] ?? '');
$source=trim($_POST['source'] ?? '');
$destination=trim($_POST['destination'] ?? '');
$distance=trim($_POST['distance'] ?? '');
$status=trim($_POST['status'] ?? 'Active');
$validStatuses=['Active','Inactive'];

if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=$_POST['action'] ?? '';
  if($route_name===''||$source===''||$destination===''||$distance===''||$status==='') $errors[]="All fields are required.";
  if($source!==''&&$destination!==''&&strcasecmp($source,$destination)===0) $errors[]="Source and Destination cannot be the same.";
  if($distance!==''&&(!is_numeric($distance)||(float)$distance<=0)) $errors[]="Distance must be a positive number.";
  if(!in_array($status,$validStatuses,true)) $errors[]="Invalid route status.";
  if(empty($errors)){
    try{
      if($action==='create'){
        $stmt=$pdo->prepare("INSERT INTO Route (Route_Name, Source, Destination, Distance, Status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$route_name,$source,$destination,(float)$distance,$status]);
        $success="Route created successfully.";
      }elseif($action==='update'&&$route_id>0){
        $stmt=$pdo->prepare("UPDATE Route SET Route_Name=?, Source=?, Destination=?, Distance=?, Status=? WHERE Route_ID=?");
        $stmt->execute([$route_name,$source,$destination,(float)$distance,$status,$route_id]);
        $success="Route updated successfully.";
      }else $errors[]="Invalid action.";
      if($success){$route_id=0;$route_name=$source=$destination=$distance='';$status='Active';}
    }catch(Exception $e){$errors[]="Database error while saving route.";}
  }
}
if(isset($_GET['deactivate'])){ $id=(int)$_GET['deactivate']; if($id>0){try{$pdo->prepare("UPDATE Route SET Status='Inactive' WHERE Route_ID=?")->execute([$id]);$success="Route deactivated successfully.";}catch(Exception $e){$errors[]="Could not deactivate route.";}} }
if(isset($_GET['activate'])){ $id=(int)$_GET['activate']; if($id>0){try{$pdo->prepare("UPDATE Route SET Status='Active' WHERE Route_ID=?")->execute([$id]);$success="Route activated successfully.";}catch(Exception $e){$errors[]="Could not activate route.";}} }
if(isset($_GET['delete'])){ $id=(int)$_GET['delete']; if($id>0){try{$pdo->prepare("DELETE FROM Route WHERE Route_ID=?")->execute([$id]);$success="Route deleted successfully.";}catch(Exception $e){$errors[]="Cannot delete this route because it may be used in schedules. Deactivate it instead.";}} }
if(isset($_GET['edit'])){ $stmt=$pdo->prepare("SELECT * FROM Route WHERE Route_ID=? LIMIT 1");$stmt->execute([(int)$_GET['edit']]);$row=$stmt->fetch(); if($row){$route_id=(int)$row['Route_ID'];$route_name=$row['Route_Name'];$source=$row['Source'];$destination=$row['Destination'];$distance=(string)$row['Distance'];$status=$row['Status'] ?? 'Active';} }
$q=trim($_GET['q'] ?? '');$filterStatus=trim($_GET['status'] ?? '');
$stmt=$pdo->prepare("SELECT Route_ID,Route_Name,Source,Destination,Distance,Status FROM Route WHERE (?='' OR Status=?) AND (?='' OR Route_Name LIKE CONCAT('%',?,'%') OR Source LIKE CONCAT('%',?,'%') OR Destination LIKE CONCAT('%',?,'%')) ORDER BY Route_ID DESC");
$stmt->execute([$filterStatus,$filterStatus,$q,$q,$q,$q]);$routes=$stmt->fetchAll();
$adminName=$_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/><title>Routes | LebanEASE Admin</title><link rel="stylesheet" href="../css/style.css"/></head><body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('routes'); ?>
<main class="section"><div class="container"><h2 class="section-title">Routes Management</h2><p class="muted mb-14">Create, update, activate, deactivate, and search routes.</p>
<?php if($errors): ?><div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px;margin-top:8px;"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div></div><div class="h-12"></div><?php endif; ?>
<?php if($success): ?><div class="card glass"><p style="font-weight:800;">✅ <?= h($success) ?></p></div><div class="h-12"></div><?php endif; ?>
<div class="admin-grid-2"><div class="card glass"><h3><?= $route_id>0?'Edit Route':'Add New Route' ?></h3><form method="POST" class="form-grid mt-12"><input type="hidden" name="route_id" value="<?= (int)$route_id ?>"><input type="hidden" name="action" value="<?= $route_id>0?'update':'create' ?>"><div class="control span-all"><label>Route Name</label><input type="text" name="route_name" value="<?= h($route_name) ?>" required/></div><div class="control"><label>Source</label><input type="text" name="source" value="<?= h($source) ?>" required/></div><div class="control"><label>Destination</label><input type="text" name="destination" value="<?= h($destination) ?>" required/></div><div class="control"><label>Distance (km)</label><input type="number" step="0.01" min="0.01" name="distance" value="<?= h($distance) ?>" required/></div><div class="control"><label>Status</label><select name="status"><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select></div><div class="control span-all flex gap-10 flex-wrap"><button class="btn btn-primary" type="submit"><?= $route_id>0?'Update Route':'Create Route' ?></button><?php if($route_id>0): ?><a class="btn btn-ghost" href="routes.php">Cancel Edit</a><?php endif; ?><a class="btn btn-ghost" href="index.php">Back</a></div></form></div>
<div class="card glass"><h3>All Routes</h3><form method="GET" class="flex gap-10 flex-wrap mt-10"><input class="admin-search" type="text" name="q" placeholder="Search routes..." value="<?= h($q) ?>"/><select class="admin-search" name="status"><option value="">All statuses</option><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $filterStatus===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-ghost" href="routes.php">Clear</a></form><div class="h-12"></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Name</th><th>Source</th><th>Destination</th><th>KM</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($routes as $r): ?><tr><td><?= (int)$r['Route_ID'] ?></td><td><?= h($r['Route_Name']) ?></td><td><?= h($r['Source']) ?></td><td><?= h($r['Destination']) ?></td><td><?= h($r['Distance']) ?></td><td><span class="status-pill status-<?= strtolower($r['Status']) ?>"><?= h($r['Status']) ?></span></td><td class="flex gap-8 flex-wrap"><a class="btn btn-ghost" href="routes.php?edit=<?= (int)$r['Route_ID'] ?>">Edit</a><?php if($r['Status']==='Active'): ?><a class="btn btn-secondary" href="routes.php?deactivate=<?= (int)$r['Route_ID'] ?>" onclick="return confirm('Deactivate this route? It will not be used for new trips.');">Deactivate</a><?php else: ?><a class="btn btn-secondary" href="routes.php?activate=<?= (int)$r['Route_ID'] ?>" onclick="return confirm('Activate this route?');">Activate</a><?php endif; ?><a class="btn btn-danger" href="routes.php?delete=<?= (int)$r['Route_ID'] ?>" onclick="return confirm('Delete this route? If it is used in schedules, it may fail.');">Delete</a></td></tr><?php endforeach; ?><?php if(empty($routes)): ?><tr><td colspan="7" class="muted">No routes found.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div></main></body></html>

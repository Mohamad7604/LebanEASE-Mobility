<?php
require "auth.php";
require_admin();
require "../db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$errors=[]; $success="";
$buses=$pdo->query("SELECT Bus_ID, Bus_Number, Type, Status FROM Bus ORDER BY Bus_Number")->fetchAll();
$maintenance_id=(int)($_POST['maintenance_id'] ?? 0);
$date=trim($_POST['date'] ?? '');
$description=trim($_POST['description'] ?? '');
$cost=trim($_POST['cost'] ?? '');
$bus_id=(int)($_POST['bus_id'] ?? 0);
$maintenance_status=trim($_POST['maintenance_status'] ?? 'Open');
$validStatuses=['Open','Completed'];
function valid_date($d){ return preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)===1; }

if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=$_POST['action'] ?? '';
  if($date===''||$description===''||$cost===''||$bus_id<=0||$maintenance_status==='') $errors[]="All fields are required.";
  if($date!==''&&!valid_date($date)) $errors[]="Invalid date format.";
  if($cost!==''&&(!is_numeric($cost)||(float)$cost<0)) $errors[]="Cost must be a non-negative number.";
  if(!in_array($maintenance_status,$validStatuses,true)) $errors[]="Invalid maintenance status.";
  if($bus_id>0){$st=$pdo->prepare("SELECT COUNT(*) FROM Bus WHERE Bus_ID=?");$st->execute([$bus_id]);if((int)$st->fetchColumn()===0)$errors[]="Selected bus does not exist.";}
  if(empty($errors)){
    try{
      $pdo->beginTransaction();
      if($action==='create'){
        $stmt=$pdo->prepare("INSERT INTO Bus_Maintenance (Date, Description, Cost, Maintenance_Status, Bus_ID) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$date,$description,(float)$cost,$maintenance_status,$bus_id]);
        $success="Maintenance record created successfully.";
      }elseif($action==='update'&&$maintenance_id>0){
        $stmt=$pdo->prepare("UPDATE Bus_Maintenance SET Date=?, Description=?, Cost=?, Maintenance_Status=?, Bus_ID=? WHERE Maintenance_ID=?");
        $stmt->execute([$date,$description,(float)$cost,$maintenance_status,$bus_id,$maintenance_id]);
        $success="Maintenance record updated successfully.";
      }else $errors[]="Invalid action.";
      if(empty($errors)){
        if($maintenance_status==='Open') $pdo->prepare("UPDATE Bus SET Status='Maintenance' WHERE Bus_ID=?")->execute([$bus_id]);
        else {
          $open=$pdo->prepare("SELECT COUNT(*) FROM Bus_Maintenance WHERE Bus_ID=? AND Maintenance_Status='Open'");$open->execute([$bus_id]);
          if((int)$open->fetchColumn()===0) $pdo->prepare("UPDATE Bus SET Status='Active' WHERE Bus_ID=? AND Status='Maintenance'")->execute([$bus_id]);
        }
        $pdo->commit();
        if($success){$maintenance_id=0;$date=$description=$cost='';$bus_id=0;$maintenance_status='Open';}
      } else $pdo->rollBack();
    }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]="Database error while saving maintenance record.";}
  }
}
if(isset($_GET['complete'])){
  $id=(int)$_GET['complete'];
  if($id>0){try{$pdo->beginTransaction();$st=$pdo->prepare("SELECT Bus_ID FROM Bus_Maintenance WHERE Maintenance_ID=?");$st->execute([$id]);$bid=(int)$st->fetchColumn();$pdo->prepare("UPDATE Bus_Maintenance SET Maintenance_Status='Completed' WHERE Maintenance_ID=?")->execute([$id]);$open=$pdo->prepare("SELECT COUNT(*) FROM Bus_Maintenance WHERE Bus_ID=? AND Maintenance_Status='Open'");$open->execute([$bid]);if((int)$open->fetchColumn()===0)$pdo->prepare("UPDATE Bus SET Status='Active' WHERE Bus_ID=? AND Status='Maintenance'")->execute([$bid]);$pdo->commit();$success="Maintenance marked as completed.";}catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]="Could not complete maintenance.";}}
}
if(isset($_GET['delete'])){
  $id=(int)$_GET['delete']; if($id>0){try{$pdo->prepare("DELETE FROM Bus_Maintenance WHERE Maintenance_ID=?")->execute([$id]);$success="Maintenance record deleted successfully.";}catch(Exception $e){$errors[]="Cannot delete this maintenance record.";}}
}
if(isset($_GET['edit'])){
  $stmt=$pdo->prepare("SELECT * FROM Bus_Maintenance WHERE Maintenance_ID=? LIMIT 1");$stmt->execute([(int)$_GET['edit']]);$row=$stmt->fetch();
  if($row){$maintenance_id=(int)$row['Maintenance_ID'];$date=$row['Date'];$description=$row['Description'];$cost=(string)$row['Cost'];$bus_id=(int)$row['Bus_ID'];$maintenance_status=$row['Maintenance_Status'] ?? 'Open';}
}
$summary=[];foreach(['Active','Maintenance','Offline','Unavailable'] as $st){try{$q=$pdo->prepare("SELECT COUNT(*) FROM Bus WHERE Status=?");$q->execute([$st]);$summary[$st]=(int)$q->fetchColumn();}catch(Exception $e){$summary[$st]=0;}}
$q=trim($_GET['q'] ?? '');$filterBus=(int)($_GET['bus_id'] ?? 0);$filterStatus=trim($_GET['maintenance_status'] ?? '');
$stmt=$pdo->prepare("SELECT m.Maintenance_ID,m.Date,m.Description,m.Cost,m.Maintenance_Status,b.Bus_ID,b.Bus_Number,b.Type AS Bus_Type,b.Status AS Bus_Status FROM Bus_Maintenance m JOIN Bus b ON m.Bus_ID=b.Bus_ID WHERE (?=0 OR b.Bus_ID=?) AND (?='' OR m.Maintenance_Status=?) AND (?='' OR m.Description LIKE CONCAT('%',?,'%') OR b.Bus_Number LIKE CONCAT('%',?,'%') OR b.Type LIKE CONCAT('%',?,'%')) ORDER BY m.Date DESC,m.Maintenance_ID DESC");
$stmt->execute([$filterBus,$filterBus,$filterStatus,$filterStatus,$q,$q,$q,$q]);$maintenanceRows=$stmt->fetchAll();
$adminName=$_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/><title>Maintenance | LebanEASE Admin</title><link rel="stylesheet" href="../css/style.css"/></head><body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('maintenance'); ?>
<main class="section"><div class="container"><h2 class="section-title">Maintenance Dashboard</h2><p class="muted mb-14">Record maintenance, group buses by status, and prevent buses under maintenance from being scheduled.</p>
<div class="admin-grid"><?php foreach($summary as $label=>$value): ?><div class="card glass admin-stat"><div class="admin-stat-num"><?= (int)$value ?></div><div class="admin-stat-label"><?= h($label) ?> Buses</div></div><?php endforeach; ?></div><div class="h-12"></div>
<?php if($errors): ?><div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px;margin-top:8px;"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div></div><div class="h-12"></div><?php endif; ?>
<?php if($success): ?><div class="card glass"><p style="font-weight:800;">✅ <?= h($success) ?></p></div><div class="h-12"></div><?php endif; ?>
<div class="admin-grid-2"><div class="card glass"><h3><?= $maintenance_id>0?'Edit Maintenance':'Add Maintenance Record' ?></h3><form method="POST" class="form-grid mt-12"><input type="hidden" name="maintenance_id" value="<?= (int)$maintenance_id ?>"><input type="hidden" name="action" value="<?= $maintenance_id>0?'update':'create' ?>"><div class="control"><label>Date</label><input type="date" name="date" value="<?= h($date) ?>" required/></div><div class="control"><label>Cost</label><input type="number" step="0.01" min="0" name="cost" value="<?= h($cost) ?>" required/></div><div class="control span-all"><label>Bus</label><select name="bus_id" required><option value="">Select bus</option><?php foreach($buses as $b): ?><option value="<?= (int)$b['Bus_ID'] ?>" <?= (int)$b['Bus_ID']===$bus_id?'selected':'' ?>><?= h($b['Bus_Number'].' — '.$b['Type'].' — '.$b['Status']) ?></option><?php endforeach; ?></select></div><div class="control span-all"><label>Maintenance Status</label><select name="maintenance_status"><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $maintenance_status===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select></div><div class="control span-all"><label>Description</label><textarea name="description" rows="5" required><?= h($description) ?></textarea></div><div class="control span-all flex gap-10 flex-wrap"><button class="btn btn-primary" type="submit"><?= $maintenance_id>0?'Update Record':'Create Record' ?></button><?php if($maintenance_id>0): ?><a class="btn btn-ghost" href="maintenance.php">Cancel Edit</a><?php endif; ?><a class="btn btn-ghost" href="index.php">Back</a></div></form></div>
<div class="card glass"><h3>Maintenance Records</h3><form method="GET" class="flex gap-10 flex-wrap mt-10"><input class="admin-search" type="text" name="q" placeholder="Search description or bus..." value="<?= h($q) ?>"/><select class="admin-search" name="bus_id"><option value="0">All buses</option><?php foreach($buses as $b): ?><option value="<?= (int)$b['Bus_ID'] ?>" <?= (int)$b['Bus_ID']===$filterBus?'selected':'' ?>><?= h($b['Bus_Number']) ?></option><?php endforeach; ?></select><select class="admin-search" name="maintenance_status"><option value="">All statuses</option><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $filterStatus===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-ghost" href="maintenance.php">Clear</a></form><div class="h-12"></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Date</th><th>Bus</th><th>Bus Status</th><th>Maintenance</th><th>Cost</th><th>Description</th><th>Actions</th></tr></thead><tbody><?php foreach($maintenanceRows as $m): ?><tr><td><?= (int)$m['Maintenance_ID'] ?></td><td><?= h($m['Date']) ?></td><td><?= h($m['Bus_Number'].' ('.$m['Bus_Type'].')') ?></td><td><span class="status-pill status-<?= strtolower($m['Bus_Status']) ?>"><?= h($m['Bus_Status']) ?></span></td><td><span class="status-pill status-<?= strtolower($m['Maintenance_Status']) ?>"><?= h($m['Maintenance_Status']) ?></span></td><td>$<?= number_format((float)$m['Cost'],2) ?></td><td><?= h($m['Description']) ?></td><td class="flex gap-8 flex-wrap"><a class="btn btn-ghost" href="maintenance.php?edit=<?= (int)$m['Maintenance_ID'] ?>">Edit</a><?php if($m['Maintenance_Status']==='Open'): ?><a class="btn btn-ghost" href="maintenance.php?complete=<?= (int)$m['Maintenance_ID'] ?>" onclick="return confirm('Mark this maintenance as completed?');">Complete</a><?php endif; ?><a class="btn btn-danger" href="maintenance.php?delete=<?= (int)$m['Maintenance_ID'] ?>" onclick="return confirm('Delete this maintenance record?');">Delete</a></td></tr><?php endforeach; ?><?php if(empty($maintenanceRows)): ?><tr><td colspan="8" class="muted">No maintenance records found.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div></main></body></html>

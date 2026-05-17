<?php
require "auth.php";
require_admin();
require "../db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$errors=[]; $success="";
$buses=$pdo->query("SELECT Bus_ID, Bus_Number, Type, Capacity, Status FROM Bus ORDER BY Bus_Number")->fetchAll();
$routes=$pdo->query("SELECT Route_ID, Route_Name, Source, Destination, Distance, Status FROM Route ORDER BY Source, Destination")->fetchAll();
$drivers=$pdo->query("SELECT Driver_ID, First_Name, Last_Name, Driver_Status FROM Driver ORDER BY First_Name, Last_Name")->fetchAll();

$schedule_id=(int)($_POST['schedule_id'] ?? 0);
$date=trim($_POST['date'] ?? '');
$departure_time=trim($_POST['departure_time'] ?? '');
$arrival_time=trim($_POST['arrival_time'] ?? '');
$bus_id=(int)($_POST['bus_id'] ?? 0);
$route_id=(int)($_POST['route_id'] ?? 0);
$driver_id=(int)($_POST['driver_id'] ?? 0);
$status=trim($_POST['status'] ?? 'Scheduled');
$validStatuses=['Scheduled','Cancelled'];
function valid_date($d){ return preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)===1; }
function valid_time($t){ return preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$t)===1; }
function to_dt(string $date,string $time): DateTime { return new DateTime($date.' '.$time); }
function overlaps(PDO $pdo, int $schedule_id, int $bus_id, int $driver_id, string $date, string $dep, string $arr): bool {
  $newStart=to_dt($date,$dep);
  $newEnd=to_dt($date,$arr);
  if($newEnd <= $newStart) return true; // invalid interval; form validation will show the exact error
  $stmt=$pdo->prepare("SELECT Schedule_ID, Bus_ID, Driver_ID, Date, Departure_Time, Arrival_Time FROM Bus_Schedule WHERE Status='Scheduled' AND Date = ? AND Schedule_ID <> ? AND (Bus_ID=? OR Driver_ID=?)");
  $stmt->execute([$date,$schedule_id,$bus_id,$driver_id]);
  foreach($stmt->fetchAll() as $row){
    $start=to_dt($row['Date'],$row['Departure_Time']);
    $end=to_dt($row['Date'],$row['Arrival_Time']);
    if($end <= $start) continue;
    if($newStart < $end && $newEnd > $start) return true;
  }
  return false;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=$_POST['action'] ?? '';
  if($date===''||$departure_time===''||$arrival_time===''||$bus_id<=0||$route_id<=0||$driver_id<=0||$status==='') $errors[]="All fields are required.";
  if($date!==''&&!valid_date($date)) $errors[]="Invalid date format.";
  if($departure_time!==''&&!valid_time($departure_time)) $errors[]="Invalid departure time.";
  if($arrival_time!==''&&!valid_time($arrival_time)) $errors[]="Invalid arrival time.";
  if(!in_array($status,$validStatuses,true)) $errors[]="Invalid schedule status.";
  if($date!=='' && valid_date($date) && $departure_time!=='' && $arrival_time!=='' && valid_time($departure_time) && valid_time($arrival_time)){
    $start=to_dt($date,$departure_time);
    $end=to_dt($date,$arrival_time);
    if($end <= $start) $errors[]="Arrival time must be later than departure time.";
    if($status==='Scheduled' && $start <= new DateTime()) $errors[]="Scheduled trips must have a future departure date and time.";
  }
  if(empty($errors)){
    $bus=$pdo->prepare("SELECT Status FROM Bus WHERE Bus_ID=?"); $bus->execute([$bus_id]); $busStatus=$bus->fetchColumn();
    $driver=$pdo->prepare("SELECT Driver_Status FROM Driver WHERE Driver_ID=?"); $driver->execute([$driver_id]); $driverStatus=$driver->fetchColumn();
    $route=$pdo->prepare("SELECT Status FROM Route WHERE Route_ID=?"); $route->execute([$route_id]); $routeStatus=$route->fetchColumn();
    if(!$busStatus) $errors[]="Selected bus does not exist."; elseif($status==='Scheduled' && $busStatus!=='Active') $errors[]="Selected bus is not active.";
    if(!$driverStatus) $errors[]="Selected driver does not exist."; elseif($status==='Scheduled' && $driverStatus!=='Active') $errors[]="Selected driver is not active.";
    if(!$routeStatus) $errors[]="Selected route does not exist."; elseif($status==='Scheduled' && $routeStatus!=='Active') $errors[]="Selected route is not active.";
    if(empty($errors) && $status==='Scheduled' && overlaps($pdo,$schedule_id,$bus_id,$driver_id,$date,$departure_time,$arrival_time)) $errors[]="Bus or driver is already assigned to another overlapping trip.";
  }
  if(empty($errors)){
    try{
      if($action==='create'){
        $stmt=$pdo->prepare("INSERT INTO Bus_Schedule (Departure_Time, Arrival_Time, Date, Bus_ID, Route_ID, Driver_ID, Status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$departure_time,$arrival_time,$date,$bus_id,$route_id,$driver_id,$status]);
        $success="Schedule created successfully.";
      }elseif($action==='update' && $schedule_id>0){
        $stmt=$pdo->prepare("UPDATE Bus_Schedule SET Departure_Time=?, Arrival_Time=?, Date=?, Bus_ID=?, Route_ID=?, Driver_ID=?, Status=? WHERE Schedule_ID=?");
        $stmt->execute([$departure_time,$arrival_time,$date,$bus_id,$route_id,$driver_id,$status,$schedule_id]);
        $success="Schedule updated successfully.";
      }else $errors[]="Invalid action.";
      if($success){$schedule_id=0;$date=$departure_time=$arrival_time='';$bus_id=$route_id=$driver_id=0;$status='Scheduled';}
    }catch(Exception $e){$errors[]="Database error while saving schedule.";}
  }
}
if(isset($_GET['cancel'])){
  $id=(int)$_GET['cancel']; if($id>0){try{$pdo->prepare("UPDATE Bus_Schedule SET Status='Cancelled' WHERE Schedule_ID=?")->execute([$id]);$success="Schedule cancelled successfully.";}catch(Exception $e){$errors[]="Could not cancel schedule.";}}
}
if(isset($_GET['delete'])){
  $id=(int)$_GET['delete']; if($id>0){try{$pdo->prepare("DELETE FROM Bus_Schedule WHERE Schedule_ID=?")->execute([$id]);$success="Schedule deleted successfully.";}catch(Exception $e){$errors[]="Cannot delete this schedule because it has reservations. Cancel it instead.";}}
}
if(isset($_GET['edit'])){
  $stmt=$pdo->prepare("SELECT * FROM Bus_Schedule WHERE Schedule_ID=? LIMIT 1");$stmt->execute([(int)$_GET['edit']]);$row=$stmt->fetch();
  if($row){$schedule_id=(int)$row['Schedule_ID'];$date=$row['Date'];$departure_time=$row['Departure_Time'];$arrival_time=$row['Arrival_Time'];$bus_id=(int)$row['Bus_ID'];$route_id=(int)$row['Route_ID'];$driver_id=(int)($row['Driver_ID']??0);$status=$row['Status'];}
}
$q=trim($_GET['q'] ?? '');$filterDate=trim($_GET['date'] ?? '');$filterStatus=trim($_GET['status'] ?? '');
$stmt=$pdo->prepare("SELECT * FROM v_schedule_overview WHERE (?='' OR Schedule_Status=?) AND (?='' OR Date=?) AND (?='' OR Bus_Number LIKE CONCAT('%',?,'%') OR Source LIKE CONCAT('%',?,'%') OR Destination LIKE CONCAT('%',?,'%') OR Driver_Name LIKE CONCAT('%',?,'%')) ORDER BY Date DESC, Departure_Time ASC");
$stmt->execute([$filterStatus,$filterStatus,$filterDate,$filterDate,$q,$q,$q,$q,$q]);$schedules=$stmt->fetchAll();
$adminName=$_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/><title>Schedules | LebanEASE Admin</title><link rel="stylesheet" href="../css/style.css"/></head><body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('schedules'); ?>
<main class="section"><div class="container"><h2 class="section-title">Trip Scheduling</h2><p class="muted mb-14">Create trips by selecting a route, bus, driver, date, and time. Overlapping bus/driver assignments are blocked.</p>
<?php if($errors): ?><div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px;margin-top:8px;"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div></div><div class="h-12"></div><?php endif; ?>
<?php if($success): ?><div class="card glass"><p style="font-weight:800;">✅ <?= h($success) ?></p></div><div class="h-12"></div><?php endif; ?>
<div class="admin-grid-2"><div class="card glass"><h3><?= $schedule_id>0?'Edit Schedule':'Add Schedule' ?></h3><form method="POST" class="form-grid mt-12"><input type="hidden" name="schedule_id" value="<?= (int)$schedule_id ?>"><input type="hidden" name="action" value="<?= $schedule_id>0?'update':'create' ?>"><div class="control"><label>Date</label><input type="date" name="date" value="<?= h($date) ?>" required/></div><div class="control"><label>Departure</label><input type="time" name="departure_time" value="<?= h(substr($departure_time,0,5)) ?>" required/></div><div class="control"><label>Arrival</label><input type="time" name="arrival_time" value="<?= h(substr($arrival_time,0,5)) ?>" required/></div><div class="control"><label>Status</label><select name="status"><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select></div><div class="control span-all"><label>Bus</label><select name="bus_id" required><option value="">Select bus</option><?php foreach($buses as $b): ?><option value="<?= (int)$b['Bus_ID'] ?>" <?= (int)$b['Bus_ID']===$bus_id?'selected':'' ?>><?= h($b['Bus_Number'].' — '.$b['Type'].' — '.$b['Status'].' — '.$b['Capacity'].' seats') ?></option><?php endforeach; ?></select></div><div class="control span-all"><label>Driver</label><select name="driver_id" required><option value="">Select driver</option><?php foreach($drivers as $d): ?><option value="<?= (int)$d['Driver_ID'] ?>" <?= (int)$d['Driver_ID']===$driver_id?'selected':'' ?>><?= h($d['First_Name'].' '.$d['Last_Name'].' — '.$d['Driver_Status']) ?></option><?php endforeach; ?></select></div><div class="control span-all"><label>Route</label><select name="route_id" required><option value="">Select route</option><?php foreach($routes as $r): ?><option value="<?= (int)$r['Route_ID'] ?>" <?= (int)$r['Route_ID']===$route_id?'selected':'' ?>><?= h($r['Route_Name'].' — '.$r['Source'].' → '.$r['Destination'].' — '.$r['Status']) ?></option><?php endforeach; ?></select></div><div class="control span-all flex gap-10 flex-wrap"><button class="btn btn-primary" type="submit"><?= $schedule_id>0?'Update Schedule':'Create Schedule' ?></button><?php if($schedule_id>0): ?><a class="btn btn-ghost" href="schedules.php">Cancel Edit</a><?php endif; ?><a class="btn btn-ghost" href="index.php">Back</a></div></form></div>
<div class="card glass"><h3>All Schedules</h3><form method="GET" class="flex gap-10 flex-wrap mt-10"><input class="admin-search" type="text" name="q" placeholder="Search bus, route, driver..." value="<?= h($q) ?>"/><input class="admin-search" type="date" name="date" value="<?= h($filterDate) ?>"/><select class="admin-search" name="status"><option value="">All statuses</option><?php foreach($validStatuses as $st): ?><option value="<?= h($st) ?>" <?= $filterStatus===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-ghost" href="schedules.php">Clear</a></form><div class="h-12"></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Date/Time</th><th>Route</th><th>Bus</th><th>Driver</th><th>Seats</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($schedules as $s): ?><tr><td><?= (int)$s['Schedule_ID'] ?></td><td><?= h($s['Date']) ?><br><span class="tiny muted"><?= h(substr($s['Departure_Time'],0,5)) ?> → <?= h(substr($s['Arrival_Time'],0,5)) ?></span></td><td><?= h($s['Source'].' → '.$s['Destination']) ?></td><td><?= h($s['Bus_Number']) ?></td><td><?= h($s['Driver_Name'] ?? 'No driver') ?></td><td><?= (int)$s['Available_Seats'] ?>/<?= (int)$s['Capacity'] ?></td><td><span class="status-pill status-<?= strtolower($s['Trip_Status']) ?>"><?= h($s['Trip_Status']) ?></span></td><td class="flex gap-8 flex-wrap"><a class="btn btn-ghost" href="schedules.php?edit=<?= (int)$s['Schedule_ID'] ?>">Edit</a><?php if($s['Schedule_Status']==='Scheduled'): ?><a class="btn btn-danger" href="schedules.php?cancel=<?= (int)$s['Schedule_ID'] ?>" onclick="return confirm('Cancel this trip? Passengers will no longer be able to reserve it.');">Cancel</a><?php endif; ?><a class="btn btn-danger" href="schedules.php?delete=<?= (int)$s['Schedule_ID'] ?>" onclick="return confirm('Delete this schedule? If it has reservations, it may fail.');">Delete</a></td></tr><?php endforeach; ?><?php if(empty($schedules)): ?><tr><td colspan="8" class="muted">No schedules found.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div></main></body></html>

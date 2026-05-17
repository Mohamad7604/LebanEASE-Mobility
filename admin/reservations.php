<?php
require "auth.php";
require_admin();
require "../db/config.php";
date_default_timezone_set('Asia/Beirut');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$errors=[]; $success="";
function trip_completed(array $r): bool {
  try { $dep=new DateTime($r['Date'].' '.$r['Departure_Time']); $arr=new DateTime($r['Date'].' '.$r['Arrival_Time']); if($arr <= $dep) $arr->modify('+1 day'); return (new DateTime()) >= $arr; }
  catch(Exception $e){ return false; }
}

if(isset($_GET['cancel'])){
  $id=(int)$_GET['cancel'];
  if($id>0){
    try{
      $stmt=$pdo->prepare("SELECT res.*, v.Date, v.Departure_Time, v.Arrival_Time FROM Reservation res JOIN v_schedule_overview v ON res.Schedule_ID=v.Schedule_ID WHERE res.Reservation_ID=? LIMIT 1");
      $stmt->execute([$id]); $row=$stmt->fetch();
      if(!$row) $errors[]="Reservation not found.";
      elseif($row['Status']!=='Active') $errors[]="Only active reservations can be cancelled.";
      else { $pdo->prepare("UPDATE Reservation SET Status='Cancelled' WHERE Reservation_ID=?")->execute([$id]); $success="Reservation cancelled successfully."; }
    }catch(Exception $e){$errors[]="Could not cancel reservation.";}
  }
}

$q=trim($_GET['q'] ?? '');
$status=trim($_GET['status'] ?? '');
$scheduleFilter=(int)($_GET['schedule_id'] ?? 0);
$schedules=$pdo->query("SELECT Schedule_ID, Date, Source, Destination, Departure_Time FROM v_schedule_overview ORDER BY Date DESC, Departure_Time ASC")->fetchAll();
$stmt=$pdo->prepare("SELECT res.Reservation_ID,res.Seat_Number,res.Booking_Date,res.Status AS Reservation_Status,p.Passenger_ID,p.First_Name,p.Last_Name,p.Email,p.Phone,v.* FROM Reservation res JOIN Passenger p ON res.Passenger_ID=p.Passenger_ID JOIN v_schedule_overview v ON res.Schedule_ID=v.Schedule_ID WHERE (?=0 OR res.Schedule_ID=?) AND (?='' OR p.First_Name LIKE CONCAT('%',?,'%') OR p.Last_Name LIKE CONCAT('%',?,'%') OR p.Email LIKE CONCAT('%',?,'%') OR v.Source LIKE CONCAT('%',?,'%') OR v.Destination LIKE CONCAT('%',?,'%')) ORDER BY res.Reservation_ID DESC");
$stmt->execute([$scheduleFilter,$scheduleFilter,$q,$q,$q,$q,$q,$q]);
$rows=$stmt->fetchAll();
if($status!==''){
  $rows=array_values(array_filter($rows,function($r) use($status){ $display=($r['Reservation_Status']==='Active' && trip_completed($r))?'Completed':$r['Reservation_Status']; return $display===$status; }));
}
$summary=['Active'=>0,'Cancelled'=>0,'Completed'=>0];
foreach($rows as $r){$display=($r['Reservation_Status']==='Active' && trip_completed($r))?'Completed':$r['Reservation_Status']; if(isset($summary[$display])) $summary[$display]++;}
$adminName=$_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/><title>Reservations | LebanEASE Admin</title><link rel="stylesheet" href="../css/style.css"/></head><body>
<?php require_once __DIR__ . "/nav.php"; render_admin_nav('reservations'); ?>
<main class="section"><div class="container"><h2 class="section-title">Reservation Management</h2><p class="muted mb-14">View, search, filter, and cancel reservations. Statuses are shown as Active, Cancelled, or Completed.</p>
<div class="admin-grid"><?php foreach($summary as $label=>$value): ?><div class="card glass admin-stat"><div class="admin-stat-num"><?= (int)$value ?></div><div class="admin-stat-label"><?= h($label) ?> Reservations</div></div><?php endforeach; ?></div><div class="h-12"></div>
<?php if($errors): ?><div class="card glass"><div class="alert"><strong>Fix this:</strong><ul style="margin-left:18px;margin-top:8px;"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div></div><div class="h-12"></div><?php endif; ?>
<?php if($success): ?><div class="card glass"><p style="font-weight:800;">✅ <?= h($success) ?></p></div><div class="h-12"></div><?php endif; ?>
<div class="card glass"><h3>All Reservations</h3><form method="GET" class="flex gap-10 flex-wrap mt-10"><input class="admin-search" type="text" name="q" placeholder="Search passenger, email, route..." value="<?= h($q) ?>"/><select class="admin-search" name="status"><option value="">All statuses</option><?php foreach(['Active','Cancelled','Completed'] as $st): ?><option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select><select class="admin-search" name="schedule_id"><option value="0">All trips</option><?php foreach($schedules as $s): ?><option value="<?= (int)$s['Schedule_ID'] ?>" <?= (int)$s['Schedule_ID']===$scheduleFilter?'selected':'' ?>>#<?= (int)$s['Schedule_ID'] ?> — <?= h($s['Source'].' → '.$s['Destination'].' '.$s['Date'].' '.substr($s['Departure_Time'],0,5)) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-ghost" href="reservations.php">Clear</a></form><div class="h-12"></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Passenger</th><th>Trip</th><th>Date/Time</th><th>Seat</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $r): ?><?php $display=($r['Reservation_Status']==='Active' && trip_completed($r))?'Completed':$r['Reservation_Status']; ?><tr><td><?= (int)$r['Reservation_ID'] ?></td><td><?= h($r['First_Name'].' '.$r['Last_Name']) ?><br><span class="tiny muted"><?= h($r['Email']) ?></span></td><td><?= h($r['Source'].' → '.$r['Destination']) ?><br><span class="tiny muted"><?= h($r['Bus_Number'].' / '.$r['Driver_Name']) ?></span></td><td><?= h($r['Date']) ?><br><span class="tiny muted"><?= h(substr($r['Departure_Time'],0,5)) ?> → <?= h(substr($r['Arrival_Time'],0,5)) ?></span></td><td><?= (int)$r['Seat_Number'] ?></td><td><span class="status-pill status-<?= strtolower($display) ?>"><?= h($display) ?></span></td><td class="flex gap-8 flex-wrap"><?php if($display==='Active'): ?><a class="btn btn-danger" href="reservations.php?cancel=<?= (int)$r['Reservation_ID'] ?>" onclick="return confirm('Cancel this reservation? The seat will become available again.');">Cancel</a><?php else: ?><span class="tiny muted">No action</span><?php endif; ?></td></tr><?php endforeach; ?><?php if(empty($rows)): ?><tr><td colspan="7" class="muted">No reservations found.</td></tr><?php endif; ?></tbody></table></div></div>
</div></main></body></html>

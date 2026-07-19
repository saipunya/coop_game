<?php
require_once __DIR__ . '/auth.php';
require_user_permission('placement');
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/navbar.php';

ensure_system_schema();
$user = current_user();
$error = '';
$flash = isset($_GET['saved']) ? 'อัปเดตน้ำหนักประมาณการเรียบร้อยแล้ว' : '';
$latestDate = db()->query('SELECT MAX(wang_date) FROM tbl_wangyang')->fetchColumn();
$selectedDate = trim($_GET['date'] ?? ($_POST['date'] ?? ($latestDate ?: date('Y-m-d', strtotime('+2 days')))));
$dateObject = DateTime::createFromFormat('Y-m-d', $selectedDate);
if (!$dateObject || $dateObject->format('Y-m-d') !== $selectedDate) $selectedDate = date('Y-m-d');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (($user['user_level'] ?? '') !== 'admin') throw new RuntimeException('เฉพาะผู้ดูแลระบบเท่านั้นที่กำหนดน้ำหนักประมาณการได้');
    if (!verify_csrf($_POST['csrf_token'] ?? '')) throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่');
    $weightPerBag = filter_var($_POST['weight_per_bag'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($weightPerBag === false || $weightPerBag < 0 || $weightPerBag > 500) throw new RuntimeException('น้ำหนักต่อกระสอบต้องอยู่ระหว่าง 0–500 กิโลกรัม');

    db()->beginTransaction();
    $stmt = db()->prepare('SELECT COALESCE(SUM(wang_sack), 0) FROM tbl_wangyang WHERE wang_date = :date');
    $stmt->execute(['date' => $selectedDate]);
    $totalBags = (float) $stmt->fetchColumn();
    $estimated = $totalBags * $weightPerBag;
    $stmt = db()->prepare('INSERT INTO tbl_wangyang_daily_summary(ws_date, ws_weight_per_bag, ws_estimated_weight, ws_saveby, ws_savedate)
      VALUES(:date, :rate, :estimated, :saveby, NOW())
      ON DUPLICATE KEY UPDATE ws_weight_per_bag = VALUES(ws_weight_per_bag), ws_estimated_weight = VALUES(ws_estimated_weight), ws_saveby = VALUES(ws_saveby), ws_savedate = NOW()');
    $stmt->execute(['date' => $selectedDate, 'rate' => $weightPerBag, 'estimated' => $estimated, 'saveby' => $user['user_fullname']]);
    $stmt = db()->prepare('UPDATE tbl_wangyang SET wang_weight = wang_sack * :rate WHERE wang_date = :date');
    $stmt->execute(['rate' => $weightPerBag, 'date' => $selectedDate]);
    audit_log('update', 'placement_summary', $selectedDate, 'คำนวณน้ำหนักประมาณการรอบ ' . $selectedDate . ' ที่ ' . number_format($weightPerBag, 2) . ' kg/กระสอบ', [
      'round_date' => $selectedDate, 'weight_per_bag' => (float) $weightPerBag,
      'total_bags' => $totalBags, 'estimated_weight' => $estimated,
    ]);
    db()->commit();
    header('Location: ' . url_for('bag-report.php?date=' . urlencode($selectedDate) . '&saved=1'));
    exit;
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
  }
}

$summaryStmt = db()->prepare('SELECT * FROM tbl_wangyang_daily_summary WHERE ws_date = :date');
$summaryStmt->execute(['date' => $selectedDate]);
$summary = $summaryStmt->fetch() ?: ['ws_weight_per_bag' => 0, 'ws_estimated_weight' => 0, 'ws_saveby' => '', 'ws_savedate' => ''];

$detailStmt = db()->prepare('SELECT w.*, COALESCE(y.yard_name, CONCAT("ลาน ", w.wang_lan)) AS yard_name
  FROM tbl_wangyang w LEFT JOIN tbl_yard y ON y.yard_code = w.wang_lan
  WHERE w.wang_date = :date ORDER BY CAST(w.wang_lan AS UNSIGNED), w.wang_lan, CAST(w.wang_number AS UNSIGNED), w.wang_number');
$detailStmt->execute(['date' => $selectedDate]);
$rows = $detailStmt->fetchAll();

$yardStmt = db()->prepare('SELECT w.wang_lan, COALESCE(y.yard_name, CONCAT("ลาน ", w.wang_lan)) AS yard_name,
  COUNT(*) AS record_count, COUNT(DISTINCT w.wang_mid) AS member_count, SUM(w.wang_sack) AS total_bags, SUM(w.wang_weight) AS total_weight
  FROM tbl_wangyang w LEFT JOIN tbl_yard y ON y.yard_code = w.wang_lan
  WHERE w.wang_date = :date GROUP BY w.wang_lan, y.yard_name ORDER BY CAST(w.wang_lan AS UNSIGNED), w.wang_lan');
$yardStmt->execute(['date' => $selectedDate]);
$yardRows = $yardStmt->fetchAll();

$totalBags = 0; $totalWeight = 0; $memberIds = [];
foreach ($rows as $row) { $totalBags += (float) $row['wang_sack']; $totalWeight += (float) $row['wang_weight']; $memberIds[$row['wang_mid']] = true; }

if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="bag-report-' . $selectedDate . '.csv"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['วันที่ช่องยาง', 'ลาน', 'เลขสมาชิก', 'ชื่อสมาชิก', 'กลุ่ม', 'จำนวนกระสอบ', 'น้ำหนักประมาณการ (kg)', 'หมายเหตุ', 'ผู้บันทึก']);
  foreach ($rows as $row) fputcsv($out, [$row['wang_date'], $row['yard_name'], $row['wang_number'], $row['wang_name'], $row['wang_group'], $row['wang_sack'], $row['wang_weight'], $row['wang_note'], $row['wang_saveby']]);
  fclose($out); exit;
}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>รายงานวางยาง</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet"><link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet"></head>
<body><?php render_topbar(); ?><main class="ops-shell">
<section class="ops-hero"><div><h1><i class="bi bi-clipboard-data me-2"></i>สรุปการวางยาง</h1><p><?php echo h(cooperative_name()); ?> · สรุปกระสอบและน้ำหนักประมาณการรายวัน/รายลาน</p></div><span class="pill">วันช่องยาง <?php echo h($selectedDate); ?></span></section>
<?php if ($flash): ?><div class="alert alert-success mt-3"><?php echo h($flash); ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo h($error); ?></div><?php endif; ?>
<section class="ops-card mt-3 no-print"><div class="ops-card-body"><div class="filter-row"><form class="filter-row" method="get"><div><label class="form-label">เลือกวันช่องยาง</label><input class="form-control" type="date" name="date" value="<?php echo h($selectedDate); ?>"></div><button class="btn btn-outline-success">แสดงรายงาน</button></form><div class="ms-auto d-flex gap-2"><a class="btn btn-outline-secondary" href="?date=<?php echo urlencode($selectedDate); ?>&export=csv"><i class="bi bi-filetype-csv me-1"></i>CSV</a><button class="btn btn-outline-dark" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์</button></div></div></div></section>
<section class="stat-grid"><article class="stat-card"><span>สมาชิก</span><strong><?php echo number_format(count($memberIds)); ?></strong><small>ราย</small></article><article class="stat-card"><span>จำนวนกระสอบ</span><strong><?php echo number_format($totalBags, 0); ?></strong><small>กระสอบ</small></article><article class="stat-card"><span>น้ำหนักต่อกระสอบ</span><strong><?php echo number_format((float) $summary['ws_weight_per_bag'], 2); ?></strong><small>kg/กระสอบ</small></article><article class="stat-card"><span>น้ำหนักประมาณการรวม</span><strong><?php echo number_format($totalWeight, 2); ?></strong><small>kg</small></article></section>
<div class="ops-grid wide-left">
<section class="ops-card"><div class="ops-card-head"><h2>สรุปตามลานยาง</h2></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>ลาน</th><th class="num">สมาชิก</th><th class="num">รายการ</th><th class="num">กระสอบ</th><th class="num">ประมาณ kg</th></tr></thead><tbody><?php foreach ($yardRows as $row): ?><tr><td><span class="badge-soft"><?php echo h($row['yard_name']); ?></span></td><td class="num"><?php echo number_format($row['member_count']); ?></td><td class="num"><?php echo number_format($row['record_count']); ?></td><td class="num fw-bold"><?php echo number_format($row['total_bags'], 0); ?></td><td class="num"><?php echo number_format($row['total_weight'], 2); ?></td></tr><?php endforeach; ?><?php if (!$yardRows): ?><tr><td colspan="5" class="empty">ไม่พบข้อมูลในวันที่เลือก</td></tr><?php endif; ?></tbody></table></div></section>
<section class="ops-card no-print"><div class="ops-card-head"><h2>น้ำหนักประมาณการ</h2></div><div class="ops-card-body"><?php if (($user['user_level'] ?? '') === 'admin'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="date" value="<?php echo h($selectedDate); ?>"><label class="form-label">กิโลกรัมต่อกระสอบ</label><div class="input-group"><input class="form-control" type="number" step="0.01" min="0" max="500" name="weight_per_bag" value="<?php echo h($summary['ws_weight_per_bag']); ?>" required><span class="input-group-text">kg</span></div><p class="form-hint mt-2">เมื่อบันทึก ระบบจะคำนวณน้ำหนักใหม่ทุกรายการของวันที่เลือก</p><button class="btn btn-green w-100 mt-2">คำนวณน้ำหนักประมาณการ</button></form><?php else: ?><p class="mb-0 text-secondary">เฉพาะ Admin เท่านั้นที่กำหนดน้ำหนักต่อกระสอบได้</p><?php endif; ?><?php if ($summary['ws_saveby']): ?><hr><small class="text-secondary">แก้ไขล่าสุดโดย <?php echo h($summary['ws_saveby']); ?><br><?php echo h($summary['ws_savedate']); ?></small><?php endif; ?></div></section></div>
<section class="ops-card mt-3"><div class="ops-card-head"><h2>รายละเอียดสมาชิก</h2></div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>ลาน</th><th>เลขสมาชิก</th><th>ชื่อสมาชิก</th><th>กลุ่ม</th><th class="num">กระสอบ</th><th class="num">ประมาณ kg</th><th>หมายเหตุ</th><th>ผู้บันทึก</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?php echo h($row['yard_name']); ?></td><td class="fw-bold"><?php echo h($row['wang_number']); ?></td><td><?php echo h($row['wang_name']); ?></td><td><?php echo h($row['wang_group']); ?></td><td class="num fw-bold"><?php echo number_format($row['wang_sack'], 0); ?></td><td class="num"><?php echo number_format($row['wang_weight'], 2); ?></td><td><?php echo h($row['wang_note']); ?></td><td><small><?php echo h($row['wang_saveby']); ?></small></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="8" class="empty">ไม่พบข้อมูลในวันที่เลือก</td></tr><?php endif; ?></tbody></table></div></section>
</main></body></html>

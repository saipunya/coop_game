<?php
require_once __DIR__ . '/auth.php';
require_user();
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/navbar.php';

ensure_system_schema();
$user = current_user();
if (($user['user_level'] ?? '') !== 'admin') {
  http_response_code(403);
  exit('เฉพาะผู้ดูแลระบบเท่านั้น');
}

function audit_valid_date($value)
{
  $date = DateTime::createFromFormat('Y-m-d', $value);
  return $date && $date->format('Y-m-d') === $value;
}

$actionDefinitions = audit_action_definitions();
$entityDefinitions = [
  'placement' => 'วางยาง', 'placement_summary' => 'สรุปวางยาง', 'workflow' => 'Workflow ยาง',
  'rubber_record' => 'ข้อมูลรับยาง', 'price' => 'ราคายาง', 'member' => 'สมาชิก',
  'user' => 'ผู้ใช้งาน/สิทธิ์', 'system' => 'ตั้งค่าระบบ',
];
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-3 months')));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
if (!audit_valid_date($dateFrom)) $dateFrom = date('Y-m-d', strtotime('-3 months'));
if (!audit_valid_date($dateTo)) $dateTo = date('Y-m-d');
if ($dateFrom > $dateTo) { $swap = $dateFrom; $dateFrom = $dateTo; $dateTo = $swap; }
$actorId = filter_var($_GET['actor_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$action = trim($_GET['action'] ?? '');
if ($action !== '' && !isset($actionDefinitions[$action])) $action = '';
$entity = trim($_GET['entity'] ?? '');
if ($entity !== '' && !isset($entityDefinitions[$entity])) $entity = '';
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 100;

$where = ['created_at >= :date_from', 'created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)'];
$params = ['date_from' => $dateFrom, 'date_to' => $dateTo];
if ($actorId) { $where[] = 'actor_user_id = :actor_id'; $params['actor_id'] = $actorId; }
if ($action !== '') { $where[] = 'action_key = :action'; $params['action'] = $action; }
if ($entity !== '') { $where[] = 'entity_type = :entity'; $params['entity'] = $entity; }
if ($search !== '') {
  $where[] = '(summary LIKE :search OR entity_id LIKE :search OR actor_username LIKE :search OR actor_fullname LIKE :search)';
  $params['search'] = '%' . $search . '%';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$countStmt = db()->prepare('SELECT COUNT(*) FROM tbl_audit_log' . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$logStmt = db()->prepare('SELECT * FROM tbl_audit_log' . $whereSql . ' ORDER BY audit_id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$logStmt->execute($params);
$logs = $logStmt->fetchAll();

$summaryStmt = db()->prepare('SELECT COUNT(*) AS total_logs,
    COUNT(DISTINCT actor_user_id) AS actor_count,
    SUM(action_key = "approve_payment") AS payment_approvals,
    SUM(DATE(created_at) = CURDATE()) AS today_logs
  FROM tbl_audit_log' . $whereSql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: ['total_logs' => 0, 'actor_count' => 0, 'payment_approvals' => 0, 'today_logs' => 0];

$actors = db()->query('SELECT actor_user_id, MAX(actor_username) AS actor_username, MAX(actor_fullname) AS actor_fullname
  FROM tbl_audit_log WHERE actor_user_id IS NOT NULL GROUP BY actor_user_id ORDER BY actor_fullname, actor_username')->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
  $exportStmt = db()->prepare('SELECT * FROM tbl_audit_log' . $whereSql . ' ORDER BY audit_id DESC LIMIT 10000');
  $exportStmt->execute($params);
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="audit-log-' . $dateFrom . '-to-' . $dateTo . '.csv"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['วันเวลา', 'ผู้ใช้งาน', 'ชื่อเจ้าหน้าที่', 'ประเภทบัญชี', 'การกระทำ', 'ประเภทข้อมูล', 'รายการอ้างอิง', 'สรุป', 'รายละเอียด JSON', 'IP']);
  while ($row = $exportStmt->fetch()) {
    fputcsv($out, [$row['created_at'], $row['actor_username'], $row['actor_fullname'], $row['actor_level'], $actionDefinitions[$row['action_key']] ?? $row['action_key'], $entityDefinitions[$row['entity_type']] ?? $row['entity_type'], $row['entity_id'], $row['summary'], $row['details_json'], $row['ip_address']]);
  }
  fclose($out);
  exit;
}

$baseQuery = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'actor_id' => $actorId, 'action' => $action, 'entity' => $entity, 'q' => $search];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>ประวัติการใช้งานระบบ</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
  <link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet">
</head>
<body>
<?php render_topbar(); ?>
<main class="ops-shell">
  <section class="ops-hero"><div><h1><i class="bi bi-clock-history me-2"></i>ประวัติการใช้งานระบบ</h1><p>ตรวจสอบว่าใครบันทึก แก้ไข ลบ หรืออนุมัติรายการใด เมื่อไร</p></div><span class="pill">เก็บข้อมูลสูงสุด 3 เดือน</span></section>

  <section class="ops-card mt-3 no-print"><div class="ops-card-body"><form class="audit-filter" method="get">
    <div><label class="form-label">ตั้งแต่วันที่</label><input class="form-control" type="date" name="date_from" value="<?php echo h($dateFrom); ?>"></div>
    <div><label class="form-label">ถึงวันที่</label><input class="form-control" type="date" name="date_to" value="<?php echo h($dateTo); ?>"></div>
    <div><label class="form-label">ผู้ใช้งาน</label><select class="form-select" name="actor_id"><option value="">ทุกคน</option><?php foreach ($actors as $actor): ?><option value="<?php echo (int) $actor['actor_user_id']; ?>" <?php echo $actorId === (int) $actor['actor_user_id'] ? 'selected' : ''; ?>><?php echo h(($actor['actor_fullname'] ?: $actor['actor_username']) . ' · ' . $actor['actor_username']); ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label">การกระทำ</label><select class="form-select" name="action"><option value="">ทุกการกระทำ</option><?php foreach ($actionDefinitions as $key => $label): ?><option value="<?php echo h($key); ?>" <?php echo $action === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label">ประเภทข้อมูล</label><select class="form-select" name="entity"><option value="">ทุกประเภท</option><?php foreach ($entityDefinitions as $key => $label): ?><option value="<?php echo h($key); ?>" <?php echo $entity === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option><?php endforeach; ?></select></div>
    <div class="audit-search"><label class="form-label">ค้นหา</label><input class="form-control" name="q" value="<?php echo h($search); ?>" placeholder="รายละเอียด / เลขอ้างอิง / ผู้ใช้"></div>
    <button class="btn btn-dark"><i class="bi bi-search me-1"></i>ค้นหา</button>
    <a class="btn btn-outline-secondary" href="<?php echo h(url_for('audit-log.php')); ?>">ล้างตัวกรอง</a>
  </form></div></section>

  <section class="stat-grid">
    <article class="stat-card"><span>Log ตามตัวกรอง</span><strong><?php echo number_format($summary['total_logs']); ?></strong><small>รายการ</small></article>
    <article class="stat-card"><span>ผู้ใช้งานที่เกี่ยวข้อง</span><strong><?php echo number_format($summary['actor_count']); ?></strong><small>บัญชี</small></article>
    <article class="stat-card"><span>อนุมัติจ่ายเงิน</span><strong><?php echo number_format($summary['payment_approvals']); ?></strong><small>ครั้ง</small></article>
    <article class="stat-card"><span>กิจกรรมวันนี้</span><strong><?php echo number_format($summary['today_logs']); ?></strong><small>รายการ</small></article>
  </section>

  <section class="ops-card mt-3">
    <div class="ops-card-head"><h2>รายการประวัติ</h2><div class="d-flex align-items-center gap-2"><span class="badge-soft"><?php echo number_format($total); ?> รายการ</span><a class="btn btn-sm btn-outline-success no-print" href="?<?php echo h(http_build_query(array_merge($baseQuery, ['export' => 'csv']))); ?>"><i class="bi bi-filetype-csv me-1"></i>CSV</a></div></div>
    <div class="table-responsive"><table class="table table-hover mb-0 audit-table"><thead><tr><th>วันเวลา</th><th>ผู้ใช้งาน</th><th>การกระทำ</th><th>ประเภท / อ้างอิง</th><th>รายละเอียด</th><th>เครือข่าย</th></tr></thead><tbody>
    <?php foreach ($logs as $row): ?>
      <?php $details = $row['details_json'] ? json_decode($row['details_json'], true) : null; ?>
      <tr><td><strong><?php echo h($row['created_at']); ?></strong></td><td><strong><?php echo h($row['actor_fullname'] ?: $row['actor_username']); ?></strong><br><small><?php echo h($row['actor_username']); ?> · <?php echo h($row['actor_level']); ?></small></td><td><span class="audit-action action-<?php echo h($row['action_key']); ?>"><?php echo h($actionDefinitions[$row['action_key']] ?? $row['action_key']); ?></span></td><td><strong><?php echo h($entityDefinitions[$row['entity_type']] ?? $row['entity_type']); ?></strong><br><small>#<?php echo h($row['entity_id'] ?: '—'); ?></small></td><td><div class="audit-summary"><?php echo h($row['summary']); ?></div><?php if (is_array($details) && $details): ?><details class="audit-details"><summary>ดูรายละเอียด</summary><pre><?php echo h(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre></details><?php endif; ?></td><td><small><?php echo h($row['ip_address'] ?: '—'); ?></small></td></tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="6" class="empty">ไม่พบประวัติตามตัวกรอง</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($pages > 1): ?><nav class="audit-pagination no-print"><ul class="pagination mb-0"><?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="?<?php echo h(http_build_query(array_merge($baseQuery, ['page' => $page - 1]))); ?>">ก่อนหน้า</a></li><?php endif; ?><li class="page-item disabled"><span class="page-link">หน้า <?php echo number_format($page); ?> / <?php echo number_format($pages); ?></span></li><?php if ($page < $pages): ?><li class="page-item"><a class="page-link" href="?<?php echo h(http_build_query(array_merge($baseQuery, ['page' => $page + 1]))); ?>">ถัดไป</a></li><?php endif; ?></ul></nav><?php endif; ?>
  </section>
</main>
</body>
</html>

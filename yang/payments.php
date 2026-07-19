<?php
require_once __DIR__ . '/auth.php';
require_user_permission('payments');
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/navbar.php';

sync_workflow_records();
$user = current_user();
$flash = $_SESSION['workflow_flash'] ?? null;
unset($_SESSION['workflow_flash']);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่');
    $id = filter_var($_POST['workflow_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$id) throw new RuntimeException('ไม่พบรายการที่ต้องการจ่ายเงิน');
    db()->beginTransaction();
    $stmt = db()->prepare('SELECT workflow_status, weigh_date, yard_code, member_number, receipt_no, net_amount FROM tbl_rubber_workflow WHERE workflow_id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $workflow = $stmt->fetch();
    if (!$workflow || $workflow['workflow_status'] !== 'deducted') throw new RuntimeException('รายการนี้ยังไม่บันทึกยอดหัก หรือจ่ายเงินไปแล้ว');
    $stmt = db()->prepare('UPDATE tbl_rubber_workflow SET workflow_status = "paid", paid_by = :staff, paid_at = NOW() WHERE workflow_id = :id');
    $stmt->execute(['staff' => $user['user_fullname'], 'id' => $id]);
    update_placement_status($id, 'paid');
    audit_log('approve_payment', 'workflow', $id, 'อนุมัติจ่ายเงินสุทธิ ' . number_format((float) $workflow['net_amount'], 2) . ' บาท สำหรับสมาชิก ' . $workflow['member_number'], [
      'round_date' => $workflow['weigh_date'], 'yard_code' => $workflow['yard_code'],
      'member_number' => $workflow['member_number'], 'receipt_no' => $workflow['receipt_no'], 'net_amount' => (float) $workflow['net_amount'],
      'paid_by' => $user['user_fullname'],
    ]);
    db()->commit();
    $_SESSION['workflow_flash'] = ['type' => 'success', 'message' => 'ยืนยันการจ่ายเงินเรียบร้อย · บันทึกผู้จ่าย: ' . $user['user_fullname']];
    workflow_redirect('payments.php');
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
  }
}

$statusFilter = trim($_GET['status'] ?? 'deducted');
$date = trim($_GET['date'] ?? '');
$where = ['workflow.workflow_status IN ("deducted", "paid")']; $params = [];
if (in_array($statusFilter, ['deducted', 'paid'], true)) { $where[] = 'workflow.workflow_status = :status'; $params['status'] = $statusFilter; }
if ($date !== '') { $where[] = 'workflow.weigh_date = :date'; $params['date'] = $date; }
$stmt = db()->prepare('SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name
  FROM tbl_rubber_workflow workflow LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code
  WHERE ' . implode(' AND ', $where) . ' ORDER BY workflow.weigh_date DESC, workflow.workflow_id DESC LIMIT 500');
$stmt->execute($params); $rows = $stmt->fetchAll();
$summary = ['count' => 0, 'net' => 0]; foreach ($rows as $row) { $summary['count']++; $summary['net'] += (float) $row['net_amount']; }
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>จ่ายเงินและพิมพ์ใบเสร็จ</title><link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet"><link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet"></head>
<body><?php render_topbar(); ?><main class="ops-shell"><section class="ops-hero"><div><h1><i class="bi bi-cash-coin me-2"></i>จ่ายเงินและใบเสร็จ</h1><p>พิมพ์ใบเสร็จ ตรวจสอบยอดสุทธิ และยืนยันสถานะเมื่อสมาชิกได้รับเงินแล้ว</p></div><span class="pill">ขั้นตอน 4 · จ่ายเงิน</span></section><?php if ($flash): ?><div class="alert alert-<?php echo h($flash['type']); ?> mt-3"><?php echo h($flash['message']); ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo h($error); ?></div><?php endif; ?>
<div class="stat-grid"><div class="stat-card"><span>จำนวนรายการตามตัวกรอง</span><strong><?php echo number_format($summary['count']); ?></strong><small>รายการ</small></div><div class="stat-card"><span>ยอดสุทธิรวม</span><strong><?php echo number_format($summary['net'], 2); ?></strong><small>บาท</small></div></div>
<section class="ops-card mt-3"><div class="ops-card-body"><form class="filter-row" method="get"><div><label class="form-label">วันชั่ง</label><input class="form-control" type="date" name="date" value="<?php echo h($date); ?>"></div><div><label class="form-label">สถานะ</label><select class="form-select" name="status"><option value="deducted" <?php echo $statusFilter === 'deducted' ? 'selected' : ''; ?>>รอจ่ายเงิน</option><option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>จ่ายเงินแล้ว</option><option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>ทั้งหมด</option></select></div><button class="btn btn-dark"><i class="bi bi-search me-1"></i>ค้นหา</button></form></div></section>
<section class="ops-card mt-3"><div class="ops-card-head"><h2>รายการชำระเงิน</h2><span class="badge-soft"><?php echo number_format(count($rows)); ?> รายการ</span></div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>เลขที่ใบเสร็จ</th><th>วันชั่ง / ลาน</th><th>สมาชิก</th><th class="num">น้ำหนัก</th><th class="num">ยอดค่ายาง</th><th class="num">ยอดหัก</th><th class="num">ยอดสุทธิ</th><th>สถานะ / จัดการ</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><strong><?php echo h($row['receipt_no']); ?></strong></td><td><?php echo h($row['weigh_date']); ?><br><small><?php echo h($row['yard_name']); ?></small></td><td><strong><?php echo h($row['member_number']); ?></strong><br><small><?php echo h($row['member_name']); ?></small></td><td class="num"><?php echo number_format((float) $row['actual_weight'], 2); ?> kg</td><td class="num"><?php echo number_format((float) $row['gross_amount'], 2); ?></td><td class="num text-danger"><?php echo number_format((float) $row['total_deduction'], 2); ?></td><td class="num fw-bold text-success"><?php echo number_format((float) $row['net_amount'], 2); ?></td><td><div class="payment-actions"><span class="workflow-status <?php echo h(workflow_status_class($row['workflow_status'])); ?>"><?php echo h(workflow_status_label($row['workflow_status'])); ?></span><a class="btn btn-sm btn-outline-dark" target="_blank" href="<?php echo h(url_for('receipt.php?id=' . (int) $row['workflow_id'])); ?>"><i class="bi bi-printer me-1"></i>ใบเสร็จ</a><?php if ($row['workflow_status'] === 'deducted'): ?><form method="post" onsubmit="return confirm('ยืนยันว่าสมาชิกได้รับเงินแล้ว? เมื่อยืนยันจะไม่สามารถแก้ไขน้ำหนักหรือยอดหักได้')"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="workflow_id" value="<?php echo (int) $row['workflow_id']; ?>"><button class="btn btn-sm btn-green"><i class="bi bi-check2-circle me-1"></i>จ่ายเงินแล้ว</button></form><?php else: ?><small><?php echo h($row['paid_at']); ?><br><?php echo h($row['paid_by']); ?></small><?php endif; ?></div></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td class="empty" colspan="8">ไม่พบรายการตามตัวกรอง</td></tr><?php endif; ?></tbody></table></div></section></main></body></html>

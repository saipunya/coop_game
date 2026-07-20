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
    $action = $_POST['action'] ?? 'pay';
    if (!$id) throw new RuntimeException('ไม่พบรายการที่ต้องการจ่ายเงิน');

    db()->beginTransaction();
    $stmt = db()->prepare('SELECT workflow_status, weigh_date, yard_code, member_number, receipt_no,
        net_amount, paid_by, paid_at
      FROM tbl_rubber_workflow WHERE workflow_id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $workflow = $stmt->fetch();
    if (in_array($action, ['edit', 'delete'], true)) {
      $requiredPermission = $action === 'edit' ? 'payments_edit' : 'payments_delete';
      if (!user_can($requiredPermission, $user)) throw new RuntimeException('บัญชีนี้ไม่มีสิทธิ์แก้ไขหรือยกเลิกการจ่ายเงิน');
      if (!$workflow || $workflow['workflow_status'] !== 'paid') throw new RuntimeException('รายการนี้ยังไม่ได้บันทึกการจ่ายเงิน');
      $stmt = db()->prepare('UPDATE tbl_rubber_workflow SET workflow_status = "deducted",
        paid_by = "", paid_at = NULL, admin_edited_by = "", admin_edited_at = NULL,
        admin_edit_type = "" WHERE workflow_id = :id');
      $stmt->execute(['id' => $id]);
      update_placement_status($id, 'deducted');
      audit_log($action === 'edit' ? 'update' : 'delete', 'workflow', $id,
        ($action === 'edit' ? 'เปิดรายการจ่ายเงินกลับมาแก้ไข' : 'ยกเลิกการจ่ายเงิน') . ' ของสมาชิก ' . $workflow['member_number'], [
          'round_date' => $workflow['weigh_date'], 'yard_code' => $workflow['yard_code'],
          'receipt_no' => $workflow['receipt_no'], 'net_amount' => (float) $workflow['net_amount'],
          'previous_paid_by' => $workflow['paid_by'], 'previous_paid_at' => $workflow['paid_at'],
        ]);
      db()->commit();
      $_SESSION['workflow_flash'] = ['type' => 'success', 'message' => $action === 'edit'
        ? 'เปิดรายการกลับมาแก้ไขแล้ว สถานะถูกย้อนเป็นรอจ่ายเงิน'
        : 'ยกเลิกการจ่ายเงินแล้ว รายการถูกย้อนกลับไปรอจ่ายเงิน'];
      if ($action === 'edit' && user_can('deductions', $user) && user_can('deductions_edit', $user)) {
        workflow_redirect('deductions.php', ['id' => $id]);
      }
      workflow_redirect('payments.php');
    }
    if (!$workflow || $workflow['workflow_status'] !== 'deducted') {
      throw new RuntimeException('รายการนี้ยังไม่บันทึกยอดหัก หรือจ่ายเงินไปแล้ว');
    }

    $stmt = db()->prepare('UPDATE tbl_rubber_workflow
      SET workflow_status = "paid", paid_by = :staff, paid_at = NOW() WHERE workflow_id = :id');
    $stmt->execute(['staff' => $user['user_fullname'], 'id' => $id]);
    update_placement_status($id, 'paid');
    audit_log('approve_payment', 'workflow', $id, 'อนุมัติจ่ายเงินสุทธิ ' . number_format((float) $workflow['net_amount'], 2) . ' บาท สำหรับสมาชิก ' . $workflow['member_number'], [
      'round_date' => $workflow['weigh_date'],
      'yard_code' => $workflow['yard_code'],
      'member_number' => $workflow['member_number'],
      'receipt_no' => $workflow['receipt_no'],
      'net_amount' => (float) $workflow['net_amount'],
      'paid_by' => $user['user_fullname'],
    ]);
    db()->commit();

    $_SESSION['workflow_flash'] = ['type' => 'success', 'message' => 'ยืนยันการจ่ายเงินเรียบร้อย · บันทึกผู้จ่าย: ' . $user['user_fullname']];
    $returnDate = trim((string) ($_POST['return_date'] ?? ''));
    workflow_redirect('payments.php', preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate) ? ['date' => $returnDate] : []);
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
  }
}

function payment_section_summary($rows)
{
  $summary = ['count' => 0, 'gross' => 0.0, 'deduction' => 0.0, 'net' => 0.0];
  foreach ($rows as $row) {
    $summary['count']++;
    $summary['gross'] += (float) $row['gross_amount'];
    $summary['deduction'] += (float) $row['total_deduction'];
    $summary['net'] += (float) $row['net_amount'];
  }
  return $summary;
}

$date = trim((string) ($_GET['date'] ?? ''));
$where = ['workflow.workflow_status IN ("deducted", "paid")'];
$params = [];
if ($date !== '') {
  $where[] = 'workflow.weigh_date = :date';
  $params['date'] = $date;
}
$stmt = db()->prepare('SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name
  FROM tbl_rubber_workflow workflow
  LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code
  WHERE ' . implode(' AND ', $where) . '
  ORDER BY workflow.weigh_date DESC, workflow.workflow_id DESC LIMIT 500');
$stmt->execute($params);
$rows = $stmt->fetchAll();
$pendingRows = array_values(array_filter($rows, function ($row) {
  return $row['workflow_status'] === 'deducted';
}));
$paidRows = array_values(array_filter($rows, function ($row) {
  return $row['workflow_status'] === 'paid';
}));
$pendingSummary = payment_section_summary($pendingRows);
$paidSummary = payment_section_summary($paidRows);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>จ่ายเงินและพิมพ์ใบเสร็จ</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
  <link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet">
</head>
<body>
<?php render_topbar(); ?>
<main class="ops-shell">
  <section class="ops-hero">
    <div><h1><i class="bi bi-cash-coin me-2"></i>จ่ายเงินและใบเสร็จ</h1><p>แยกรายการรอจ่ายและจ่ายแล้ว พร้อมสรุปยอดรับ ยอดหัก และยอดคงเหลือ</p></div>
    <span class="pill">ขั้นตอน 4 · จ่ายเงิน</span>
  </section>

  <?php if ($flash): ?><div class="alert alert-<?php echo h($flash['type']); ?> mt-3"><?php echo h($flash['message']); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo h($error); ?></div><?php endif; ?>

  <section class="ops-card mt-3">
    <div class="ops-card-body">
      <form class="filter-row" method="get">
        <div><label class="form-label">รอบตามวันชั่ง</label><input class="form-control" type="date" name="date" value="<?php echo h($date); ?>"></div>
        <button class="btn btn-dark"><i class="bi bi-search me-1"></i>แสดงรายงาน</button>
        <?php if ($date !== ''): ?><a class="btn btn-outline-secondary" href="<?php echo h(url_for('payments.php')); ?>">ล้างวันที่</a><?php endif; ?>
      </form>
    </div>
  </section>

  <section class="ops-card mt-3 payment-report-section pending-payment-section">
    <div class="ops-card-head">
      <div><h2><i class="bi bi-hourglass-split text-warning me-1"></i>รายการที่ยังไม่จ่ายเงิน</h2><small class="text-secondary">บันทึกยอดหักแล้วและรอยืนยันการจ่ายเงิน</small></div>
      <span class="badge-soft"><?php echo number_format($pendingSummary['count']); ?> รายการ</span>
    </div>
    <div class="stat-grid payment-section-summary">
      <div class="stat-card"><span>จำนวนรายการ</span><strong><?php echo number_format($pendingSummary['count']); ?></strong><small>รอจ่ายเงิน</small></div>
      <div class="stat-card"><span>ยอดรับก่อนหัก</span><strong><?php echo number_format($pendingSummary['gross'], 2); ?></strong><small>บาท</small></div>
      <div class="stat-card accent-amber"><span>ยอดหักรวม</span><strong><?php echo number_format($pendingSummary['deduction'], 2); ?></strong><small>บาท</small></div>
      <div class="stat-card accent-green"><span>ยอดคงเหลือที่ต้องจ่าย</span><strong><?php echo number_format($pendingSummary['net'], 2); ?></strong><small>บาท</small></div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>เลขที่ใบเสร็จ</th><th>วันชั่ง / ลาน</th><th>สมาชิก</th><th class="num">น้ำหนัก</th><th class="num">ยอดรับ</th><th class="num">ยอดหัก</th><th class="num">คงเหลือ</th><th>จัดการ</th></tr></thead>
        <tbody>
        <?php foreach ($pendingRows as $row): ?>
          <tr>
            <td><strong><?php echo h($row['receipt_no']); ?></strong></td>
            <td><?php echo h($row['weigh_date']); ?><br><small><?php echo h($row['yard_name']); ?></small></td>
            <td><strong><?php echo h($row['member_number']); ?></strong><br><small><?php echo h($row['member_name']); ?></small></td>
            <td class="num"><?php echo number_format((float) $row['actual_weight'], 2); ?> kg</td>
            <td class="num"><?php echo number_format((float) $row['gross_amount'], 2); ?></td>
            <td class="num text-danger"><?php echo number_format((float) $row['total_deduction'], 2); ?></td>
            <td class="num fw-bold text-success"><?php echo number_format((float) $row['net_amount'], 2); ?></td>
            <td><div class="payment-actions"><a class="btn btn-sm btn-outline-dark" target="_blank" href="<?php echo h(url_for('receipt.php?id=' . (int) $row['workflow_id'])); ?>"><i class="bi bi-printer me-1"></i>ใบเสร็จ</a><form method="post" onsubmit="return confirm('ยืนยันว่าสมาชิกได้รับเงินแล้ว? เมื่อยืนยันจะไม่สามารถแก้ไขน้ำหนักหรือยอดหักได้')"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="pay"><input type="hidden" name="workflow_id" value="<?php echo (int) $row['workflow_id']; ?>"><input type="hidden" name="return_date" value="<?php echo h($date); ?>"><button class="btn btn-sm btn-green"><i class="bi bi-check2-circle me-1"></i>จ่ายเงินแล้ว</button></form></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$pendingRows): ?><tr><td class="empty" colspan="8">ไม่มีรายการค้างจ่ายตามวันที่ที่เลือก</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="ops-card mt-3 payment-report-section paid-payment-section">
    <div class="ops-card-head">
      <div><h2><i class="bi bi-check2-circle text-success me-1"></i>รายการที่จ่ายเงินแล้ว</h2><small class="text-secondary">รายงานการจ่ายเงินพร้อมเจ้าหน้าที่และเวลาที่ยืนยัน</small></div>
      <span class="badge-soft"><?php echo number_format($paidSummary['count']); ?> รายการ</span>
    </div>
    <div class="stat-grid payment-section-summary">
      <div class="stat-card"><span>จำนวนรายการ</span><strong><?php echo number_format($paidSummary['count']); ?></strong><small>จ่ายเงินแล้ว</small></div>
      <div class="stat-card"><span>ยอดรับก่อนหัก</span><strong><?php echo number_format($paidSummary['gross'], 2); ?></strong><small>บาท</small></div>
      <div class="stat-card accent-amber"><span>ยอดหักรวม</span><strong><?php echo number_format($paidSummary['deduction'], 2); ?></strong><small>บาท</small></div>
      <div class="stat-card accent-green"><span>ยอดคงเหลือที่จ่ายแล้ว</span><strong><?php echo number_format($paidSummary['net'], 2); ?></strong><small>บาท</small></div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>เลขที่ใบเสร็จ</th><th>วันชั่ง / ลาน</th><th>สมาชิก</th><th class="num">น้ำหนัก</th><th class="num">ยอดรับ</th><th class="num">ยอดหัก</th><th class="num">คงเหลือ</th><th>ผู้จ่าย / ใบเสร็จ</th></tr></thead>
        <tbody>
        <?php foreach ($paidRows as $row): ?>
          <tr>
            <td><strong><?php echo h($row['receipt_no']); ?></strong></td>
            <td><?php echo h($row['weigh_date']); ?><br><small><?php echo h($row['yard_name']); ?></small></td>
            <td><strong><?php echo h($row['member_number']); ?></strong><br><small><?php echo h($row['member_name']); ?></small></td>
            <td class="num"><?php echo number_format((float) $row['actual_weight'], 2); ?> kg</td>
            <td class="num"><?php echo number_format((float) $row['gross_amount'], 2); ?></td>
            <td class="num text-danger"><?php echo number_format((float) $row['total_deduction'], 2); ?></td>
            <td class="num fw-bold text-success"><?php echo number_format((float) $row['net_amount'], 2); ?></td>
            <td><div class="payment-actions"><span class="workflow-status status-paid">จ่ายเงินแล้ว</span><a class="btn btn-sm btn-outline-dark" target="_blank" href="<?php echo h(url_for('receipt.php?id=' . (int) $row['workflow_id'])); ?>"><i class="bi bi-printer me-1"></i>ใบเสร็จ</a><?php if (user_can('payments_edit', $user)): ?><form method="post" onsubmit="return confirm('เปิดรายการนี้กลับมาแก้ไขและยกเลิกสถานะจ่ายแล้วชั่วคราว?')"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="workflow_id" value="<?php echo (int) $row['workflow_id']; ?>"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>แก้ไข</button></form><?php endif; ?><?php if (user_can('payments_delete', $user)): ?><form method="post" onsubmit="return confirm('ยืนยันยกเลิกการจ่ายเงินรายการนี้?')"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="workflow_id" value="<?php echo (int) $row['workflow_id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>ยกเลิกจ่าย</button></form><?php endif; ?><small><?php echo h($row['paid_by'] ?: 'ไม่พบชื่อผู้จ่าย'); ?><br><?php echo h($row['paid_at']); ?></small></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$paidRows): ?><tr><td class="empty" colspan="8">ยังไม่มีรายการจ่ายเงินตามวันที่ที่เลือก</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
</body>
</html>

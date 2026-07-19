<?php
require_once __DIR__ . '/auth.php';
require_user_permission('deductions');
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/navbar.php';

sync_workflow_records();
$user = current_user();
$deductionTypes = active_deduction_types();
$flash = $_SESSION['workflow_flash'] ?? null;
unset($_SESSION['workflow_flash']);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่');
    $id = filter_var($_POST['workflow_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$id) throw new RuntimeException('ไม่พบรายการที่ต้องการบันทึกยอดหัก');
    if (!$deductionTypes) throw new RuntimeException('ยังไม่ได้ตั้งค่ารายการหัก กรุณากำหนดรายการที่หน้า Setup ระบบก่อน');
    $amounts = (array) ($_POST['deduction_amount'] ?? []);
    $items = []; $totalDeduction = 0.0;
    foreach ($deductionTypes as $type) {
      $rawAmount = $amounts[(string) $type['deduction_type_id']] ?? '';
      if ($rawAmount === '') $rawAmount = 0;
      $amount = filter_var($rawAmount, FILTER_VALIDATE_FLOAT);
      if ($amount === false || $amount < 0 || $amount > 100000000) throw new RuntimeException('กรุณาระบุจำนวนเงินของ “' . $type['deduction_name'] . '” ให้ถูกต้อง');
      if ($amount == 0) continue;
      $items[] = ['label' => $type['deduction_name'], 'amount' => $amount];
      $totalDeduction += $amount;
    }

    db()->beginTransaction();
    $stmt = db()->prepare('SELECT * FROM tbl_rubber_workflow WHERE workflow_id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $workflow = $stmt->fetch();
    if (!$workflow || !in_array($workflow['workflow_status'], ['weighed', 'deducted'], true)) throw new RuntimeException('รายการนี้ยังไม่ผ่านการชั่ง หรือจ่ายเงินแล้ว');
    $stmt = db()->prepare('SELECT pr_price FROM tbl_price WHERE pr_date = :date ORDER BY pr_id DESC LIMIT 1');
    $stmt->execute(['date' => $workflow['weigh_date']]);
    $price = $stmt->fetchColumn();
    if ($price === false || (float) $price <= 0) throw new RuntimeException('ยังไม่มีราคายางของวันชั่ง ' . $workflow['weigh_date'] . ' กรุณาบันทึกราคาประจำวันก่อน');
    $price = (float) $price;
    $gross = round((float) $workflow['actual_weight'] * $price, 2);
    if ($totalDeduction > $gross) throw new RuntimeException('ยอดหักรวมต้องไม่เกินยอดค่ายาง');
    $net = round($gross - $totalDeduction, 2);
    $receiptNo = $workflow['receipt_no'] ?: workflow_receipt_no($id, $workflow['weigh_date']);
    db()->prepare('DELETE FROM tbl_rubber_deduction WHERE workflow_id = :id')->execute(['id' => $id]);
    $insert = db()->prepare('INSERT INTO tbl_rubber_deduction
      (workflow_id, deduction_label, deduction_amount, saved_by) VALUES(:id, :label, :amount, :staff)');
    foreach ($items as $item) $insert->execute(['id' => $id, 'label' => $item['label'], 'amount' => $item['amount'], 'staff' => $user['user_fullname']]);
    $stmt = db()->prepare('UPDATE tbl_rubber_workflow SET price_per_kg = :price, gross_amount = :gross,
      total_deduction = :deduction, net_amount = :net, workflow_status = "deducted",
      deduction_by = :staff, deduction_at = NOW(), receipt_no = :receipt WHERE workflow_id = :id');
    $stmt->execute(['price' => $price, 'gross' => $gross, 'deduction' => $totalDeduction, 'net' => $net, 'staff' => $user['user_fullname'], 'receipt' => $receiptNo, 'id' => $id]);
    update_placement_status($id, 'deducted');
    audit_log('deduct', 'workflow', $id, 'บันทึกยอดหักรวม ' . number_format($totalDeduction, 2) . ' บาท สำหรับสมาชิก ' . $workflow['member_number'], [
      'round_date' => $workflow['weigh_date'], 'yard_code' => $workflow['yard_code'],
      'member_number' => $workflow['member_number'], 'gross_amount' => $gross,
      'deductions' => $items, 'total_deduction' => $totalDeduction, 'net_amount' => $net, 'receipt_no' => $receiptNo,
    ]);
    db()->commit();
    $_SESSION['workflow_flash'] = ['type' => 'success', 'message' => 'บันทึกยอดหักแล้ว ยอดสุทธิ ' . number_format($net, 2) . ' บาท'];
    workflow_redirect('deductions.php', ['id' => $id]);
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
  }
}

$list = db()->query('SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name
  FROM tbl_rubber_workflow workflow LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code
  WHERE workflow.workflow_status IN ("weighed", "deducted") ORDER BY workflow.weigh_date DESC, workflow.workflow_id DESC LIMIT 300')->fetchAll();
$selectedId = filter_var($_GET['id'] ?? ($list[0]['workflow_id'] ?? 0), FILTER_VALIDATE_INT);
$selected = null; $savedDeductionAmounts = [];
if ($selectedId) {
  $stmt = db()->prepare('SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name
    FROM tbl_rubber_workflow workflow LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code WHERE workflow.workflow_id = :id LIMIT 1');
  $stmt->execute(['id' => $selectedId]); $selected = $stmt->fetch();
  if ($selected) {
    $stmt = db()->prepare('SELECT deduction_label, deduction_amount FROM tbl_rubber_deduction WHERE workflow_id = :id ORDER BY deduction_id');
    $stmt->execute(['id' => $selectedId]);
    foreach ($stmt->fetchAll() as $savedItem) $savedDeductionAmounts[$savedItem['deduction_label']] = $savedItem['deduction_amount'];
  }
}
$defaultPrice = 0;
if ($selected) { $stmt = db()->prepare('SELECT pr_price FROM tbl_price WHERE pr_date = :date ORDER BY pr_id DESC LIMIT 1'); $stmt->execute(['date' => $selected['weigh_date']]); $defaultPrice = (float) ($stmt->fetchColumn() ?: 0); }
$hasDailyPrice = $defaultPrice > 0;
$formPrice = $defaultPrice;
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>บันทึกรายการหัก</title><link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet"><link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet"></head>
<body><?php render_topbar(); ?><main class="ops-shell"><section class="ops-hero"><div><h1><i class="bi bi-receipt-cutoff me-2"></i>บันทึกรายการหัก</h1><p>รับเฉพาะรายการที่ชั่งแล้ว คำนวณยอดค่ายาง รายการหัก และยอดสุทธิ</p></div><span class="pill">ขั้นตอน 3 · ยอดหัก</span></section><?php if ($flash): ?><div class="alert alert-<?php echo h($flash['type']); ?> mt-3"><?php echo h($flash['message']); ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo h($error); ?></div><?php endif; ?>
<div class="ops-grid wide-left"><section class="ops-card"><div class="ops-card-head"><h2>รายการรอบันทึกยอดหัก</h2><span class="badge-soft"><?php echo number_format(count($list)); ?> รายการ</span></div><div class="workflow-list"><?php foreach ($list as $item): ?><a class="workflow-item <?php echo (int) $selectedId === (int) $item['workflow_id'] ? 'selected' : ''; ?>" href="?id=<?php echo (int) $item['workflow_id']; ?>"><span><strong><?php echo h($item['member_number'] . ' · ' . $item['member_name']); ?></strong><small><?php echo h($item['weigh_date'] . ' · ' . $item['yard_name']); ?></small></span><span class="text-end"><strong><?php echo number_format((float) $item['actual_weight'], 2); ?> kg</strong><small><?php echo h(workflow_status_label($item['workflow_status'])); ?></small></span></a><?php endforeach; ?><?php if (!$list): ?><div class="empty">ยังไม่มีรายการที่ชั่งแล้ว</div><?php endif; ?></div></section>
<section class="ops-card sticky"><div class="ops-card-head"><h2>คำนวณยอดสมาชิก</h2><?php if ($selected && $selected['receipt_no']): ?><a class="btn btn-sm btn-outline-dark" target="_blank" href="<?php echo h(url_for('receipt.php?id=' . (int) $selected['workflow_id'])); ?>"><i class="bi bi-printer"></i></a><?php endif; ?></div><?php if ($selected && in_array($selected['workflow_status'], ['weighed', 'deducted'], true)): ?><form class="ops-card-body" method="post" id="deductionForm"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="workflow_id" value="<?php echo (int) $selected['workflow_id']; ?>"><div class="selected-member"><strong><?php echo h($selected['member_number'] . ' · ' . $selected['member_name']); ?></strong><small><?php echo h($selected['weigh_date'] . ' · ' . $selected['yard_name']); ?> · <?php echo number_format((float) $selected['actual_weight'], 2); ?> kg</small></div><?php if (!$hasDailyPrice): ?><div class="alert alert-warning">ยังไม่มีราคายางของวันชั่ง <?php echo h($selected['weigh_date']); ?> กรุณา <a href="<?php echo h(url_for('price.php')); ?>">บันทึกราคาประจำวัน</a> ก่อนทำรายการหัก</div><?php endif; ?><div class="mb-3"><label class="form-label">ราคาประจำวันชั่ง (อ่านอย่างเดียว)</label><div class="input-group"><input id="price" class="form-control" type="number" readonly value="<?php echo $hasDailyPrice ? h($formPrice) : ''; ?>" placeholder="ยังไม่มีราคา"><span class="input-group-text">บาท/kg</span></div><div class="form-hint mt-1">ดึงจากราคาวันที่ <?php echo h($selected['weigh_date']); ?> โดยอัตโนมัติ</div></div><div class="d-flex align-items-center justify-content-between gap-2 mb-2"><label class="form-label mb-0">รายการหักที่กำหนดใน Setup</label><a class="form-hint" href="<?php echo h(url_for('setup.php')); ?>">แก้ไขรายการ</a></div><div id="deductionRows"><?php foreach ($deductionTypes as $type): ?><?php $typeId = (string) $type['deduction_type_id']; $amountValue = isset($_POST['deduction_amount'][$typeId]) ? $_POST['deduction_amount'][$typeId] : ($savedDeductionAmounts[$type['deduction_name']] ?? ''); ?><div class="deduction-row"><div class="deduction-label-static"><i class="bi bi-dash-circle"></i><span><?php echo h($type['deduction_name']); ?></span></div><div class="input-group"><input class="form-control deduction-amount" type="number" name="deduction_amount[<?php echo (int) $type['deduction_type_id']; ?>]" min="0" max="100000000" step="0.01" placeholder="0.00" value="<?php echo h($amountValue); ?>"><span class="input-group-text">บาท</span></div></div><?php endforeach; ?><?php if (!$deductionTypes): ?><div class="alert alert-warning">ยังไม่มีรายการหัก กรุณา <a href="<?php echo h(url_for('setup.php')); ?>">ตั้งค่าระบบ</a> ก่อนบันทึก</div><?php endif; ?></div><div class="calculation"><div><span>ยอดค่ายาง</span><strong id="gross">0.00 บาท</strong></div><div><span>ยอดหักรวม</span><strong id="deduct">0.00 บาท</strong></div><div class="net"><span>ยอดสุทธิ</span><strong id="net">0.00 บาท</strong></div></div><button class="btn btn-green w-100" <?php echo (!$hasDailyPrice || !$deductionTypes) ? 'disabled' : ''; ?>><i class="bi bi-check2-circle me-1"></i>บันทึกยอดหัก</button></form><?php else: ?><div class="empty">เลือกรายการที่ชั่งแล้วเพื่อบันทึกยอดหัก</div><?php endif; ?></section></div></main>
<?php if ($selected): ?><script>(function(){var weight=<?php echo json_encode((float) $selected['actual_weight']); ?>,price=document.getElementById('price'),amounts=document.querySelectorAll('.deduction-amount');function money(v){return Number(v||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2})+' บาท';}function calculate(){var gross=weight*Number(price.value||0),deduct=0;amounts.forEach(function(input){deduct+=Number(input.value||0);});document.getElementById('gross').textContent=money(gross);document.getElementById('deduct').textContent=money(deduct);document.getElementById('net').textContent=money(gross-deduct);}if(price){price.addEventListener('input',calculate);amounts.forEach(function(input){input.addEventListener('input',calculate);});calculate();}}());</script><?php endif; ?></body></html>

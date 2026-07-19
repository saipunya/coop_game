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
$isAdmin = ($user['user_level'] ?? '') === 'admin';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่');
    $id = filter_var($_POST['workflow_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$id) throw new RuntimeException('ไม่พบรายการที่ต้องการบันทึกยอดหัก');
    db()->beginTransaction();
    $stmt = db()->prepare('SELECT * FROM tbl_rubber_workflow WHERE workflow_id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $workflow = $stmt->fetch();
    $isPaidAdminEdit = $workflow && $workflow['workflow_status'] === 'paid' && $isAdmin;
    if (!$workflow || (!in_array($workflow['workflow_status'], ['weighed', 'deducted'], true) && !$isPaidAdminEdit)) {
      throw new RuntimeException('รายการนี้ยังไม่ผ่านการชั่ง หรือถูกล็อกหลังจ่ายเงินแล้ว');
    }
    $previousItemsStmt = db()->prepare('SELECT deduction_id, deduction_type_id, deduction_label AS label,
        deduction_amount AS amount, sort_order
      FROM tbl_rubber_deduction WHERE workflow_id = :id ORDER BY sort_order, deduction_id FOR UPDATE');
    $previousItemsStmt->execute(['id' => $id]);
    $previousItems = $previousItemsStmt->fetchAll();
    $isFirstDeduction = $workflow['workflow_status'] === 'weighed';
    if ($isFirstDeduction) {
      if (!$deductionTypes) throw new RuntimeException('ยังไม่ได้ตั้งค่ารายการหัก กรุณากำหนดรายการที่หน้า Setup ระบบก่อน');
      $itemDefinitions = array_map(function ($type) {
        return [
          'input_key' => 'type_' . $type['deduction_type_id'],
          'type_id' => (int) $type['deduction_type_id'],
          'label' => $type['deduction_name'],
          'sort_order' => (int) $type['sort_order'],
        ];
      }, $deductionTypes);
    } else {
      if (!$previousItems) throw new RuntimeException('รายการนี้ไม่มี Snapshot รายการหักเดิม จึงไม่สามารถนำ Setup ปัจจุบันมาแทนที่ได้');
      $itemDefinitions = array_map(function ($item) {
        return [
          'input_key' => 'saved_' . $item['deduction_id'],
          'type_id' => $item['deduction_type_id'] !== null ? (int) $item['deduction_type_id'] : null,
          'label' => $item['label'],
          'sort_order' => (int) $item['sort_order'],
        ];
      }, $previousItems);
    }
    $amounts = (array) ($_POST['deduction_amount'] ?? []);
    $items = [];
    $totalDeduction = 0.0;
    foreach ($itemDefinitions as $definition) {
      $rawAmount = $amounts[$definition['input_key']] ?? '';
      if ($rawAmount === '') $rawAmount = 0;
      $amount = filter_var($rawAmount, FILTER_VALIDATE_FLOAT);
      if ($amount === false || $amount < 0 || $amount > 100000000) {
        throw new RuntimeException('กรุณาระบุจำนวนเงินของ “' . $definition['label'] . '” ให้ถูกต้อง');
      }
      $items[] = [
        'type_id' => $definition['type_id'],
        'label' => $definition['label'],
        'amount' => (float) $amount,
        'sort_order' => $definition['sort_order'],
      ];
      $totalDeduction += (float) $amount;
    }
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
      (workflow_id, deduction_type_id, deduction_label, deduction_amount, sort_order, saved_by)
      VALUES(:id, :type_id, :label, :amount, :sort_order, :staff)');
    foreach ($items as $item) $insert->execute([
      'id' => $id, 'type_id' => $item['type_id'], 'label' => $item['label'], 'amount' => $item['amount'],
      'sort_order' => $item['sort_order'], 'staff' => $user['user_fullname'],
    ]);
    if ($isPaidAdminEdit) {
      $stmt = db()->prepare('UPDATE tbl_rubber_workflow SET price_per_kg = :price, gross_amount = :gross,
        total_deduction = :deduction, net_amount = :net, admin_edited_by = :staff,
        admin_edited_at = NOW(), admin_edit_type = "deduction" WHERE workflow_id = :id');
      $stmt->execute(['price' => $price, 'gross' => $gross, 'deduction' => $totalDeduction, 'net' => $net, 'staff' => $user['user_fullname'], 'id' => $id]);
    } else {
      $stmt = db()->prepare('UPDATE tbl_rubber_workflow SET price_per_kg = :price, gross_amount = :gross,
        total_deduction = :deduction, net_amount = :net, workflow_status = "deducted",
        deduction_by = :staff, deduction_at = NOW(), receipt_no = :receipt WHERE workflow_id = :id');
      $stmt->execute(['price' => $price, 'gross' => $gross, 'deduction' => $totalDeduction, 'net' => $net, 'staff' => $user['user_fullname'], 'receipt' => $receiptNo, 'id' => $id]);
      update_placement_status($id, 'deducted');
    }
    audit_log($isPaidAdminEdit ? 'admin_edit_paid_deduction' : 'deduct', 'workflow', $id,
      ($isPaidAdminEdit ? 'ADMIN แก้ไขยอดหักของรายการที่จ่ายแล้วเป็น ' : 'บันทึกยอดหักรวม ') . number_format($totalDeduction, 2) . ' บาท สำหรับสมาชิก ' . $workflow['member_number'], [
      'round_date' => $workflow['weigh_date'], 'yard_code' => $workflow['yard_code'],
      'member_number' => $workflow['member_number'], 'gross_amount' => $gross,
      'deductions' => $items, 'total_deduction' => $totalDeduction, 'net_amount' => $net, 'receipt_no' => $receiptNo,
      'previous_deductions' => $previousItems,
      'previous_total_deduction' => (float) $workflow['total_deduction'],
      'previous_net_amount' => (float) $workflow['net_amount'],
      'admin_override' => $isPaidAdminEdit,
    ]);
    db()->commit();
    $_SESSION['workflow_flash'] = ['type' => 'success', 'message' => $isPaidAdminEdit
      ? 'ADMIN แก้ไขยอดหักของรายการที่จ่ายแล้วเรียบร้อย ใบเสร็จจะแสดงลายน้ำการแก้ไข'
      : 'บันทึกยอดหักแล้ว ยอดสุทธิ ' . number_format($net, 2) . ' บาท'];
    workflow_redirect('deductions.php', ['id' => $id]);
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
  }
}

$visibleStatuses = $isAdmin ? '("weighed", "deducted", "paid")' : '("weighed", "deducted")';
$list = db()->query('SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name
  FROM tbl_rubber_workflow workflow LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code
  WHERE workflow.workflow_status IN ' . $visibleStatuses . ' ORDER BY workflow.weigh_date DESC, workflow.workflow_id DESC LIMIT 300')->fetchAll();
$pendingList = array_values(array_filter($list, function ($item) {
  return $item['workflow_status'] === 'weighed';
}));
$savedList = array_values(array_filter($list, function ($item) {
  return in_array($item['workflow_status'], ['deducted', 'paid'], true);
}));
$defaultSelectedId = $pendingList[0]['workflow_id'] ?? ($savedList[0]['workflow_id'] ?? 0);
$selectedId = filter_var($_GET['id'] ?? $defaultSelectedId, FILTER_VALIDATE_INT);
$selected = null;
$formDeductionTypes = [];
if ($selectedId) {
  $stmt = db()->prepare('SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name
    FROM tbl_rubber_workflow workflow LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code WHERE workflow.workflow_id = :id LIMIT 1');
  $stmt->execute(['id' => $selectedId]); $selected = $stmt->fetch();
  if ($selected) {
    if ($selected['workflow_status'] === 'weighed') {
      foreach ($deductionTypes as $type) {
        $formDeductionTypes[] = [
          'input_key' => 'type_' . $type['deduction_type_id'],
          'deduction_name' => $type['deduction_name'],
          'deduction_amount' => '',
        ];
      }
    } else {
      $stmt = db()->prepare('SELECT deduction_id, deduction_label, deduction_amount
        FROM tbl_rubber_deduction WHERE workflow_id = :id ORDER BY sort_order, deduction_id');
      $stmt->execute(['id' => $selectedId]);
      foreach ($stmt->fetchAll() as $savedItem) {
        $formDeductionTypes[] = [
          'input_key' => 'saved_' . $savedItem['deduction_id'],
          'deduction_name' => $savedItem['deduction_label'],
          'deduction_amount' => $savedItem['deduction_amount'],
        ];
      }
    }
  }
}
$defaultPrice = 0;
if ($selected) { $stmt = db()->prepare('SELECT pr_price FROM tbl_price WHERE pr_date = :date ORDER BY pr_id DESC LIMIT 1'); $stmt->execute(['date' => $selected['weigh_date']]); $defaultPrice = (float) ($stmt->fetchColumn() ?: 0); }
$hasDailyPrice = $defaultPrice > 0;
$formPrice = $defaultPrice;
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>บันทึกรายการหัก</title><link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet"><link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet"></head>
<body><?php render_topbar(); ?><main class="ops-shell"><section class="ops-hero"><div><h1><i class="bi bi-receipt-cutoff me-2"></i>บันทึกรายการหัก</h1><p>รับเฉพาะรายการที่ชั่งแล้ว คำนวณยอดค่ายาง รายการหัก และยอดสุทธิ</p></div><span class="pill">ขั้นตอน 3 · ยอดหัก</span></section><?php if ($flash): ?><div class="alert alert-<?php echo h($flash['type']); ?> mt-3"><?php echo h($flash['message']); ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo h($error); ?></div><?php endif; ?>
<div class="ops-grid wide-left"><div class="d-grid gap-3"><section class="ops-card"><div class="ops-card-head"><div><h2><i class="bi bi-hourglass-split text-warning me-1"></i>รายการรอบันทึกยอดหัก</h2><small class="text-secondary">ชั่งน้ำหนักแล้วและยังไม่บันทึกยอดหัก</small></div><span class="badge-soft"><?php echo number_format(count($pendingList)); ?> รายการ</span></div><div class="workflow-list"><?php foreach ($pendingList as $item): ?><a class="workflow-item <?php echo (int) $selectedId === (int) $item['workflow_id'] ? 'selected' : ''; ?>" href="?id=<?php echo (int) $item['workflow_id']; ?>"><span><strong><?php echo h($item['member_number'] . ' · ' . $item['member_name']); ?></strong><small><?php echo h($item['weigh_date'] . ' · ' . $item['yard_name']); ?></small></span><span class="text-end"><strong><?php echo number_format((float) $item['actual_weight'], 2); ?> kg</strong><small><span class="workflow-status status-weighed">รอบันทึก</span></small></span></a><?php endforeach; ?><?php if (!$pendingList): ?><div class="empty">ไม่มีรายการรอบันทึกยอดหัก</div><?php endif; ?></div></section>
<section class="ops-card"><div class="ops-card-head"><div><h2><i class="bi bi-check2-circle text-success me-1"></i>รายการที่บันทึกยอดหักแล้ว</h2><small class="text-secondary"><?php echo $isAdmin ? 'รายการจ่ายแล้วจะแก้ได้เฉพาะ admin และมีลายน้ำกำกับ' : 'เลือกเพื่อดูรายละเอียดหรือแก้ไขก่อนจ่ายเงิน'; ?></small></div><span class="badge-soft"><?php echo number_format(count($savedList)); ?> รายการ</span></div><div class="workflow-list"><?php foreach ($savedList as $item): ?><a class="workflow-item <?php echo (int) $selectedId === (int) $item['workflow_id'] ? 'selected' : ''; ?>" href="?id=<?php echo (int) $item['workflow_id']; ?>"><span><strong><?php echo h($item['member_number'] . ' · ' . $item['member_name']); ?></strong><small><?php echo h($item['weigh_date'] . ' · ' . $item['yard_name']); ?> · <?php echo h($item['deduction_by'] ?: 'ไม่พบผู้บันทึก'); ?><?php echo $item['workflow_status'] === 'paid' ? ' · จ่ายเงินแล้ว' : ''; ?></small></span><span class="text-end"><strong class="text-danger">หัก <?php echo number_format((float) $item['total_deduction'], 2); ?></strong><small>สุทธิ <?php echo number_format((float) $item['net_amount'], 2); ?> บาท</small></span></a><?php endforeach; ?><?php if (!$savedList): ?><div class="empty">ยังไม่มีรายการที่บันทึกยอดหักแล้ว</div><?php endif; ?></div></section></div>
<section class="ops-card sticky"><div class="ops-card-head"><h2><?php echo $selected && $selected['workflow_status'] === 'paid' ? 'Admin แก้ไขรายการจ่ายแล้ว' : ($selected && $selected['workflow_status'] === 'deducted' ? 'ตรวจสอบ / แก้ไขยอดหัก' : 'คำนวณยอดสมาชิก'); ?></h2><?php if ($selected && $selected['receipt_no']): ?><a class="btn btn-sm btn-outline-dark" target="_blank" href="<?php echo h(url_for('receipt.php?id=' . (int) $selected['workflow_id'])); ?>"><i class="bi bi-printer"></i></a><?php endif; ?></div><?php $canEditSelected = $selected && (in_array($selected['workflow_status'], ['weighed', 'deducted'], true) || ($isAdmin && $selected['workflow_status'] === 'paid')); ?><?php if ($canEditSelected): ?><form class="ops-card-body" method="post" id="deductionForm"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="workflow_id" value="<?php echo (int) $selected['workflow_id']; ?>"><div class="selected-member"><strong><?php echo h($selected['member_number'] . ' · ' . $selected['member_name']); ?></strong><small><?php echo h($selected['weigh_date'] . ' · ' . $selected['yard_name']); ?> · <?php echo number_format((float) $selected['actual_weight'], 2); ?> kg</small><?php if ($selected['workflow_status'] === 'deducted'): ?><span class="workflow-status status-deducted mt-2">บันทึกยอดหักแล้ว · ใช้รายการเดิม ณ วันที่บันทึก</span><?php elseif ($selected['workflow_status'] === 'paid'): ?><span class="workflow-status status-paid mt-2">จ่ายเงินแล้ว · เฉพาะ admin แก้ไขได้</span><div class="alert alert-danger mt-2 mb-0"><i class="bi bi-shield-lock-fill me-1"></i>การบันทึกครั้งนี้จะเก็บชื่อและเวลาแก้ไขใน Audit Log พร้อมแสดงลายน้ำบนใบเสร็จ</div><?php endif; ?></div><?php if (!$hasDailyPrice): ?><div class="alert alert-warning">ยังไม่มีราคายางของวันชั่ง <?php echo h($selected['weigh_date']); ?> กรุณา <a href="<?php echo h(url_for('price.php')); ?>">บันทึกราคาประจำวัน</a> ก่อนทำรายการหัก</div><?php endif; ?><div class="mb-3"><label class="form-label">ราคาประจำวันชั่ง (อ่านอย่างเดียว)</label><div class="input-group"><input id="price" class="form-control" type="number" readonly value="<?php echo $hasDailyPrice ? h($formPrice) : ''; ?>" placeholder="ยังไม่มีราคา"><span class="input-group-text">บาท/kg</span></div><div class="form-hint mt-1">ดึงจากราคาวันที่ <?php echo h($selected['weigh_date']); ?> โดยอัตโนมัติ</div></div><div class="d-flex align-items-center justify-content-between gap-2 mb-2"><label class="form-label mb-0"><?php echo $selected['workflow_status'] === 'weighed' ? 'รายการหักจาก Setup ปัจจุบัน' : 'Snapshot รายการหักของงานนี้'; ?></label><?php if ($selected['workflow_status'] === 'weighed'): ?><a class="form-hint" href="<?php echo h(url_for('setup.php')); ?>">แก้ไข Setup</a><?php else: ?><span class="form-hint"><i class="bi bi-lock-fill me-1"></i>Setup ภายหลังไม่มีผล</span><?php endif; ?></div><div id="deductionRows"><?php foreach ($formDeductionTypes as $type): ?><?php $inputKey = (string) $type['input_key']; $amountValue = isset($_POST['deduction_amount'][$inputKey]) ? $_POST['deduction_amount'][$inputKey] : $type['deduction_amount']; ?><div class="deduction-row"><div class="deduction-label-static"><i class="bi bi-dash-circle"></i><span><?php echo h($type['deduction_name']); ?></span></div><div class="input-group"><input class="form-control deduction-amount" type="number" name="deduction_amount[<?php echo h($inputKey); ?>]" min="0" max="100000000" step="0.01" placeholder="0.00" value="<?php echo h($amountValue); ?>"><span class="input-group-text">บาท</span></div></div><?php endforeach; ?><?php if (!$formDeductionTypes): ?><div class="alert alert-warning"><?php echo $selected['workflow_status'] === 'weighed' ? 'ยังไม่มีรายการหัก กรุณาตั้งค่าระบบก่อนบันทึก' : 'ไม่พบ Snapshot รายการหักเดิมของงานนี้'; ?></div><?php endif; ?></div><div class="calculation"><div><span>ยอดค่ายาง</span><strong id="gross">0.00 บาท</strong></div><div><span>ยอดหักรวม</span><strong id="deduct">0.00 บาท</strong></div><div class="net"><span>ยอดสุทธิ</span><strong id="net">0.00 บาท</strong></div></div><button class="btn <?php echo $selected['workflow_status'] === 'paid' ? 'btn-danger' : 'btn-green'; ?> w-100" <?php echo (!$hasDailyPrice || !$formDeductionTypes) ? 'disabled' : ''; ?>><i class="bi bi-check2-circle me-1"></i><?php echo $selected['workflow_status'] === 'paid' ? 'Admin อัปเดตยอดหัก' : ($selected['workflow_status'] === 'deducted' ? 'อัปเดตยอดหัก' : 'บันทึกยอดหัก'); ?></button></form><?php else: ?><div class="empty">เลือกรายการที่ชั่งแล้วเพื่อบันทึกยอดหัก</div><?php endif; ?></section></div></main>
<?php if ($selected): ?><script>(function(){var weight=<?php echo json_encode((float) $selected['actual_weight']); ?>,price=document.getElementById('price'),amounts=document.querySelectorAll('.deduction-amount');function money(v){return Number(v||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2})+' บาท';}function calculate(){var gross=weight*Number(price.value||0),deduct=0;amounts.forEach(function(input){deduct+=Number(input.value||0);});document.getElementById('gross').textContent=money(gross);document.getElementById('deduct').textContent=money(deduct);document.getElementById('net').textContent=money(gross-deduct);}if(price){price.addEventListener('input',calculate);amounts.forEach(function(input){input.addEventListener('input',calculate);});calculate();}}());</script><?php endif; ?></body></html>

<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit("CLI only\n");
}

require_once dirname(__DIR__) . '/workflow.php';

const DEMO_MARKER = '[WORKFLOW_DEMO_V2]';
const DEMO_ACTOR_PREFIX = 'demo.workflow.';

ensure_workflow_schema();
$pdo = db();
$requestedDate = trim((string) ($argv[1] ?? ''));
if ($requestedDate !== '') {
  $dateObject = DateTime::createFromFormat('Y-m-d', $requestedDate);
  if (!$dateObject || $dateObject->format('Y-m-d') !== $requestedDate) {
    fwrite(STDERR, "Usage: php yang/scripts/seed_workflow_demo.php [YYYY-MM-DD]\n");
    exit(1);
  }
  $roundDate = $requestedDate;
} else {
  $roundDate = date('Y-m-d', strtotime('+2 days'));
}

function demo_datetime($roundDate, $dayOffset, $time)
{
  return date('Y-m-d', strtotime($roundDate . ' ' . ($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days')) . ' ' . $time;
}

function demo_audit(PDO $pdo, $actorUsername, $actorFullname, $action, $workflowId, $summary, $details, $createdAt)
{
  $stmt = $pdo->prepare('INSERT INTO tbl_audit_log
    (actor_user_id, actor_username, actor_fullname, actor_level, action_key, entity_type, entity_id,
      summary, details_json, ip_address, user_agent, created_at)
    VALUES(NULL, :username, :fullname, "demo", :action, "workflow", :entity_id,
      :summary, :details, "127.0.0.1", "Workflow demo seeder", :created_at)');
  $stmt->execute([
    'username' => $actorUsername,
    'fullname' => $actorFullname,
    'action' => $action,
    'entity_id' => (string) $workflowId,
    'summary' => $summary,
    'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'created_at' => $createdAt,
  ]);
}

$pdo->beginTransaction();
try {
  $oldWorkflowIds = $pdo->query('SELECT DISTINCT workflow.workflow_id
    FROM tbl_rubber_workflow workflow
    INNER JOIN tbl_wangyang placement ON placement.wang_date = workflow.weigh_date
      AND placement.wang_lan = workflow.yard_code AND placement.wang_mid = workflow.member_id
    WHERE placement.wang_note LIKE "' . DEMO_MARKER . '%" OR placement.wang_saveby = "DEMO SYSTEM"')->fetchAll(PDO::FETCH_COLUMN);
  if ($oldWorkflowIds) {
    $placeholders = implode(',', array_fill(0, count($oldWorkflowIds), '?'));
    $stmt = $pdo->prepare('DELETE FROM tbl_rubber_deduction WHERE workflow_id IN (' . $placeholders . ')');
    $stmt->execute($oldWorkflowIds);
    $stmt = $pdo->prepare('DELETE FROM tbl_audit_log WHERE entity_type = "workflow" AND entity_id IN (' . $placeholders . ')');
    $stmt->execute(array_map('strval', $oldWorkflowIds));
    $stmt = $pdo->prepare('DELETE FROM tbl_rubber_workflow WHERE workflow_id IN (' . $placeholders . ')');
    $stmt->execute($oldWorkflowIds);
  }
  $pdo->exec('DELETE FROM tbl_wangyang WHERE wang_note LIKE "' . DEMO_MARKER . '%" OR wang_saveby = "DEMO SYSTEM"');
  $pdo->exec('DELETE FROM tbl_audit_log WHERE actor_username LIKE "' . DEMO_ACTOR_PREFIX . '%"');

  $priceStmt = $pdo->prepare('SELECT pr_price FROM tbl_price WHERE pr_date = :date ORDER BY pr_id DESC LIMIT 1');
  $priceStmt->execute(['date' => $roundDate]);
  $price = $priceStmt->fetchColumn();
  if ($price === false) {
    $price = 42.75;
    $stmt = $pdo->prepare('INSERT INTO tbl_price
      (pr_year, pr_date, pr_number, pr_price, pr_saveby, pr_savedate)
      VALUES(:year, :date, "DEMO", :price, "DEMO WORKFLOW", CURDATE())');
    $stmt->execute(['year' => (int) date('Y', strtotime($roundDate)) + 543, 'date' => $roundDate, 'price' => $price]);
  }
  $price = (float) $price;

  $weightPerBag = 48.50;
  $stmt = $pdo->prepare('INSERT INTO tbl_wangyang_daily_summary
    (ws_date, ws_weight_per_bag, ws_estimated_weight, ws_saveby, ws_savedate)
    VALUES(:date, :rate, 0, "DEMO WORKFLOW", NOW())
    ON DUPLICATE KEY UPDATE ws_weight_per_bag = IF(ws_weight_per_bag > 0, ws_weight_per_bag, VALUES(ws_weight_per_bag))');
  $stmt->execute(['date' => $roundDate, 'rate' => $weightPerBag]);
  $stmt = $pdo->prepare('SELECT ws_weight_per_bag FROM tbl_wangyang_daily_summary WHERE ws_date = :date');
  $stmt->execute(['date' => $roundDate]);
  $weightPerBag = (float) ($stmt->fetchColumn() ?: $weightPerBag);

  $membersStmt = $pdo->prepare('SELECT member.mem_id, member.mem_group, member.mem_number, member.mem_fullname, member.mem_class
    FROM tbl_member member
    LEFT JOIN tbl_rubber_workflow workflow ON workflow.weigh_date = :date AND workflow.member_id = member.mem_id
    WHERE workflow.workflow_id IS NULL
    ORDER BY CAST(member.mem_number AS UNSIGNED), member.mem_number, member.mem_id LIMIT 12');
  $membersStmt->execute(['date' => $roundDate]);
  $members = $membersStmt->fetchAll();
  if (count($members) < 12) throw new RuntimeException('ต้องมีสมาชิกว่างอย่างน้อย 12 รายสำหรับสร้างข้อมูล Demo');

  $yards = active_yards();
  if (!$yards) throw new RuntimeException('ยังไม่มีลานยางที่เปิดใช้งาน');
  $placementAtBase = demo_datetime($roundDate, -2, '08:00:00');
  $insertPlacement = $pdo->prepare('INSERT INTO tbl_wangyang
    (wang_date, wang_lan, wang_note, wang_mid, wang_group, wang_number, wang_name, wang_class,
      wang_sack, wang_weight, wang_status, wang_saveby, wang_savedate)
    VALUES(:date, :yard, :note, :mid, :member_group, :number, :name, :member_class,
      :bags, :estimated_weight, "placed", :saveby, :saved_at)');

  $demoRows = [];
  foreach ($members as $index => $member) {
    $yard = $yards[$index % count($yards)];
    $bags = 8 + (($index * 3) % 17);
    $estimatedWeight = round($bags * $weightPerBag, 2);
    $placementAt = date('Y-m-d H:i:s', strtotime($placementAtBase . ' +' . ($index * 7) . ' minutes'));
    $stage = $index < 3 ? 'placed' : ($index < 6 ? 'weighed' : ($index < 9 ? 'deducted' : 'paid'));
    $insertPlacement->execute([
      'date' => $roundDate,
      'yard' => $yard['yard_code'],
      'note' => DEMO_MARKER . ' สถานะตัวอย่าง: ' . $stage,
      'mid' => $member['mem_id'],
      'member_group' => $member['mem_group'],
      'number' => $member['mem_number'],
      'name' => $member['mem_fullname'],
      'member_class' => $member['mem_class'],
      'bags' => $bags,
      'estimated_weight' => $estimatedWeight,
      'saveby' => 'Demo · เจ้าหน้าที่วางยาง',
      'saved_at' => $placementAt,
    ]);
    $demoRows[] = compact('member', 'yard', 'bags', 'estimatedWeight', 'placementAt', 'stage', 'index');
  }

  sync_workflow_records();
  $findWorkflow = $pdo->prepare('SELECT workflow_id FROM tbl_rubber_workflow
    WHERE weigh_date = :date AND yard_code = :yard AND member_id = :member_id LIMIT 1');
  $updateWorkflow = $pdo->prepare('UPDATE tbl_rubber_workflow SET
    actual_weight = :actual_weight, price_per_kg = :price, gross_amount = :gross,
    total_deduction = :total_deduction, net_amount = :net, workflow_status = :status,
    weighed_by = :weighed_by, weighed_at = :weighed_at,
    deduction_by = :deduction_by, deduction_at = :deduction_at,
    receipt_no = :receipt_no, paid_by = :paid_by, paid_at = :paid_at,
    created_at = :created_at
    WHERE workflow_id = :workflow_id');
  $insertDeduction = $pdo->prepare('INSERT INTO tbl_rubber_deduction
    (workflow_id, deduction_label, deduction_amount, saved_by, saved_at)
    VALUES(:workflow_id, :label, :amount, :saved_by, :saved_at)');

  $statusCounts = ['placed' => 0, 'weighed' => 0, 'deducted' => 0, 'paid' => 0];
  foreach ($demoRows as $demo) {
    $findWorkflow->execute([
      'date' => $roundDate,
      'yard' => $demo['yard']['yard_code'],
      'member_id' => $demo['member']['mem_id'],
    ]);
    $workflowId = (int) $findWorkflow->fetchColumn();
    if (!$workflowId) throw new RuntimeException('สร้าง workflow Demo ไม่สำเร็จ');

    $weighedAt = null;
    $deductionAt = null;
    $paidAt = null;
    $receiptNo = null;
    $actualWeight = 0.0;
    $gross = 0.0;
    $deductions = [];
    $totalDeduction = 0.0;
    $net = 0.0;
    if ($demo['stage'] !== 'placed') {
      $actualWeight = round($demo['estimatedWeight'] * (0.94 + (($demo['index'] % 4) * 0.015)), 2);
      $weighedAt = demo_datetime($roundDate, 0, sprintf('09:%02d:00', 5 + $demo['index'] * 3));
    }
    if (in_array($demo['stage'], ['deducted', 'paid'], true)) {
      $gross = round($actualWeight * $price, 2);
      $deductionAt = demo_datetime($roundDate, 0, sprintf('11:%02d:00', 2 + $demo['index'] * 2));
      $deductions = [
        ['label' => 'ค่าปุ๋ย', 'amount' => 150 + ($demo['index'] * 25)],
        ['label' => 'เงินกู้', 'amount' => 300 + ($demo['index'] * 40)],
      ];
      if ($demo['index'] % 2 === 0) $deductions[] = ['label' => 'ค่าขนส่ง', 'amount' => 80];
      $totalDeduction = array_sum(array_column($deductions, 'amount'));
      $net = round($gross - $totalDeduction, 2);
      $receiptNo = workflow_receipt_no($workflowId, $roundDate);
    }
    if ($demo['stage'] === 'paid') {
      $paidAt = demo_datetime($roundDate, 0, sprintf('13:%02d:00', 5 + $demo['index'] * 2));
    }

    $updateWorkflow->execute([
      'actual_weight' => $actualWeight,
      'price' => in_array($demo['stage'], ['deducted', 'paid'], true) ? $price : 0,
      'gross' => $gross,
      'total_deduction' => $totalDeduction,
      'net' => $net,
      'status' => $demo['stage'],
      'weighed_by' => $weighedAt ? 'Demo · เจ้าหน้าที่ชั่งยาง' : '',
      'weighed_at' => $weighedAt,
      'deduction_by' => $deductionAt ? 'Demo · เจ้าหน้าที่ยอดหัก' : '',
      'deduction_at' => $deductionAt,
      'receipt_no' => $receiptNo,
      'paid_by' => $paidAt ? 'Demo · เจ้าหน้าที่จ่ายเงิน' : '',
      'paid_at' => $paidAt,
      'created_at' => $demo['placementAt'],
      'workflow_id' => $workflowId,
    ]);
    update_placement_status($workflowId, $demo['stage']);

    demo_audit($pdo, DEMO_ACTOR_PREFIX . 'placement', 'Demo · เจ้าหน้าที่วางยาง', 'create', $workflowId,
      'บันทึกวางยาง Demo สำหรับสมาชิก ' . $demo['member']['mem_number'],
      ['round_date' => $roundDate, 'yard_code' => $demo['yard']['yard_code'], 'bags' => $demo['bags']], $demo['placementAt']);
    if ($weighedAt) {
      demo_audit($pdo, DEMO_ACTOR_PREFIX . 'weighing', 'Demo · เจ้าหน้าที่ชั่งยาง', 'weigh', $workflowId,
        'บันทึกน้ำหนักจริง Demo ' . number_format($actualWeight, 2) . ' kg',
        ['actual_weight' => $actualWeight, 'round_date' => $roundDate], $weighedAt);
    }
    if ($deductionAt) {
      foreach ($deductions as $item) {
        $insertDeduction->execute([
          'workflow_id' => $workflowId,
          'label' => $item['label'],
          'amount' => $item['amount'],
          'saved_by' => 'Demo · เจ้าหน้าที่ยอดหัก',
          'saved_at' => $deductionAt,
        ]);
      }
      demo_audit($pdo, DEMO_ACTOR_PREFIX . 'deduction', 'Demo · เจ้าหน้าที่ยอดหัก', 'deduct', $workflowId,
        'บันทึกยอดหัก Demo ' . number_format($totalDeduction, 2) . ' บาท',
        ['deductions' => $deductions, 'total_deduction' => $totalDeduction, 'net_amount' => $net], $deductionAt);
      $receiptViewedAt = date('Y-m-d H:i:s', strtotime($deductionAt . ' +15 minutes'));
      demo_audit($pdo, DEMO_ACTOR_PREFIX . 'receipt', 'Demo · เจ้าหน้าที่ใบเสร็จ', 'view_receipt', $workflowId,
        'เปิดและพิมพ์ใบเสร็จ Demo ' . $receiptNo,
        ['receipt_no' => $receiptNo, 'round_date' => $roundDate], $receiptViewedAt);
    }
    if ($paidAt) {
      demo_audit($pdo, DEMO_ACTOR_PREFIX . 'payment', 'Demo · เจ้าหน้าที่จ่ายเงิน', 'approve_payment', $workflowId,
        'อนุมัติจ่ายเงิน Demo ' . number_format($net, 2) . ' บาท',
        ['receipt_no' => $receiptNo, 'net_amount' => $net], $paidAt);
    }
    $statusCounts[$demo['stage']]++;
  }

  $stmt = $pdo->prepare('UPDATE tbl_wangyang_daily_summary SET ws_estimated_weight =
    (SELECT COALESCE(SUM(wang_weight), 0) FROM tbl_wangyang WHERE wang_date = :source_date)
    WHERE ws_date = :summary_date');
  $stmt->execute(['source_date' => $roundDate, 'summary_date' => $roundDate]);
  $pdo->commit();

  echo json_encode([
    'result' => 'ok',
    'round_date' => $roundDate,
    'price_per_kg' => $price,
    'records' => array_sum($statusCounts),
    'statuses' => $statusCounts,
    'source_tables' => ['tbl_wangyang', 'tbl_rubber_workflow', 'tbl_rubber_deduction', 'tbl_audit_log'],
    'legacy_tbl_rubber_used' => false,
  ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, 'Demo seed failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

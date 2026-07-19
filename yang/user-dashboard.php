<?php
require_once __DIR__ . '/auth.php';
require_user();
require_once __DIR__ . '/navbar.php';

$user = current_user();
$isAdmin = ($user['user_level'] ?? '') === 'admin';
$definitions = workflow_permission_definitions();
$permissionLabels = [];
foreach (user_permission_keys($user) as $key) {
  if (isset($definitions[$key])) $permissionLabels[] = $definitions[$key]['label'];
}

$price = null;
$placement = ['record_count' => 0, 'member_count' => 0, 'total_bags' => 0, 'total_weight' => 0];
$yards = [];
$weighing = ['record_count' => 0, 'pending_count' => 0, 'weighed_count' => 0, 'total_weight' => 0];
$deductions = ['record_count' => 0, 'total_gross' => 0, 'total_deduction' => 0, 'total_net' => 0];
$deductionTypes = [];
$payments = ['paid_count' => 0, 'member_count' => 0, 'total_weight' => 0, 'total_net' => 0];
$paymentRows = [];
$dbError = '';

try {
  $price = db()->query('SELECT pr_date, pr_number, pr_price FROM tbl_price ORDER BY pr_date DESC, pr_id DESC LIMIT 1')->fetch() ?: null;
  $roundDate = $price['pr_date'] ?? '';

  if ($roundDate && user_can('placement', $user)) {
    $stmt = db()->prepare('SELECT COUNT(*) record_count, COUNT(DISTINCT wang_mid) member_count, COALESCE(SUM(wang_sack),0) total_bags, COALESCE(SUM(wang_weight),0) total_weight FROM tbl_wangyang WHERE wang_date=:round_date');
    $stmt->execute(['round_date' => $roundDate]);
    $placement = array_merge($placement, $stmt->fetch() ?: []);
    $stmt = db()->prepare('SELECT w.wang_lan yard_code, COALESCE(y.yard_name,CONCAT("ลาน ",w.wang_lan)) yard_name, COUNT(*) record_count, COUNT(DISTINCT w.wang_mid) member_count, COALESCE(SUM(w.wang_sack),0) total_bags, COALESCE(SUM(w.wang_weight),0) total_weight FROM tbl_wangyang w LEFT JOIN tbl_yard y ON y.yard_code=w.wang_lan WHERE w.wang_date=:round_date GROUP BY w.wang_lan,y.yard_name ORDER BY CAST(w.wang_lan AS UNSIGNED),w.wang_lan');
    $stmt->execute(['round_date' => $roundDate]);
    $yards = $stmt->fetchAll();
  }
  if ($roundDate && user_can('weighing', $user)) {
    $stmt = db()->prepare('SELECT COUNT(*) record_count, COALESCE(SUM(workflow_status="placed"),0) pending_count, COALESCE(SUM(workflow_status IN ("weighed","deducted","paid")),0) weighed_count, COALESCE(SUM(CASE WHEN actual_weight>0 THEN actual_weight ELSE 0 END),0) total_weight FROM tbl_rubber_workflow WHERE weigh_date=:round_date');
    $stmt->execute(['round_date' => $roundDate]);
    $weighing = array_merge($weighing, $stmt->fetch() ?: []);
  }
  if ($roundDate && user_can('deductions', $user)) {
    $stmt = db()->prepare('SELECT COUNT(*) record_count, COALESCE(SUM(gross_amount),0) total_gross, COALESCE(SUM(total_deduction),0) total_deduction, COALESCE(SUM(net_amount),0) total_net FROM tbl_rubber_workflow WHERE weigh_date=:round_date AND workflow_status IN ("deducted","paid")');
    $stmt->execute(['round_date' => $roundDate]);
    $deductions = array_merge($deductions, $stmt->fetch() ?: []);
    $stmt = db()->prepare('SELECT d.deduction_label, COUNT(*) record_count, COALESCE(SUM(d.deduction_amount),0) total_amount FROM tbl_rubber_deduction d INNER JOIN tbl_rubber_workflow w ON w.workflow_id=d.workflow_id WHERE w.weigh_date=:round_date AND w.workflow_status IN ("deducted","paid") GROUP BY d.deduction_label ORDER BY total_amount DESC,d.deduction_label');
    $stmt->execute(['round_date' => $roundDate]);
    $deductionTypes = $stmt->fetchAll();
  }
  if ($roundDate && user_can('payments', $user)) {
    $stmt = db()->prepare('SELECT COUNT(*) paid_count, COUNT(DISTINCT member_id) member_count, COALESCE(SUM(actual_weight),0) total_weight, COALESCE(SUM(net_amount),0) total_net FROM tbl_rubber_workflow WHERE weigh_date=:round_date AND workflow_status="paid"');
    $stmt->execute(['round_date' => $roundDate]);
    $payments = array_merge($payments, $stmt->fetch() ?: []);
    $stmt = db()->prepare('SELECT w.receipt_no,w.member_number,w.member_name,COALESCE(y.yard_name,CONCAT("ลาน ",w.yard_code)) yard_name,w.actual_weight,w.total_deduction,w.net_amount,w.paid_at,w.paid_by FROM tbl_rubber_workflow w LEFT JOIN tbl_yard y ON y.yard_code=w.yard_code WHERE w.weigh_date=:round_date AND w.workflow_status="paid" ORDER BY w.paid_at DESC,w.workflow_id DESC LIMIT 20');
    $stmt->execute(['round_date' => $roundDate]);
    $paymentRows = $stmt->fetchAll();
  }
} catch (Exception $e) {
  error_log('Personal dashboard failed: ' . $e->getMessage());
  $dbError = db_friendly_error($e);
}

function pd_num($value) { return number_format((float) $value, 2); }
function pd_date($value) {
  $time = $value ? strtotime($value) : false;
  return $time ? date('d/m/', $time) . ((int) date('Y', $time) + 543) : '-';
}

function pd_stats($items) {
  echo '<div class="stat-grid dashboard-stat-grid">';
  foreach ($items as $item) {
    echo '<article class="stat-card ' . h($item[3] ?? '') . '"><span>' . h($item[0]) . '</span><strong>' . h($item[1]) . '</strong><small>' . h($item[2]) . '</small></article>';
  }
  echo '</div>';
}

function pd_head($icon, $tone, $title, $description) {
  echo '<div class="dashboard-section-head"><div><span class="section-icon ' . h($tone) . '"><i class="bi ' . h($icon) . '"></i></span><div><h2>' . h($title) . '</h2><p>' . h($description) . '</p></div></div><span class="readonly-badge"><i class="bi bi-lock-fill"></i>อ่านอย่างเดียว</span></div>';
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Dashboard ของฉัน</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet"><link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet">
</head>
<body>
<?php render_topbar(); ?>
<main class="ops-shell personal-dashboard">
  <section class="ops-hero"><div><h1><i class="bi bi-person-workspace me-2"></i>Dashboard ของฉัน</h1><p><?php echo h($user['user_fullname']); ?> · แสดงข้อมูลตามสิทธิ์ที่ได้รับ</p></div><span class="pill"><i class="bi bi-calendar3 me-1"></i>รอบวันที่ราคา <?php echo $price ? pd_date($price['pr_date']) : '-'; ?></span></section>
  <div class="readonly-notice"><i class="bi bi-eye-fill"></i><div><strong>หน้านี้ใช้สำหรับตรวจสอบข้อมูลเท่านั้น</strong><span>ไม่มีการแก้ไข บันทึก อนุมัติ หรือจ่ายเงินจาก Dashboard นี้</span></div></div>
  <?php if ($dbError): ?><div class="alert alert-danger mt-3"><?php echo h($dbError); ?></div><?php endif; ?>
  <section class="access-overview"><span class="access-overview-label">สิทธิ์ข้อมูลของบัญชีนี้</span><div class="permission-chip-list">
    <?php if ($isAdmin): ?><span class="permission-chip admin"><i class="bi bi-shield-check"></i>ผู้ดูแลระบบ · เข้าถึงทั้งหมด</span>
    <?php elseif ($permissionLabels): foreach ($permissionLabels as $label): ?><span class="permission-chip"><i class="bi bi-check-circle-fill"></i><?php echo h($label); ?></span><?php endforeach;
    else: ?><span class="permission-chip empty-chip"><i class="bi bi-info-circle"></i>ยังไม่ได้รับสิทธิ์ขั้นตอนการทำงาน</span><?php endif; ?>
  </div></section>

  <section class="dashboard-data-section" data-section="price">
    <?php pd_head('bi-tags-fill', 'rose', 'ราคายางรอบล่าสุด', 'รอบของระบบอ้างอิงตามวันที่กำหนดราคายาง'); ?>
    <?php if ($price): ?><?php pd_stats([['วันที่ของรอบ', pd_date($price['pr_date']), 'วันที่ราคายาง'], ['ราคาต่อกิโลกรัม', pd_num($price['pr_price']), 'บาท / kg', 'accent-green'], ['ลำดับราคา', $price['pr_number'] ?: '-', 'เลขอ้างอิงในระบบ']]); ?>
    <?php else: ?><div class="empty-state">ยังไม่มีการกำหนดราคายาง จึงยังไม่สามารถแสดงข้อมูลรอบล่าสุดได้</div><?php endif; ?>
  </section>

  <?php if (user_can('placement', $user)): ?><section class="dashboard-data-section" data-section="placement">
    <?php pd_head('bi-box-seam-fill', 'violet', 'การรวมยางรอบล่าสุด', 'ข้อมูลการวางยางของรอบวันที่ราคาล่าสุด');
    pd_stats([['รายการวางยาง', number_format((int)$placement['record_count']), 'รายการ'], ['สมาชิก', number_format((int)$placement['member_count']), 'ราย'], ['จำนวนกระสอบ', pd_num($placement['total_bags']), 'กระสอบ'], ['น้ำหนักประมาณการ', pd_num($placement['total_weight']), 'kg']]); ?>
    <?php if ($yards): ?><div class="table-responsive dashboard-table"><table class="table mb-0"><thead><tr><th>ลาน</th><th class="num">รายการ</th><th class="num">สมาชิก</th><th class="num">กระสอบ</th><th class="num">น้ำหนักประมาณการ (kg)</th></tr></thead><tbody><?php foreach ($yards as $row): ?><tr><td><strong><?php echo h($row['yard_name']); ?></strong><small class="d-block text-secondary">รหัส <?php echo h($row['yard_code']); ?></small></td><td class="num"><?php echo number_format((int)$row['record_count']); ?></td><td class="num"><?php echo number_format((int)$row['member_count']); ?></td><td class="num"><?php echo pd_num($row['total_bags']); ?></td><td class="num"><?php echo pd_num($row['total_weight']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state compact">ยังไม่มีข้อมูลวางยางในรอบนี้</div><?php endif; ?>
  </section><?php endif; ?>

  <?php if (user_can('weighing', $user)): ?><section class="dashboard-data-section" data-section="weighing">
    <?php pd_head('bi-speedometer2', 'blue', 'สถานะการชั่งยาง', 'ภาพรวมการชั่งของรอบล่าสุดโดยไม่เปิดให้แก้ไขน้ำหนัก');
    pd_stats([['รายการในรอบ', number_format((int)$weighing['record_count']), 'รายการ'], ['ชั่งแล้ว', number_format((int)$weighing['weighed_count']), 'รวมขั้นตอนถัดไป'], ['รอชั่ง', number_format((int)$weighing['pending_count']), 'รายการ'], ['น้ำหนักจริงรวม', pd_num($weighing['total_weight']), 'kg']]); ?>
  </section><?php endif; ?>

  <?php if (user_can('deductions', $user)): ?><section class="dashboard-data-section" data-section="deductions">
    <?php pd_head('bi-receipt-cutoff', 'amber', 'รายการหัก', 'ยอดรวมของรายการที่บันทึกยอดหักแล้วในรอบล่าสุด');
    pd_stats([['รายการที่คำนวณแล้ว', number_format((int)$deductions['record_count']), 'รายการ'], ['ยอดค่ายาง', pd_num($deductions['total_gross']), 'บาท'], ['ยอดหักรวม', pd_num($deductions['total_deduction']), 'บาท', 'accent-amber'], ['ยอดสุทธิหลังหัก', pd_num($deductions['total_net']), 'บาท']]); ?>
    <?php if ($deductionTypes): ?><div class="table-responsive dashboard-table narrow-table"><table class="table mb-0"><thead><tr><th>ประเภทรายการหัก</th><th class="num">จำนวนรายการ</th><th class="num">ยอดรวม (บาท)</th></tr></thead><tbody><?php foreach ($deductionTypes as $row): ?><tr><td><strong><?php echo h($row['deduction_label']); ?></strong></td><td class="num"><?php echo number_format((int)$row['record_count']); ?></td><td class="num"><?php echo pd_num($row['total_amount']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state compact">ยังไม่มีรายละเอียดรายการหักในรอบนี้</div><?php endif; ?>
  </section><?php endif; ?>

  <?php if (user_can('payments', $user)): ?><section class="dashboard-data-section" data-section="payments">
    <?php pd_head('bi-cash-coin', 'green', 'ยอดการชำระเงินแล้ว', 'รายการที่อนุมัติจ่ายเงินแล้วในรอบล่าสุด');
    pd_stats([['จ่ายแล้ว', number_format((int)$payments['paid_count']), 'รายการ'], ['สมาชิกที่รับเงิน', number_format((int)$payments['member_count']), 'ราย'], ['น้ำหนักรวม', pd_num($payments['total_weight']), 'kg'], ['ยอดจ่ายสุทธิ', pd_num($payments['total_net']), 'บาท', 'accent-green']]); ?>
    <?php if ($paymentRows): ?><div class="table-responsive dashboard-table"><table class="table mb-0"><thead><tr><th>ใบเสร็จ</th><th>สมาชิก</th><th>ลาน</th><th class="num">น้ำหนัก (kg)</th><th class="num">ยอดหัก</th><th class="num">ยอดจ่ายสุทธิ</th><th>จ่ายเมื่อ / โดย</th></tr></thead><tbody><?php foreach ($paymentRows as $row): ?><tr><td><strong><?php echo h($row['receipt_no'] ?: '-'); ?></strong></td><td><?php echo h($row['member_name']); ?><small class="d-block text-secondary"><?php echo h($row['member_number']); ?></small></td><td><?php echo h($row['yard_name']); ?></td><td class="num"><?php echo pd_num($row['actual_weight']); ?></td><td class="num"><?php echo pd_num($row['total_deduction']); ?></td><td class="num"><strong><?php echo pd_num($row['net_amount']); ?></strong></td><td><?php echo h($row['paid_at'] ?: '-'); ?><small class="d-block text-secondary"><?php echo h($row['paid_by'] ?: '-'); ?></small></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state compact">ยังไม่มีรายการชำระเงินแล้วในรอบนี้</div><?php endif; ?>
  </section><?php endif; ?>

  <?php if (!$isAdmin && !$permissionLabels): ?><section class="dashboard-data-section no-access"><i class="bi bi-shield-exclamation"></i><h2>ยังไม่มีข้อมูลขั้นตอนเพิ่มเติม</h2><p>Admin สามารถกำหนดสิทธิ์ วางยาง ชั่งยาง รายการหัก หรือจ่ายเงินให้บัญชีนี้ได้</p></section><?php endif; ?>
</main>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/workflow.php';
require_member();

$member = current_member();
$rows = [];
$rounds = [];
$deductionMap = [];
$totals = ['rounds' => 0, 'weight' => 0, 'gross' => 0, 'deduction' => 0, 'net' => 0, 'paid' => 0];
$dbError = '';

try {
  $stmt = db()->prepare('SELECT w.*, COALESCE(y.yard_name, CONCAT("ลาน ", w.yard_code)) AS yard_name,
      COALESCE(NULLIF(w.price_per_kg, 0), p.pr_price, 0) AS round_price
    FROM tbl_rubber_workflow w
    LEFT JOIN tbl_yard y ON y.yard_code = w.yard_code
    LEFT JOIN tbl_price p ON p.pr_date = w.weigh_date
      AND p.pr_id = (SELECT MAX(p2.pr_id) FROM tbl_price p2 WHERE p2.pr_date = w.weigh_date)
    WHERE w.member_id = :member_id OR w.member_number = :member_number
    ORDER BY w.weigh_date DESC, w.workflow_id DESC
    LIMIT 200');
  $stmt->execute(['member_id' => (int) $member['mem_id'], 'member_number' => $member['mem_number']]);
  $rows = $stmt->fetchAll();

  $workflowIds = array_map(function ($row) { return (int) $row['workflow_id']; }, $rows);
  if ($workflowIds) {
    $placeholders = implode(',', array_fill(0, count($workflowIds), '?'));
    $stmt = db()->prepare('SELECT workflow_id, deduction_label, deduction_amount
      FROM tbl_rubber_deduction WHERE workflow_id IN (' . $placeholders . ') AND deduction_amount > 0
      ORDER BY workflow_id, sort_order, deduction_id');
    $stmt->execute($workflowIds);
    foreach ($stmt->fetchAll() as $deduction) {
      $deductionMap[(int) $deduction['workflow_id']][] = $deduction;
    }
  }

  foreach ($rows as $row) {
    $date = $row['weigh_date'];
    if (!isset($rounds[$date])) {
      $rounds[$date] = ['date' => $date, 'price' => (float) $row['round_price'], 'items' => []];
    }
    $row['deductions'] = $deductionMap[(int) $row['workflow_id']] ?? [];
    $rounds[$date]['items'][] = $row;
    $totals['weight'] += (float) $row['actual_weight'];
    if (in_array($row['workflow_status'], ['deducted', 'paid'], true)) {
      $totals['gross'] += (float) $row['gross_amount'];
      $totals['deduction'] += (float) $row['total_deduction'];
      $totals['net'] += (float) $row['net_amount'];
    }
    if ($row['workflow_status'] === 'paid') $totals['paid'] += (float) $row['net_amount'];
  }
  $totals['rounds'] = count($rounds);
} catch (Throwable $e) {
  error_log('All member rounds failed: ' . $e->getMessage());
  $dbError = db_friendly_error($e);
}

function am_money($value) { return number_format((float) $value, 2); }
function am_thai_date($date) {
  if (!$date) return '-';
  $months = [1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  $time = strtotime($date);
  return (int) date('j', $time) . ' ' . $months[(int) date('n', $time)] . ' ' . ((int) date('Y', $time) + 543);
}
function am_status($status) {
  $map = [
    'placed' => ['รอชั่ง', 'wait', 'bi-box-seam'],
    'weighed' => ['ชั่งแล้ว', 'weighed', 'bi-speedometer2'],
    'deducted' => ['สรุปยอดแล้ว', 'deducted', 'bi-receipt-cutoff'],
    'paid' => ['จ่ายเงินแล้ว', 'paid', 'bi-check-circle-fill'],
  ];
  return $map[$status] ?? [$status, 'wait', 'bi-clock'];
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#0e5137">
  <title>ข้อมูลขายยางของฉัน</title>
  <link href="typography.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{--green:#16734c;--green-dark:#0e5137;--mint:#e8f5ed;--ink:#17241e;--muted:#6b7971;--line:#dce5df;--bg:#f2f6f3;--paper:#fff;--amber:#a96500;--red:#b42318}
    *{box-sizing:border-box}body{margin:0;font-family:"Sarabun",sans-serif;color:var(--ink);background:var(--bg)}a{color:inherit;text-decoration:none}.app-shell{width:min(760px,100%);min-height:100vh;margin:auto;padding-bottom:36px;background:var(--bg)}
    .app-topbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:66px;padding:10px 18px;color:#fff;background:rgba(14,81,55,.97);backdrop-filter:blur(12px)}.app-brand{display:flex;align-items:center;gap:10px;min-width:0}.app-mark{width:40px;height:40px;display:grid;flex:0 0 auto;place-items:center;border-radius:12px;color:var(--green-dark);background:#fff;font-weight:900}.app-brand strong,.app-brand small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.app-brand strong{font-size:16px}.app-brand small{color:rgba(255,255,255,.72);font-size:11px}.logout-btn{width:40px;height:40px;display:grid;flex:0 0 auto;place-items:center;border:1px solid rgba(255,255,255,.25);border-radius:11px;color:#fff;background:rgba(255,255,255,.1);font-size:18px}
    .member-head{padding:24px 18px 74px;color:#fff;background:linear-gradient(140deg,#0e5137,#1b7d55)}.member-line{display:flex;align-items:center;gap:12px}.member-avatar{width:54px;height:54px;display:grid;flex:0 0 auto;place-items:center;border:2px solid rgba(255,255,255,.4);border-radius:18px;background:rgba(255,255,255,.15);font-size:25px}.member-line h1{margin:0;font-size:22px}.member-line p{margin:3px 0 0;color:rgba(255,255,255,.75);font-size:13px}.member-head-note{margin:18px 0 0;color:rgba(255,255,255,.78);font-size:13px}
    .content{padding:0 14px}.wallet{margin-top:-54px;padding:20px;border:1px solid rgba(255,255,255,.5);border-radius:20px;color:#fff;background:linear-gradient(135deg,#1d7a54,#0b5437);box-shadow:0 14px 30px rgba(14,81,55,.2)}.wallet-label{display:flex;align-items:center;justify-content:space-between;color:rgba(255,255,255,.75);font-size:13px}.wallet-balance{display:block;margin-top:8px;font-size:32px;line-height:1;font-weight:800}.wallet-balance small{font-size:14px}.wallet-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:20px}.wallet-grid div{padding:10px;border-radius:12px;background:rgba(255,255,255,.1)}.wallet-grid span,.wallet-grid strong{display:block}.wallet-grid span{color:rgba(255,255,255,.68);font-size:10px}.wallet-grid strong{margin-top:4px;font-size:13px}
    .quick-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}.quick-stat{padding:13px 8px;border:1px solid var(--line);border-radius:14px;background:#fff;text-align:center}.quick-stat i{display:block;margin-bottom:5px;color:var(--green);font-size:18px}.quick-stat strong,.quick-stat span{display:block}.quick-stat strong{font-size:17px}.quick-stat span{margin-top:2px;color:var(--muted);font-size:10px}
    .section-head{display:flex;align-items:end;justify-content:space-between;gap:12px;margin:24px 2px 10px}.section-head h2{margin:0;font-size:19px}.section-head p{margin:3px 0 0;color:var(--muted);font-size:12px}.round-count{padding:5px 9px;border-radius:999px;color:var(--green);background:var(--mint);font-size:11px;font-weight:800}
    .round-group{margin-bottom:14px}.round-date{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 3px 7px}.round-date strong{font-size:14px}.round-price{color:var(--green);font-size:12px;font-weight:800}.sale-card{margin-bottom:10px;border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 6px 18px rgba(23,54,39,.05);overflow:hidden}.sale-main{padding:16px}.sale-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.yard{display:flex;align-items:center;gap:9px}.yard-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;color:var(--green);background:var(--mint)}.yard strong,.yard small{display:block}.yard small{margin-top:2px;color:var(--muted);font-size:11px}.status{display:inline-flex;align-items:center;gap:5px;padding:6px 8px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap}.status.wait{color:#8a5b00;background:#fff2cc}.status.weighed{color:#075985;background:#e0f2fe}.status.deducted{color:#6b21a8;background:#f3e8ff}.status.paid{color:#12633f;background:#dcfce7}
    .rubber-info{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:14px}.rubber-info div{padding:9px;border-radius:10px;background:#f5f8f6}.rubber-info span,.rubber-info strong{display:block}.rubber-info span{color:var(--muted);font-size:10px}.rubber-info strong{margin-top:3px;font-size:13px}.financial{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid var(--line);background:#fbfdfc}.financial div{padding:12px 9px;text-align:center}.financial div+div{border-left:1px solid var(--line)}.financial span,.financial strong{display:block}.financial span{color:var(--muted);font-size:10px}.financial strong{margin-top:4px;font-size:14px}.financial .deduct strong{color:var(--red)}.financial .net strong{color:var(--green)}
    .deduction-box{padding:12px 16px;border-top:1px solid var(--line);background:#fffaf2}.deduction-box-title{margin-bottom:6px;color:var(--amber);font-size:11px;font-weight:800}.deduction-row{display:flex;justify-content:space-between;gap:12px;padding:5px 0;font-size:12px}.deduction-row strong{color:var(--red)}.receipt-btn{display:flex;align-items:center;justify-content:center;gap:7px;margin:0 16px 15px;padding:10px;border:1px solid #aad2ba;border-radius:11px;color:var(--green);background:#eff9f3;font-size:13px;font-weight:800}.pending-note{padding:11px 16px;border-top:1px solid var(--line);color:var(--muted);background:#fafcfb;font-size:11px;text-align:center}
    .empty,.error{margin-top:14px;padding:28px 18px;border:1px solid var(--line);border-radius:17px;background:#fff;text-align:center}.empty i{display:block;margin-bottom:8px;color:#9aaba1;font-size:36px}.empty strong,.empty span{display:block}.empty span{margin-top:4px;color:var(--muted);font-size:12px}.error{color:#9f1239;background:#fff1f2}.privacy-note{display:flex;align-items:flex-start;gap:8px;margin:18px 4px 0;color:var(--muted);font-size:11px;line-height:1.5}
    @media(min-width:640px){.content{padding:0 22px}.member-head{padding-left:28px;padding-right:28px}.sale-main{padding:18px}.wallet-grid strong{font-size:15px}}
  </style>
</head>
<body>
<div class="app-shell">
  <header class="app-topbar"><a class="app-brand" href="<?php echo h(url_for('index.php')); ?>"><span class="app-mark">ย</span><span><strong><?php echo h(system_name()); ?></strong><small><?php echo h(cooperative_name()); ?></small></span></a><a class="logout-btn" href="<?php echo h(url_for('logout.php')); ?>" aria-label="ออกจากระบบ"><i class="bi bi-box-arrow-right"></i></a></header>
  <section class="member-head"><div class="member-line"><span class="member-avatar"><i class="bi bi-person-fill"></i></span><div><h1><?php echo h($member['mem_fullname']); ?></h1><p>สมาชิกเลขที่ <?php echo h($member['mem_number']); ?> · กลุ่ม <?php echo h($member['mem_group']); ?></p></div></div><p class="member-head-note">ข้อมูลการขายยาง รายรับ และรายการหักของคุณ</p></section>
  <main class="content">
    <section class="wallet"><div class="wallet-label"><span>ยอดคงเหลือสุทธิทั้งหมด</span><i class="bi bi-wallet2"></i></div><strong class="wallet-balance"><?php echo am_money($totals['net']); ?> <small>บาท</small></strong><div class="wallet-grid"><div><span>รายรับรวม</span><strong><?php echo am_money($totals['gross']); ?></strong></div><div><span>รายการหักรวม</span><strong><?php echo am_money($totals['deduction']); ?></strong></div><div><span>จ่ายแล้ว</span><strong><?php echo am_money($totals['paid']); ?></strong></div></div></section>
    <section class="quick-stats"><article class="quick-stat"><i class="bi bi-calendar2-week"></i><strong><?php echo number_format($totals['rounds']); ?></strong><span>รอบราคายาง</span></article><article class="quick-stat"><i class="bi bi-speedometer2"></i><strong><?php echo am_money($totals['weight']); ?></strong><span>น้ำหนักจริง kg</span></article><article class="quick-stat"><i class="bi bi-receipt"></i><strong><?php echo number_format(count($rows)); ?></strong><span>รายการขายยาง</span></article></section>
    <?php if ($dbError): ?><div class="error"><i class="bi bi-exclamation-triangle me-1"></i><?php echo h($dbError); ?></div><?php endif; ?>
    <div class="section-head"><div><h2>รายการขายยางแต่ละรอบ</h2><p>เรียงตามวันที่ราคายางล่าสุด</p></div><span class="round-count"><?php echo number_format(count($rounds)); ?> รอบ</span></div>
    <?php foreach ($rounds as $round): ?><section class="round-group"><div class="round-date"><strong><i class="bi bi-calendar3 me-1"></i><?php echo h(am_thai_date($round['date'])); ?></strong><span class="round-price">ราคา <?php echo am_money($round['price']); ?> บาท/kg</span></div>
      <?php foreach ($round['items'] as $row): $status = am_status($row['workflow_status']); $ready = in_array($row['workflow_status'], ['deducted', 'paid'], true); ?>
      <article class="sale-card"><div class="sale-main"><div class="sale-top"><div class="yard"><span class="yard-icon"><i class="bi bi-geo-alt-fill"></i></span><span><strong><?php echo h($row['yard_name']); ?></strong><small><?php echo h($row['receipt_no'] ?: 'รายการ #' . $row['workflow_id']); ?></small></span></div><span class="status <?php echo h($status[1]); ?>"><i class="bi <?php echo h($status[2]); ?>"></i><?php echo h($status[0]); ?></span></div><div class="rubber-info"><div><span>กระสอบ</span><strong><?php echo number_format((float) $row['total_bags'], 0); ?></strong></div><div><span>น้ำหนักจริง</span><strong><?php echo (float) $row['actual_weight'] > 0 ? am_money($row['actual_weight']) : '-'; ?> kg</strong></div><div><span>ราคา/kg</span><strong><?php echo am_money($row['round_price']); ?></strong></div></div></div>
        <?php if ($ready): ?><div class="financial"><div><span>รายรับ</span><strong><?php echo am_money($row['gross_amount']); ?></strong></div><div class="deduct"><span>รายการหัก</span><strong>-<?php echo am_money($row['total_deduction']); ?></strong></div><div class="net"><span>ยอดคงเหลือ</span><strong><?php echo am_money($row['net_amount']); ?></strong></div></div>
          <?php if ($row['deductions']): ?><div class="deduction-box"><div class="deduction-box-title"><i class="bi bi-list-check me-1"></i>รายละเอียดรายการหัก</div><?php foreach ($row['deductions'] as $item): ?><div class="deduction-row"><span><?php echo h($item['deduction_label']); ?></span><strong>-<?php echo am_money($item['deduction_amount']); ?> บาท</strong></div><?php endforeach; ?></div><?php endif; ?>
          <a class="receipt-btn" href="<?php echo h(url_for('receipt.php?id=' . (int) $row['workflow_id'])); ?>"><i class="bi bi-receipt"></i>ดูรายละเอียดใบรับเงิน</a>
        <?php else: ?><div class="pending-note"><i class="bi bi-info-circle me-1"></i>ยอดรายรับและรายการหักจะแสดงเมื่อเจ้าหน้าที่สรุปยอดแล้ว</div><?php endif; ?>
      </article><?php endforeach; ?>
    </section><?php endforeach; ?>
    <?php if (!$rounds && !$dbError): ?><div class="empty"><i class="bi bi-inbox"></i><strong>ยังไม่มีรายการขายยาง</strong><span>เมื่อมีการวางและชั่งยาง รายการจะแสดงที่หน้านี้</span></div><?php endif; ?>
    <div class="privacy-note"><i class="bi bi-shield-lock-fill"></i><span>หน้านี้แสดงเฉพาะข้อมูลของสมาชิกที่เข้าสู่ระบบ และไม่สามารถแก้ไขข้อมูลการเงินได้</span></div>
  </main>
</div>
</body>
</html>

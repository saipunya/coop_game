<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/workflow.php';
require_member();

$member = current_member();
$stats = [
  'total_quantity' => 0,
  'total_value' => 0,
  'total_deduct' => 0,
  'total_net' => 0,
  'record_count' => 0,
];
$bagStats = ['total_bags' => 0, 'estimated_weight' => 0, 'record_count' => 0];
$bagRows = [];
$workflowRows = [];
$latestPrice = null;
$dbError = '';

try {
  sync_workflow_records();
  $stmt = db()->prepare('
    SELECT
      COALESCE(SUM(CASE WHEN actual_weight > 0 THEN actual_weight ELSE 0 END), 0) AS total_quantity,
      COALESCE(SUM(gross_amount), 0) AS total_value,
      COALESCE(SUM(total_deduction), 0) AS total_deduct,
      COALESCE(SUM(net_amount), 0) AS total_net,
      COUNT(*) AS record_count
    FROM tbl_rubber_workflow
    WHERE member_id = :member_id OR member_number = :mem_number
  ');
  $stmt->execute(['member_id' => $member['mem_id'], 'mem_number' => $member['mem_number']]);
  $stats = $stmt->fetch() ?: $stats;

  $stmt = db()->query('
    SELECT pr_date, pr_number, pr_price
    FROM tbl_price
    ORDER BY pr_date DESC, pr_id DESC
    LIMIT 1
  ');
  $latestPrice = $stmt->fetch();

  $stmt = db()->prepare('SELECT COALESCE(SUM(wang_sack), 0) AS total_bags,
    COALESCE(SUM(wang_weight), 0) AS estimated_weight, COUNT(*) AS record_count
    FROM tbl_wangyang WHERE wang_mid = :member_id OR wang_number = :mem_number');
  $stmt->execute(['member_id' => $member['mem_id'], 'mem_number' => $member['mem_number']]);
  $bagStats = $stmt->fetch() ?: $bagStats;

  $stmt = db()->prepare('SELECT wang_date, wang_lan, wang_sack, wang_weight, wang_status, wang_note
    FROM tbl_wangyang WHERE wang_mid = :member_id OR wang_number = :mem_number
    ORDER BY wang_date DESC, wang_id DESC LIMIT 10');
  $stmt->execute(['member_id' => $member['mem_id'], 'mem_number' => $member['mem_number']]);
  $bagRows = $stmt->fetchAll();

  $stmt = db()->prepare('SELECT workflow_id, weigh_date, yard_code, total_bags, actual_weight,
    gross_amount, total_deduction, net_amount, workflow_status, receipt_no
    FROM tbl_rubber_workflow WHERE member_id = :member_id OR member_number = :mem_number
    ORDER BY weigh_date DESC, workflow_id DESC LIMIT 20');
  $stmt->execute(['member_id' => $member['mem_id'], 'mem_number' => $member['mem_number']]);
  $workflowRows = $stmt->fetchAll();
} catch (Exception $e) {
  error_log('Member dashboard failed: ' . $e->getMessage());
  $dbError = db_friendly_error($e);
}

function money($value)
{
  return number_format((float) $value, 2);
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>หน้าสมาชิก</title>
  <link href="typography.css" rel="stylesheet">
  <style>
    :root {
      --bg: #f2f5f3;
      --paper: #ffffff;
      --ink: #17212f;
      --muted: #667085;
      --line: #d8e0e6;
      --green: #1d7a54;
      --green-dark: #0f5138;
      --red: #bd3f3f;
    }

    * { box-sizing: border-box; }
    body { margin: 0; font-family: var(--font-family-sans); background: var(--bg); color: var(--ink); }
    a { color: inherit; text-decoration: none; }
    .shell { width: min(1120px, calc(100% - 28px)); margin: 22px auto 44px; }
    .topbar {
      min-height: 68px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      padding: 14px 18px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: #fff;
    }
    .brand { display: flex; align-items: center; gap: 12px; }
    .mark {
      width: 42px;
      height: 42px;
      display: grid;
      place-items: center;
      border-radius: 8px;
      background: var(--green-dark);
      color: #fff;
      font-weight: 900;
    }
    .brand strong { display: block; font-size: 18px; }
    .brand span { display: block; margin-top: 3px; color: var(--muted); font-size: 13px; }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 38px;
      padding: 0 13px;
      border: 1px solid var(--line);
      border-radius: 6px;
      background: #fff;
      font-weight: 800;
      font-size: 14px;
    }
    .btn-primary { background: var(--green); border-color: var(--green); color: #fff; }
    .hero {
      margin-top: 18px;
      padding: 26px;
      border-radius: 8px;
      background: linear-gradient(135deg, #0f5138, #1d7a54);
      color: #fff;
    }
    .hero h1 { margin: 0 0 8px; font-size: 30px; letter-spacing: 0; }
    .hero p { margin: 0; color: rgba(255, 255, 255, 0.84); line-height: 1.6; }
    .price { margin-top: 16px; font-weight: 800; }
    .kpis {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-top: 18px;
    }
    .kpi {
      min-height: 132px;
      padding: 17px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--paper);
    }
    .kpi span { color: var(--muted); font-size: 13px; }
    .kpi strong { display: block; margin-top: 12px; font-size: 26px; line-height: 1; }
    .kpi small { display: block; margin-top: 8px; color: var(--muted); }
    .card {
      margin-top: 18px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--paper);
      overflow: hidden;
    }
    .card-head {
      min-height: 58px;
      padding: 16px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      border-bottom: 1px solid var(--line);
      background: #fbfcfd;
    }
    .card-head h2 { margin: 0; font-size: 18px; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { padding: 14px 16px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
    th { color: var(--muted); background: #fbfcfd; font-size: 14px; font-weight: 800; }
    tr:last-child td { border-bottom: 0; }
    .num { text-align: right; }
    .empty { padding: 26px 18px; color: var(--muted); line-height: 1.55; }
    .status { display: inline-block; padding: 5px 9px; border-radius: 999px; color: #175c40; background: #e3f2e9; font-size: 13px; font-weight: 800; }
    .receipt-link { color: var(--green); font-weight: 900; }
    .alert {
      margin-top: 18px;
      padding: 13px 14px;
      border: 1px solid #f1b8b8;
      border-radius: 8px;
      background: #fff1f1;
      color: var(--red);
    }
    @media (max-width: 820px) {
      .topbar { align-items: flex-start; flex-direction: column; }
      .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 560px) {
      .shell { width: min(100% - 20px, 1120px); }
      .kpis { grid-template-columns: 1fr; }
      .hero h1 { font-size: 25px; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <a class="brand" href="<?php echo h(url_for('index.php')); ?>">
        <div class="mark">ย</div>
        <div>
          <strong><?php echo h(system_name()); ?></strong>
          <span><?php echo h(cooperative_name()); ?> · พื้นที่สมาชิก</span>
        </div>
      </a>
      <div>
        <a class="btn" href="<?php echo h(url_for('index.php')); ?>">ภาพรวม</a>
        <a class="btn btn-primary" href="<?php echo h(url_for('logout.php')); ?>">ออกจากระบบ</a>
      </div>
    </header>

    <section class="hero">
      <h1>สวัสดี <?php echo h($member['mem_fullname']); ?></h1>
      <p>เลขสมาชิก <?php echo h($member['mem_number']); ?> · กลุ่ม <?php echo h($member['mem_group']); ?> · ตรวจสอบประวัติส่งยางและยอดรับเงินของคุณ</p>
      <?php if ($latestPrice): ?>
        <div class="price">ราคาล่าสุด <?php echo h($latestPrice['pr_price']); ?> บาท/kg · วันที่ <?php echo h($latestPrice['pr_date']); ?></div>
      <?php endif; ?>
    </section>

    <?php if ($dbError): ?>
      <div class="alert"><?php echo h($dbError); ?></div>
    <?php endif; ?>

    <section class="kpis">
      <article class="kpi">
        <span>จำนวนรายการ</span>
        <strong><?php echo number_format((int) $stats['record_count']); ?></strong>
        <small>ครั้ง</small>
      </article>
      <article class="kpi">
        <span>ปริมาณรวม</span>
        <strong><?php echo money($stats['total_quantity']); ?></strong>
        <small>kg</small>
      </article>
      <article class="kpi">
        <span>ยอดหักรวม</span>
        <strong><?php echo money($stats['total_deduct']); ?></strong>
        <small>บาท</small>
      </article>
      <article class="kpi">
        <span>ยอดสุทธิรวม</span>
        <strong><?php echo money($stats['total_net']); ?></strong>
        <small>บาท</small>
      </article>
    </section>

    <section class="card">
      <div class="card-head">
        <h2>สถานะรอบยางและการรับเงิน</h2>
      </div>
      <?php if ($workflowRows): ?>
        <div class="table-wrap"><table><thead><tr><th>วันชั่ง</th><th>ลาน</th><th class="num">กระสอบ</th><th class="num">น้ำหนักจริง</th><th class="num">ยอดหัก</th><th class="num">ยอดสุทธิ</th><th>สถานะ</th><th>เอกสาร</th></tr></thead><tbody>
          <?php foreach ($workflowRows as $row): ?><tr><td><?php echo h($row['weigh_date']); ?></td><td><?php echo h($row['yard_code']); ?></td><td class="num"><?php echo number_format((float) $row['total_bags'], 0); ?></td><td class="num"><?php echo (float) $row['actual_weight'] > 0 ? money($row['actual_weight']) . ' kg' : '-'; ?></td><td class="num"><?php echo money($row['total_deduction']); ?></td><td class="num"><?php echo money($row['net_amount']); ?></td><td><span class="status"><?php echo h(workflow_status_label($row['workflow_status'])); ?></span></td><td><?php if (in_array($row['workflow_status'], ['deducted', 'paid'], true)): ?><a class="receipt-link" target="_blank" href="<?php echo h(url_for('receipt.php?id=' . (int) $row['workflow_id'])); ?>">ใบเสร็จ</a><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?><div class="empty">ยังไม่มีรายการในกระบวนการชั่งยาง</div><?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <h2>สรุปยางที่นำมาวาง</h2>
        <strong><?php echo number_format((float) $bagStats['total_bags'], 0); ?> กระสอบ · ประมาณ <?php echo money($bagStats['estimated_weight']); ?> kg</strong>
      </div>
      <?php if ($bagRows): ?>
        <div class="table-wrap"><table><thead><tr><th>วันช่องยาง</th><th>ลาน</th><th class="num">กระสอบ</th><th class="num">น้ำหนักประมาณการ</th><th>หมายเหตุ</th></tr></thead><tbody>
          <?php foreach ($bagRows as $row): ?><tr><td><?php echo h($row['wang_date']); ?></td><td><?php echo h($row['wang_lan']); ?></td><td class="num"><?php echo number_format((float) $row['wang_sack'], 0); ?></td><td class="num"><?php echo money($row['wang_weight']); ?> kg</td><td><?php echo h($row['wang_note']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?><div class="empty">ยังไม่มีรายการวางยางล่วงหน้าของสมาชิก</div><?php endif; ?>
    </section>

  </main>
</body>
</html>

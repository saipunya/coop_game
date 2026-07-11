<?php
require_once __DIR__ . '/auth.php';
require_member();

$member = current_member();
$stats = [
  'total_quantity' => 0,
  'total_value' => 0,
  'total_deduct' => 0,
  'total_net' => 0,
  'record_count' => 0,
];
$latestRows = [];
$latestPrice = null;
$dbError = '';

try {
  $stmt = db()->prepare('
    SELECT
      COALESCE(SUM(ru_quantity), 0) AS total_quantity,
      COALESCE(SUM(ru_value), 0) AS total_value,
      COALESCE(SUM(ru_expend), 0) AS total_deduct,
      COALESCE(SUM(ru_netvalue), 0) AS total_net,
      COUNT(*) AS record_count
    FROM tbl_rubber
    WHERE ru_number = :mem_number AND ru_class = "member"
  ');
  $stmt->execute(['mem_number' => $member['mem_number']]);
  $stats = $stmt->fetch() ?: $stats;

  $stmt = db()->prepare('
    SELECT ru_date, ru_lan, ru_quantity, ru_value, ru_expend, ru_netvalue
    FROM tbl_rubber
    WHERE ru_number = :mem_number AND ru_class = "member"
    ORDER BY ru_date DESC, ru_id DESC
    LIMIT 10
  ');
  $stmt->execute(['mem_number' => $member['mem_number']]);
  $latestRows = $stmt->fetchAll();

  $stmt = db()->query('
    SELECT pr_date, pr_number, pr_price
    FROM tbl_price
    ORDER BY pr_date DESC, pr_id DESC
    LIMIT 1
  ');
  $latestPrice = $stmt->fetch();
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
    body { margin: 0; font-family: Arial, Tahoma, sans-serif; background: var(--bg); color: var(--ink); }
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
    th { color: var(--muted); background: #fbfcfd; font-size: 12px; font-weight: 900; }
    tr:last-child td { border-bottom: 0; }
    .num { text-align: right; }
    .empty { padding: 26px 18px; color: var(--muted); line-height: 1.55; }
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
          <strong>ระบบรวบรวมยาง</strong>
          <span>พื้นที่สมาชิก</span>
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
        <div class="price">ราคาล่าสุด <?php echo h($latestPrice['pr_price']); ?> บาท/kg · วันที่ <?php echo h($latestPrice['pr_date']); ?> · รอบ <?php echo h($latestPrice['pr_number']); ?></div>
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
        <h2>รายการส่งยางล่าสุด</h2>
      </div>
      <?php if ($latestRows): ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>วันที่</th>
                <th>ลาน</th>
                <th class="num">ปริมาณ</th>
                <th class="num">ยอดเงิน</th>
                <th class="num">ยอดหัก</th>
                <th class="num">สุทธิ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($latestRows as $row): ?>
                <tr>
                  <td><?php echo h($row['ru_date']); ?></td>
                  <td><?php echo h($row['ru_lan']); ?></td>
                  <td class="num"><?php echo money($row['ru_quantity']); ?></td>
                  <td class="num"><?php echo money($row['ru_value']); ?></td>
                  <td class="num"><?php echo money($row['ru_expend']); ?></td>
                  <td class="num"><?php echo money($row['ru_netvalue']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="empty">ยังไม่พบประวัติการส่งยางของสมาชิกเลขนี้ในตาราง tbl_rubber</div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>

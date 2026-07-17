<?php
require_once __DIR__ . '/auth.php';
require_user();
require_once __DIR__ . '/navbar.php';

$user = current_user();
$stats = [
  'today_count' => 0,
  'today_quantity' => 0,
  'today_net' => 0,
  'total_saved' => 0,
];
$recentRows = [];
$dbError = '';

try {
  $stmt = db()->prepare('
    SELECT
      COUNT(*) AS today_count,
      COALESCE(SUM(ru_quantity), 0) AS today_quantity,
      COALESCE(SUM(ru_netvalue), 0) AS today_net
    FROM tbl_rubber
    WHERE ru_savedate = CURDATE() AND ru_saveby = :fullname
  ');
  $stmt->execute(['fullname' => $user['user_fullname']]);
  $today = $stmt->fetch();
  if ($today) {
    $stats = array_merge($stats, $today);
  }

  $stmt = db()->prepare('SELECT COUNT(*) AS total_saved FROM tbl_rubber WHERE ru_saveby = :fullname');
  $stmt->execute(['fullname' => $user['user_fullname']]);
  $total = $stmt->fetch();
  if ($total) {
    $stats['total_saved'] = $total['total_saved'];
  }

  $stmt = db()->prepare('
    SELECT ru_date, ru_lan, ru_number, ru_fullname, ru_quantity, ru_netvalue
    FROM tbl_rubber
    WHERE ru_saveby = :fullname
    ORDER BY ru_id DESC
    LIMIT 12
  ');
  $stmt->execute(['fullname' => $user['user_fullname']]);
  $recentRows = $stmt->fetchAll();
} catch (Exception $e) {
  error_log('User dashboard failed: ' . $e->getMessage());
  $dbError = db_friendly_error($e);
}

function user_money($value)
{
  return number_format((float) $value, 2);
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>แดชบอร์ดเจ้าหน้าที่</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
    .actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
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
    .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 18px; }
    .kpi { min-height: 126px; padding: 17px; border: 1px solid var(--line); border-radius: 8px; background: var(--paper); }
    .kpi span { color: var(--muted); font-size: 13px; }
    .kpi strong { display: block; margin-top: 12px; font-size: 26px; line-height: 1; }
    .kpi small { display: block; margin-top: 8px; color: var(--muted); }
    .card { margin-top: 18px; border: 1px solid var(--line); border-radius: 8px; background: var(--paper); overflow: hidden; }
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
    .alert { margin-top: 18px; padding: 13px 14px; border: 1px solid #f1b8b8; border-radius: 8px; background: #fff1f1; color: var(--red); }
    @media (max-width: 820px) {
      .topbar { align-items: flex-start; flex-direction: column; }
      .actions { width: 100%; justify-content: flex-start; }
      .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 560px) {
      .shell { width: min(100% - 20px, 1120px); }
      .kpis { grid-template-columns: 1fr; }
      .hero h1 { font-size: 25px; }
    }
  </style>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
</head>
<body>
  <?php render_topbar(); ?>
  <main class="shell">
    <section class="hero">
      <h1>สวัสดี <?php echo h($user['user_fullname']); ?></h1>
      <p>บัญชี <?php echo h($user['user_username']); ?> · สิทธิ์ <?php echo h($user['user_level']); ?> · สถานะ <?php echo h($user['user_status']); ?></p>
    </section>

    <?php if ($dbError): ?>
      <div class="alert"><?php echo h($dbError); ?></div>
    <?php endif; ?>

    <section class="kpis">
      <article class="kpi">
        <span>บันทึกวันนี้</span>
        <strong><?php echo number_format((int) $stats['today_count']); ?></strong>
        <small>รายการ</small>
      </article>
      <article class="kpi">
        <span>ปริมาณวันนี้</span>
        <strong><?php echo user_money($stats['today_quantity']); ?></strong>
        <small>kg</small>
      </article>
      <article class="kpi">
        <span>ยอดสุทธิวันนี้</span>
        <strong><?php echo user_money($stats['today_net']); ?></strong>
        <small>บาท</small>
      </article>
      <article class="kpi">
        <span>บันทึกทั้งหมด</span>
        <strong><?php echo number_format((int) $stats['total_saved']); ?></strong>
        <small>รายการ</small>
      </article>
    </section>

    <section class="card">
      <div class="card-head">
        <h2>รายการล่าสุดที่บันทึกโดยคุณ</h2>
      </div>
      <?php if ($recentRows): ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>วันที่</th>
                <th>ลาน</th>
                <th>เลขสมาชิก</th>
                <th>ชื่อ</th>
                <th class="num">ปริมาณ</th>
                <th class="num">สุทธิ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentRows as $row): ?>
                <tr>
                  <td><?php echo h($row['ru_date']); ?></td>
                  <td><?php echo h($row['ru_lan']); ?></td>
                  <td><?php echo h($row['ru_number']); ?></td>
                  <td><?php echo h($row['ru_fullname']); ?></td>
                  <td class="num"><?php echo user_money($row['ru_quantity']); ?></td>
                  <td class="num"><?php echo user_money($row['ru_netvalue']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="empty">ยังไม่พบรายการที่บันทึกโดยชื่อเจ้าหน้าที่นี้ในตาราง tbl_rubber</div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>

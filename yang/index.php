<?php
require_once __DIR__ . '/db.php';

$thaiMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$publicUser = $_SESSION['user'] ?? null;
$selectedYear = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$availableYears = [];
$monthlyData = array_fill(1, 12, ['quantity' => 0, 'records' => 0]);
$roundRows = [];
$latestYardRows = [];
$latestRoundDate = null;
$dbError = '';

function public_thai_date($date)
{
  if (!$date) return '—';
  $months = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
  $time = strtotime($date);
  return (int) date('j', $time) . ' ' . $months[(int) date('n', $time)] . ' ' . ((int) date('Y', $time) + 543);
}

try {
  $availableYears = db()->query('
    SELECT DISTINCT YEAR(ru_date) AS data_year
    FROM tbl_rubber
    WHERE ru_date IS NOT NULL
    ORDER BY data_year DESC
  ')->fetchAll(PDO::FETCH_COLUMN);
  $availableYears = array_values(array_filter(array_map('intval', $availableYears)));

  if (!$selectedYear) {
    $selectedYear = $availableYears[0] ?? (int) date('Y');
  }

  $stmt = db()->prepare('
    SELECT MONTH(ru_date) AS data_month,
           COALESCE(SUM(ru_quantity), 0) AS total_quantity,
           COUNT(*) AS total_records
    FROM tbl_rubber
    WHERE YEAR(ru_date) = :year
    GROUP BY MONTH(ru_date)
    ORDER BY data_month
  ');
  $stmt->execute(['year' => $selectedYear]);
  foreach ($stmt->fetchAll() as $row) {
    $month = (int) $row['data_month'];
    if ($month >= 1 && $month <= 12) {
      $monthlyData[$month] = [
        'quantity' => (float) $row['total_quantity'],
        'records' => (int) $row['total_records'],
      ];
    }
  }

  $roundStmt = db()->prepare('
    SELECT ru_date, COUNT(*) AS total_records,
           COUNT(DISTINCT CONCAT(ru_class, ":", ru_number, ":", ru_fullname)) AS total_people,
           COALESCE(SUM(ru_quantity), 0) AS total_quantity,
           COALESCE(SUM(ru_value), 0) AS total_value,
           COALESCE(SUM(ru_expend), 0) AS total_expend,
           COALESCE(SUM(ru_netvalue), 0) AS total_netvalue
    FROM tbl_rubber
    WHERE YEAR(ru_date) = :year
    GROUP BY ru_date
    ORDER BY ru_date DESC
  ');
  $roundStmt->execute(['year' => $selectedYear]);
  $roundRows = $roundStmt->fetchAll();
  $latestRoundDate = $roundRows[0]['ru_date'] ?? null;

  if ($latestRoundDate) {
    $yardStmt = db()->prepare('
      SELECT ru_lan, COUNT(*) AS total_records,
             COALESCE(SUM(ru_quantity), 0) AS total_quantity,
             COALESCE(SUM(ru_value), 0) AS total_value,
             COALESCE(SUM(ru_expend), 0) AS total_expend,
             COALESCE(SUM(ru_netvalue), 0) AS total_netvalue
      FROM tbl_rubber
      WHERE ru_date = :date
      GROUP BY ru_lan
      ORDER BY CAST(ru_lan AS UNSIGNED), ru_lan
    ');
    $yardStmt->execute(['date' => $latestRoundDate]);
    $latestYardRows = $yardStmt->fetchAll();
  }
} catch (Throwable $e) {
  error_log('Public monthly summary failed: ' . $e->getMessage());
  $dbError = db_friendly_error($e);
  $selectedYear = $selectedYear ?: (int) date('Y');
}

$chartLabels = [];
$chartQuantities = [];
$totalQuantity = 0;
$totalRecords = 0;
$activeMonths = 0;
foreach ($monthlyData as $month => $data) {
  $chartLabels[] = $thaiMonths[$month - 1];
  $chartQuantities[] = round($data['quantity'], 2);
  $totalQuantity += $data['quantity'];
  $totalRecords += $data['records'];
  if ($data['quantity'] > 0) {
    $activeMonths++;
  }
}
$averageQuantity = $activeMonths ? $totalQuantity / $activeMonths : 0;
$buddhistYear = $selectedYear + 543;
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ข้อมูลการรวบรวมยาง</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
  :root {
    --green: #176b49;
    --green-dark: #0d4932;
    --mint: #eaf5ef;
    --ink: #18251f;
    --muted: #68766f;
    --line: #dce5df;
    --paper: #fff;
    --bg: #f4f7f5;
  }

  * {
    box-sizing: border-box;
  }

  body {
    margin: 0;
    font-family: "Sarabun", sans-serif;
    color: var(--ink);
    background: var(--bg);
  }

  a {
    color: inherit;
    text-decoration: none;
  }

  .public-nav {
    position: sticky;
    top: 0;
    z-index: 20;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
    background: rgba(13, 73, 50, .96);
    color: #fff;
    backdrop-filter: blur(12px);
  }

  .nav-inner {
    width: min(1180px, calc(100% - 32px));
    min-height: 68px;
    margin: auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 11px;
    font-weight: 800;
  }

  .brand-mark {
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    color: var(--green-dark);
    background: #fff;
  }

  .nav-actions {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .nav-link {
    color: rgba(255, 255, 255, .84);
    font-size: 14px;
    font-weight: 600;
  }

  .login-btn {
    padding: 9px 15px;
    border: 1px solid rgba(255, 255, 255, .5);
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
  }

  .hero {
    color: #fff;
    background: linear-gradient(125deg, var(--green-dark), var(--green) 60%, #2d8a64);
  }

  .hero-inner {
    width: min(1180px, calc(100% - 32px));
    margin: auto;
    padding: 70px 0 84px;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
    gap: 36px;
  }

  .eyebrow {
    margin-bottom: 12px;
    color: #bfe3d1;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: .08em;
  }

  h1 {
    max-width: 720px;
    margin: 0;
    font-size: clamp(2rem, 5vw, 3.7rem);
    line-height: 1.12;
  }

  .hero p {
    max-width: 650px;
    margin: 18px 0 0;
    color: rgba(255, 255, 255, .76);
    font-size: 17px;
    line-height: 1.7;
  }

  .year-form {
    min-width: 210px;
    padding: 18px;
    border: 1px solid rgba(255, 255, 255, .2);
    border-radius: 14px;
    background: rgba(255, 255, 255, .1);
  }

  .year-form label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 700;
  }

  .year-form select {
    width: 100%;
    height: 43px;
    padding: 0 10px;
    border: 0;
    border-radius: 8px;
    font: inherit;
    color: var(--ink);
    background: #fff;
  }

  .container {
    width: min(1180px, calc(100% - 32px));
    margin: -38px auto 60px;
    position: relative;
    z-index: 2;
  }

  .stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
  }

  .stat {
    padding: 22px;
    border: 1px solid var(--line);
    border-radius: 14px;
    background: var(--paper);
    box-shadow: 0 12px 34px rgba(20, 65, 44, .08);
  }

  .stat-label {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--muted);
    font-size: 13px;
    font-weight: 700;
  }

  .stat strong {
    display: block;
    margin-top: 12px;
    font-size: clamp(1.5rem, 3vw, 2.2rem);
    line-height: 1;
  }

  .stat small {
    display: block;
    margin-top: 9px;
    color: var(--muted);
  }

  .panel {
    margin-top: 20px;
    border: 1px solid var(--line);
    border-radius: 14px;
    background: var(--paper);
    overflow: hidden;
    box-shadow: 0 8px 28px rgba(20, 65, 44, .06);
  }

  .panel-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 22px 24px;
    border-bottom: 1px solid var(--line);
  }

  .panel-head h2 {
    margin: 0 0 4px;
    font-size: 20px;
  }

  .panel-head p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .year-badge {
    padding: 7px 11px;
    border-radius: 999px;
    color: var(--green);
    background: var(--mint);
    font-size: 13px;
    font-weight: 800;
  }

  .chart-wrap {
    height: 390px;
    padding: 24px;
  }

  .alert {
    margin-top: 20px;
    padding: 15px 18px;
    border: 1px solid #efc2c2;
    border-radius: 10px;
    color: #9e3333;
    background: #fff2f2;
  }

  .table-wrap {
    overflow-x: auto;
  }

  table {
    width: 100%;
    border-collapse: collapse;
  }

  th,
  td {
    padding: 14px 24px;
    border-bottom: 1px solid #edf1ee;
    text-align: left;
  }

  th {
    color: var(--muted);
    background: #fafcfb;
    font-size: 12px;
  }

  td.num,
  th.num {
    text-align: right;
  }

  tbody tr:last-child td {
    border-bottom: 0;
  }

  .round-date { color: var(--green-dark); font-weight: 700; white-space: nowrap; }
  .yard-name { display: inline-flex; align-items: center; gap: 7px; padding: 6px 11px; border-radius: 999px; color: var(--green); background: var(--mint); font-weight: 800; white-space: nowrap; }
  .net-value { color: var(--green); font-weight: 800; }
  .total-row td { color: var(--green-dark); background: #f2f8f5; font-weight: 800; }
  .empty-row { padding: 32px; color: var(--muted); text-align: center; }

  footer {
    padding: 28px 16px;
    color: var(--muted);
    text-align: center;
    font-size: 13px;
  }

  @media (max-width:760px) {
    .nav-link {
      display: none;
    }

    .hero-inner {
      padding: 48px 0 68px;
      grid-template-columns: 1fr;
    }

    .year-form {
      min-width: 0;
    }

    .stats {
      grid-template-columns: 1fr;
    }

    .chart-wrap {
      height: 320px;
      padding: 14px;
    }

    th,
    td {
      padding: 12px 16px;
    }
  }
  </style>
</head>

<body>
  <header class="public-nav">
    <div class="nav-inner">
      <a class="brand" href="<?php echo h(url_for('index.php')); ?>"><span
          class="brand-mark">ย</span><span>ระบบรวบรวมยาง</span></a>
      <nav class="nav-actions" aria-label="เมนูหลัก">
        <a class="nav-link" href="<?php echo h(url_for('index.php')); ?>"><i
            class="bi bi-house-door me-1"></i>หน้าแรก</a>
        <a class="nav-link" href="#monthly">ข้อมูลรายเดือน</a>
        <a class="nav-link" href="<?php echo h(url_for('price.php')); ?>"><i class="bi bi-tags me-1"></i>ราคาอ้างอิง</a>
        <?php if ($publicUser): ?>
        <a class="login-btn" href="<?php echo h(url_for('dashboard.php')); ?>"><i class="bi bi-grid-1x2 me-1"></i>
          แดชบอร์ด</a>
        <?php else: ?>
        <a class="login-btn" href="<?php echo h(url_for('user-login.php')); ?>"><i class="bi bi-person-lock me-1"></i>
          เข้าสู่ระบบ</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <section class="hero">
    <div class="hero-inner">
      <div>
        <div class="eyebrow">RUBBER COLLECTION OVERVIEW</div>
        <h1>ภาพรวมปริมาณการรวบรวมยางรายเดือน</h1>
        <p>ข้อมูลสาธารณะสำหรับติดตามปริมาณยางที่รวบรวมได้ในแต่ละเดือนของสหกรณ์</p>
      </div>
      <form class="year-form" method="get">
        <label for="year">เลือกปีข้อมูล</label>
        <select id="year" name="year" onchange="this.form.submit()">
          <?php if (!$availableYears): ?><option value="<?php echo $selectedYear; ?>">พ.ศ. <?php echo $buddhistYear; ?>
          </option><?php endif; ?>
          <?php foreach ($availableYears as $year): ?><option value="<?php echo $year; ?>"
            <?php echo $year === $selectedYear ? 'selected' : ''; ?>>พ.ศ. <?php echo $year + 543; ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </section>

  <main class="container" id="monthly">
    <section class="stats" aria-label="ข้อมูลสรุป">
      <article class="stat">
        <div class="stat-label"><i class="bi bi-box-seam"></i> ปริมาณรวมทั้งปี</div>
        <strong><?php echo number_format($totalQuantity, 2); ?></strong><small>กิโลกรัม</small>
      </article>
      <article class="stat">
        <div class="stat-label"><i class="bi bi-receipt"></i> จำนวนรายการรวบรวม</div>
        <strong><?php echo number_format($totalRecords); ?></strong><small>รายการ</small>
      </article>
      <article class="stat">
        <div class="stat-label"><i class="bi bi-bar-chart"></i> ค่าเฉลี่ยต่อเดือนที่มีข้อมูล</div>
        <strong><?php echo number_format($averageQuantity, 2); ?></strong><small>กิโลกรัม / เดือน</small>
      </article>
    </section>

    <?php if ($dbError): ?><div class="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo h($dbError); ?>
    </div><?php endif; ?>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>กราฟปริมาณการรวบรวมยาง</h2>
          <p>เปรียบเทียบปริมาณรวมตั้งแต่เดือนมกราคมถึงธันวาคม</p>
        </div><span class="year-badge">พ.ศ. <?php echo $buddhistYear; ?></span>
      </div>
      <div class="chart-wrap"><canvas id="monthlyChart" aria-label="กราฟปริมาณยางรายเดือน" role="img"></canvas></div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>ข้อมูลแยกรายเดือน</h2>
          <p>รายละเอียดปริมาณและจำนวนรายการในแต่ละเดือน</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>เดือน</th>
              <th class="num">ปริมาณ (kg)</th>
              <th class="num">จำนวนรายการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($monthlyData as $month => $data): ?><tr>
              <td><?php echo h($thaiMonths[$month - 1]); ?></td>
              <td class="num"><?php echo number_format($data['quantity'], 2); ?></td>
              <td class="num"><?php echo number_format($data['records']); ?></td>
            </tr><?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>รอบล่าสุดแยกตามลาน</h2>
          <p><?php echo $latestRoundDate ? 'ข้อมูลรอบวันที่ ' . h(public_thai_date($latestRoundDate)) : 'ยังไม่มีข้อมูลในปีที่เลือก'; ?></p>
        </div>
        <?php if ($latestRoundDate): ?><span class="year-badge"><i class="bi bi-geo-alt me-1"></i><?php echo number_format(array_sum(array_column($latestYardRows, 'total_records'))); ?> รายการ</span><?php endif; ?>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ลานรับยาง</th><th class="num">จำนวนรายการ</th><th class="num">น้ำหนัก (kg)</th><th class="num">ยอดก่อนหัก (บาท)</th><th class="num">ค่าใช้จ่าย (บาท)</th><th class="num">ยอดหลังหัก (บาท)</th></tr></thead>
          <tbody>
            <?php foreach ($latestYardRows as $yard): ?><tr>
              <td><span class="yard-name"><i class="bi bi-geo-alt-fill"></i>ลาน <?php echo h($yard['ru_lan']); ?></span></td>
              <td class="num"><?php echo number_format((int) $yard['total_records']); ?></td>
              <td class="num"><?php echo number_format((float) $yard['total_quantity'], 2); ?></td>
              <td class="num"><?php echo number_format((float) $yard['total_value'], 2); ?></td>
              <td class="num"><?php echo number_format((float) $yard['total_expend'], 2); ?></td>
              <td class="num net-value"><?php echo number_format((float) $yard['total_netvalue'], 2); ?></td>
            </tr><?php endforeach; ?>
            <?php if ($latestYardRows): ?><tr class="total-row"><td>รวมทุกลาน</td><td class="num"><?php echo number_format(array_sum(array_column($latestYardRows, 'total_records'))); ?></td><td class="num"><?php echo number_format(array_sum(array_column($latestYardRows, 'total_quantity')), 2); ?></td><td class="num"><?php echo number_format(array_sum(array_column($latestYardRows, 'total_value')), 2); ?></td><td class="num"><?php echo number_format(array_sum(array_column($latestYardRows, 'total_expend')), 2); ?></td><td class="num"><?php echo number_format(array_sum(array_column($latestYardRows, 'total_netvalue')), 2); ?></td></tr><?php else: ?><tr><td class="empty-row" colspan="6">ไม่พบข้อมูลรอบล่าสุด</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <div><h2>สรุปข้อมูลในแต่ละรอบ</h2><p>น้ำหนัก ยอดเงินก่อนหัก ค่าใช้จ่าย และยอดสุทธิของแต่ละวันที่รวบรวม</p></div>
        <span class="year-badge">พ.ศ. <?php echo $buddhistYear; ?></span>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>วันที่ / รอบ</th><th class="num">ผู้รวบรวม</th><th class="num">จำนวนรายการ</th><th class="num">น้ำหนัก (kg)</th><th class="num">ยอดก่อนหัก (บาท)</th><th class="num">ค่าใช้จ่าย (บาท)</th><th class="num">ยอดหลังหัก (บาท)</th></tr></thead>
          <tbody>
            <?php foreach ($roundRows as $round): ?><tr>
              <td class="round-date"><?php echo h(public_thai_date($round['ru_date'])); ?></td>
              <td class="num"><?php echo number_format((int) $round['total_people']); ?></td>
              <td class="num"><?php echo number_format((int) $round['total_records']); ?></td>
              <td class="num"><?php echo number_format((float) $round['total_quantity'], 2); ?></td>
              <td class="num"><?php echo number_format((float) $round['total_value'], 2); ?></td>
              <td class="num"><?php echo number_format((float) $round['total_expend'], 2); ?></td>
              <td class="num net-value"><?php echo number_format((float) $round['total_netvalue'], 2); ?></td>
            </tr><?php endforeach; ?>
            <?php if (!$roundRows): ?><tr><td class="empty-row" colspan="7">ไม่พบข้อมูลรอบรับยางในปีที่เลือก</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
  <footer>ระบบข้อมูลการรวบรวมยาง · ข้อมูลอัปเดตจากรายการที่บันทึกในระบบ</footer>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
  <script>
  new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
      labels: <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>,
      datasets: [{
        label: 'ปริมาณยาง (kg)',
        data: <?php echo json_encode($chartQuantities); ?>,
        backgroundColor: '#2d8a64',
        hoverBackgroundColor: '#176b49',
        borderRadius: 7,
        maxBarThickness: 54
      }]
    },
    options: {
      maintainAspectRatio: false,
      responsive: true,
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          callbacks: {
            label: context => new Intl.NumberFormat('th-TH', {
              maximumFractionDigits: 2
            }).format(context.raw) + ' kg'
          }
        }
      },
      scales: {
        x: {
          grid: {
            display: false
          },
          ticks: {
            font: {
              family: 'Sarabun'
            }
          }
        },
        y: {
          beginAtZero: true,
          grid: {
            color: '#edf1ee'
          },
          ticks: {
            callback: value => new Intl.NumberFormat('th-TH', {
              notation: 'compact'
            }).format(value),
            font: {
              family: 'Sarabun'
            }
          }
        }
      }
    }
  });
  </script>
</body>

</html>

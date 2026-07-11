<?php
$basePath = rtrim($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '/yang', '/');
$today = date('d/m/Y');

$latestPrice = ['date' => '10 มิถุนายน 2569', 'round' => 'รอบ 1', 'price' => 45.35, 'records' => 6737];
$latestRound = [
  'label' => 'รอบวางยางล่าสุด',
  'period' => '8 มิถุนายน 2569 ถึง 9 มิถุนายน 2569',
  'weighDate' => '10 มิถุนายน 2569',
  'lastUpdate' => '04/07/2569 06:40',
];

$kpis = [
  ['label' => 'ปริมาณรวมวันนี้', 'value' => '413,234.00', 'unit' => 'kg', 'note' => 'รวมทุกลานในวันที่ราคาอ้างอิง'],
  ['label' => 'ยอดเงินรวม', 'value' => '18,740,161.90', 'unit' => 'บาท', 'note' => 'คำนวณจากปริมาณ x ราคาล่าสุด'],
  ['label' => 'ยอดหักรวม', 'value' => '1,205,337.36', 'unit' => 'บาท', 'note' => 'ค่าหัก/หนี้/รายการปรับปรุง'],
  ['label' => 'ยอดสุทธิที่จ่าย', 'value' => '17,534,827.22', 'unit' => 'บาท', 'note' => 'ยอดจ่ายหลังหักทั้งหมด'],
];

$actions = [
  ['href' => 'rubbers.php', 'label' => 'รายการรับซื้อ', 'desc' => 'ค้นหา ตรวจสอบ และแก้ไขรายการรวบรวมยาง'],
  ['href' => 'members.php', 'label' => 'สมาชิก/เกษตรกร', 'desc' => 'เพิ่มข้อมูลสมาชิกและบัญชีรับเงิน'],
  ['href' => 'prices.php', 'label' => 'ราคาอ้างอิง', 'desc' => 'บันทึกราคาตามวันที่และรอบรับซื้อ'],
  ['href' => 'payments.php', 'label' => 'สรุปจ่ายเงิน', 'desc' => 'ตรวจยอดหัก ยอดสุทธิ และสถานะโอนเงิน'],
];

$rounds = [
  ['date' => '10 มิ.ย.', 'weight' => 413234, 'amount' => 17534827, 'people' => 576],
  ['date' => '20 พ.ค.', 'weight' => 354562, 'amount' => 14668893, 'people' => 513],
  ['date' => '29 เม.ย.', 'weight' => 129913, 'amount' => 5123800, 'people' => 325],
  ['date' => '8 เม.ย.', 'weight' => 122577, 'amount' => 4514972, 'people' => 324],
  ['date' => '25 มี.ค.', 'weight' => 198923, 'amount' => 7047242, 'people' => 396],
  ['date' => '5 มี.ค.', 'weight' => 204583, 'amount' => 6826753, 'people' => 393],
];

$yards = [
  ['yard' => 'ลาน 1', 'weight' => '96,937.00', 'amount' => '4,396,092.95', 'share' => 23],
  ['yard' => 'ลาน 2', 'weight' => '100,257.50', 'amount' => '4,546,677.63', 'share' => 24],
  ['yard' => 'ลาน 3', 'weight' => '120,780.50', 'amount' => '5,477,395.68', 'share' => 29],
  ['yard' => 'ลาน 4', 'weight' => '95,259.00', 'amount' => '4,319,995.65', 'share' => 23],
];

$dailyRows = [
  ['date' => '12 มิถุนายน 2569', 'price' => '45.35', 'weight' => '368.00', 'gross' => '16,688.80', 'deduct' => '0.00', 'net' => '16,688.80', 'records' => '1', 'people' => '0/1'],
  ['date' => '10 มิถุนายน 2569', 'price' => '45.35', 'weight' => '413,234.00', 'gross' => '18,740,164.58', 'deduct' => '1,205,337.36', 'net' => '17,534,827.22', 'records' => '1,033', 'people' => '432/144'],
  ['date' => '20 พฤษภาคม 2569', 'price' => '44.19', 'weight' => '354,562.00', 'gross' => '15,668,096.96', 'deduct' => '999,203.50', 'net' => '14,668,893.45', 'records' => '890', 'people' => '399/114'],
  ['date' => '29 เมษายน 2569', 'price' => '41.50', 'weight' => '129,912.50', 'gross' => '5,391,368.75', 'deduct' => '267,568.91', 'net' => '5,123,799.84', 'records' => '461', 'people' => '254/71'],
  ['date' => '8 เมษายน 2569', 'price' => '37.48', 'weight' => '122,577.00', 'gross' => '4,594,185.96', 'deduct' => '79,213.56', 'net' => '4,514,972.40', 'records' => '499', 'people' => '242/82'],
];

$maxWeight = max(array_column($rounds, 'weight'));
$maxAmount = max(array_column($rounds, 'amount'));
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ระบบรวบรวมยาง</title>
  <style>
    :root {
      --bg: #f2f5f3;
      --paper: #ffffff;
      --ink: #17212f;
      --muted: #667085;
      --line: #d8e0e6;
      --soft: #f7f9fb;
      --green: #1d7a54;
      --green-2: #0f5138;
      --blue: #2d6cdf;
      --amber: #bd7418;
      --red: #bd3f3f;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: Arial, Tahoma, sans-serif;
      background: var(--bg);
      color: var(--ink);
    }

    a { color: inherit; text-decoration: none; }

    .app {
      min-height: 100vh;
      display: grid;
      grid-template-columns: 248px minmax(0, 1fr);
    }

    .sidebar {
      position: sticky;
      top: 0;
      height: 100vh;
      padding: 20px 16px;
      border-right: 1px solid var(--line);
      background: #fbfcfd;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px;
      margin-bottom: 22px;
    }

    .brand-mark {
      width: 40px;
      height: 40px;
      display: grid;
      place-items: center;
      border-radius: 8px;
      background: var(--green-2);
      color: #fff;
      font-weight: 800;
    }

    .brand-title { font-weight: 800; line-height: 1.25; }
    .brand-subtitle { margin-top: 2px; color: var(--muted); font-size: 12px; }

    .nav-label {
      margin: 18px 8px 8px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 700;
    }

    .nav a {
      display: flex;
      align-items: center;
      min-height: 40px;
      padding: 0 10px;
      border-radius: 7px;
      color: #334155;
      font-size: 14px;
      font-weight: 700;
    }

    .nav a.active,
    .nav a:hover {
      background: #e8f3ed;
      color: var(--green-2);
    }

    .content {
      min-width: 0;
      padding: 20px 22px 42px;
    }

    .topline {
      min-height: 58px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px;
    }

    .page-title h1 {
      margin: 0;
      font-size: 24px;
      letter-spacing: 0;
    }

    .page-title p {
      margin: 5px 0 0;
      color: var(--muted);
      font-size: 13px;
    }

    .toolbar {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 38px;
      padding: 0 13px;
      border: 1px solid var(--line);
      border-radius: 6px;
      background: #fff;
      color: var(--ink);
      font-size: 14px;
      font-weight: 800;
      white-space: nowrap;
    }

    .btn-primary {
      border-color: var(--green);
      background: var(--green);
      color: #fff;
    }

    .price-strip {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto auto;
      gap: 14px;
      align-items: center;
      padding: 16px 18px;
      border: 1px solid #b7d9c8;
      border-radius: 8px;
      background: #eaf6ef;
      margin-bottom: 18px;
    }

    .price-strip strong {
      display: block;
      font-size: 16px;
      margin-bottom: 4px;
    }

    .price-strip span {
      color: var(--muted);
      font-size: 13px;
    }

    .latest-price {
      font-size: 28px;
      font-weight: 900;
      color: var(--green-2);
      white-space: nowrap;
    }

    .layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 340px;
      gap: 18px;
    }

    .card {
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

    .card-head h2 {
      margin: 0;
      font-size: 18px;
      letter-spacing: 0;
    }

    .card-head small {
      color: var(--muted);
      font-size: 12px;
    }

    .kpis {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }

    .kpi {
      min-height: 150px;
      padding: 18px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--paper);
    }

    .kpi-label { color: var(--muted); font-size: 13px; }
    .kpi-value { margin-top: 14px; font-size: 28px; font-weight: 900; line-height: 1; }
    .kpi-unit { margin-top: 6px; color: var(--muted); font-size: 13px; }
    .kpi-note { margin-top: 14px; color: var(--green-2); font-size: 12px; font-weight: 800; line-height: 1.4; }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      padding: 16px;
    }

    .summary-item {
      min-height: 96px;
      padding: 14px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--soft);
    }

    .summary-item span {
      display: block;
      color: var(--muted);
      font-size: 12px;
      margin-bottom: 8px;
    }

    .summary-item strong {
      display: block;
      font-size: 18px;
      line-height: 1.35;
    }

    .charts {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
      margin-top: 18px;
    }

    .bars {
      padding: 18px;
      display: grid;
      gap: 12px;
    }

    .bar-row {
      display: grid;
      grid-template-columns: 64px minmax(0, 1fr) 92px;
      gap: 10px;
      align-items: center;
      font-size: 13px;
    }

    .track {
      height: 12px;
      overflow: hidden;
      border-radius: 999px;
      background: #e8edf2;
    }

    .fill {
      height: 100%;
      width: var(--w);
      border-radius: inherit;
      background: var(--green);
    }

    .fill.blue { background: var(--blue); }
    .bar-value { color: var(--muted); text-align: right; white-space: nowrap; }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    th, td {
      padding: 13px 14px;
      border-bottom: 1px solid var(--line);
      text-align: left;
      white-space: nowrap;
    }

    th {
      color: var(--muted);
      background: #fbfcfd;
      font-size: 12px;
      font-weight: 800;
    }

    tr:last-child td { border-bottom: 0; }
    td.num, th.num { text-align: right; }

    .yard-bar {
      display: grid;
      grid-template-columns: 74px minmax(0, 1fr) 44px;
      gap: 10px;
      align-items: center;
    }

    .side-stack {
      display: grid;
      gap: 18px;
    }

    .actions {
      display: grid;
      gap: 10px;
      padding: 14px;
    }

    .action {
      display: block;
      padding: 13px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--soft);
    }

    .action strong { display: block; margin-bottom: 5px; }
    .action span { color: var(--muted); font-size: 12px; line-height: 1.4; }

    .avg-box {
      padding: 16px;
      display: grid;
      gap: 12px;
    }

    .avg {
      padding: 14px;
      border-radius: 8px;
      background: #f6f8fb;
      border: 1px solid var(--line);
    }

    .avg span { color: var(--muted); font-size: 12px; }
    .avg strong { display: block; margin-top: 8px; font-size: 24px; }

    .footer-note {
      margin-top: 18px;
      color: var(--muted);
      font-size: 12px;
      text-align: right;
    }

    .table-wrap { overflow-x: auto; }

    @media (max-width: 1120px) {
      .app { grid-template-columns: 1fr; }
      .sidebar {
        position: static;
        height: auto;
        border-right: 0;
        border-bottom: 1px solid var(--line);
      }
      .nav {
        display: flex;
        gap: 6px;
        overflow-x: auto;
      }
      .nav-label { display: none; }
      .layout { grid-template-columns: 1fr; }
      .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 760px) {
      .content { padding: 14px 12px 32px; }
      .topline { align-items: flex-start; flex-direction: column; }
      .toolbar { width: 100%; justify-content: stretch; }
      .toolbar .btn { flex: 1 1 auto; }
      .price-strip { grid-template-columns: 1fr; }
      .latest-price { font-size: 24px; }
      .kpis, .summary-grid, .charts { grid-template-columns: 1fr; }
      .bar-row { grid-template-columns: 54px minmax(0, 1fr) 78px; }
    }
  </style>
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <a class="brand" href="<?php echo htmlspecialchars($basePath ?: '/yang'); ?>">
        <div class="brand-mark">ย</div>
        <div>
          <div class="brand-title">ระบบรวบรวมยาง</div>
          <div class="brand-subtitle">สหกรณ์ / ลานรับซื้อ</div>
        </div>
      </a>

      <div class="nav-label">เมนูระบบ</div>
      <nav class="nav" aria-label="เมนูระบบ">
        <a class="active" href="#overview">ภาพรวม</a>
        <a href="#round">รอบวางยาง</a>
        <a href="#charts">กราฟสรุป</a>
        <a href="#yards">สรุปแต่ละลาน</a>
        <a href="#daily">รับซื้อรายวัน</a>
      </nav>

      <div class="nav-label">จัดการข้อมูล</div>
      <nav class="nav" aria-label="จัดการข้อมูล">
        <a href="<?php echo htmlspecialchars($basePath); ?>/rubbers.php">รายการรับซื้อ</a>
        <a href="<?php echo htmlspecialchars($basePath); ?>/members.php">สมาชิก</a>
        <a href="<?php echo htmlspecialchars($basePath); ?>/prices.php">ราคาอ้างอิง</a>
        <a href="<?php echo htmlspecialchars($basePath); ?>/login.php">สมาชิกเข้าสู่ระบบ</a>
        <a href="<?php echo htmlspecialchars($basePath); ?>/user-login.php">เจ้าหน้าที่เข้าสู่ระบบ</a>
      </nav>
    </aside>

    <main class="content" id="overview">
      <header class="topline">
        <div class="page-title">
          <h1>ภาพรวมข้อมูลการรวบรวมยาง</h1>
          <p>สรุปราคา ปริมาณ ยอดหัก ยอดสุทธิ และข้อมูลแยกตามลานในหน้าเดียว</p>
        </div>
        <div class="toolbar">
          <a class="btn" href="<?php echo htmlspecialchars($basePath); ?>/prices.php">ราคาอ้างอิง</a>
          <a class="btn" href="<?php echo htmlspecialchars($basePath); ?>/login.php">สมาชิกเข้าสู่ระบบ</a>
          <a class="btn" href="<?php echo htmlspecialchars($basePath); ?>/user-login.php">เจ้าหน้าที่เข้าสู่ระบบ</a>
          <a class="btn btn-primary" href="<?php echo htmlspecialchars($basePath); ?>/rubbers.php">บันทึกข้อมูล</a>
        </div>
      </header>

      <section class="price-strip">
        <div>
          <strong>ราคาล่าสุดที่ใช้คำนวณ</strong>
          <span><?php echo htmlspecialchars($latestPrice['date']); ?> · <?php echo htmlspecialchars($latestPrice['round']); ?> · ทั้งหมด <?php echo number_format($latestPrice['records']); ?> รายการ</span>
        </div>
        <div class="latest-price"><?php echo number_format($latestPrice['price'], 2); ?> บาท/kg</div>
        <a class="btn" href="<?php echo htmlspecialchars($basePath); ?>/prices.php">ดูประวัติราคา</a>
      </section>

      <section class="kpis">
        <?php foreach ($kpis as $kpi): ?>
          <article class="kpi">
            <div class="kpi-label"><?php echo htmlspecialchars($kpi['label']); ?></div>
            <div class="kpi-value"><?php echo htmlspecialchars($kpi['value']); ?></div>
            <div class="kpi-unit"><?php echo htmlspecialchars($kpi['unit']); ?></div>
            <div class="kpi-note"><?php echo htmlspecialchars($kpi['note']); ?></div>
          </article>
        <?php endforeach; ?>
      </section>

      <div class="layout">
        <div>
          <section class="card" id="round">
            <div class="card-head">
              <div>
                <h2><?php echo htmlspecialchars($latestRound['label']); ?></h2>
                <small>อัปเดตล่าสุด: <?php echo htmlspecialchars($latestRound['lastUpdate']); ?></small>
              </div>
            </div>
            <div class="summary-grid">
              <div class="summary-item">
                <span>ช่วงวางยาง</span>
                <strong><?php echo htmlspecialchars($latestRound['period']); ?></strong>
              </div>
              <div class="summary-item">
                <span>วันชั่งยาง</span>
                <strong><?php echo htmlspecialchars($latestRound['weighDate']); ?></strong>
              </div>
              <div class="summary-item">
                <span>สถานะระบบ</span>
                <strong>พร้อมตรวจสอบและบันทึกข้อมูล</strong>
              </div>
            </div>
          </section>

          <section class="charts" id="charts">
            <div class="card">
              <div class="card-head">
                <h2>ปริมาณยางตามรอบ</h2>
                <small>kg</small>
              </div>
              <div class="bars">
                <?php foreach ($rounds as $round): ?>
                  <?php $width = max(6, round(($round['weight'] / $maxWeight) * 100)); ?>
                  <div class="bar-row">
                    <span><?php echo htmlspecialchars($round['date']); ?></span>
                    <div class="track"><div class="fill" style="--w: <?php echo $width; ?>%;"></div></div>
                    <span class="bar-value"><?php echo number_format($round['weight']); ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="card">
              <div class="card-head">
                <h2>ยอดสุทธิตามรอบ</h2>
                <small>บาท</small>
              </div>
              <div class="bars">
                <?php foreach ($rounds as $round): ?>
                  <?php $width = max(6, round(($round['amount'] / $maxAmount) * 100)); ?>
                  <div class="bar-row">
                    <span><?php echo htmlspecialchars($round['date']); ?></span>
                    <div class="track"><div class="fill blue" style="--w: <?php echo $width; ?>%;"></div></div>
                    <span class="bar-value"><?php echo number_format($round['amount'] / 1000000, 1); ?>M</span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="card" id="yards" style="margin-top: 18px;">
            <div class="card-head">
              <h2>ปริมาณรวบรวมแต่ละลาน</h2>
              <small>วันที่ราคา: <?php echo htmlspecialchars($latestPrice['date']); ?></small>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>ลาน</th>
                    <th>สัดส่วน</th>
                    <th class="num">ปริมาณรวม (kg)</th>
                    <th class="num">ยอดเงินรวม (บาท)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($yards as $yard): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($yard['yard']); ?></td>
                      <td>
                        <div class="yard-bar">
                          <span><?php echo $yard['share']; ?>%</span>
                          <div class="track"><div class="fill" style="--w: <?php echo $yard['share']; ?>%;"></div></div>
                          <span></span>
                        </div>
                      </td>
                      <td class="num"><?php echo htmlspecialchars($yard['weight']); ?></td>
                      <td class="num"><?php echo htmlspecialchars($yard['amount']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>

          <section class="card" id="daily" style="margin-top: 18px;">
            <div class="card-head">
              <h2>สรุปรับซื้อรายวัน</h2>
              <small>รวมทุกลาน</small>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>วันที่</th>
                    <th class="num">ราคา</th>
                    <th class="num">ปริมาณ</th>
                    <th class="num">เงินรวม</th>
                    <th class="num">ยอดหัก</th>
                    <th class="num">สุทธิ</th>
                    <th class="num">รายการ</th>
                    <th class="num">สมาชิก/ทั่วไป</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($dailyRows as $row): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($row['date']); ?></td>
                      <td class="num"><?php echo htmlspecialchars($row['price']); ?></td>
                      <td class="num"><?php echo htmlspecialchars($row['weight']); ?></td>
                      <td class="num"><?php echo htmlspecialchars($row['gross']); ?></td>
                      <td class="num"><?php echo htmlspecialchars($row['deduct']); ?></td>
                      <td class="num"><?php echo htmlspecialchars($row['net']); ?></td>
                      <td class="num"><?php echo htmlspecialchars($row['records']); ?></td>
                      <td class="num"><?php echo htmlspecialchars($row['people']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <aside class="side-stack">
          <section class="card">
            <div class="card-head">
              <h2>งานด่วน</h2>
            </div>
            <div class="actions">
              <?php foreach ($actions as $action): ?>
                <a class="action" href="<?php echo htmlspecialchars($basePath . '/' . $action['href']); ?>">
                  <strong><?php echo htmlspecialchars($action['label']); ?></strong>
                  <span><?php echo htmlspecialchars($action['desc']); ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="card">
            <div class="card-head">
              <h2>ค่าเฉลี่ยรอบล่าสุด</h2>
              <small>ผู้ส่งทั้งหมด 576 คน</small>
            </div>
            <div class="avg-box">
              <div class="avg">
                <span>ปริมาณเฉลี่ยต่อคน</span>
                <strong>717.42 kg</strong>
              </div>
              <div class="avg">
                <span>รายรับสุทธิเฉลี่ยต่อคน</span>
                <strong>30,442.41 บาท</strong>
              </div>
            </div>
          </section>

          <section class="card">
            <div class="card-head">
              <h2>สถานะการใช้งาน</h2>
            </div>
            <div class="avg-box">
              <div class="avg">
                <span>ผู้ใช้งานออนไลน์</span>
                <strong>1 คน</strong>
              </div>
              <div class="avg">
                <span>วันทำการ</span>
                <strong>08:30 - 16:30</strong>
              </div>
            </div>
          </section>
        </aside>
      </div>

      <div class="footer-note">
        Route: <?php echo htmlspecialchars($basePath); ?> · PHP <?php echo htmlspecialchars(PHP_VERSION); ?> · วันที่ระบบ <?php echo htmlspecialchars($today); ?>
      </div>
    </main>
  </div>
</body>
</html>

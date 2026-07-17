<?php
require_once __DIR__ . '/auth.php';
require_user();
require_once __DIR__ . '/navbar.php';
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
  ['label' => 'ปริมาณรวมวันนี้', 'value' => '413,234.00', 'unit' => 'kg', 'note' => 'รวมทุกลานในวันที่ราคาอ้างอิง', 'icon' => 'bi-box-seam', 'accent' => 'violet'],
  ['label' => 'ยอดเงินรวม', 'value' => '18,740,161.90', 'unit' => 'บาท', 'note' => 'คำนวณจากปริมาณ x ราคาล่าสุด', 'icon' => 'bi-shop', 'accent' => 'amber'],
  ['label' => 'ยอดหักรวม', 'value' => '1,205,337.36', 'unit' => 'บาท', 'note' => 'ค่าหัก/หนี้/รายการปรับปรุง', 'icon' => 'bi-people', 'accent' => 'purple'],
  ['label' => 'ยอดสุทธิที่จ่าย', 'value' => '17,534,827.22', 'unit' => 'บาท', 'note' => 'ยอดจ่ายหลังหักทั้งหมด', 'icon' => 'bi-wallet2', 'accent' => 'teal'],
];

$actions = [
  ['href' => 'rubbers.php', 'label' => 'รายการรับซื้อ', 'desc' => 'ค้นหา ตรวจสอบ และแก้ไขรายการรวบรวมยาง'],
  ['href' => 'members.php', 'label' => 'สมาชิก/เกษตรกร', 'desc' => 'เพิ่มข้อมูลสมาชิกและบัญชีรับเงิน'],
  ['href' => 'price.php', 'label' => 'ราคาอ้างอิง', 'desc' => 'บันทึกราคาตามวันที่และรอบรับซื้อ'],
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

function dashboard_thai_date($date)
{
  $months = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
  $time = strtotime($date);
  return (int) date('j', $time) . ' ' . $months[(int) date('n', $time)] . ' ' . ((int) date('Y', $time) + 543);
}

$dailyRows = [];
try {
  $dailyStmt = db()->query('
    SELECT ru_date,
           CASE WHEN SUM(ru_quantity) > 0 THEN SUM(ru_value) / SUM(ru_quantity) ELSE 0 END AS average_price,
           COALESCE(SUM(ru_quantity), 0) AS total_weight,
           COALESCE(SUM(ru_value), 0) AS total_gross,
           COALESCE(SUM(ru_expend), 0) AS total_deduct,
           COALESCE(SUM(ru_netvalue), 0) AS total_net,
           COUNT(*) AS total_records,
           COUNT(DISTINCT CASE WHEN ru_class = "member" THEN CONCAT(ru_number, ":", ru_fullname) END) AS member_count,
           COUNT(DISTINCT CASE WHEN ru_class <> "member" THEN CONCAT(ru_number, ":", ru_fullname) END) AS general_count
    FROM tbl_rubber
    GROUP BY ru_date
    ORDER BY ru_date DESC
    LIMIT 20
  ');

  foreach ($dailyStmt->fetchAll() as $row) {
    $dailyRows[] = [
      'date' => dashboard_thai_date($row['ru_date']),
      'price' => number_format((float) $row['average_price'], 2),
      'weight' => number_format((float) $row['total_weight'], 2),
      'gross' => number_format((float) $row['total_gross'], 2),
      'deduct' => number_format((float) $row['total_deduct'], 2),
      'net' => number_format((float) $row['total_net'], 2),
      'records' => number_format((int) $row['total_records']),
      'people' => number_format((int) $row['member_count']) . '/' . number_format((int) $row['general_count']),
    ];
  }
} catch (Throwable $e) {
  error_log('Dashboard daily summary failed: ' . $e->getMessage());
}

$maxWeight = max(array_column($rounds, 'weight'));
$maxAmount = max(array_column($rounds, 'amount'));
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>แดชบอร์ดระบบรวบรวมยาง</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
    integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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

  * {
    box-sizing: border-box;
  }

  body {
    margin: 0;
    font-family: "Sarabun", var(--bs-font-sans-serif);
    background: var(--bg);
    color: var(--ink);
  }

  a {
    color: inherit;
    text-decoration: none;
  }

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

  .brand-title {
    font-weight: 800;
    line-height: 1.25;
  }

  .brand-subtitle {
    margin-top: 2px;
    color: var(--muted);
    font-size: 12px;
  }

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

  .kpi-label {
    color: var(--muted);
    font-size: 13px;
  }

  .kpi-value {
    margin-top: 14px;
    font-size: 28px;
    font-weight: 900;
    line-height: 1;
  }

  .kpi-unit {
    margin-top: 6px;
    color: var(--muted);
    font-size: 13px;
  }

  .kpi-note {
    margin-top: 14px;
    color: var(--green-2);
    font-size: 12px;
    font-weight: 800;
    line-height: 1.4;
  }

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

  .fill.blue {
    background: var(--blue);
  }

  .bar-value {
    color: var(--muted);
    text-align: right;
    white-space: nowrap;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }

  th,
  td {
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

  tr:last-child td {
    border-bottom: 0;
  }

  td.num,
  th.num {
    text-align: right;
  }

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

  .action strong {
    display: block;
    margin-bottom: 5px;
  }

  .action span {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.4;
  }

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

  .avg span {
    color: var(--muted);
    font-size: 12px;
  }

  .avg strong {
    display: block;
    margin-top: 8px;
    font-size: 24px;
  }

  .footer-note {
    margin-top: 18px;
    color: var(--muted);
    font-size: 12px;
    text-align: right;
  }

  .table-wrap {
    overflow-x: auto;
  }

  @media (max-width: 1120px) {
    .app {
      grid-template-columns: 1fr;
    }

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

    .nav-label {
      display: none;
    }

    .layout {
      grid-template-columns: 1fr;
    }

    .kpis {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 760px) {
    .content {
      padding: 14px 12px 32px;
    }

    .topline {
      align-items: flex-start;
      flex-direction: column;
    }

    .toolbar {
      width: 100%;
      justify-content: stretch;
    }

    .toolbar .btn {
      flex: 1 1 auto;
    }

    .price-strip {
      grid-template-columns: 1fr;
    }

    .latest-price {
      font-size: 24px;
    }

    .kpis,
    .summary-grid,
    .charts {
      grid-template-columns: 1fr;
    }

    .bar-row {
      grid-template-columns: 54px minmax(0, 1fr) 78px;
    }
  }

  /* Material Admin inspired theme */
  body {
    background: #f5f6f8;
  }

  .topbar {
    position: sticky;
    top: 0;
    z-index: 1030;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 30px;
    background: #212121;
    color: #fff;
    box-shadow: 0 2px 5px rgba(0, 0, 0, .22);
  }

  .topbar-brand,
  .topbar-tools {
    display: flex;
    align-items: center;
    gap: 22px;
  }

  .topbar-brand {
    font-size: 15px;
    font-weight: 700;
    letter-spacing: .08em;
  }

  .topbar-brand i {
    font-size: 22px;
  }

  .topbar-tools a {
    color: #f5f5f5;
    font-size: 14px;
  }

  .topbar-tools i {
    font-size: 18px;
  }

  .app {
    min-height: calc(100vh - 64px);
    grid-template-columns: 260px minmax(0, 1fr);
  }

  .sidebar {
    top: 64px;
    height: calc(100vh - 64px);
    display: flex;
    flex-direction: column;
    padding: 24px 16px 18px;
    background: linear-gradient(180deg, #fff 0%, #fbfaff 100%);
    border-color: #e9e5f2;
    box-shadow: 8px 0 30px rgba(40, 24, 80, .04);
  }

  .brand {
    gap: 12px;
    margin: 0 4px 22px;
    padding: 8px 8px 20px;
    border-bottom: 1px solid #eeeaf5;
  }

  .brand-mark {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    border-radius: 13px;
    background: linear-gradient(135deg, #7c3aed, #4f16d5);
    box-shadow: 0 7px 16px rgba(101, 31, 255, .25);
    font-size: 20px;
  }

  .brand-title {
    color: #241b35;
    font-size: 16px;
  }

  .brand-subtitle {
    color: #948ba4;
    font-size: 11px;
  }

  .nav-label {
    margin: 16px 14px 8px;
    color: #a29aaa;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
  }

  .nav {
    display: grid;
    gap: 5px;
  }

  .nav a {
    position: relative;
    min-height: 46px;
    gap: 12px;
    padding: 0 13px;
    border-radius: 10px;
    color: #625a70;
    font-size: 14px;
    font-weight: 600;
    transition: background .2s ease, color .2s ease, transform .2s ease;
  }

  .nav a::before {
    content: none;
  }

  .nav a i {
    width: 34px;
    height: 34px;
    display: grid;
    flex: 0 0 34px;
    place-items: center;
    border-radius: 9px;
    background: #f4f1f8;
    color: #80768d;
    font-size: 16px;
    transition: inherit;
  }

  .nav a .menu-arrow {
    width: auto;
    height: auto;
    margin-left: auto;
    background: transparent;
    font-size: 12px;
  }

  .nav a.active,
  .nav a:hover {
    background: #f1eaff;
    color: #5b21d2;
    transform: translateX(2px);
  }

  .nav a.active::after {
    content: "";
    position: absolute;
    left: -16px;
    width: 4px;
    height: 25px;
    border-radius: 0 6px 6px 0;
    background: #651fff;
  }

  .nav a.active i,
  .nav a:hover i {
    background: #651fff;
    color: #fff;
    box-shadow: 0 5px 12px rgba(101, 31, 255, .2);
  }

  .nav a:hover .menu-arrow {
    background: transparent;
    color: #651fff;
    box-shadow: none;
  }

  .sidebar-account {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: auto;
    padding: 13px;
    border: 1px solid #ebe6f2;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 6px 18px rgba(43, 27, 70, .06);
  }

  .account-avatar {
    width: 38px;
    height: 38px;
    display: grid;
    flex: 0 0 38px;
    place-items: center;
    border-radius: 50%;
    background: #ede5ff;
    color: #651fff;
    font-size: 18px;
  }

  .account-copy {
    min-width: 0;
    line-height: 1.25;
  }

  .account-copy strong {
    display: block;
    color: #31283d;
    font-size: 13px;
  }

  .account-copy span {
    color: #9b93a4;
    font-size: 10px;
  }

  .sidebar-account>i {
    margin-left: auto;
    color: #aaa1b5;
  }

  .content {
    padding: 34px 38px 48px;
  }

  .topline {
    align-items: flex-start;
    margin-bottom: 32px;
  }

  .page-title h1 {
    font-size: 34px;
    font-weight: 500;
  }

  .page-title p {
    font-size: 14px;
  }

  .toolbar .btn {
    min-height: 46px;
    border-color: #b7b7b7;
    border-radius: 4px;
    box-shadow: none;
    font-weight: 500;
  }

  .toolbar .btn-primary {
    border-color: #651fff;
    background: #651fff;
  }

  .price-strip {
    border: 0;
    border-left: 4px solid #651fff;
    border-radius: 3px;
    background: #fff;
    box-shadow: 0 2px 7px rgba(0, 0, 0, .12);
  }

  .latest-price {
    color: #651fff;
  }

  .kpis {
    gap: 28px;
    margin-bottom: 34px;
  }

  .kpi {
    position: relative;
    min-height: 126px;
    padding: 18px 72px 16px 24px;
    border: 0;
    border-left: 4px solid var(--accent);
    border-radius: 3px;
    background: #fff;
    box-shadow: 0 2px 7px rgba(0, 0, 0, .14);
  }

  .kpi-icon {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    font-size: 21px;
  }

  .kpi.violet {
    --accent: #651fff;
  }

  .kpi.amber {
    --accent: #ffab00;
  }

  .kpi.purple {
    --accent: #9c27b0;
  }

  .kpi.teal {
    --accent: #26a69a;
  }

  .kpi-value {
    margin-top: 7px;
    font-size: 23px;
    font-weight: 500;
  }

  .kpi-unit {
    margin-top: 2px;
  }

  .kpi-note {
    margin-top: 8px;
    color: #777;
    font-weight: 500;
  }

  .layout {
    grid-template-columns: minmax(0, 2.1fr) minmax(300px, 1fr);
    gap: 28px;
  }

  .charts,
  .side-stack {
    gap: 28px;
  }

  .chart-card {
    position: relative;
  }

  .chart-card .card-head {
    align-items: flex-start;
  }

  .chart-title-wrap small {
    display: block;
    margin-top: 4px;
  }

  .chart-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    background: #f0eaff;
    color: #651fff;
    font-size: 11px;
    font-weight: 700;
  }

  .chart-badge.teal {
    background: #e5f7f4;
    color: #16877d;
  }

  .chart-body {
    padding: 18px 20px 14px;
  }

  .chart-canvas-wrap {
    position: relative;
    height: 285px;
  }

  .chart-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
    padding: 0 3px;
  }

  .chart-summary span {
    color: #958d9f;
    font-size: 11px;
  }

  .chart-summary strong {
    display: block;
    margin-top: 2px;
    color: #30273d;
    font-size: 20px;
  }

  .chart-trend {
    color: #16877d !important;
    font-weight: 700;
  }

  .card {
    border: 0;
    border-radius: 3px;
    box-shadow: 0 2px 7px rgba(0, 0, 0, .14);
  }

  .card-head {
    min-height: 70px;
    padding: 18px 22px;
    background: #fff;
    border-color: #e5e5e5;
  }

  .card-head h2 {
    font-size: 19px;
    font-weight: 600;
  }

  .summary-item,
  .action,
  .avg {
    border-color: #e4e4e4;
    border-radius: 3px;
    background: #fafafa;
  }

  .fill {
    background: #651fff;
  }

  .fill.blue {
    background: #a17af4;
  }

  .track {
    background: #eee;
  }

  th {
    background: #fafafa;
  }

  .action:hover {
    border-color: #651fff;
    background: #f7f3ff;
    color: #651fff;
  }

  @media (max-width: 1120px) {
    .app {
      grid-template-columns: 1fr;
    }

    .sidebar {
      position: static;
      height: auto;
      padding: 12px 20px;
    }

    .brand,
    .nav-label {
      display: none;
    }

    .nav {
      display: flex;
    }

    .nav a {
      flex: 0 0 auto;
    }

    .nav a.active::after,
    .sidebar-account {
      display: none;
    }

    .content {
      padding: 28px 24px 40px;
    }

    .layout {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 760px) {
    .topbar {
      height: 56px;
      padding: 0 16px;
    }

    .topbar-tools .text-link {
      display: none;
    }

    .topbar-brand span {
      font-size: 13px;
    }

    .content {
      padding: 24px 14px 34px;
    }

    .page-title h1 {
      font-size: 28px;
    }

    .kpis {
      gap: 14px;
    }

    .layout,
    .charts,
    .side-stack {
      gap: 18px;
    }
  }
  </style>
  <link href="<?php echo htmlspecialchars(navbar_url('navbar.css')); ?>" rel="stylesheet">
</head>

<body>
  <?php render_topbar(); ?>
  <div class="app">
    <?php render_sidebar('overview'); ?>

    <main class="content" id="overview">
      <header class="topline">
        <div class="page-title">
          <h1>ภาพรวมข้อมูลการรวบรวมยาง</h1>
          <p>สรุปราคา ปริมาณ ยอดหัก ยอดสุทธิ และข้อมูลแยกตามลานในหน้าเดียว</p>
        </div>
      </header>

      <section class="price-strip">
        <div>
          <strong>ราคาล่าสุดที่ใช้คำนวณ</strong>
          <span><?php echo htmlspecialchars($latestPrice['date']); ?> ·
            <?php echo htmlspecialchars($latestPrice['round']); ?> · ทั้งหมด
            <?php echo number_format($latestPrice['records']); ?> รายการ</span>
        </div>
        <div class="latest-price"><?php echo number_format($latestPrice['price'], 2); ?> บาท/kg</div>
        <a class="btn" href="<?php echo htmlspecialchars($basePath); ?>/price.php">ดูประวัติราคา</a>
      </section>

      <section class="kpis">
        <?php foreach ($kpis as $kpi): ?>
        <article class="kpi <?php echo htmlspecialchars($kpi['accent']); ?>">
          <div class="kpi-icon"><i class="bi <?php echo htmlspecialchars($kpi['icon']); ?>" aria-hidden="true"></i>
          </div>
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
            <div class="card chart-card">
              <div class="card-head">
                <div class="chart-title-wrap">
                  <h2>ปริมาณยางตามรอบ</h2>
                  <small>เปรียบเทียบปริมาณรับซื้อ 6 รอบล่าสุด</small>
                </div>
                <span class="chart-badge"><i class="bi bi-bar-chart-fill"></i> กิโลกรัม</span>
              </div>
              <div class="chart-body">
                <div class="chart-summary">
                  <div><span>รอบล่าสุด</span><strong><?php echo number_format($rounds[0]['weight']); ?> kg</strong>
                  </div>
                  <span class="chart-trend"><i class="bi bi-arrow-up-right"></i> ปริมาณสูงสุด</span>
                </div>
                <div class="chart-canvas-wrap">
                  <canvas id="weightChart" aria-label="กราฟปริมาณยางตามรอบ" role="img"></canvas>
                </div>
              </div>
            </div>

            <div class="card chart-card">
              <div class="card-head">
                <div class="chart-title-wrap">
                  <h2>แนวโน้มยอดสุทธิ</h2>
                  <small>ยอดจ่ายสุทธิในแต่ละรอบรับซื้อ</small>
                </div>
                <span class="chart-badge teal"><i class="bi bi-graph-up-arrow"></i> ล้านบาท</span>
              </div>
              <div class="chart-body">
                <div class="chart-summary">
                  <div>
                    <span>ยอดสุทธิรอบล่าสุด</span><strong><?php echo number_format($rounds[0]['amount'] / 1000000, 2); ?>
                      ล้านบาท</strong></div>
                  <span class="chart-trend"><i class="bi bi-check-circle-fill"></i> อัปเดตแล้ว</span>
                </div>
                <div class="chart-canvas-wrap">
                  <canvas id="amountChart" aria-label="กราฟแนวโน้มยอดสุทธิตามรอบ" role="img"></canvas>
                </div>
              </div>
            </div>
          </section>

          <section class="card" id="yards" style="margin-top: 18px;">
            <div class="card-head">
              <h2>ปริมาณรวบรวมแต่ละลาน</h2>
              <small>วันที่ราคา: <?php echo htmlspecialchars($latestPrice['date']); ?></small>
            </div>
            <div class="table-wrap">
              <table class="table table-hover align-middle mb-0">
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
                        <div class="track">
                          <div class="fill" style="--w: <?php echo $yard['share']; ?>%;"></div>
                        </div>
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
              <table class="table table-hover align-middle mb-0">
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
              <a class="action"
                href="<?php echo htmlspecialchars(strpos($action['href'], '/') === 0 ? $action['href'] : $basePath . '/' . $action['href']); ?>">
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
        Route: <?php echo htmlspecialchars($basePath); ?> · PHP <?php echo htmlspecialchars(PHP_VERSION); ?> ·
        วันที่ระบบ <?php echo htmlspecialchars($today); ?>
      </div>
    </main>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous">
  </script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <script>
  (() => {
    if (typeof Chart === 'undefined') return;

    const rounds =
    <?php echo json_encode(array_reverse($rounds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const labels = rounds.map(item => item.date);
    const number = new Intl.NumberFormat('th-TH');

    Chart.defaults.font.family = 'Sarabun, sans-serif';
    Chart.defaults.color = '#81798b';
    Chart.defaults.animation.duration = 1100;

    const sharedScales = {
      x: {
        grid: {
          display: false
        },
        border: {
          display: false
        },
        ticks: {
          font: {
            size: 11,
            weight: '600'
          }
        }
      },
      y: {
        beginAtZero: true,
        border: {
          display: false
        },
        grid: {
          color: 'rgba(83, 64, 107, .08)',
          drawTicks: false
        },
        ticks: {
          padding: 10,
          font: {
            size: 10
          }
        }
      }
    };

    new Chart(document.getElementById('weightChart'), {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'ปริมาณยาง',
          data: rounds.map(item => item.weight),
          backgroundColor: context => {
            const {
              chart
            } = context;
            const area = chart.chartArea;
            if (!area) return '#7c3aed';
            const gradient = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
            gradient.addColorStop(0, '#7c3aed');
            gradient.addColorStop(1, '#b79af7');
            return gradient;
          },
          hoverBackgroundColor: '#5b21d2',
          borderRadius: 8,
          borderSkipped: false,
          maxBarThickness: 38
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          intersect: false,
          mode: 'index'
        },
        scales: {
          x: sharedScales.x,
          y: {
            ...sharedScales.y,
            ticks: {
              ...sharedScales.y.ticks,
              callback: value => value >= 1000 ? `${value / 1000}K` : value
            }
          }
        },
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            padding: 12,
            displayColors: false,
            backgroundColor: '#292032',
            titleFont: {
              family: 'Sarabun',
              size: 12
            },
            bodyFont: {
              family: 'Sarabun',
              size: 13,
              weight: '600'
            },
            callbacks: {
              label: context => ` ${number.format(context.raw)} กิโลกรัม`
            }
          }
        }
      }
    });

    new Chart(document.getElementById('amountChart'), {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'ยอดสุทธิ',
          data: rounds.map(item => item.amount / 1000000),
          borderColor: '#20a99a',
          backgroundColor: context => {
            const {
              chart
            } = context;
            const area = chart.chartArea;
            if (!area) return 'rgba(32, 169, 154, .16)';
            const gradient = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
            gradient.addColorStop(0, 'rgba(32, 169, 154, .32)');
            gradient.addColorStop(1, 'rgba(32, 169, 154, .01)');
            return gradient;
          },
          fill: true,
          tension: .42,
          pointRadius: 4,
          pointHoverRadius: 7,
          pointBackgroundColor: '#fff',
          pointBorderColor: '#20a99a',
          pointBorderWidth: 3,
          borderWidth: 3
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          intersect: false,
          mode: 'index'
        },
        scales: {
          x: sharedScales.x,
          y: {
            ...sharedScales.y,
            ticks: {
              ...sharedScales.y.ticks,
              callback: value => `${value}M`
            }
          }
        },
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            padding: 12,
            displayColors: false,
            backgroundColor: '#183d3a',
            callbacks: {
              label: context => ` ${number.format(context.raw)} ล้านบาท`
            }
          }
        }
      }
    });
  })();
  </script>
</body>

</html>

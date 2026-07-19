<?php
require_once __DIR__ . '/auth.php';
require_user();
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/navbar.php';

$user = current_user();
if (($user['user_level'] ?? '') !== 'admin') {
  http_response_code(403);
  exit('เฉพาะผู้ดูแลระบบเท่านั้น');
}

sync_workflow_records();

function paid_summary_valid_date($value)
{
  if ($value === '') return true;
  $date = DateTime::createFromFormat('Y-m-d', $value);
  return $date && $date->format('Y-m-d') === $value;
}

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
if (!paid_summary_valid_date($dateFrom)) $dateFrom = '';
if (!paid_summary_valid_date($dateTo)) $dateTo = '';
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
  $swap = $dateFrom; $dateFrom = $dateTo; $dateTo = $swap;
}

$where = ['p.pr_id = (SELECT MAX(p2.pr_id) FROM tbl_price p2 WHERE p2.pr_date = p.pr_date)'];
$params = [];
if ($dateFrom !== '') { $where[] = 'p.pr_date >= :date_from'; $params['date_from'] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'p.pr_date <= :date_to'; $params['date_to'] = $dateTo; }

$roundStmt = db()->prepare('SELECT p.pr_date, p.pr_number, p.pr_price,
    COUNT(w.workflow_id) AS paid_count,
    COUNT(DISTINCT w.member_id) AS member_count,
    COALESCE(SUM(w.total_bags), 0) AS total_bags,
    COALESCE(SUM(w.actual_weight), 0) AS total_weight,
    COALESCE(SUM(w.gross_amount), 0) AS total_gross,
    COALESCE(SUM(w.total_deduction), 0) AS total_deduction,
    COALESCE(SUM(w.net_amount), 0) AS total_net,
    MIN(w.paid_at) AS first_paid_at,
    MAX(w.paid_at) AS last_paid_at,
    (SELECT COUNT(*) FROM tbl_rubber_workflow all_workflow WHERE all_workflow.weigh_date = p.pr_date) AS workflow_count
  FROM tbl_price p
  LEFT JOIN tbl_rubber_workflow w ON w.weigh_date = p.pr_date AND w.workflow_status = "paid"
  WHERE ' . implode(' AND ', $where) . '
  GROUP BY p.pr_id, p.pr_date, p.pr_number, p.pr_price
  ORDER BY p.pr_date DESC
  LIMIT 200');
$roundStmt->execute($params);
$rounds = $roundStmt->fetchAll();

$roundMap = [];
$overall = ['paid_count' => 0, 'total_weight' => 0, 'total_gross' => 0, 'total_deduction' => 0, 'total_net' => 0];
foreach ($rounds as $round) {
  $roundMap[$round['pr_date']] = $round;
  foreach ($overall as $key => $value) $overall[$key] += (float) $round[$key];
}

$selectedRound = trim($_GET['round'] ?? '');
if (!isset($roundMap[$selectedRound])) $selectedRound = $rounds[0]['pr_date'] ?? '';
$selectedSummary = $selectedRound !== '' ? $roundMap[$selectedRound] : null;
$details = [];
$yards = [];

if ($selectedRound !== '') {
  $detailStmt = db()->prepare('SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name
    FROM tbl_rubber_workflow workflow
    LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code
    WHERE workflow.workflow_status = "paid" AND workflow.weigh_date = :round_date
    ORDER BY CAST(workflow.yard_code AS UNSIGNED), workflow.yard_code, CAST(workflow.member_number AS UNSIGNED), workflow.member_number');
  $detailStmt->execute(['round_date' => $selectedRound]);
  $details = $detailStmt->fetchAll();

  $yardStmt = db()->prepare('SELECT workflow.yard_code,
      COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name,
      COUNT(*) AS paid_count, COUNT(DISTINCT workflow.member_id) AS member_count,
      COALESCE(SUM(workflow.actual_weight), 0) AS total_weight,
      COALESCE(SUM(workflow.total_deduction), 0) AS total_deduction,
      COALESCE(SUM(workflow.net_amount), 0) AS total_net
    FROM tbl_rubber_workflow workflow
    LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code
    WHERE workflow.workflow_status = "paid" AND workflow.weigh_date = :round_date
    GROUP BY workflow.yard_code, yard.yard_name
    ORDER BY CAST(workflow.yard_code AS UNSIGNED), workflow.yard_code');
  $yardStmt->execute(['round_date' => $selectedRound]);
  $yards = $yardStmt->fetchAll();
}

if (($_GET['export'] ?? '') === 'csv' && $selectedRound !== '') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="paid-round-' . $selectedRound . '.csv"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['รอบวันที่ราคา', 'ราคาต่อกิโลกรัม', 'เลขที่ใบเสร็จ', 'ลาน', 'เลขสมาชิก', 'ชื่อสมาชิก', 'น้ำหนักจริง (kg)', 'ยอดค่ายาง', 'ยอดหัก', 'ยอดจ่ายสุทธิ', 'วันที่จ่าย', 'ผู้จ่าย']);
  foreach ($details as $row) {
    fputcsv($out, [$selectedRound, $selectedSummary['pr_price'], $row['receipt_no'], $row['yard_name'], $row['member_number'], $row['member_name'], $row['actual_weight'], $row['gross_amount'], $row['total_deduction'], $row['net_amount'], $row['paid_at'], $row['paid_by']]);
  }
  fclose($out);
  exit;
}

$filterQuery = ['date_from' => $dateFrom, 'date_to' => $dateTo];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>สรุปยอดจ่ายแต่ละรอบ</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
  <link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet">
</head>
<body>
<?php render_topbar(); ?>
<main class="ops-shell">
  <section class="ops-hero">
    <div><h1><i class="bi bi-clipboard2-data-fill me-2"></i>สรุปยอดจ่ายแต่ละรอบ</h1><p>สรุปรายการที่จ่ายเงินแล้ว โดยอ้างอิงรอบจากวันที่กำหนดราคายาง</p></div>
    <span class="pill">เฉพาะผู้ดูแลระบบ</span>
  </section>

  <section class="ops-card mt-3 no-print">
    <div class="ops-card-body">
      <form class="filter-row" method="get">
        <div><label class="form-label">รอบราคาตั้งแต่</label><input class="form-control" type="date" name="date_from" value="<?php echo h($dateFrom); ?>"></div>
        <div><label class="form-label">ถึงรอบราคา</label><input class="form-control" type="date" name="date_to" value="<?php echo h($dateTo); ?>"></div>
        <button class="btn btn-dark"><i class="bi bi-search me-1"></i>แสดงผล</button>
        <a class="btn btn-outline-secondary" href="<?php echo h(url_for('paid-summary.php')); ?>">ล้างตัวกรอง</a>
      </form>
    </div>
  </section>

  <section class="stat-grid">
    <article class="stat-card"><span>รอบราคาตามตัวกรอง</span><strong><?php echo number_format(count($rounds)); ?></strong><small>รอบ</small></article>
    <article class="stat-card"><span>รายการจ่ายเงินแล้ว</span><strong><?php echo number_format($overall['paid_count']); ?></strong><small>รายการ</small></article>
    <article class="stat-card"><span>ยอดหักรวม</span><strong><?php echo number_format($overall['total_deduction'], 2); ?></strong><small>บาท</small></article>
    <article class="stat-card"><span>ยอดจ่ายสุทธิรวม</span><strong><?php echo number_format($overall['total_net'], 2); ?></strong><small>บาท</small></article>
  </section>

  <section class="ops-card mt-3">
    <div class="ops-card-head"><h2>สรุปตามรอบวันที่ราคา</h2><span class="badge-soft"><?php echo number_format(count($rounds)); ?> รอบ</span></div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>รอบวันที่ราคา</th><th class="num">ราคา/kg</th><th class="num">จ่ายแล้ว / ทั้งรอบ</th><th class="num">สมาชิก</th><th class="num">น้ำหนัก</th><th class="num">ยอดค่ายาง</th><th class="num">ยอดหัก</th><th class="num">ยอดจ่ายสุทธิ</th><th>ตรวจสอบ</th></tr></thead>
        <tbody>
        <?php foreach ($rounds as $round): ?>
          <?php $roundQuery = array_merge($filterQuery, ['round' => $round['pr_date']]); ?>
          <tr class="<?php echo $selectedRound === $round['pr_date'] ? 'table-success' : ''; ?>">
            <td><strong><?php echo h($round['pr_date']); ?></strong><br><small>รอบตามวันที่กำหนดราคา</small></td>
            <td class="num fw-bold"><?php echo number_format((float) $round['pr_price'], 2); ?></td>
            <td class="num"><strong><?php echo number_format($round['paid_count']); ?></strong> / <?php echo number_format($round['workflow_count']); ?></td>
            <td class="num"><?php echo number_format($round['member_count']); ?></td>
            <td class="num"><?php echo number_format((float) $round['total_weight'], 2); ?> kg</td>
            <td class="num"><?php echo number_format((float) $round['total_gross'], 2); ?></td>
            <td class="num text-danger"><?php echo number_format((float) $round['total_deduction'], 2); ?></td>
            <td class="num fw-bold text-success"><?php echo number_format((float) $round['total_net'], 2); ?></td>
            <td><a class="btn btn-sm btn-outline-success" href="?<?php echo h(http_build_query($roundQuery)); ?>"><i class="bi bi-eye me-1"></i>ดูรายการ</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rounds): ?><tr><td class="empty" colspan="9">ไม่พบรอบราคายางตามช่วงวันที่ที่เลือก</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if ($selectedSummary): ?>
    <section class="ops-card mt-3">
      <div class="ops-card-head">
        <h2>รายการจ่ายแล้ว · รอบราคา <?php echo h($selectedRound); ?> · <?php echo number_format((float) $selectedSummary['pr_price'], 2); ?> บาท/kg</h2>
        <div class="d-flex gap-2 no-print"><a class="btn btn-sm btn-outline-secondary" href="?<?php echo h(http_build_query(array_merge($filterQuery, ['round' => $selectedRound, 'export' => 'csv']))); ?>"><i class="bi bi-filetype-csv me-1"></i>CSV</a><button class="btn btn-sm btn-outline-dark" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์</button></div>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>ลาน</th><th class="num">รายการ</th><th class="num">สมาชิก</th><th class="num">น้ำหนัก</th><th class="num">ยอดหัก</th><th class="num">ยอดจ่ายสุทธิ</th></tr></thead>
          <tbody><?php foreach ($yards as $yard): ?><tr><td><span class="badge-soft"><?php echo h($yard['yard_name']); ?></span></td><td class="num"><?php echo number_format($yard['paid_count']); ?></td><td class="num"><?php echo number_format($yard['member_count']); ?></td><td class="num"><?php echo number_format((float) $yard['total_weight'], 2); ?> kg</td><td class="num text-danger"><?php echo number_format((float) $yard['total_deduction'], 2); ?></td><td class="num fw-bold text-success"><?php echo number_format((float) $yard['total_net'], 2); ?></td></tr><?php endforeach; ?><?php if (!$yards): ?><tr><td class="empty" colspan="6">รอบราคานี้ยังไม่มีรายการที่จ่ายเงินแล้ว</td></tr><?php endif; ?></tbody>
        </table>
      </div>
    </section>

    <section class="ops-card mt-3">
      <div class="ops-card-head"><h2>รายละเอียดผู้รับเงิน</h2><span class="badge-soft"><?php echo number_format(count($details)); ?> รายการ</span></div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>ใบเสร็จ</th><th>ลาน</th><th>สมาชิก</th><th class="num">น้ำหนัก</th><th class="num">ยอดค่ายาง</th><th class="num">ยอดหัก</th><th class="num">ยอดสุทธิ</th><th>ข้อมูลการจ่าย</th></tr></thead>
          <tbody>
          <?php foreach ($details as $row): ?><tr><td><a class="fw-bold" target="_blank" href="<?php echo h(url_for('receipt.php?id=' . (int) $row['workflow_id'])); ?>"><?php echo h($row['receipt_no']); ?> <i class="bi bi-box-arrow-up-right"></i></a></td><td><?php echo h($row['yard_name']); ?></td><td><strong><?php echo h($row['member_number']); ?></strong><br><small><?php echo h($row['member_name']); ?></small></td><td class="num"><?php echo number_format((float) $row['actual_weight'], 2); ?> kg</td><td class="num"><?php echo number_format((float) $row['gross_amount'], 2); ?></td><td class="num text-danger"><?php echo number_format((float) $row['total_deduction'], 2); ?></td><td class="num fw-bold text-success"><?php echo number_format((float) $row['net_amount'], 2); ?></td><td><small><?php echo h($row['paid_at']); ?><br><?php echo h($row['paid_by']); ?></small></td></tr><?php endforeach; ?>
          <?php if (!$details): ?><tr><td class="empty" colspan="8">รอบราคานี้ยังไม่มีรายการที่จ่ายเงินแล้ว</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</main>
</body>
</html>

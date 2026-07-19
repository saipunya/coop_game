<?php
require_once __DIR__ . '/auth.php';
require_user();
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/navbar.php';

sync_workflow_records();
$user = current_user();
$stagePermissions = ['placement' => 'placement', 'weighing' => 'weighing', 'deduction' => 'deductions', 'payment' => 'payments'];
$visibleStages = array_keys(array_filter($stagePermissions, function ($permission) use ($user) {
  return user_can($permission, $user);
}));
if (!$visibleStages) {
  http_response_code(403);
  exit('บัญชีนี้ไม่มีสิทธิ์เข้าถึงข้อมูลขั้นตอนการรับซื้อยาง');
}
$canPrintReceipt = user_can('payments', $user);
$canViewFinancialSummary = user_can('deductions', $user) || user_can('payments', $user);
$error = '';

function rubber_summary_datetime($value)
{
  if (!$value) return '—';
  $timestamp = strtotime($value);
  if (!$timestamp) return (string) $value;
  return date('d/m/', $timestamp) . ((int) date('Y', $timestamp) + 543) . ' · ' . date('H:i', $timestamp) . ' น.';
}

function rubber_summary_date($value)
{
  if (!$value) return '—';
  $timestamp = strtotime($value);
  if (!$timestamp) return (string) $value;
  return date('d/m/', $timestamp) . ((int) date('Y', $timestamp) + 543);
}

function rubber_summary_search($key)
{
  return mb_substr(trim((string) ($_GET[$key] ?? '')), 0, 100);
}

function rubber_summary_page($key)
{
  $page = filter_var($_GET[$key] ?? 1, FILTER_VALIDATE_INT);
  return $page && $page > 0 ? $page : 1;
}

function rubber_summary_query($stage, $search, $page, $perPage = 10)
{
  $stageConfig = [
    'placement' => [
      'condition' => '1=1',
      'order' => 'COALESCE(workflow.placement_at, placement.placement_first_at, workflow.created_at) DESC',
      'extra' => ', placement.placement_by, placement.placement_first_at, placement.placement_last_at',
      'join' => 'LEFT JOIN (
        SELECT wang_date, wang_lan, wang_mid,
          GROUP_CONCAT(DISTINCT NULLIF(wang_saveby, "") ORDER BY wang_saveby SEPARATOR ", ") AS placement_by,
          MIN(wang_savedate) AS placement_first_at, MAX(wang_savedate) AS placement_last_at
        FROM tbl_wangyang
        WHERE wang_status IN ("placed", "weighed", "deducted", "paid")
        GROUP BY wang_date, wang_lan, wang_mid
      ) placement ON placement.wang_date = workflow.weigh_date
        AND placement.wang_lan = workflow.yard_code AND placement.wang_mid = workflow.member_id',
    ],
    'weighing' => [
      'condition' => 'workflow.weighed_at IS NOT NULL',
      'order' => 'workflow.weighed_at DESC',
      'extra' => '',
      'join' => '',
    ],
    'deduction' => [
      'condition' => 'workflow.deduction_at IS NOT NULL',
      'order' => 'workflow.deduction_at DESC',
      'extra' => ', deduction_items.deduction_items',
      'join' => 'LEFT JOIN (
        SELECT workflow_id,
          GROUP_CONCAT(CONCAT(deduction_label, " ", FORMAT(deduction_amount, 2)) ORDER BY deduction_id SEPARATOR " · ") AS deduction_items
        FROM tbl_rubber_deduction GROUP BY workflow_id
      ) deduction_items ON deduction_items.workflow_id = workflow.workflow_id',
    ],
    'payment' => [
      'condition' => 'workflow.receipt_no IS NOT NULL',
      'order' => 'COALESCE(workflow.paid_at, workflow.deduction_at) DESC',
      'extra' => ', receipt_log.receipt_view_by, receipt_log.receipt_view_at',
      'join' => 'LEFT JOIN (
        SELECT entity_id,
          SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(actor_fullname, "") ORDER BY created_at DESC SEPARATOR "||"), "||", 1) AS receipt_view_by,
          MAX(created_at) AS receipt_view_at
        FROM tbl_audit_log
        WHERE action_key = "view_receipt" AND entity_type = "workflow"
        GROUP BY entity_id
      ) receipt_log ON CAST(receipt_log.entity_id AS UNSIGNED) = workflow.workflow_id',
    ],
  ];

  if (!isset($stageConfig[$stage])) throw new RuntimeException('ไม่พบขั้นตอนที่ต้องการสรุป');
  $config = $stageConfig[$stage];
  $where = [$config['condition']];
  $params = [];
  if ($search !== '') {
    $where[] = '(workflow.member_name LIKE :search_name OR workflow.member_number LIKE :search_number OR workflow.member_group LIKE :search_group)';
    $params['search_name'] = '%' . $search . '%';
    $params['search_number'] = '%' . $search . '%';
    $params['search_group'] = '%' . $search . '%';
  }

  $baseFrom = ' FROM tbl_rubber_workflow workflow
    LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code
    ' . $config['join'] . '
    WHERE ' . implode(' AND ', $where);
  $summaryStmt = db()->prepare('SELECT COUNT(*) AS record_count,
    COALESCE(SUM(workflow.gross_amount), 0) AS gross_amount,
    COALESCE(SUM(workflow.total_deduction), 0) AS total_deduction,
    COALESCE(SUM(workflow.net_amount), 0) AS net_amount,
    COALESCE(SUM(CASE WHEN workflow.paid_at IS NOT NULL THEN workflow.net_amount ELSE 0 END), 0) AS paid_amount,
    SUM(CASE WHEN workflow.paid_at IS NOT NULL THEN 1 ELSE 0 END) AS paid_count' . $baseFrom);
  $summaryStmt->execute($params);
  $summary = $summaryStmt->fetch() ?: [];
  $count = (int) ($summary['record_count'] ?? 0);
  $totalPages = max(1, (int) ceil($count / $perPage));
  $page = min(max(1, (int) $page), $totalPages);
  $offset = ($page - 1) * $perPage;

  $sql = 'SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name'
    . $config['extra'] . $baseFrom . ' ORDER BY ' . $config['order'] . ', workflow.workflow_id DESC LIMIT :page_limit OFFSET :page_offset';
  $stmt = db()->prepare($sql);
  foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
  $stmt->bindValue(':page_limit', $perPage, PDO::PARAM_INT);
  $stmt->bindValue(':page_offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  return [
    'count' => $count,
    'rows' => $stmt->fetchAll(),
    'page' => $page,
    'pages' => $totalPages,
    'from' => $count ? $offset + 1 : 0,
    'to' => min($offset + $perPage, $count),
    'gross_amount' => (float) ($summary['gross_amount'] ?? 0),
    'total_deduction' => (float) ($summary['total_deduction'] ?? 0),
    'net_amount' => (float) ($summary['net_amount'] ?? 0),
    'paid_amount' => (float) ($summary['paid_amount'] ?? 0),
    'paid_count' => (int) ($summary['paid_count'] ?? 0),
  ];
}

function rubber_summary_url($searches, $pages, $changes, $anchor)
{
  $params = [];
  foreach ($searches as $stage => $value) {
    if ($value !== '') $params[$stage . '_q'] = $value;
  }
  foreach ($pages as $stage => $page) {
    if ($page > 1) $params[$stage . '_page'] = $page;
  }
  foreach ($changes as $key => $value) {
    if ($value === null || $value === '' || $value === 1) unset($params[$key]);
    else $params[$key] = $value;
  }
  return url_for('rubbers.php') . ($params ? '?' . http_build_query($params) : '') . '#' . $anchor;
}

function rubber_summary_page_numbers($current, $total)
{
  if ($total <= 7) return range(1, $total);
  $pages = [1, $total];
  for ($page = max(2, $current - 2); $page <= min($total - 1, $current + 2); $page++) $pages[] = $page;
  $pages = array_values(array_unique($pages));
  sort($pages);
  return $pages;
}

$searches = [
  'placement' => rubber_summary_search('placement_q'),
  'weighing' => rubber_summary_search('weighing_q'),
  'deduction' => rubber_summary_search('deduction_q'),
  'payment' => rubber_summary_search('payment_q'),
];
$pages = [
  'placement' => rubber_summary_page('placement_page'),
  'weighing' => rubber_summary_page('weighing_page'),
  'deduction' => rubber_summary_page('deduction_page'),
  'payment' => rubber_summary_page('payment_page'),
];
$sections = [];
foreach ($visibleStages as $stage) $sections[$stage] = ['count' => 0, 'rows' => [], 'page' => 1, 'pages' => 1, 'from' => 0, 'to' => 0, 'gross_amount' => 0, 'total_deduction' => 0, 'net_amount' => 0, 'paid_amount' => 0, 'paid_count' => 0];
$paidCount = 0;
$financialOverview = ['paid_count' => 0, 'member_count' => 0, 'gross_amount' => 0, 'total_deduction' => 0, 'paid_amount' => 0];

try {
  foreach ($sections as $stage => $unused) {
    $sections[$stage] = rubber_summary_query($stage, $searches[$stage], $pages[$stage], 10);
    $pages[$stage] = $sections[$stage]['page'];
  }
  if (in_array('payment', $visibleStages, true)) {
    $paidCount = (int) db()->query('SELECT COUNT(*) FROM tbl_rubber_workflow WHERE paid_at IS NOT NULL')->fetchColumn();
  }
  if ($canViewFinancialSummary) {
    $financialOverview = db()->query('SELECT COUNT(*) AS paid_count, COUNT(DISTINCT member_id) AS member_count,
      COALESCE(SUM(gross_amount), 0) AS gross_amount,
      COALESCE(SUM(total_deduction), 0) AS total_deduction,
      COALESCE(SUM(net_amount), 0) AS paid_amount
      FROM tbl_rubber_workflow
      WHERE workflow_status = "paid" AND paid_at IS NOT NULL')->fetch() ?: $financialOverview;
  }
} catch (Throwable $e) {
  error_log('Rubber workflow summary failed: ' . $e->getMessage());
  $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
}

$stageMeta = [
  'placement' => ['label' => 'ข้อมูลการวางยาง', 'desc' => 'รายการรับกระสอบเข้าลาน', 'icon' => 'bi-box-seam-fill', 'tone' => 'violet', 'search' => 'placement_q'],
  'weighing' => ['label' => 'ข้อมูลการชั่งยาง', 'desc' => 'รายการที่บันทึกน้ำหนักจริงแล้ว', 'icon' => 'bi-speedometer2', 'tone' => 'blue', 'search' => 'weighing_q'],
  'deduction' => ['label' => 'ข้อมูลการใส่ยอดหัก', 'desc' => 'ยอดค่ายาง รายการหัก และยอดสุทธิ', 'icon' => 'bi-receipt-cutoff', 'tone' => 'amber', 'search' => 'deduction_q'],
  'payment' => ['label' => 'ข้อมูลใบเสร็จและจ่ายเงิน', 'desc' => 'การออกใบเสร็จและสถานะการจ่ายเงิน', 'icon' => 'bi-cash-coin', 'tone' => 'green', 'search' => 'payment_q'],
];
$stageMeta = array_intersect_key($stageMeta, array_flip($visibleStages));
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>สรุปข้อมูลการรับซื้อยาง</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
  <link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet">
</head>
<body>
<?php render_topbar(); ?>
<main class="ops-shell rubber-summary-shell">
  <section class="ops-hero">
    <div><h1><i class="bi bi-clipboard2-data-fill me-2"></i>สรุปข้อมูลการรับซื้อยาง</h1><p>ตรวจสอบรายการตามสถานะ พร้อมผู้บันทึกและเวลาของแต่ละขั้นตอน</p></div>
    <span class="pill"><i class="bi bi-eye-fill me-1"></i>ข้อมูลอ่านอย่างเดียว</span>
  </section>
  <?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo h($error); ?></div><?php endif; ?>

  <?php if ($canViewFinancialSummary): ?>
    <section class="stat-grid rubber-financial-overview" aria-label="ภาพรวมยอดเงินที่จ่ายแล้ว">
      <div class="stat-card"><span>รายการจ่ายเงินแล้ว</span><strong><?php echo number_format((int) $financialOverview['paid_count']); ?></strong><small><?php echo number_format((int) $financialOverview['member_count']); ?> สมาชิก</small></div>
      <div class="stat-card"><span>ยอดค่ายางก่อนหัก</span><strong><?php echo number_format((float) $financialOverview['gross_amount'], 2); ?></strong><small>บาท</small></div>
      <div class="stat-card accent-amber"><span>ยอดรวมรายการหัก</span><strong><?php echo number_format((float) $financialOverview['total_deduction'], 2); ?></strong><small>บาท</small></div>
      <div class="stat-card accent-green"><span>ยอดจ่ายให้แก่สมาชิก</span><strong><?php echo number_format((float) $financialOverview['paid_amount'], 2); ?></strong><small>บาท · จ่ายแล้วเท่านั้น</small></div>
    </section>
  <?php endif; ?>

  <nav class="rubber-stage-overview" aria-label="ขั้นตอนการรับซื้อยาง">
    <?php foreach ($stageMeta as $stage => $meta): ?>
      <a class="rubber-stage-card <?php echo h($meta['tone']); ?>" href="#<?php echo h($stage); ?>">
        <span class="rubber-stage-icon"><i class="bi <?php echo h($meta['icon']); ?>"></i></span>
        <span><small><?php echo h($meta['desc']); ?></small><strong><?php echo h($meta['label']); ?></strong></span>
        <b><?php echo number_format($sections[$stage]['count']); ?></b>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php foreach ($stageMeta as $stage => $meta): ?>
    <section id="<?php echo h($stage); ?>" class="rubber-status-section <?php echo h($meta['tone']); ?>">
      <header class="rubber-status-head">
        <div class="rubber-status-title">
          <span class="rubber-status-icon"><i class="bi <?php echo h($meta['icon']); ?>"></i></span>
          <div><h2><?php echo h($meta['label']); ?></h2><p><?php echo h($meta['desc']); ?></p></div>
        </div>
        <span class="rubber-result-count"><?php echo number_format($sections[$stage]['count']); ?> รายการ · หน้า <?php echo number_format($sections[$stage]['page']); ?>/<?php echo number_format($sections[$stage]['pages']); ?></span>
      </header>
      <?php if (in_array($stage, ['deduction', 'payment'], true)): ?>
        <div class="rubber-section-finance" aria-label="ยอดรวม<?php echo h($meta['label']); ?>">
          <div><span>ยอดค่ายาง</span><strong><?php echo number_format($sections[$stage]['gross_amount'], 2); ?> บาท</strong></div>
          <div><span>ยอดหักรวม</span><strong class="text-danger"><?php echo number_format($sections[$stage]['total_deduction'], 2); ?> บาท</strong></div>
          <?php if ($stage === 'payment'): ?>
            <div><span>จ่ายแล้ว <?php echo number_format($sections[$stage]['paid_count']); ?> รายการ</span><strong class="text-success"><?php echo number_format($sections[$stage]['paid_amount'], 2); ?> บาท</strong></div>
          <?php else: ?>
            <div><span>ยอดสุทธิรวม</span><strong class="text-success"><?php echo number_format($sections[$stage]['net_amount'], 2); ?> บาท</strong></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <form class="rubber-member-search" method="get" action="<?php echo h(url_for('rubbers.php') . '#' . $stage); ?>">
        <?php foreach ($searches as $otherStage => $otherSearch): ?>
          <?php if ($otherStage !== $stage && $otherSearch !== ''): ?><input type="hidden" name="<?php echo h($otherStage . '_q'); ?>" value="<?php echo h($otherSearch); ?>"><?php endif; ?>
        <?php endforeach; ?>
        <?php foreach ($pages as $otherStage => $otherPage): ?>
          <?php if ($otherStage !== $stage && $otherPage > 1): ?><input type="hidden" name="<?php echo h($otherStage . '_page'); ?>" value="<?php echo (int) $otherPage; ?>"><?php endif; ?>
        <?php endforeach; ?>
        <label for="<?php echo h($meta['search']); ?>">ค้นหาสมาชิกในส่วนนี้</label>
        <div class="rubber-search-control">
          <i class="bi bi-search"></i>
          <input id="<?php echo h($meta['search']); ?>" class="form-control" type="search" name="<?php echo h($meta['search']); ?>" value="<?php echo h($searches[$stage]); ?>" placeholder="พิมพ์ชื่อหรือเลขสมาชิก">
          <?php if ($searches[$stage] !== ''): ?><a href="<?php echo h(rubber_summary_url($searches, $pages, [$meta['search'] => null, $stage . '_page' => null], $stage)); ?>" aria-label="ล้างคำค้น"><i class="bi bi-x-lg"></i></a><?php endif; ?>
        </div>
        <button class="btn btn-dark" type="submit">ค้นหา</button>
      </form>

      <div class="rubber-record-list">
        <?php foreach ($sections[$stage]['rows'] as $row): ?>
          <article class="rubber-stage-record">
            <div class="rubber-record-member">
              <span class="rubber-member-avatar"><i class="bi bi-person-fill"></i></span>
              <div><strong><?php echo h($row['member_number'] . ' · ' . $row['member_name']); ?></strong><small>กลุ่ม <?php echo h($row['member_group'] ?: '—'); ?> · <?php echo h($row['yard_name']); ?> · รอบราคา <?php echo h(rubber_summary_date($row['weigh_date'])); ?></small></div>
            </div>

            <?php if ($stage === 'placement'): ?>
              <div class="rubber-record-values three-values"><div><span>กระสอบ</span><strong><?php echo number_format((float) $row['total_bags'], 0); ?></strong></div><div><span>ประมาณการ</span><strong><?php echo number_format((float) $row['estimated_weight'], 2); ?> kg</strong></div><div><span>สถานะปัจจุบัน</span><strong><span class="workflow-status <?php echo h(workflow_status_class($row['workflow_status'])); ?>"><?php echo h(workflow_status_label($row['workflow_status'])); ?></span></strong></div></div>
              <div class="rubber-record-operator"><span>ผู้บันทึกวางยาง</span><strong><?php echo h($row['placement_by'] ?: 'ไม่พบชื่อผู้บันทึก'); ?></strong><small><i class="bi bi-clock"></i><?php echo h(rubber_summary_datetime($row['placement_at'] ?: $row['placement_first_at'] ?: $row['created_at'])); ?></small></div>
            <?php elseif ($stage === 'weighing'): ?>
              <div class="rubber-record-values"><div><span>กระสอบ</span><strong><?php echo number_format((float) $row['total_bags'], 0); ?></strong></div><div><span>น้ำหนักจริง</span><strong><?php echo number_format((float) $row['actual_weight'], 2); ?> kg</strong></div></div>
              <div class="rubber-record-operator"><span>ผู้บันทึกน้ำหนัก</span><strong><?php echo h($row['weighed_by'] ?: 'ไม่พบชื่อผู้บันทึก'); ?></strong><small><i class="bi bi-clock"></i><?php echo h(rubber_summary_datetime($row['weighed_at'])); ?></small></div>
            <?php elseif ($stage === 'deduction'): ?>
              <div class="rubber-record-values three-values"><div><span>ยอดค่ายาง</span><strong><?php echo number_format((float) $row['gross_amount'], 2); ?></strong></div><div><span>ยอดหัก</span><strong class="text-danger"><?php echo number_format((float) $row['total_deduction'], 2); ?></strong></div><div><span>ยอดสุทธิ</span><strong class="text-success"><?php echo number_format((float) $row['net_amount'], 2); ?></strong></div><?php if (!empty($row['deduction_items'])): ?><small class="rubber-deduction-detail"><?php echo h($row['deduction_items']); ?></small><?php endif; ?></div>
              <div class="rubber-record-operator"><span>ผู้บันทึกยอดหัก</span><strong><?php echo h($row['deduction_by'] ?: 'ไม่พบชื่อผู้บันทึก'); ?></strong><small><i class="bi bi-clock"></i><?php echo h(rubber_summary_datetime($row['deduction_at'])); ?></small></div>
            <?php else: ?>
              <div class="rubber-record-values three-values payment-values"><div><span>ยอดค่ายาง</span><strong><?php echo number_format((float) $row['gross_amount'], 2); ?></strong></div><div><span>ยอดหัก</span><strong class="text-danger"><?php echo number_format((float) $row['total_deduction'], 2); ?></strong></div><div><span>ยอดจ่ายสุทธิ</span><strong class="text-success"><?php echo number_format((float) $row['net_amount'], 2); ?> บาท</strong></div><small class="rubber-deduction-detail">ใบเสร็จ <?php echo h($row['receipt_no'] ?: '—'); ?></small><?php if ($canPrintReceipt): ?><a class="btn btn-sm btn-outline-dark" target="_blank" href="<?php echo h(url_for('receipt.php?id=' . (int) $row['workflow_id'])); ?>"><i class="bi bi-printer me-1"></i>เปิดใบเสร็จ</a><?php endif; ?></div>
              <div class="rubber-record-operator payment-operator">
                <span>ออกใบเสร็จโดย</span><strong><?php echo h($row['deduction_by'] ?: 'ไม่พบชื่อผู้บันทึก'); ?></strong><small><i class="bi bi-clock"></i><?php echo h(rubber_summary_datetime($row['deduction_at'])); ?></small>
                <?php if ($row['receipt_view_at']): ?><span class="operator-next">เปิด/พิมพ์ล่าสุดโดย</span><strong><?php echo h($row['receipt_view_by'] ?: 'ไม่พบชื่อผู้ใช้งาน'); ?></strong><small><i class="bi bi-clock"></i><?php echo h(rubber_summary_datetime($row['receipt_view_at'])); ?></small><?php endif; ?>
                <span class="operator-next">สถานะการจ่ายเงิน</span><?php if ($row['paid_at']): ?><strong class="text-success">จ่ายแล้ว · <?php echo h($row['paid_by'] ?: 'ไม่พบชื่อผู้จ่าย'); ?></strong><small><i class="bi bi-clock"></i><?php echo h(rubber_summary_datetime($row['paid_at'])); ?></small><?php else: ?><strong class="text-warning-emphasis">รอจ่ายเงิน</strong><small>ยังไม่มีผู้อนุมัติการจ่าย</small><?php endif; ?>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
        <?php if (!$sections[$stage]['rows']): ?><div class="rubber-empty"><i class="bi bi-search"></i><strong>ไม่พบรายการในส่วนนี้</strong><span><?php echo $searches[$stage] !== '' ? 'ลองค้นหาด้วยชื่อหรือเลขสมาชิกอื่น' : 'ยังไม่มีข้อมูลที่ผ่านขั้นตอนนี้'; ?></span></div><?php endif; ?>
      </div>
      <?php if ($sections[$stage]['count'] > 0): ?>
        <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-2 mt-3">
          <small class="text-secondary">แสดง <?php echo number_format($sections[$stage]['from']); ?>–<?php echo number_format($sections[$stage]['to']); ?> จาก <?php echo number_format($sections[$stage]['count']); ?> รายการ</small>
          <?php if ($sections[$stage]['pages'] > 1): ?>
            <nav aria-label="หน้ารายการ<?php echo h($meta['label']); ?>">
              <ul class="pagination pagination-sm mb-0 flex-wrap justify-content-center">
                <li class="page-item<?php echo $sections[$stage]['page'] <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo h(rubber_summary_url($searches, $pages, [$stage . '_page' => $sections[$stage]['page'] - 1], $stage)); ?>" aria-label="หน้าก่อนหน้า">ก่อนหน้า</a></li>
                <?php $previousPage = 0; foreach (rubber_summary_page_numbers($sections[$stage]['page'], $sections[$stage]['pages']) as $pageNumber): ?>
                  <?php if ($previousPage && $pageNumber > $previousPage + 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                  <li class="page-item<?php echo $pageNumber === $sections[$stage]['page'] ? ' active' : ''; ?>"><a class="page-link" href="<?php echo h(rubber_summary_url($searches, $pages, [$stage . '_page' => $pageNumber], $stage)); ?>"><?php echo number_format($pageNumber); ?></a></li>
                <?php $previousPage = $pageNumber; endforeach; ?>
                <li class="page-item<?php echo $sections[$stage]['page'] >= $sections[$stage]['pages'] ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo h(rubber_summary_url($searches, $pages, [$stage . '_page' => $sections[$stage]['page'] + 1], $stage)); ?>" aria-label="หน้าถัดไป">ถัดไป</a></li>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <div class="rubber-summary-note"><i class="bi bi-info-circle-fill"></i><span>จำนวนรายการด้านบนอ้างอิงสถานะที่บันทึกจริงในระบบ และแสดงเฉพาะขั้นตอนที่บัญชีนี้ได้รับสิทธิ์<?php if (in_array('payment', $visibleStages, true)): ?> · จ่ายเงินแล้วทั้งหมด <?php echo number_format($paidCount); ?> รายการ<?php endif; ?></span></div>
</main>
</body>
</html>

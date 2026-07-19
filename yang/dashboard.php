<?php
require_once __DIR__ . '/auth.php';
require_user();
require_once __DIR__ . '/navbar.php';

$user = current_user();
$isAdmin = ($user['user_level'] ?? '') === 'admin';

$workflowMenus = [
  ['permission' => 'placement', 'href' => 'bag-intake.php', 'label' => 'วางยาง', 'desc' => 'บันทึกจำนวนกระสอบตามสมาชิกและลาน', 'icon' => 'bi-box-seam-fill', 'tone' => 'violet', 'step' => 'ขั้นตอน 1'],
  ['permission' => 'weighing', 'href' => 'weighing.php', 'label' => 'ชั่งยาง', 'desc' => 'บันทึกน้ำหนักจริงในวันชั่ง', 'icon' => 'bi-speedometer2', 'tone' => 'blue', 'step' => 'ขั้นตอน 2'],
  ['permission' => 'deductions', 'href' => 'deductions.php', 'label' => 'บันทึกยอดหัก', 'desc' => 'คำนวณยอดหักและยอดสุทธิ', 'icon' => 'bi-receipt-cutoff', 'tone' => 'amber', 'step' => 'ขั้นตอน 3'],
  ['permission' => 'payments', 'href' => 'payments.php', 'label' => 'พิมพ์ใบเสร็จและจ่ายเงิน', 'desc' => 'พิมพ์ใบเสร็จและยืนยันการจ่าย', 'icon' => 'bi-cash-coin', 'tone' => 'green', 'step' => 'ขั้นตอน 4'],
];
$workflowMenus = array_values(array_filter($workflowMenus, function ($menu) use ($user) {
  return user_can($menu['permission'], $user);
}));

$informationMenus = [
  ['href' => 'user-dashboard.php', 'label' => 'Dashboard ของฉัน', 'desc' => 'ดูข้อมูลสรุปตามสิทธิ์แบบอ่านอย่างเดียว', 'icon' => 'bi-person-workspace', 'tone' => 'slate'],
  ['href' => 'price.php', 'label' => 'ราคาประจำวัน', 'desc' => 'ดูราคาและประวัติรอบราคายาง', 'icon' => 'bi-tags-fill', 'tone' => 'rose'],
];
if (user_can('placement', $user)) {
  $informationMenus[] = ['href' => 'bag-report.php', 'label' => 'รายงานวางยาง', 'desc' => 'สรุปกระสอบ น้ำหนัก และข้อมูลรายลาน', 'icon' => 'bi-bar-chart-fill', 'tone' => 'purple'];
  $informationMenus[] = ['href' => 'rubbers.php', 'label' => 'ข้อมูลรับยาง', 'desc' => 'ค้นหาและตรวจสอบข้อมูลรับยาง', 'icon' => 'bi-droplet-fill', 'tone' => 'teal'];
}

$adminMenus = [
  ['href' => 'audit-log.php', 'label' => 'ประวัติการใช้งาน', 'desc' => 'ตรวจสอบการบันทึกและอนุมัติย้อนหลัง', 'icon' => 'bi-clock-history', 'tone' => 'amber'],
  ['href' => 'paid-summary.php', 'label' => 'สรุปยอดจ่ายแต่ละรอบ', 'desc' => 'รายการจ่ายแล้วตามวันที่ราคายาง', 'icon' => 'bi-clipboard2-data-fill', 'tone' => 'green'],
  ['href' => 'members.php', 'label' => 'จัดการสมาชิก', 'desc' => 'เพิ่ม แก้ไข และค้นหาสมาชิก', 'icon' => 'bi-people-fill', 'tone' => 'blue'],
  ['href' => 'users.php', 'label' => 'จัดการผู้ใช้งาน', 'desc' => 'บัญชี สิทธิ์ และสถานะเจ้าหน้าที่', 'icon' => 'bi-person-gear', 'tone' => 'purple'],
  ['href' => 'setup.php', 'label' => 'ตั้งค่าระบบ', 'desc' => 'ข้อมูลสหกรณ์ ลาน และโครงสร้างระบบ', 'icon' => 'bi-gear-fill', 'tone' => 'slate'],
];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>เมนูใช้งานระบบรวบรวมยาง</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
  <link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet">
</head>
<body>
<?php render_topbar(); ?>
<main class="ops-shell dashboard-menu-shell">
  <section class="ops-hero"><div><h1><i class="bi bi-grid-1x2-fill me-2"></i>เมนูใช้งาน</h1><p>เลือกงานที่ได้รับสิทธิ์จากปุ่มด้านล่าง</p></div><span class="pill"><i class="bi bi-person-circle me-1"></i><?php echo h($user['user_fullname']); ?></span></section>

  <section class="menu-section">
    <div class="menu-section-head"><div><h2><i class="bi bi-arrow-repeat"></i> ขั้นตอนการทำงาน</h2><p>ระบบแสดงเฉพาะขั้นตอนที่บัญชีนี้ได้รับสิทธิ์</p></div><span class="badge-soft"><?php echo number_format(count($workflowMenus)); ?> เมนู</span></div>
    <div class="dashboard-menu-grid"><?php foreach ($workflowMenus as $menu): ?><a class="dashboard-menu-card <?php echo h($menu['tone']); ?>" href="<?php echo h(url_for($menu['href'])); ?>"><span class="dashboard-menu-icon"><i class="bi <?php echo h($menu['icon']); ?>"></i></span><span class="dashboard-menu-arrow"><i class="bi bi-chevron-right"></i></span><strong><?php echo h($menu['label']); ?></strong><small><?php echo h($menu['desc']); ?></small><span class="dashboard-menu-step"><?php echo h($menu['step']); ?></span></a><?php endforeach; ?></div>
  </section>

  <section class="menu-section">
    <div class="menu-section-head"><div><h2><i class="bi bi-folder-fill"></i> ข้อมูลและ Dashboard</h2><p>ดูข้อมูลสรุปหรือรายงานที่เกี่ยวข้องกับสิทธิ์ของคุณ</p></div></div>
    <div class="dashboard-menu-grid"><?php foreach ($informationMenus as $menu): ?><a class="dashboard-menu-card <?php echo h($menu['tone']); ?>" href="<?php echo h(url_for($menu['href'])); ?>"><span class="dashboard-menu-icon"><i class="bi <?php echo h($menu['icon']); ?>"></i></span><span class="dashboard-menu-arrow"><i class="bi bi-chevron-right"></i></span><strong><?php echo h($menu['label']); ?></strong><small><?php echo h($menu['desc']); ?></small></a><?php endforeach; ?></div>
  </section>

  <?php if ($isAdmin): ?><section class="menu-section admin-menu-section"><div class="menu-section-head"><div><h2><i class="bi bi-shield-lock-fill"></i> เมนูผู้ดูแลระบบ</h2><p>การตั้งค่า ตรวจสอบ และบริหารระบบทั้งหมด</p></div></div><div class="dashboard-menu-grid"><?php foreach ($adminMenus as $menu): ?><a class="dashboard-menu-card <?php echo h($menu['tone']); ?>" href="<?php echo h(url_for($menu['href'])); ?>"><span class="dashboard-menu-icon"><i class="bi <?php echo h($menu['icon']); ?>"></i></span><span class="dashboard-menu-arrow"><i class="bi bi-chevron-right"></i></span><strong><?php echo h($menu['label']); ?></strong><small><?php echo h($menu['desc']); ?></small></a><?php endforeach; ?></div></section><?php endif; ?>
</main>
</body>
</html>

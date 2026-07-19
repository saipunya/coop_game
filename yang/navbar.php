<?php
require_once __DIR__ . '/system.php';

function navbar_url($path = '')
{
  if (function_exists('url_for')) return url_for($path);
  $basePath = rtrim($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '/yang', '/');
  return ($basePath ?: '/yang') . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function navbar_is_active($files)
{
  $current = basename(parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['PHP_SELF'] ?? ''), PHP_URL_PATH));
  return in_array($current, (array) $files, true) ? 'active' : '';
}

function render_topbar()
{
  $user = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? null);
  $canPlacement = $user && function_exists('user_can') && user_can('placement', $user);
  $canWeighing = $user && function_exists('user_can') && user_can('weighing', $user);
  $canDeductions = $user && function_exists('user_can') && user_can('deductions', $user);
  $canPayments = $user && function_exists('user_can') && user_can('payments', $user);
  ?>
  <header class="topbar">
    <div class="topbar-inner">
      <a class="topbar-brand" href="<?php echo htmlspecialchars(navbar_url('index.php')); ?>">
        <span class="topbar-mark">ย</span>
        <span class="brand-copy"><strong><?php echo htmlspecialchars(system_name()); ?></strong><small><?php echo htmlspecialchars(cooperative_name()); ?></small></span>
      </a>

      <button class="nav-toggle" id="navToggle" type="button" aria-label="เปิดเมนู" aria-expanded="false" aria-controls="topbarMenu">
        <i class="bi bi-list" aria-hidden="true"></i>
      </button>

      <div class="topbar-menu" id="topbarMenu">
        <nav class="main-nav" aria-label="เมนูระบบ">
          <?php if (!$user): ?>
            <a class="nav-direct <?php echo navbar_is_active(['index.php', '']); ?>" href="<?php echo htmlspecialchars(navbar_url('index.php')); ?>"><i class="bi bi-house-door-fill"></i><span>หน้าแรก</span></a>
            <a class="nav-direct <?php echo navbar_is_active(['price.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('price.php')); ?>"><i class="bi bi-tags-fill"></i><span>ราคาประจำวัน</span></a>
          <?php else: ?>
            <div class="nav-group <?php echo navbar_is_active(['index.php', 'dashboard.php', 'user-dashboard.php']); ?>">
              <button class="nav-group-toggle" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="navGroupMain">
                <i class="bi bi-grid-fill"></i><span>เมนูหลัก</span><i class="bi bi-chevron-down nav-chevron"></i>
              </button>
              <div class="nav-dropdown" id="navGroupMain">
                <span class="nav-dropdown-title">ภาพรวมระบบ</span>
                <a class="<?php echo navbar_is_active(['index.php', '']); ?>" href="<?php echo htmlspecialchars(navbar_url('index.php')); ?>"><i class="bi bi-house-door-fill"></i><span><strong>หน้าแรก</strong><small>ข้อมูลสาธารณะของสหกรณ์</small></span></a>
                <a class="<?php echo navbar_is_active(['dashboard.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('dashboard.php')); ?>"><i class="bi bi-grid-1x2-fill"></i><span><strong>เมนูใช้งาน</strong><small>เลือกงานตามสิทธิ์ของบัญชี</small></span></a>
                <a class="<?php echo navbar_is_active(['user-dashboard.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('user-dashboard.php')); ?>"><i class="bi bi-person-workspace"></i><span><strong>Dashboard ของฉัน</strong><small>ข้อมูลตามสิทธิ์แบบอ่านอย่างเดียว</small></span></a>
              </div>
            </div>

            <?php if ($canPlacement): ?>
            <div class="nav-group <?php echo navbar_is_active(['rubbers.php', 'bag-intake.php']); ?>">
              <button class="nav-group-toggle" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="navGroupPlacement">
                <i class="bi bi-box-seam-fill"></i><span>วางยาง</span><i class="bi bi-chevron-down nav-chevron"></i>
              </button>
              <div class="nav-dropdown" id="navGroupPlacement">
                <span class="nav-dropdown-title">งานรับและวางยาง</span>
                <a class="<?php echo navbar_is_active(['bag-intake.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('bag-intake.php')); ?>"><i class="bi bi-box-seam-fill"></i><span><strong>รับวางยาง</strong><small>บันทึกกระสอบตามสมาชิกและลาน</small></span></a>
                <a class="<?php echo navbar_is_active(['rubbers.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('rubbers.php')); ?>"><i class="bi bi-droplet-fill"></i><span><strong>บันทึกรับยาง</strong><small>จัดการข้อมูลรับซื้อยาง</small></span></a>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($canWeighing || $canDeductions || $canPayments): ?>
            <div class="nav-group <?php echo navbar_is_active(['weighing.php', 'deductions.php', 'payments.php', 'receipt.php']); ?>">
              <button class="nav-group-toggle" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="navGroupWeighing">
                <i class="bi bi-speedometer2"></i><span>ชั่งและจ่าย</span><i class="bi bi-chevron-down nav-chevron"></i>
              </button>
              <div class="nav-dropdown" id="navGroupWeighing">
                <span class="nav-dropdown-title">ขั้นตอนชั่งยางและการเงิน</span>
                <?php if ($canWeighing): ?><a class="<?php echo navbar_is_active(['weighing.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('weighing.php')); ?>"><i class="bi bi-speedometer2"></i><span><strong>ชั่งยาง</strong><small>บันทึกน้ำหนักจริง</small></span></a><?php endif; ?>
                <?php if ($canDeductions): ?><a class="<?php echo navbar_is_active(['deductions.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('deductions.php')); ?>"><i class="bi bi-receipt-cutoff"></i><span><strong>รายการหัก</strong><small>คำนวณยอดหักและยอดสุทธิ</small></span></a><?php endif; ?>
                <?php if ($canPayments): ?><a class="<?php echo navbar_is_active(['payments.php', 'receipt.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('payments.php')); ?>"><i class="bi bi-cash-coin"></i><span><strong>จ่ายเงิน</strong><small>ยืนยันการจ่ายและพิมพ์ใบเสร็จ</small></span></a><?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($canPlacement): ?>
            <div class="nav-group <?php echo navbar_is_active(['bag-report.php']); ?>">
              <button class="nav-group-toggle" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="navGroupReports">
                <i class="bi bi-bar-chart-fill"></i><span>รายงาน</span><i class="bi bi-chevron-down nav-chevron"></i>
              </button>
              <div class="nav-dropdown" id="navGroupReports">
                <span class="nav-dropdown-title">รายงานและข้อมูลสรุป</span>
                <a class="<?php echo navbar_is_active(['bag-report.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('bag-report.php')); ?>"><i class="bi bi-clipboard-data-fill"></i><span><strong>รายงานวางยาง</strong><small>สรุปกระสอบ น้ำหนัก และข้อมูลรายลาน</small></span></a>
              </div>
            </div>
            <?php endif; ?>

            <div class="nav-group <?php echo navbar_is_active(['price.php', 'setup.php']); ?>">
              <button class="nav-group-toggle" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="navGroupSettings">
                <i class="bi bi-sliders2"></i><span>ตั้งค่า</span><i class="bi bi-chevron-down nav-chevron"></i>
              </button>
              <div class="nav-dropdown nav-dropdown-end" id="navGroupSettings">
                <span class="nav-dropdown-title">ข้อมูลและการตั้งค่าระบบ</span>
                <a class="<?php echo navbar_is_active(['price.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('price.php')); ?>"><i class="bi bi-tags-fill"></i><span><strong>ราคาประจำวัน</strong><small>ดูและกำหนดราคายาง</small></span></a>
                <?php if (($user['user_level'] ?? '') === 'admin'): ?>
                  <a class="<?php echo navbar_is_active(['setup.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('setup.php')); ?>"><i class="bi bi-gear-fill"></i><span><strong>ตั้งค่าระบบ</strong><small>ข้อมูลสหกรณ์ ลาน และโครงสร้างระบบ</small></span></a>
                <?php endif; ?>
              </div>
            </div>

            <?php if (($user['user_level'] ?? '') === 'admin'): ?>
              <div class="nav-group nav-group-admin <?php echo navbar_is_active(['members.php', 'users.php', 'paid-summary.php', 'audit-log.php']); ?>">
                <button class="nav-group-toggle" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="navGroupAdmin">
                  <i class="bi bi-shield-lock-fill"></i><span>ผู้ดูแลระบบ</span><i class="bi bi-chevron-down nav-chevron"></i>
                </button>
                <div class="nav-dropdown nav-dropdown-end" id="navGroupAdmin">
                  <span class="nav-dropdown-title">เฉพาะผู้ดูแลระบบ</span>
                  <a class="<?php echo navbar_is_active(['audit-log.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('audit-log.php')); ?>"><i class="bi bi-clock-history"></i><span><strong>ประวัติการใช้งาน</strong><small>ตรวจสอบการบันทึกและอนุมัติย้อนหลัง</small></span></a>
                  <a class="<?php echo navbar_is_active(['paid-summary.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('paid-summary.php')); ?>"><i class="bi bi-clipboard2-data-fill"></i><span><strong>สรุปยอดจ่ายแต่ละรอบ</strong><small>รายการจ่ายแล้วตามวันที่ราคายาง</small></span></a>
                  <a class="<?php echo navbar_is_active(['members.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('members.php')); ?>"><i class="bi bi-people-fill"></i><span><strong>จัดการสมาชิก</strong><small>เพิ่ม แก้ไข และค้นหาสมาชิก</small></span></a>
                  <a class="<?php echo navbar_is_active(['users.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('users.php')); ?>"><i class="bi bi-person-gear"></i><span><strong>จัดการผู้ใช้งาน</strong><small>บัญชี สิทธิ์ และสถานะเจ้าหน้าที่</small></span></a>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </nav>

        <div class="account-menu">
          <?php if ($user): ?>
            <span class="account-avatar"><i class="bi bi-person-fill"></i></span>
            <span class="account-name"><small>เข้าสู่ระบบโดย</small><strong><?php echo htmlspecialchars($user['user_fullname']); ?></strong></span>
            <a class="logout-link" href="<?php echo htmlspecialchars(navbar_url('user-logout.php')); ?>" title="ออกจากระบบ"><i class="bi bi-box-arrow-right"></i><span>ออกจากระบบ</span></a>
          <?php else: ?>
            <a class="login-link" href="<?php echo htmlspecialchars(navbar_url('user-login.php')); ?>"><i class="bi bi-person-lock"></i> เข้าสู่ระบบ</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>
  <script>
    (function () {
      var button = document.getElementById('navToggle');
      var menu = document.getElementById('topbarMenu');
      if (!button || !menu) return;
      var groupButtons = menu.querySelectorAll('.nav-group-toggle');

      function closeGroups(exceptButton) {
        groupButtons.forEach(function (groupButton) {
          if (groupButton === exceptButton) return;
          groupButton.setAttribute('aria-expanded', 'false');
          groupButton.parentElement.classList.remove('open');
        });
      }

      groupButtons.forEach(function (groupButton) {
        groupButton.addEventListener('click', function () {
          var shouldOpen = groupButton.getAttribute('aria-expanded') !== 'true';
          closeGroups(groupButton);
          groupButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
          groupButton.parentElement.classList.toggle('open', shouldOpen);
        });
      });

      button.addEventListener('click', function () {
        var open = menu.classList.toggle('show');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.setAttribute('aria-label', open ? 'ปิดเมนู' : 'เปิดเมนู');
        button.innerHTML = '<i class="bi ' + (open ? 'bi-x-lg' : 'bi-list') + '" aria-hidden="true"></i>';
        if (!open) closeGroups();
      });

      document.addEventListener('click', function (event) {
        if (!menu.contains(event.target)) closeGroups();
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeGroups();
      });
    }());
  </script>
  <?php
}

// เก็บ function เดิมไว้เพื่อให้หน้าที่มีอยู่เรียกใช้งานได้ โดยไม่แสดง sidebar อีกต่อไป
function render_sidebar($activeNav = '')
{
  return;
}

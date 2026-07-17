<?php

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
  ?>
  <header class="topbar">
    <div class="topbar-inner">
      <a class="topbar-brand" href="<?php echo htmlspecialchars(navbar_url('index.php')); ?>">
        <span class="topbar-mark">ย</span>
        <span class="brand-copy"><strong>ระบบรวบรวมยาง</strong><small>RUBBER CO-OP ADMIN</small></span>
      </a>

      <button class="nav-toggle" id="navToggle" type="button" aria-label="เปิดเมนู" aria-expanded="false" aria-controls="topbarMenu">
        <i class="bi bi-list" aria-hidden="true"></i>
      </button>

      <div class="topbar-menu" id="topbarMenu">
        <nav class="main-nav" aria-label="เมนูหลัก">
          <a class="<?php echo navbar_is_active(['index.php', '']); ?>" href="<?php echo htmlspecialchars(navbar_url('index.php')); ?>"><i class="bi bi-house-door-fill"></i><span>หน้าแรก</span></a>
          <a class="<?php echo navbar_is_active(['price.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('price.php')); ?>"><i class="bi bi-tags-fill"></i><span>ราคาอ้างอิง</span></a>
          <?php if ($user): ?>
            <a class="<?php echo navbar_is_active(['dashboard.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('dashboard.php')); ?>"><i class="bi bi-grid-1x2-fill"></i><span>แดชบอร์ด</span></a>
            <a class="<?php echo navbar_is_active(['rubbers.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('rubbers.php')); ?>"><i class="bi bi-droplet-fill"></i><span>บันทึกรับยาง</span></a>
            <a class="<?php echo navbar_is_active(['user-dashboard.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('user-dashboard.php')); ?>"><i class="bi bi-clipboard-data-fill"></i><span>รายการของฉัน</span></a>
            <?php if (($user['user_level'] ?? '') === 'admin'): ?>
              <a class="<?php echo navbar_is_active(['members.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('members.php')); ?>"><i class="bi bi-people-fill"></i><span>สมาชิก</span></a>
              <a class="<?php echo navbar_is_active(['users.php']); ?>" href="<?php echo htmlspecialchars(navbar_url('users.php')); ?>"><i class="bi bi-person-gear"></i><span>ผู้ใช้งาน</span></a>
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
      button.addEventListener('click', function () {
        var open = menu.classList.toggle('show');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.setAttribute('aria-label', open ? 'ปิดเมนู' : 'เปิดเมนู');
        button.innerHTML = '<i class="bi ' + (open ? 'bi-x-lg' : 'bi-list') + '" aria-hidden="true"></i>';
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

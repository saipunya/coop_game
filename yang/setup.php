<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/navbar.php';

ensure_system_schema();
$userCount = (int) db()->query('SELECT COUNT(*) FROM tbl_user')->fetchColumn();
$currentUser = current_user();

if ($userCount > 0) {
  require_user();
  $currentUser = current_user();
  if (($currentUser['user_level'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('เฉพาะผู้ดูแลระบบเท่านั้น');
  }
}

$error = '';
$success = isset($_GET['saved']) ? 'บันทึกการตั้งค่าระบบเรียบร้อยแล้ว' : '';
$existingYards = db()->query('SELECT * FROM tbl_yard ORDER BY sort_order, yard_id')->fetchAll();
$yardCount = max(1, (int) system_setting('yard_count', count($existingYards) ?: 1));
$existingDeductionTypes = active_deduction_types();
$deductionNames = array_column($existingDeductionTypes, 'deduction_name');
$deductionCount = max(1, count($deductionNames));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่');

    $systemName = trim($_POST['system_name'] ?? '');
    $coopName = trim($_POST['cooperative_name'] ?? '');
    $yardCount = filter_var($_POST['yard_count'] ?? 0, FILTER_VALIDATE_INT);
    $yardNames = $_POST['yard_names'] ?? [];
    $deductionCount = filter_var($_POST['deduction_count'] ?? 0, FILTER_VALIDATE_INT);
    $deductionNames = array_map('trim', array_slice((array) ($_POST['deduction_names'] ?? []), 0, 20));

    if ($systemName === '' || $coopName === '') throw new RuntimeException('กรุณาระบุชื่อระบบและชื่อสหกรณ์');
    if (!$yardCount || $yardCount < 1 || $yardCount > 50) throw new RuntimeException('จำนวนลานยางต้องอยู่ระหว่าง 1–50 ลาน');
    if (!$deductionCount || $deductionCount < 1 || $deductionCount > 20) throw new RuntimeException('จำนวนรายการหักต้องอยู่ระหว่าง 1–20 รายการ');
    if (count($deductionNames) < $deductionCount) throw new RuntimeException('กรุณาระบุชื่อรายการหักให้ครบตามจำนวน');
    $deductionNames = array_slice($deductionNames, 0, $deductionCount);
    foreach ($deductionNames as $name) {
      if ($name === '') throw new RuntimeException('ชื่อรายการหักห้ามเว้นว่าง');
      if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 255) {
        throw new RuntimeException('ชื่อรายการหักต้องไม่เกิน 255 ตัวอักษร');
      }
    }
    if (count(array_unique($deductionNames)) !== count($deductionNames)) throw new RuntimeException('ชื่อรายการหักต้องไม่ซ้ำกัน');

    $adminUsername = trim($_POST['admin_username'] ?? '');
    $adminFullname = trim($_POST['admin_fullname'] ?? '');
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    if ($userCount === 0) {
      if (!preg_match('/^[A-Za-z0-9_.-]{3,255}$/', $adminUsername)) throw new RuntimeException('ชื่อผู้ดูแลต้องมีอย่างน้อย 3 ตัวอักษร และใช้ตัวอักษรอังกฤษหรือตัวเลข');
      if ($adminFullname === '') throw new RuntimeException('กรุณาระบุชื่อผู้ดูแลระบบ');
      if (strlen($adminPassword) < 8) throw new RuntimeException('รหัสผ่านผู้ดูแลต้องมีอย่างน้อย 8 ตัวอักษร');
    }

    db()->beginTransaction();
    save_system_setting('system_name', $systemName);
    save_system_setting('cooperative_name', $coopName);
    save_system_setting('yard_count', (string) $yardCount);

    db()->exec('UPDATE tbl_yard SET yard_status = "inactive"');
    $yardStmt = db()->prepare('INSERT INTO tbl_yard(yard_code, yard_name, yard_status, sort_order)
      VALUES(:code, :name, "active", :sort)
      ON DUPLICATE KEY UPDATE yard_name = VALUES(yard_name), yard_status = "active", sort_order = VALUES(sort_order)');
    for ($i = 1; $i <= $yardCount; $i++) {
      $name = trim($yardNames[$i - 1] ?? '');
      $yardStmt->execute(['code' => (string) $i, 'name' => $name !== '' ? $name : 'ลาน ' . $i, 'sort' => $i]);
    }

    db()->exec('UPDATE tbl_deduction_type SET deduction_status = "inactive"');
    $deductionStmt = db()->prepare('INSERT INTO tbl_deduction_type(deduction_name, deduction_status, sort_order)
      VALUES(:name, "active", :sort)
      ON DUPLICATE KEY UPDATE deduction_status = "active", sort_order = VALUES(sort_order)');
    foreach ($deductionNames as $index => $name) {
      $deductionStmt->execute(['name' => $name, 'sort' => $index + 1]);
    }

    // Older dumps can contain kg/bag rates while individual estimated
    // weights remain zero. Rebuild both detail and daily totals at setup time.
    db()->exec('UPDATE tbl_wangyang w
      INNER JOIN tbl_wangyang_daily_summary s ON s.ws_date = w.wang_date
      SET w.wang_weight = w.wang_sack * s.ws_weight_per_bag
      WHERE s.ws_weight_per_bag > 0');
    db()->exec('UPDATE tbl_wangyang_daily_summary s
      SET s.ws_estimated_weight = (SELECT COALESCE(SUM(w.wang_weight), 0) FROM tbl_wangyang w WHERE w.wang_date = s.ws_date)');

    if ($userCount === 0) {
      $stmt = db()->prepare('INSERT INTO tbl_user(user_username, user_password, user_fullname, user_level, user_status)
        VALUES(:username, :password, :fullname, "admin", "active")');
      $stmt->execute([
        'username' => $adminUsername,
        'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
        'fullname' => $adminFullname,
      ]);
    }

    save_system_setting('setup_complete', '1');
    save_system_setting('setup_updated_at', date('Y-m-d H:i:s'));
    audit_log('configure', 'system', 'setup', 'บันทึกการตั้งค่าระบบ ลานยาง และรายการหัก', [
      'system_name' => $systemName, 'cooperative_name' => $coopName,
      'yard_count' => (int) $yardCount, 'deduction_count' => (int) $deductionCount,
      'deduction_names' => $deductionNames,
    ]);
    db()->commit();
    header('Location: ' . url_for('setup.php?saved=1'));
    exit;
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
  }
}

$yardMap = [];
foreach ($existingYards as $yard) $yardMap[(int) $yard['sort_order']] = $yard['yard_name'];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>ตั้งค่าระบบ</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
  <link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet">
</head>
<body>
  <?php if ($userCount > 0) render_topbar(); ?>
  <main class="ops-shell">
    <section class="ops-hero">
      <div><h1><i class="bi bi-sliders2 me-2"></i>Setup ระบบรวบรวมยาง</h1><p>กำหนดข้อมูลพื้นฐาน ลานยาง รายการหัก และบัญชีผู้ดูแลก่อนเริ่มใช้งาน</p></div>
      <span class="pill"><?php echo setup_is_complete() ? 'ตั้งค่าแล้ว' : 'เริ่มต้นระบบ'; ?></span>
    </section>

    <section class="setup-steps">
      <article class="setup-step"><strong>1. ข้อมูลระบบ</strong><span>ชื่อระบบและชื่อสหกรณ์</span></article>
      <article class="setup-step"><strong>2. ลานยาง</strong><span>จำนวนและชื่อเรียกแต่ละลาน</span></article>
      <article class="setup-step"><strong>3. รายการหัก</strong><span>จำนวนและชื่อรายการมาตรฐาน</span></article>
      <article class="setup-step"><strong>4. ผู้ใช้งาน</strong><span>Admin และเจ้าหน้าที่บันทึกข้อมูล</span></article>
      <article class="setup-step"><strong>5. เริ่มบันทึก</strong><span>วางยางและประมาณน้ำหนัก</span></article>
    </section>

    <?php if ($success): ?><div class="alert alert-success"><?php echo h($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>

    <form method="post" class="ops-grid wide-left">
      <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
      <section class="ops-card">
        <div class="ops-card-head"><h2>ข้อมูลระบบและสหกรณ์</h2></div>
        <div class="ops-card-body">
          <div class="mb-3"><label class="form-label">ชื่อระบบ</label><input class="form-control" name="system_name" required value="<?php echo h(system_setting('system_name', 'ระบบรวบรวมยาง')); ?>"></div>
          <div class="mb-3"><label class="form-label">ชื่อสหกรณ์</label><input class="form-control" name="cooperative_name" required value="<?php echo h(system_setting('cooperative_name', '')); ?>" placeholder="เช่น สหกรณ์การเกษตรตัวอย่าง จำกัด"></div>
          <div class="mb-3"><label class="form-label">จำนวนลานยาง</label><input id="yardCount" class="form-control" type="number" name="yard_count" min="1" max="50" required value="<?php echo (int) $yardCount; ?>"><div class="form-hint mt-1">ระบบจะสร้างช่องกรอกชื่อให้ตามจำนวนลาน</div></div>
          <?php if ($userCount === 0): ?>
            <hr><h3 class="h6 fw-bold">บัญชี Admin คนแรก</h3>
            <div class="row g-2"><div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="admin_username" required></div><div class="col-md-6"><label class="form-label">ชื่อ-สกุล</label><input class="form-control" name="admin_fullname" required></div></div>
            <div class="mt-3"><label class="form-label">รหัสผ่าน</label><input class="form-control" type="password" name="admin_password" minlength="8" required></div>
          <?php else: ?>
            <div class="alert alert-light border mt-3 mb-0">มีบัญชีผู้ใช้งานแล้ว <?php echo number_format($userCount); ?> บัญชี สามารถจัดการต่อที่หน้า <a href="<?php echo h(url_for('users.php')); ?>">ผู้ใช้งาน</a></div>
          <?php endif; ?>
        </div>
      </section>

      <section class="ops-card">
        <div class="ops-card-head"><h2>ลานยางและรายการหัก</h2></div>
        <div class="ops-card-body">
          <h3 class="h6 fw-bold mb-3">รายชื่อลานยาง</h3>
          <div id="yardFields" data-names="<?php echo h(json_encode(array_values($yardMap), JSON_UNESCAPED_UNICODE)); ?>"></div>
          <hr class="my-4">
          <h3 class="h6 fw-bold mb-3">รายการหักมาตรฐาน</h3>
          <div class="mb-3"><label class="form-label">จำนวนรายการหัก</label><input id="deductionCount" class="form-control" type="number" name="deduction_count" min="1" max="20" required value="<?php echo (int) $deductionCount; ?>"><div class="form-hint mt-1">หน้าบันทึกยอดหักจะแสดงรายการเหล่านี้ และให้กรอกเฉพาะจำนวนเงิน</div></div>
          <div id="deductionFields" data-names="<?php echo h(json_encode(array_values($deductionNames), JSON_UNESCAPED_UNICODE)); ?>"></div>
          <button class="btn btn-green w-100 mt-3" type="submit"><i class="bi bi-check2-circle me-1"></i>บันทึกและพร้อมใช้งาน</button>
        </div>
      </section>
    </form>
  </main>
  <script>
  (function () {
    var count = document.getElementById('yardCount');
    var fields = document.getElementById('yardFields');
    var names = JSON.parse(fields.getAttribute('data-names') || '[]');
    function render() {
      var old = Array.prototype.map.call(fields.querySelectorAll('input'), function (input) { return input.value; });
      if (old.length) names = old;
      var total = Math.max(1, Math.min(50, parseInt(count.value || '1', 10)));
      fields.innerHTML = '';
      for (var i = 1; i <= total; i++) {
        var row = document.createElement('div'); row.className = 'yard-row';
        row.innerHTML = '<input class="form-control" value="' + i + '" disabled aria-label="รหัสลาน"><input class="form-control" name="yard_names[]" required placeholder="ชื่อลาน ' + i + '">';
        row.querySelector('[name="yard_names[]"]').value = names[i - 1] || ('ลาน ' + i);
        fields.appendChild(row);
      }
    }
    count.addEventListener('input', render); render();

    var deductionCount = document.getElementById('deductionCount');
    var deductionFields = document.getElementById('deductionFields');
    var deductionNames = JSON.parse(deductionFields.getAttribute('data-names') || '[]');
    function renderDeductions() {
      var old = Array.prototype.map.call(deductionFields.querySelectorAll('input[name="deduction_names[]"]'), function (input) { return input.value; });
      if (old.length) deductionNames = old;
      var total = Math.max(1, Math.min(20, parseInt(deductionCount.value || '1', 10)));
      deductionFields.innerHTML = '';
      for (var i = 1; i <= total; i++) {
        var row = document.createElement('div'); row.className = 'deduction-config-row';
        row.innerHTML = '<span class="deduction-order">' + i + '</span><input class="form-control" name="deduction_names[]" maxlength="255" required placeholder="ชื่อรายการหักลำดับ ' + i + '">';
        row.querySelector('[name="deduction_names[]"]').value = deductionNames[i - 1] || '';
        deductionFields.appendChild(row);
      }
    }
    deductionCount.addEventListener('input', renderDeductions); renderDeductions();
  }());
  </script>
</body>
</html>

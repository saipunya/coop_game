<?php
require_once __DIR__ . '/auth.php';
require_user();
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/navbar.php';

ensure_system_schema();
$currentUser = current_user();
if (($currentUser['user_level'] ?? '') !== 'admin') {
  http_response_code(403);
  exit('เฉพาะผู้ดูแลระบบเท่านั้น');
}

$permissionDefinitions = workflow_permission_definitions();
$permissionKeys = array_keys($permissionDefinitions);
$error = '';
$flash = $_SESSION['user_crud_flash'] ?? null;
unset($_SESSION['user_crud_flash']);

function users_redirect()
{
  header('Location: ' . url_for('users.php'));
  exit;
}

$postedPermissions = array_values(array_intersect($permissionKeys, (array) ($_POST['permissions'] ?? [])));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่');
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'delete') {
      if (!$id) throw new RuntimeException('ไม่พบผู้ใช้งาน');
      if ($id === (int) $currentUser['user_id']) throw new RuntimeException('ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่');
      $targetStmt = db()->prepare('SELECT user_username, user_fullname, user_level FROM tbl_user WHERE user_id = :id');
      $targetStmt->execute(['id' => $id]);
      $deletedUser = $targetStmt->fetch();
      if (!$deletedUser) throw new RuntimeException('ไม่พบผู้ใช้งาน');
      db()->beginTransaction();
      db()->prepare('DELETE FROM tbl_user_permission WHERE user_id = :id')->execute(['id' => $id]);
      db()->prepare('DELETE FROM tbl_user WHERE user_id = :id')->execute(['id' => $id]);
      audit_log('delete', 'user', $id, 'ลบบัญชีผู้ใช้งาน ' . $deletedUser['user_username'], [
        'target_username' => $deletedUser['user_username'], 'target_fullname' => $deletedUser['user_fullname'], 'target_level' => $deletedUser['user_level'],
      ]);
      db()->commit();
      $_SESSION['user_crud_flash'] = ['type' => 'success', 'message' => 'ลบผู้ใช้งานและสิทธิ์เรียบร้อย'];
      users_redirect();
    }

    if (!in_array($action, ['create', 'update'], true)) throw new RuntimeException('คำสั่งไม่ถูกต้อง');
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $level = in_array($_POST['level'] ?? '', ['admin', 'user'], true) ? $_POST['level'] : 'user';
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

    if (!preg_match('/^[A-Za-z0-9_.-]{3,255}$/', $username)) throw new RuntimeException('ชื่อผู้ใช้ต้องมีอย่างน้อย 3 ตัว และใช้ตัวอักษรอังกฤษ ตัวเลข จุด ขีดกลาง หรือขีดล่าง');
    if ($fullname === '') throw new RuntimeException('กรุณาระบุชื่อ-สกุล');
    if ($action === 'create' && strlen($password) < 8) throw new RuntimeException('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
    if ($level === 'user' && !$postedPermissions) throw new RuntimeException('กรุณากำหนดสิทธิ์ให้เจ้าหน้าที่อย่างน้อย 1 ขั้นตอน');

    db()->beginTransaction();
    if ($action === 'create') {
      $stmt = db()->prepare('INSERT INTO tbl_user(user_username, user_password, user_fullname, user_level, user_status)
        VALUES(:username, :password, :fullname, :level, :status)');
      $stmt->execute(['username' => $username, 'password' => password_hash($password, PASSWORD_DEFAULT), 'fullname' => $fullname, 'level' => $level, 'status' => $status]);
      $targetUserId = (int) db()->lastInsertId();
      $message = 'เพิ่มผู้ใช้งานและกำหนดสิทธิ์เรียบร้อย';
    } else {
      if (!$id) throw new RuntimeException('ไม่พบผู้ใช้งาน');
      $targetUserId = (int) $id;
      $data = ['username' => $username, 'fullname' => $fullname, 'level' => $level, 'status' => $status, 'id' => $targetUserId];
      $sql = 'UPDATE tbl_user SET user_username = :username, user_fullname = :fullname, user_level = :level, user_status = :status';
      if ($password !== '') {
        if (strlen($password) < 8) throw new RuntimeException('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
        $sql .= ', user_password = :password';
        $data['password'] = password_hash($password, PASSWORD_DEFAULT);
      }
      $sql .= ' WHERE user_id = :id';
      db()->prepare($sql)->execute($data);
      $message = 'แก้ไขผู้ใช้งานและสิทธิ์เรียบร้อย';
    }

    db()->prepare('DELETE FROM tbl_user_permission WHERE user_id = :user_id')->execute(['user_id' => $targetUserId]);
    if ($level === 'user') {
      $permissionStmt = db()->prepare('INSERT INTO tbl_user_permission(user_id, permission_key, granted_by)
        VALUES(:user_id, :permission_key, :granted_by)');
      foreach ($postedPermissions as $permissionKey) {
        $permissionStmt->execute(['user_id' => $targetUserId, 'permission_key' => $permissionKey, 'granted_by' => $currentUser['user_fullname']]);
      }
    }
    audit_log($action === 'create' ? 'create' : 'update', 'user', $targetUserId,
      ($action === 'create' ? 'เพิ่ม' : 'แก้ไข') . 'บัญชีผู้ใช้งาน ' . $username, [
        'target_username' => $username, 'target_fullname' => $fullname, 'level' => $level,
        'status' => $status, 'permissions' => $level === 'admin' ? array_keys($permissionDefinitions) : $postedPermissions,
      ]);
    db()->commit();
    $_SESSION['user_crud_flash'] = ['type' => 'success', 'message' => $message];
    users_redirect();
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $e instanceof PDOException ? 'ชื่อผู้ใช้นี้อาจถูกใช้แล้ว หรือฐานข้อมูลไม่พร้อม' : $e->getMessage();
  }
}

$permissionMap = [];
foreach (db()->query('SELECT user_id, permission_key FROM tbl_user_permission ORDER BY permission_key')->fetchAll() as $permissionRow) {
  $permissionMap[(int) $permissionRow['user_id']][] = $permissionRow['permission_key'];
}

$edit = null;
if (isset($_GET['edit']) && ($editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT))) {
  $stmt = db()->prepare('SELECT * FROM tbl_user WHERE user_id = :id');
  $stmt->execute(['id' => $editId]);
  $edit = $stmt->fetch() ?: null;
}

$isPostError = $error !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$formIsEdit = $isPostError ? (($_POST['action'] ?? '') === 'update') : (bool) $edit;
$formUser = $isPostError ? [
  'user_id' => (int) ($_POST['id'] ?? 0),
  'user_username' => trim($_POST['username'] ?? ''),
  'user_fullname' => trim($_POST['fullname'] ?? ''),
  'user_level' => $_POST['level'] ?? 'user',
  'user_status' => $_POST['status'] ?? 'active',
] : $edit;
$formPermissions = $isPostError ? $postedPermissions : ($edit ? ($permissionMap[(int) $edit['user_id']] ?? []) : []);

$q = trim($_GET['q'] ?? '');
$params = [];
$where = '';
if ($q !== '') { $where = ' WHERE user_username LIKE :q OR user_fullname LIKE :q'; $params['q'] = '%' . $q . '%'; }
$stmt = db()->prepare('SELECT * FROM tbl_user' . $where . ' ORDER BY user_id DESC');
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>จัดการผู้ใช้งานและสิทธิ์</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
  <link href="<?php echo h(url_for('admin-crud.css')); ?>" rel="stylesheet">
</head>
<body>
<?php render_topbar(); ?>
<main class="admin-shell">
  <section class="admin-hero"><div><h1><i class="bi bi-person-gear me-2"></i>จัดการผู้ใช้งานและสิทธิ์</h1><p>Admin กำหนดสิทธิ์เข้าถึงแต่ละขั้นตอนให้เจ้าหน้าที่ได้มากกว่าหนึ่งรายการ</p></div><span class="hero-count"><?php echo number_format(count($users)); ?> บัญชี</span></section>
  <?php if ($flash): ?><div class="alert alert-<?php echo h($flash['type']); ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>

  <div class="crud-grid user-permission-layout">
    <section class="crud-card sticky">
      <div class="crud-head"><h2><?php echo $formIsEdit ? 'แก้ไขผู้ใช้งาน' : 'เพิ่มผู้ใช้งาน'; ?></h2></div>
      <form class="crud-body" method="post" id="userForm">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo $formIsEdit ? 'update' : 'create'; ?>">
        <?php if ($formIsEdit): ?><input type="hidden" name="id" value="<?php echo (int) $formUser['user_id']; ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label">ชื่อผู้ใช้</label><input class="form-control" name="username" value="<?php echo h($formUser['user_username'] ?? ''); ?>" required></div>
        <div class="mb-3"><label class="form-label">ชื่อ-สกุล</label><input class="form-control" name="fullname" value="<?php echo h($formUser['user_fullname'] ?? ''); ?>" required></div>
        <div class="mb-3"><label class="form-label">รหัสผ่าน <?php if ($formIsEdit): ?><small class="text-secondary">(เว้นว่างหากไม่เปลี่ยน)</small><?php endif; ?></label><input class="form-control" type="password" name="password" <?php echo $formIsEdit ? '' : 'required'; ?> minlength="8"></div>
        <div class="row g-2 mb-3"><div class="col"><label class="form-label">ประเภทบัญชี</label><select class="form-select" name="level" id="userLevel"><option value="user" <?php echo ($formUser['user_level'] ?? 'user') === 'user' ? 'selected' : ''; ?>>เจ้าหน้าที่</option><option value="admin" <?php echo ($formUser['user_level'] ?? '') === 'admin' ? 'selected' : ''; ?>>ผู้ดูแลระบบ</option></select></div><div class="col"><label class="form-label">สถานะ</label><select class="form-select" name="status"><option value="active" <?php echo ($formUser['user_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>ใช้งาน</option><option value="inactive" <?php echo ($formUser['user_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>ระงับ</option></select></div></div>

        <fieldset class="permission-fieldset" id="permissionFieldset">
          <legend>สิทธิ์การเข้าถึงขั้นตอน</legend>
          <p class="permission-admin-note"><i class="bi bi-shield-check"></i> บัญชี Admin เข้าถึงทุกขั้นตอนอัตโนมัติ</p>
          <div class="permission-options">
          <?php foreach ($permissionDefinitions as $key => $definition): ?>
            <label class="permission-option">
              <input type="checkbox" name="permissions[]" value="<?php echo h($key); ?>" <?php echo in_array($key, $formPermissions, true) ? 'checked' : ''; ?>>
              <span class="permission-icon"><i class="bi <?php echo h($definition['icon']); ?>"></i></span>
              <span><strong><?php echo h($definition['label']); ?></strong><small><?php echo h($definition['description']); ?></small></span>
            </label>
          <?php endforeach; ?>
          </div>
        </fieldset>

        <div class="d-grid gap-2 mt-4"><button class="btn btn-purple" type="submit"><i class="bi bi-floppy me-1"></i>บันทึกบัญชีและสิทธิ์</button><?php if ($formIsEdit): ?><a class="btn btn-light" href="<?php echo h(url_for('users.php')); ?>">ยกเลิก</a><?php endif; ?></div>
      </form>
    </section>

    <section class="crud-card">
      <div class="toolbar"><strong>บัญชีทั้งหมด</strong><form class="search-form"><input class="form-control" name="q" value="<?php echo h($q); ?>" placeholder="ค้นหาชื่อหรือ username"><button class="btn btn-dark"><i class="bi bi-search"></i></button></form></div>
      <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>USERNAME</th><th>ชื่อ-สกุล</th><th>ประเภท</th><th>สิทธิ์ขั้นตอน</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead><tbody>
      <?php foreach ($users as $row): ?>
        <tr><td class="fw-bold"><?php echo h($row['user_username']); ?></td><td><?php echo h($row['user_fullname']); ?></td><td><span class="status-badge <?php echo $row['user_level'] === 'admin' ? 'level-admin' : 'level-user'; ?>"><?php echo $row['user_level'] === 'admin' ? 'Admin' : 'เจ้าหน้าที่'; ?></span></td><td><div class="permission-badges"><?php if ($row['user_level'] === 'admin'): ?><span class="permission-badge all"><i class="bi bi-shield-check"></i> ทุกขั้นตอน</span><?php else: ?><?php foreach ($permissionMap[(int) $row['user_id']] ?? [] as $key): ?><?php if (isset($permissionDefinitions[$key])): ?><span class="permission-badge"><?php echo h($permissionDefinitions[$key]['label']); ?></span><?php endif; ?><?php endforeach; ?><?php if (empty($permissionMap[(int) $row['user_id']])): ?><span class="text-danger small">ยังไม่มีสิทธิ์</span><?php endif; ?><?php endif; ?></div></td><td><span class="status-badge <?php echo $row['user_status'] === 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo $row['user_status'] === 'active' ? 'ใช้งาน' : 'ระงับ'; ?></span></td><td><div class="row-actions"><a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo (int) $row['user_id']; ?>"><i class="bi bi-pencil"></i></a><?php if ((int) $row['user_id'] !== (int) $currentUser['user_id']): ?><form method="post" onsubmit="return confirm('ยืนยันการลบบัญชีและสิทธิ์ทั้งหมด?')"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $row['user_id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?></div></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </section>
  </div>
</main>
<script>
(function () {
  var level = document.getElementById('userLevel');
  var fieldset = document.getElementById('permissionFieldset');
  function updatePermissionState() {
    fieldset.classList.toggle('admin-selected', level.value === 'admin');
  }
  level.addEventListener('change', updatePermissionState);
  updatePermissionState();
}());
</script>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/system.php';

ensure_system_schema();
if ((int) db()->query('SELECT COUNT(*) FROM tbl_user')->fetchColumn() === 0) {
  redirect_to('setup.php');
}

if (current_user()) {
  redirect_to('dashboard.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = (string) ($_POST['password'] ?? '');

  if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $error = 'แบบฟอร์มหมดอายุ กรุณาลองใหม่อีกครั้ง';
  } elseif ($username === '' || $password === '') {
    $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
  } else {
    try {
      $user = find_user_by_username($username);

      if ($user && $user['user_status'] === 'active' && password_verify($password, $user['user_password'])) {
        login_user($user);
        redirect_to('dashboard.php');
      }

      if ($user && $user['user_status'] !== 'active') {
        $error = 'บัญชีผู้ใช้นี้ถูกปิดใช้งาน';
      } else {
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
      }
    } catch (Exception $e) {
      error_log('User login failed: ' . $e->getMessage());
      $error = db_friendly_error($e);
    }
  }
}
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เข้าสู่ระบบเจ้าหน้าที่</title>
  <link href="typography.css" rel="stylesheet">
  <style>
  :root {
    --ink: #17212f;
    --muted: #667085;
    --line: #d8e0e6;
    --green: #1d7a54;
    --green-dark: #0f5138;
    --red: #bd3f3f;
    --bg: #eef3f0;
  }

  * {
    box-sizing: border-box;
  }

  body {
    margin: 0;
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 24px;
    font-family: var(--font-family-sans);
    background:
      linear-gradient(rgba(238, 243, 240, 0.86), rgba(238, 243, 240, 0.92)),
      url("https://images.unsplash.com/photo-1605000797499-95a51c5269ae?auto=format&fit=crop&w=1400&q=80");
    background-size: cover;
    background-position: center;
    color: var(--ink);
  }

  .card {
    width: min(100%, 430px);
    padding: 30px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(23, 33, 47, 0.16);
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 26px;
  }

  .mark {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 8px;
    background: var(--green-dark);
    color: #fff;
    font-weight: 900;
  }

  h1 {
    margin: 0 0 8px;
    font-size: 28px;
    letter-spacing: 0;
  }

  p {
    margin: 0 0 24px;
    color: var(--muted);
    line-height: 1.55;
  }

  label {
    display: block;
    margin: 15px 0 7px;
    font-weight: 900;
    font-size: 14px;
  }

  input {
    width: 100%;
    height: 48px;
    padding: 0 13px;
    border: 1px solid var(--line);
    border-radius: 7px;
    background: #fbfcfd;
    font: inherit;
    font-size: 16px;
  }

  input:focus {
    outline: 3px solid rgba(29, 122, 84, 0.16);
    border-color: var(--green);
    background: #fff;
  }

  .alert {
    padding: 12px 13px;
    border-radius: 7px;
    border: 1px solid #f1b8b8;
    background: #fff1f1;
    color: var(--red);
    font-size: 14px;
    line-height: 1.45;
  }

  .btn {
    width: 100%;
    height: 48px;
    margin-top: 22px;
    border: 0;
    border-radius: 7px;
    background: var(--green);
    color: #fff;
    font-weight: 900;
    font-size: 16px;
    cursor: pointer;
  }

  .links {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 18px;
    font-size: 14px;
  }

  .links a {
    color: var(--green-dark);
    text-decoration: none;
    font-weight: 800;
  }
  </style>
</head>

<body>
  <main class="card">
    <div class="brand">
      <div class="mark">ย</div>
      <div>
        <strong><?php echo h(system_name()); ?></strong><br>
        <span style="color: var(--muted); font-size: 13px;"><?php echo h(cooperative_name()); ?> · สำหรับเจ้าหน้าที่</span>
      </div>
    </div>

    <h1>เข้าสู่ระบบ</h1>
    <p>ใช้บัญชีจากตาราง tbl_user ในฐานข้อมูล <?php echo h(env_value('YANG_DB_NAME', 'coopgame_db')); ?></p>

    <?php if ($error): ?>
    <div class="alert"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo h(url_for('user-login.php')); ?>" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

      <label for="username">ชื่อผู้ใช้</label>
      <input id="username" name="username" value="<?php echo h($username); ?>" autocomplete="username" required>

      <label for="password">รหัสผ่าน</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>

      <button class="btn" type="submit">เข้าสู่ระบบ</button>
    </form>

    <div class="links">
      <a href="<?php echo h(url_for('index.php')); ?>">กลับหน้าภาพรวม</a>
      <a href="<?php echo h(url_for('login.php')); ?>">สมาชิกเข้าสู่ระบบ</a>
    </div>
  </main>
</body>

</html>

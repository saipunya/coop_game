<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/system.php';

if (current_member()) {
  redirect_to('allmember.php');
}

$error = '';
$memNumber = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $memNumber = trim($_POST['mem_number'] ?? '');
  $pin = trim($_POST['pin'] ?? '');

  if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $error = 'แบบฟอร์มหมดอายุ กรุณาลองใหม่อีกครั้ง';
  } elseif ($memNumber === '' || $pin === '') {
    $error = 'กรุณากรอกเลขสมาชิกและรหัสสมาชิก';
  } else {
    try {
      $member = find_member_by_number($memNumber);
      $storedPin = $member ? trim((string) $member['mem_personcode']) : '';

      if ($member && $storedPin !== '' && hash_equals($storedPin, $pin)) {
        login_member($member);
        redirect_to('allmember.php');
      }

      $error = 'เลขสมาชิกหรือรหัสสมาชิกไม่ถูกต้อง';
    } catch (Exception $e) {
      error_log('Member login failed: ' . $e->getMessage());
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
  <title>เข้าสู่ระบบสมาชิก</title>
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

    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      grid-template-columns: minmax(0, 1fr) 440px;
      font-family: var(--font-family-sans);
      background: var(--bg);
      color: var(--ink);
    }

    .visual {
      min-height: 100vh;
      padding: 42px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      background:
        linear-gradient(rgba(15, 81, 56, 0.78), rgba(15, 81, 56, 0.82)),
        url("https://images.unsplash.com/photo-1605000797499-95a51c5269ae?auto=format&fit=crop&w=1400&q=80");
      background-size: cover;
      background-position: center;
      color: #fff;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      font-weight: 800;
    }

    .brand-mark {
      width: 42px;
      height: 42px;
      display: grid;
      place-items: center;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.18);
    }

    .visual h1 {
      max-width: 680px;
      margin: 0 0 14px;
      font-size: clamp(34px, 5vw, 60px);
      line-height: 1.08;
      letter-spacing: 0;
    }

    .visual p {
      max-width: 620px;
      margin: 0;
      color: rgba(255, 255, 255, 0.86);
      line-height: 1.7;
      font-size: 17px;
    }

    .panel {
      min-height: 100vh;
      padding: 38px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      background: #fff;
      border-left: 1px solid var(--line);
    }

    .panel h2 {
      margin: 0 0 8px;
      font-size: 28px;
      letter-spacing: 0;
    }

    .panel-subtitle {
      margin: 0 0 28px;
      color: var(--muted);
      line-height: 1.55;
    }

    label {
      display: block;
      margin: 16px 0 7px;
      font-weight: 800;
      font-size: 14px;
    }

    input {
      width: 100%;
      height: 48px;
      padding: 0 13px;
      border: 1px solid var(--line);
      border-radius: 7px;
      font: inherit;
      font-size: 16px;
      background: #fbfcfd;
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

    .links a { color: var(--green-dark); text-decoration: none; font-weight: 800; }
    .hint { margin-top: 26px; color: var(--muted); font-size: 14px; line-height: 1.55; }

    @media (max-width: 860px) {
      body { grid-template-columns: 1fr; }
      .visual { min-height: 320px; padding: 28px; }
      .panel { min-height: auto; border-left: 0; padding: 28px 20px 36px; }
    }
  </style>
</head>
<body>
  <section class="visual">
    <div class="brand">
      <div class="brand-mark">ย</div>
      <div><?php echo h(system_name()); ?></div>
    </div>
    <div>
      <h1>ตรวจสอบข้อมูลรับซื้อของสมาชิกได้ทันที</h1>
      <p>เข้าสู่ระบบด้วยเลขสมาชิกและรหัสสมาชิก เพื่อดูประวัติส่งยาง ยอดเงิน ยอดหัก และยอดสุทธิของตนเอง</p>
    </div>
  </section>

  <main class="panel">
    <h2>เข้าสู่ระบบสมาชิก</h2>
    <p class="panel-subtitle">ใช้เลขสมาชิก และรหัสสมาชิก 4 หลักตามข้อมูลทะเบียน</p>

    <?php if ($error): ?>
      <div class="alert"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo h(url_for('login.php')); ?>" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

      <label for="mem_number">เลขสมาชิก</label>
      <input id="mem_number" name="mem_number" value="<?php echo h($memNumber); ?>" inputmode="numeric" autocomplete="username" required>

      <label for="pin">รหัสสมาชิก</label>
      <input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="current-password" required>

      <button class="btn" type="submit">เข้าสู่ระบบ</button>
    </form>

    <div class="links">
      <a href="<?php echo h(url_for('index.php')); ?>">กลับหน้าภาพรวม</a>
      <a href="<?php echo h(url_for('allmember.php')); ?>">ข้อมูลขายยางของฉัน</a>
    </div>

    <div class="hint">
      หมายเหตุ: รหัสที่ใช้ตรวจสอบมาจากฟิลด์ <strong>mem_personcode</strong> ในตาราง <strong>tbl_member</strong>
    </div>
  </main>
</body>
</html>

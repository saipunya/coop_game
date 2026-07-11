<?php
require_once __DIR__ . '/db.php';

function current_member()
{
  return $_SESSION['member'] ?? null;
}

function require_member()
{
  if (!current_member()) {
    redirect_to('login.php');
  }
}

function find_member_by_number($memNumber)
{
  $stmt = db()->prepare('
    SELECT mem_id, mem_group, mem_number, mem_fullname, mem_birthtext, mem_class, mem_personcode
    FROM tbl_member
    WHERE mem_number = :mem_number
    LIMIT 1
  ');
  $stmt->execute(['mem_number' => $memNumber]);
  return $stmt->fetch();
}

function login_member($member)
{
  session_regenerate_id(true);
  $_SESSION['member'] = [
    'mem_id' => (int) $member['mem_id'],
    'mem_group' => $member['mem_group'],
    'mem_number' => $member['mem_number'],
    'mem_fullname' => $member['mem_fullname'],
    'mem_class' => $member['mem_class'],
  ];

  log_member_action($member, 'login');
}

function logout_member()
{
  $member = current_member();
  if ($member) {
    log_member_action($member, 'logout');
  }

  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
}

function log_member_action($member, $actionType)
{
  try {
    $stmt = db()->prepare('
      INSERT INTO tbl_member_portal_log
        (mem_id, mem_number, mem_fullname, action_type, ip_address, user_agent, created_at)
      VALUES
        (:mem_id, :mem_number, :mem_fullname, :action_type, :ip_address, :user_agent, NOW())
    ');
    $stmt->execute([
      'mem_id' => (int) $member['mem_id'],
      'mem_number' => $member['mem_number'],
      'mem_fullname' => $member['mem_fullname'],
      'action_type' => $actionType,
      'ip_address' => client_ip(),
      'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
  } catch (Exception $e) {
    error_log('Member portal log failed: ' . $e->getMessage());
  }
}

function current_user()
{
  return $_SESSION['user'] ?? null;
}

function require_user()
{
  if (!current_user()) {
    redirect_to('user-login.php');
  }
}

function find_user_by_username($username)
{
  $stmt = db()->prepare('
    SELECT user_id, user_username, user_password, user_fullname, user_level, user_status
    FROM tbl_user
    WHERE user_username = :username
    LIMIT 1
  ');
  $stmt->execute(['username' => $username]);
  return $stmt->fetch();
}

function login_user($user)
{
  session_regenerate_id(true);
  $_SESSION['user'] = [
    'user_id' => (int) $user['user_id'],
    'user_username' => $user['user_username'],
    'user_fullname' => $user['user_fullname'],
    'user_level' => $user['user_level'],
    'user_status' => $user['user_status'],
  ];
}

function logout_user()
{
  unset($_SESSION['user']);
}
?>

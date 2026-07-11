<?php
if (session_status() === PHP_SESSION_NONE) {
  session_name('yang_member_session');
  session_start();
}

define('YANG_BASE_PATH', rtrim($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '/yang', '/'));
define('YANG_ROOT', __DIR__);

function yang_load_env($path)
{
  if (!is_readable($path)) {
    return;
  }

  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
      continue;
    }

    list($key, $value) = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);
    $value = trim($value, "\"'");

    if ($key !== '' && getenv($key) === false) {
      putenv($key . '=' . $value);
      $_ENV[$key] = $value;
    }
  }
}

yang_load_env(dirname(__DIR__) . '/.env');

function env_value($key, $default = '')
{
  $value = getenv($key);
  return $value === false ? $default : $value;
}

function h($value)
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url_for($path = '')
{
  $base = YANG_BASE_PATH ?: '/yang';
  return $base . '/' . ltrim($path, '/');
}

function redirect_to($path)
{
  header('Location: ' . url_for($path));
  exit;
}

function csrf_token()
{
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function verify_csrf($token)
{
  return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $token);
}

function client_ip()
{
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    return trim($parts[0]);
  }
  return $_SERVER['REMOTE_ADDR'] ?? '';
}
?>

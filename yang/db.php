<?php
require_once __DIR__ . '/bootstrap.php';

function db()
{
  static $pdo = null;

  if ($pdo instanceof PDO) {
    return $pdo;
  }

  $host = env_value('YANG_DB_HOST', env_value('DB_HOST', '127.0.0.1'));
  $port = env_value('YANG_DB_PORT', env_value('DB_PORT', '3306'));
  $name = env_value('YANG_DB_NAME', env_value('DB_NAME', 'rts_db'));
  $user = env_value('YANG_DB_USER', env_value('DB_USER', 'root'));
  $pass = env_value('YANG_DB_PASSWORD', env_value('DB_PASSWORD', ''));

  $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  return $pdo;
}

function db_friendly_error(Exception $e)
{
  $message = $e->getMessage();

  if (strpos($message, 'SQLSTATE[HY000] [1129]') !== false || stripos($message, 'is blocked because of many connection errors') !== false) {
    return 'ฐานข้อมูลบล็อก IP เครื่องนี้จาก connection error หลายครั้ง ต้องให้ผู้ดูแลฐานข้อมูลรัน FLUSH HOSTS หรือ mariadb-admin flush-hosts บน server ฐานข้อมูล';
  }

  if (strpos($message, 'SQLSTATE[HY000] [1045]') !== false) {
    return 'ชื่อผู้ใช้หรือรหัสผ่านฐานข้อมูลไม่ถูกต้อง กรุณาตรวจสอบ YANG_DB_USER และ YANG_DB_PASSWORD';
  }

  if (strpos($message, 'SQLSTATE[HY000] [1049]') !== false) {
    return 'ไม่พบฐานข้อมูลที่กำหนด กรุณาตรวจสอบ YANG_DB_NAME หรือ import ฐานข้อมูล rts_db ก่อน';
  }

  if (stripos($message, 'Base table or view not found') !== false) {
    return 'ไม่พบตารางระบบสมาชิก กรุณา import SQL dump ให้มี tbl_member, tbl_rubber และ tbl_member_portal_log';
  }

  if (strpos($message, 'SQLSTATE[HY000] [2002]') !== false) {
    return 'เชื่อมต่อ database host ไม่ได้ กรุณาตรวจสอบ YANG_DB_HOST, YANG_DB_PORT และสถานะ MySQL/MariaDB';
  }

  return 'เชื่อมต่อฐานข้อมูลไม่ได้ กรุณาตรวจสอบการตั้งค่า YANG_DB_*';
}
?>

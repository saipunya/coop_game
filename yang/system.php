<?php
require_once __DIR__ . '/db.php';

function ensure_system_schema()
{
  static $ready = false;
  if ($ready) return;

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_user (
    user_id int(11) NOT NULL AUTO_INCREMENT,
    user_username varchar(255) NOT NULL,
    user_password varchar(255) NOT NULL,
    user_fullname varchar(255) NOT NULL,
    user_level varchar(255) NOT NULL DEFAULT "user",
    user_status varchar(255) NOT NULL DEFAULT "active",
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_username (user_username)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_user_permission (
    user_id int(11) NOT NULL,
    permission_key varchar(50) NOT NULL,
    granted_by varchar(255) NOT NULL DEFAULT "",
    granted_at datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (user_id, permission_key),
    KEY idx_user_permission_key (permission_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_audit_log (
    audit_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id int(11) DEFAULT NULL,
    actor_username varchar(255) NOT NULL DEFAULT "",
    actor_fullname varchar(255) NOT NULL DEFAULT "",
    actor_level varchar(50) NOT NULL DEFAULT "",
    action_key varchar(80) NOT NULL,
    entity_type varchar(80) NOT NULL,
    entity_id varchar(100) NOT NULL DEFAULT "",
    summary varchar(500) NOT NULL,
    details_json longtext DEFAULT NULL,
    ip_address varchar(45) NOT NULL DEFAULT "",
    user_agent varchar(500) NOT NULL DEFAULT "",
    created_at datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (audit_id),
    KEY idx_audit_created_at (created_at),
    KEY idx_audit_actor_created (actor_user_id, created_at),
    KEY idx_audit_action_created (action_key, created_at),
    KEY idx_audit_entity (entity_type, entity_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

  db()->exec('DELETE FROM tbl_audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)');

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_member (
    mem_id int(11) NOT NULL AUTO_INCREMENT,
    mem_group varchar(255) NOT NULL DEFAULT "",
    mem_number varchar(255) NOT NULL,
    mem_fullname varchar(255) NOT NULL,
    mem_birthtext varchar(8) NOT NULL DEFAULT "",
    mem_class varchar(255) NOT NULL DEFAULT "member",
    mem_personcode text NOT NULL,
    mem_saveby varchar(255) NOT NULL DEFAULT "",
    mem_savedate date NOT NULL,
    PRIMARY KEY (mem_id),
    KEY idx_member_number (mem_number)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_member_portal_log (
    log_id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    mem_id int(10) UNSIGNED NOT NULL,
    mem_number varchar(50) NOT NULL,
    mem_fullname varchar(255) NOT NULL,
    action_type varchar(20) NOT NULL,
    ip_address varchar(45) NOT NULL DEFAULT "",
    user_agent varchar(255) NOT NULL DEFAULT "",
    created_at datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (log_id),
    KEY idx_mem_id (mem_id),
    KEY idx_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_wangyang (
    wang_id int(11) NOT NULL AUTO_INCREMENT,
    wang_date date NOT NULL,
    wang_lan varchar(255) NOT NULL,
    wang_note text DEFAULT NULL,
    wang_mid int(11) NOT NULL,
    wang_group varchar(255) NOT NULL,
    wang_number varchar(255) NOT NULL DEFAULT "",
    wang_name varchar(255) NOT NULL,
    wang_class varchar(255) NOT NULL DEFAULT "member",
    wang_sack decimal(18,2) NOT NULL DEFAULT 0.00,
    wang_weight decimal(18,2) NOT NULL DEFAULT 0.00,
    wang_status varchar(255) NOT NULL DEFAULT "pending",
    wang_saveby varchar(255) NOT NULL,
    wang_savedate timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (wang_id),
    KEY idx_wang_date_yard (wang_date, wang_lan),
    KEY idx_wang_member (wang_mid, wang_number)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_wangyang_daily_summary (
    ws_date date NOT NULL,
    ws_weight_per_bag decimal(10,2) NOT NULL DEFAULT 0.00,
    ws_estimated_weight decimal(18,2) NOT NULL DEFAULT 0.00,
    ws_saveby varchar(255) NOT NULL DEFAULT "",
    ws_savedate datetime NOT NULL,
    PRIMARY KEY (ws_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_system_setting (
    setting_key varchar(64) NOT NULL,
    setting_value text NOT NULL,
    updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (setting_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

  $permissionMigration = db()->query('SELECT setting_value FROM tbl_system_setting
    WHERE setting_key = "workflow_permissions_initialized" LIMIT 1')->fetchColumn();
  if ($permissionMigration === false) {
    $legacyUsers = db()->query('SELECT user_id FROM tbl_user WHERE user_level <> "admin"')->fetchAll(PDO::FETCH_COLUMN);
    $permissionStmt = db()->prepare('INSERT IGNORE INTO tbl_user_permission(user_id, permission_key, granted_by)
      VALUES(:user_id, :permission_key, "system migration")');
    foreach ($legacyUsers as $legacyUserId) {
      foreach (["placement", "weighing", "deductions", "payments"] as $permissionKey) {
        $permissionStmt->execute(['user_id' => $legacyUserId, 'permission_key' => $permissionKey]);
      }
    }
    db()->exec('INSERT INTO tbl_system_setting(setting_key, setting_value)
      VALUES("workflow_permissions_initialized", "1")
      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
  }

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_yard (
    yard_id int(11) NOT NULL AUTO_INCREMENT,
    yard_code varchar(50) NOT NULL,
    yard_name varchar(255) NOT NULL,
    yard_status varchar(20) NOT NULL DEFAULT "active",
    sort_order int(11) NOT NULL DEFAULT 0,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (yard_id),
    UNIQUE KEY uq_yard_code (yard_code),
    KEY idx_yard_status_sort (yard_status, sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_rubber_workflow (
    workflow_id int(11) NOT NULL AUTO_INCREMENT,
    weigh_date date NOT NULL,
    yard_code varchar(50) NOT NULL,
    member_id int(11) NOT NULL,
    member_number varchar(255) NOT NULL DEFAULT "",
    member_name varchar(255) NOT NULL,
    member_group varchar(255) NOT NULL DEFAULT "",
    placement_at datetime DEFAULT NULL,
    total_bags decimal(18,2) NOT NULL DEFAULT 0.00,
    estimated_weight decimal(18,2) NOT NULL DEFAULT 0.00,
    actual_weight decimal(18,2) NOT NULL DEFAULT 0.00,
    price_per_kg decimal(18,2) NOT NULL DEFAULT 0.00,
    gross_amount decimal(18,2) NOT NULL DEFAULT 0.00,
    total_deduction decimal(18,2) NOT NULL DEFAULT 0.00,
    net_amount decimal(18,2) NOT NULL DEFAULT 0.00,
    workflow_status varchar(20) NOT NULL DEFAULT "placed",
    weighed_by varchar(255) NOT NULL DEFAULT "",
    weighed_at datetime DEFAULT NULL,
    deduction_by varchar(255) NOT NULL DEFAULT "",
    deduction_at datetime DEFAULT NULL,
    receipt_no varchar(50) DEFAULT NULL,
    paid_by varchar(255) NOT NULL DEFAULT "",
    paid_at datetime DEFAULT NULL,
    admin_edited_by varchar(255) NOT NULL DEFAULT "",
    admin_edited_at datetime DEFAULT NULL,
    admin_edit_type varchar(50) NOT NULL DEFAULT "",
    created_at datetime NOT NULL DEFAULT current_timestamp(),
    updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (workflow_id),
    UNIQUE KEY uq_workflow_round (weigh_date, yard_code, member_id),
    UNIQUE KEY uq_workflow_receipt (receipt_no),
    KEY idx_workflow_status_date (workflow_status, weigh_date),
    KEY idx_workflow_member (member_id, member_number)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

  $placementAtColumn = db()->query('SHOW COLUMNS FROM tbl_rubber_workflow LIKE "placement_at"')->fetch();
  if (!$placementAtColumn) {
    db()->exec('ALTER TABLE tbl_rubber_workflow ADD COLUMN placement_at datetime DEFAULT NULL AFTER member_group');
    db()->exec('UPDATE tbl_rubber_workflow workflow
      LEFT JOIN (
        SELECT wang_date, wang_lan, wang_mid, MIN(wang_savedate) AS first_placement_at
        FROM tbl_wangyang GROUP BY wang_date, wang_lan, wang_mid
      ) placement ON placement.wang_date = workflow.weigh_date
        AND placement.wang_lan = workflow.yard_code AND placement.wang_mid = workflow.member_id
      SET workflow.placement_at = COALESCE(placement.first_placement_at, workflow.created_at)
      WHERE workflow.placement_at IS NULL');
  }

  $adminEditColumns = [
    'admin_edited_by' => 'varchar(255) NOT NULL DEFAULT "" AFTER paid_at',
    'admin_edited_at' => 'datetime DEFAULT NULL AFTER admin_edited_by',
    'admin_edit_type' => 'varchar(50) NOT NULL DEFAULT "" AFTER admin_edited_at',
  ];
  foreach ($adminEditColumns as $columnName => $definition) {
    $column = db()->query('SHOW COLUMNS FROM tbl_rubber_workflow LIKE ' . db()->quote($columnName))->fetch();
    if (!$column) db()->exec('ALTER TABLE tbl_rubber_workflow ADD COLUMN ' . $columnName . ' ' . $definition);
  }

  $wangSavedateColumn = db()->query('SHOW COLUMNS FROM tbl_wangyang LIKE "wang_savedate"')->fetch();
  if ($wangSavedateColumn && stripos((string) ($wangSavedateColumn['Extra'] ?? ''), 'on update') !== false) {
    db()->exec('ALTER TABLE tbl_wangyang MODIFY wang_savedate timestamp NOT NULL DEFAULT current_timestamp()');
  }

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_rubber_deduction (
    deduction_id int(11) NOT NULL AUTO_INCREMENT,
    workflow_id int(11) NOT NULL,
    deduction_type_id int(11) DEFAULT NULL,
    deduction_label varchar(255) NOT NULL,
    deduction_amount decimal(18,2) NOT NULL DEFAULT 0.00,
    sort_order int(11) NOT NULL DEFAULT 0,
    saved_by varchar(255) NOT NULL DEFAULT "",
    saved_at datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (deduction_id),
    KEY idx_deduction_workflow (workflow_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

  $deductionSnapshotColumns = [
    'deduction_type_id' => 'int(11) DEFAULT NULL AFTER workflow_id',
    'sort_order' => 'int(11) NOT NULL DEFAULT 0 AFTER deduction_amount',
  ];
  foreach ($deductionSnapshotColumns as $columnName => $definition) {
    $column = db()->query('SHOW COLUMNS FROM tbl_rubber_deduction LIKE ' . db()->quote($columnName))->fetch();
    if (!$column) db()->exec('ALTER TABLE tbl_rubber_deduction ADD COLUMN ' . $columnName . ' ' . $definition);
  }
  db()->exec('UPDATE tbl_rubber_deduction SET sort_order = deduction_id WHERE sort_order = 0');

  db()->exec('CREATE TABLE IF NOT EXISTS tbl_deduction_type (
    deduction_type_id int(11) NOT NULL AUTO_INCREMENT,
    deduction_name varchar(255) NOT NULL,
    deduction_status varchar(20) NOT NULL DEFAULT "active",
    sort_order int(11) NOT NULL DEFAULT 0,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (deduction_type_id),
    UNIQUE KEY uq_deduction_name (deduction_name),
    KEY idx_deduction_status_sort (deduction_status, sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

  $deductionTypeCount = (int) db()->query('SELECT COUNT(*) FROM tbl_deduction_type')->fetchColumn();
  if ($deductionTypeCount === 0) {
    $defaultTypes = ['ค่าปุ๋ย', 'เงินกู้', 'ค่าขนส่ง', 'รายการหักอื่น'];
    $typeStmt = db()->prepare('INSERT INTO tbl_deduction_type(deduction_name, deduction_status, sort_order)
      VALUES(:name, "active", :sort)');
    foreach ($defaultTypes as $index => $name) {
      $typeStmt->execute(['name' => $name, 'sort' => $index + 1]);
    }
  }

  $ready = true;
}

function system_setting($key, $default = '')
{
  ensure_system_schema();
  $stmt = db()->prepare('SELECT setting_value FROM tbl_system_setting WHERE setting_key = :key LIMIT 1');
  $stmt->execute(['key' => $key]);
  $value = $stmt->fetchColumn();
  return $value === false ? $default : $value;
}

function save_system_setting($key, $value)
{
  ensure_system_schema();
  $stmt = db()->prepare('INSERT INTO tbl_system_setting(setting_key, setting_value)
    VALUES(:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
  $stmt->execute(['key' => $key, 'value' => $value]);
}

function system_name()
{
  return system_setting('system_name', 'ระบบรวบรวมยาง');
}

function cooperative_name()
{
  return system_setting('cooperative_name', 'สหกรณ์การเกษตร');
}

function active_yards()
{
  ensure_system_schema();
  return db()->query('SELECT yard_id, yard_code, yard_name FROM tbl_yard
    WHERE yard_status = "active" ORDER BY sort_order, yard_id')->fetchAll();
}

function active_deduction_types()
{
  ensure_system_schema();
  return db()->query('SELECT deduction_type_id, deduction_name, sort_order FROM tbl_deduction_type
    WHERE deduction_status = "active" ORDER BY sort_order, deduction_type_id')->fetchAll();
}

function audit_action_definitions()
{
  return [
    'create' => 'เพิ่มข้อมูล',
    'update' => 'แก้ไขข้อมูล',
    'delete' => 'ลบข้อมูล',
    'weigh' => 'บันทึกการชั่ง',
    'deduct' => 'บันทึกยอดหัก',
    'view_receipt' => 'เปิดใบเสร็จ',
    'approve_payment' => 'อนุมัติจ่ายเงิน',
    'admin_edit_paid_weight' => 'ADMIN แก้น้ำหนักหลังจ่าย',
    'admin_edit_paid_deduction' => 'ADMIN แก้ยอดหักหลังจ่าย',
    'configure' => 'ตั้งค่าระบบ',
  ];
}

function audit_log($actionKey, $entityType, $entityId, $summary, array $details = [])
{
  ensure_system_schema();
  $actor = function_exists('current_user') ? current_user() : null;
  $detailsJson = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
  if ($detailsJson !== null && strlen($detailsJson) > 65535) {
    $detailsJson = json_encode(['notice' => 'รายละเอียดถูกตัดเนื่องจากมีขนาดใหญ่เกินกำหนด'], JSON_UNESCAPED_UNICODE);
  }
  $stmt = db()->prepare('INSERT INTO tbl_audit_log
    (actor_user_id, actor_username, actor_fullname, actor_level, action_key, entity_type, entity_id,
     summary, details_json, ip_address, user_agent)
    VALUES(:actor_user_id, :actor_username, :actor_fullname, :actor_level, :action_key, :entity_type,
     :entity_id, :summary, :details_json, :ip_address, :user_agent)');
  $stmt->execute([
    'actor_user_id' => $actor['user_id'] ?? null,
    'actor_username' => $actor['user_username'] ?? '',
    'actor_fullname' => $actor['user_fullname'] ?? '',
    'actor_level' => $actor['user_level'] ?? '',
    'action_key' => substr((string) $actionKey, 0, 80),
    'entity_type' => substr((string) $entityType, 0, 80),
    'entity_id' => substr((string) $entityId, 0, 100),
    'summary' => substr((string) $summary, 0, 500),
    'details_json' => $detailsJson,
    'ip_address' => function_exists('client_ip') ? substr(client_ip(), 0, 45) : '',
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
  ]);
}

function setup_is_complete()
{
  return system_setting('setup_complete', '0') === '1';
}
?>

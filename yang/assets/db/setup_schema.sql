-- Additional schema for the first-run setup and yard management screens.
-- Safe to run more than once on MySQL/MariaDB.

CREATE TABLE IF NOT EXISTS `tbl_system_setting` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_user_permission` (
  `user_id` int(11) NOT NULL,
  `permission_key` varchar(50) NOT NULL,
  `granted_by` varchar(255) NOT NULL DEFAULT '',
  `granted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`permission_key`),
  KEY `idx_user_permission_key` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_audit_log` (
  `audit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_username` varchar(255) NOT NULL DEFAULT '',
  `actor_fullname` varchar(255) NOT NULL DEFAULT '',
  `actor_level` varchar(50) NOT NULL DEFAULT '',
  `action_key` varchar(80) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` varchar(100) NOT NULL DEFAULT '',
  `summary` varchar(500) NOT NULL,
  `details_json` longtext DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_created_at` (`created_at`),
  KEY `idx_audit_actor_created` (`actor_user_id`,`created_at`),
  KEY `idx_audit_action_created` (`action_key`,`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_yard` (
  `yard_id` int(11) NOT NULL AUTO_INCREMENT,
  `yard_code` varchar(50) NOT NULL,
  `yard_name` varchar(255) NOT NULL,
  `yard_status` varchar(20) NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`yard_id`),
  UNIQUE KEY `uq_yard_code` (`yard_code`),
  KEY `idx_yard_status_sort` (`yard_status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_rubber_workflow` (
  `workflow_id` int(11) NOT NULL AUTO_INCREMENT,
  `weigh_date` date NOT NULL,
  `yard_code` varchar(50) NOT NULL,
  `member_id` int(11) NOT NULL,
  `member_number` varchar(255) NOT NULL DEFAULT '',
  `member_name` varchar(255) NOT NULL,
  `member_group` varchar(255) NOT NULL DEFAULT '',
  `placement_at` datetime DEFAULT NULL,
  `total_bags` decimal(18,2) NOT NULL DEFAULT 0.00,
  `estimated_weight` decimal(18,2) NOT NULL DEFAULT 0.00,
  `actual_weight` decimal(18,2) NOT NULL DEFAULT 0.00,
  `price_per_kg` decimal(18,2) NOT NULL DEFAULT 0.00,
  `gross_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_deduction` decimal(18,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `workflow_status` varchar(20) NOT NULL DEFAULT 'placed',
  `weighed_by` varchar(255) NOT NULL DEFAULT '',
  `weighed_at` datetime DEFAULT NULL,
  `deduction_by` varchar(255) NOT NULL DEFAULT '',
  `deduction_at` datetime DEFAULT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `paid_by` varchar(255) NOT NULL DEFAULT '',
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`workflow_id`),
  UNIQUE KEY `uq_workflow_round` (`weigh_date`,`yard_code`,`member_id`),
  UNIQUE KEY `uq_workflow_receipt` (`receipt_no`),
  KEY `idx_workflow_status_date` (`workflow_status`,`weigh_date`),
  KEY `idx_workflow_member` (`member_id`,`member_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_rubber_deduction` (
  `deduction_id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_id` int(11) NOT NULL,
  `deduction_label` varchar(255) NOT NULL,
  `deduction_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `saved_by` varchar(255) NOT NULL DEFAULT '',
  `saved_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`deduction_id`),
  KEY `idx_deduction_workflow` (`workflow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_deduction_type` (
  `deduction_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `deduction_name` varchar(255) NOT NULL,
  `deduction_status` varchar(20) NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`deduction_type_id`),
  UNIQUE KEY `uq_deduction_name` (`deduction_name`),
  KEY `idx_deduction_status_sort` (`deduction_status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

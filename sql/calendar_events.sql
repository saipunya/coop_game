CREATE TABLE IF NOT EXISTS calendar_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  location VARCHAR(200) NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  color VARCHAR(7) NOT NULL DEFAULT '#2563eb',
  status ENUM('planned', 'in_progress', 'done', 'cancelled') NOT NULL DEFAULT 'planned',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_calendar_events_range (start_at, end_at),
  INDEX idx_calendar_events_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

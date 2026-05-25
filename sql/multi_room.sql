-- Multi-room support for Coop Game
-- Idempotent migration for existing installations.

CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('active', 'inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rooms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rooms (id, name, slug, status)
VALUES (1, 'Default Room', 'default-room', 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status);

CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin', 'room_admin') NOT NULL DEFAULT 'room_admin',
  room_id INT NULL,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (room_id) REFERENCES rooms(id),
  INDEX idx_admin_users_role (role),
  INDEX idx_admin_users_room (room_id),
  INDEX idx_admin_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE game_codes ADD COLUMN IF NOT EXISTS room_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE questions ADD COLUMN IF NOT EXISTS room_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE game_attempts ADD COLUMN IF NOT EXISTS room_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE questions ADD COLUMN IF NOT EXISTS created_by VARCHAR(100) NULL AFTER room_id;
ALTER TABLE game_settings ADD COLUMN IF NOT EXISTS room_id INT NOT NULL DEFAULT 1 FIRST;

UPDATE game_codes SET room_id = 1 WHERE room_id IS NULL OR room_id = 0;
UPDATE questions SET room_id = 1 WHERE room_id IS NULL OR room_id = 0;
UPDATE game_attempts SET room_id = 1 WHERE room_id IS NULL OR room_id = 0;
UPDATE game_attempts ga
JOIN game_codes gc ON gc.id = ga.game_code_id
SET ga.room_id = gc.room_id
WHERE ga.room_id <> gc.room_id;

-- Convert legacy global settings primary key to per-room settings when needed.
-- Run manually if your MySQL version does not support IF NOT EXISTS index syntax.

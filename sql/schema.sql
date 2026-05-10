-- Database Schema for Coop Game System
-- Run this script to create the database and tables

CREATE DATABASE IF NOT EXISTS coopgame_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE coopgame_db;

-- Table: game_codes
-- Stores generated game codes with 24-hour expiry
CREATE TABLE IF NOT EXISTS game_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) UNIQUE NOT NULL,
  status ENUM('unused', 'in_progress', 'used', 'expired') DEFAULT 'unused',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL,
  INDEX idx_code (code),
  INDEX idx_status (status),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: questions
-- Question bank with difficulty levels
CREATE TABLE IF NOT EXISTS questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_text TEXT NOT NULL,
  option_a VARCHAR(255) NOT NULL,
  option_b VARCHAR(255) NOT NULL,
  option_c VARCHAR(255) NOT NULL,
  option_d VARCHAR(255) NOT NULL,
  correct_answer ENUM('A', 'B', 'C', 'D') NOT NULL,
  difficulty ENUM('easy', 'medium', 'hard') NOT NULL,
  time_limit INT NOT NULL COMMENT 'seconds: 15, 20, or 25 based on difficulty',
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_difficulty (difficulty),
  INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: game_settings
-- Stores game-level configuration such as total questions per attempt
CREATE TABLE IF NOT EXISTS game_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: game_attempts
-- Tracks each player's game session
CREATE TABLE IF NOT EXISTS game_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  game_code_id INT NOT NULL,
  player_name VARCHAR(100) NULL,
  phone_number VARCHAR(20) NULL,
  score INT DEFAULT 0,
  total_time INT DEFAULT 0 COMMENT 'total seconds taken',
  status ENUM('in_progress', 'completed', 'abandoned') DEFAULT 'in_progress',
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  finished_at TIMESTAMP NULL,
  FOREIGN KEY (game_code_id) REFERENCES game_codes(id),
  INDEX idx_status (status),
  INDEX idx_score (score DESC),
  INDEX idx_finished (finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: attempt_questions
-- Links attempts to specific questions (for randomization)
CREATE TABLE IF NOT EXISTS attempt_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  question_order INT NOT NULL COMMENT '1, 2, 3...',
  option_order VARCHAR(20) NULL COMMENT 'JSON array of source option keys in displayed order',
  FOREIGN KEY (attempt_id) REFERENCES game_attempts(id),
  FOREIGN KEY (question_id) REFERENCES questions(id),
  UNIQUE KEY unique_attempt_order (attempt_id, question_order),
  INDEX idx_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: attempt_answers
-- Stores player's answers with response times
CREATE TABLE IF NOT EXISTS attempt_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  selected_answer ENUM('A', 'B', 'C', 'D') NULL,
  is_correct BOOLEAN NOT NULL,
  response_time INT NOT NULL COMMENT 'seconds taken to answer',
  answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (attempt_id) REFERENCES game_attempts(id),
  FOREIGN KEY (question_id) REFERENCES questions(id),
  INDEX idx_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample questions
INSERT INTO questions (question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty, time_limit) VALUES
('เธชเธซเธเธฃเธ“เนเธเธทเธญเธญเธฐเนเธฃ?', 'เธเธฅเธธเนเธกเธเธฒเธฃเธเนเธฒ', 'เธญเธเธเนเธเธฃเธ—เธฒเธเน€เธจเธฃเธฉเธเธเธดเธ', 'เธเธฃเธดเธฉเธฑเธ—เน€เธญเธเธเธ', 'เธซเธเนเธงเธขเธเธฒเธเธฃเธฒเธเธเธฒเธฃ', 'B', 'easy', 15),
('เธชเธซเธเธฃเธ“เนเธกเธตเธเธตเนเธเธฃเธฐเน€เธ เธ—เธซเธฅเธฑเธ?', '2 เธเธฃเธฐเน€เธ เธ—', '3 เธเธฃเธฐเน€เธ เธ—', '4 เธเธฃเธฐเน€เธ เธ—', '5 เธเธฃเธฐเน€เธ เธ—', 'C', 'easy', 15),
('เธซเธฅเธฑเธเธเธฒเธฃเธชเธซเธเธฃเธ“เนเธกเธตเธเธตเนเธเนเธญ?', '5 เธเนเธญ', '6 เธเนเธญ', '7 เธเนเธญ', '8 เธเนเธญ', 'C', 'easy', 15),
('naimetเธชเธฑเธเธเธฑเธ”เธเธฃเธฐเธ—เธฃเธงเธเนเธ”?', 'เธเธฃเธฐเธ—เธฃเธงเธเน€เธเธฉเธ•เธฃเนเธฅเธฐเธชเธซเธเธฃเธ“เน', 'เธเธฃเธฐเธ—เธฃเธงเธเธกเธซเธฒเธ”เนเธ—เธข', 'เธเธฃเธฐเธ—เธฃเธงเธเธเธฒเธ“เธดเธเธขเน', 'เธเธฃเธฐเธ—เธฃเธงเธเธเธฒเธฃเธเธฅเธฑเธ', 'A', 'medium', 20),
('เธชเธซเธเธฃเธ“เนเน€เธเธฃเธ”เธดเธ•เธขเธนเน€เธเธตเธขเธเธกเธตเธซเธเนเธฒเธ—เธตเนเธซเธฅเธฑเธเธญเธฐเนเธฃ?', 'เธเธฅเธดเธ•เธชเธดเธเธเนเธฒ', 'เนเธซเนเธเธนเนเธขเธทเธกเน€เธเธดเธ', 'เธเธณเธซเธเนเธฒเธขเธชเธดเธเธเนเธฒ', 'เนเธซเนเธเธฃเธดเธเธฒเธฃเธ—เนเธญเธเน€เธ—เธตเนเธขเธง', 'B', 'medium', 20),
('เธเธฅเธธเนเธกเน€เธเธฉเธ•เธฃเธเธฃเธ•เนเธญเธเธเธฒเธฃเธเธ”เธ—เธฐเน€เธเธตเธขเธเน€เธเนเธเธเธดเธ•เธดเธเธธเธเธเธฅเธเธงเธฃเธ—เธณเธญเธขเนเธฒเธเนเธฃ?', 'เธเธ”เธ—เธฐเน€เธเธตเธขเธเธเธฃเธดเธฉเธฑเธ—', 'เธเธ”เธ—เธฐเน€เธเธตเธขเธเธงเธดเธชเธฒเธซเธเธดเธเธเธธเธกเธเธ', 'เธเธ”เธ—เธฐเน€เธเธตเธขเธเธชเธซเธเธฃเธ“เน', 'เนเธกเนเธ•เนเธญเธเธเธ”เธ—เธฐเน€เธเธตเธขเธ', 'C', 'medium', 20),
('เธเธฃเธฐเธฃเธฒเธเธเธฑเธเธเธฑเธ•เธดเธชเธซเธเธฃเธ“เน เธ.เธจ. 2542 เธกเธตเธเธนเนเธฃเธฑเธเธเธฑเธ”เธ•เธฑเนเธเธชเธซเธเธฃเธ“เนเธเธฑเนเธเธ•เนเธณเธเธตเนเธเธ?', '5 เธเธ', '10 เธเธ', '15 เธเธ', '20 เธเธ', 'C', 'hard', 25),
('เธญเธฑเธ•เธฃเธฒเธชเนเธงเธเธเธฒเธฃเธญเธญเธเน€เธชเธตเธขเธเนเธเธ—เธตเนเธเธฃเธฐเธเธธเธกเธชเธซเธเธฃเธ“เนเธเธทเธญเธญเธขเนเธฒเธเนเธฃ?', '1 เธเธ 1 เน€เธชเธตเธขเธ', 'เธ•เธฒเธกเธเธณเธเธงเธเธซเธธเนเธ', 'เธ•เธฒเธกเธญเธฒเธขเธธเธชเธกเธฒเธเธดเธ', 'เธเธ“เธฐเธเธฃเธฃเธกเธเธฒเธฃเธ•เธฑเธ”เธชเธดเธ', 'A', 'hard', 25),
('เธ—เธธเธเธเธ”เธ—เธฐเน€เธเธตเธขเธเธเธฑเนเธเธ•เนเธณเธเธญเธเธชเธซเธเธฃเธ“เนเธเธฃเธฐเน€เธ เธ—เน€เธเธฃเธ”เธดเธ•เธเธทเธญเน€เธ—เนเธฒเนเธ”?', '100,000 เธเธฒเธ—', '500,000 เธเธฒเธ—', '1,000,000 เธเธฒเธ—', 'เนเธกเนเธเธณเธซเธเธ”', 'B', 'hard', 25));

-- Default game settings
INSERT INTO game_settings (setting_key, setting_value) VALUES
('totalQuestions', '9')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP;

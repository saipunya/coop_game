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
  time_limit INT NOT NULL COMMENT 'seconds: 10, 15, or 20',
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_difficulty (difficulty),
  INDEX idx_active (is_active)
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
  selected_answer ENUM('A', 'B', 'C', 'D') NOT NULL,
  is_correct BOOLEAN NOT NULL,
  response_time INT NOT NULL COMMENT 'seconds taken to answer',
  answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (attempt_id) REFERENCES game_attempts(id),
  FOREIGN KEY (question_id) REFERENCES questions(id),
  INDEX idx_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample questions
INSERT INTO questions (question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty, time_limit) VALUES
('สหกรณ์คืออะไร?', 'กลุ่มการค้า', 'องค์กรทางเศรษฐกิจ', 'บริษัทเอกชน', 'หน่วยงานราชการ', 'B', 'easy', 10),
('สหกรณ์มีกี่ประเภทหลัก?', '2 ประเภท', '3 ประเภท', '4 ประเภท', '5 ประเภท', 'C', 'easy', 10),
('หลักการสหกรณ์มีกี่ข้อ?', '5 ข้อ', '6 ข้อ', '7 ข้อ', '8 ข้อ', 'C', 'easy', 10),
('กรมส่งเสริมสหกรณ์สังกัดกระทรวงใด?', 'กระทรวงเกษตรและสหกรณ์', 'กระทรวงมหาดไทย', 'กระทรวงพาณิชย์', 'กระทรวงการคลัง', 'A', 'medium', 15),
('สหกรณ์เครดิตยูเนียนมีหน้าที่หลักอะไร?', 'ผลิตสินค้า', 'ให้กู้ยืมเงิน', 'จำหน่ายสินค้า', 'ให้บริการท่องเที่ยว', 'B', 'medium', 15),
('กลุ่มเกษตรกรต้องการจดทะเบียนเป็นนิติบุคคลควรทำอย่างไร?', 'จดทะเบียนบริษัท', 'จดทะเบียนวิสาหกิจชุมชน', 'จดทะเบียนสหกรณ์', 'ไม่ต้องจดทะเบียน', 'C', 'medium', 15),
('พระราชบัญญัติสหกรณ์ พ.ศ. 2542 มีผู้รับจัดตั้งสหกรณ์ขั้นต่ำกี่คน?', '5 คน', '10 คน', '15 คน', '20 คน', 'C', 'hard', 20),
('อัตราส่วนการออกเสียงในที่ประชุมสหกรณ์คืออย่างไร?', '1 คน 1 เสียง', 'ตามจำนวนหุ้น', 'ตามอายุสมาชิก', 'คณะกรรมการตัดสิน', 'A', 'hard', 20),
('ทุนจดทะเบียนขั้นต่ำของสหกรณ์ประเภทเครดิตคือเท่าใด?', '100,000 บาท', '500,000 บาท', '1,000,000 บาท', 'ไม่กำหนด', 'B', 'hard', 20);

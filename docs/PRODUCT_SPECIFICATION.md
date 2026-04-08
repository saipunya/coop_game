# เกมตอบคำถามสหกรณ์ - Product Specification Document

**Version:** 1.0  
**Date:** April 6, 2026  
**Status:** Draft  
**Document Owner:** Product Team

---

## Table of Contents

1. [Product Overview](#1-product-overview)
2. [User Flow](#2-user-flow)
3. [Admin Flow](#3-admin-flow)
4. [ER Diagram Explanation](#4-er-diagram-explanation)
5. [SQL Schema](#5-sql-schema)
6. [API Specification](#6-api-specification)
7. [Folder Structure](#7-folder-structure)
8. [Starter Code](#8-starter-code)
9. [Security Considerations](#9-security-considerations)
10. [Anti-Cheat Considerations](#10-anti-cheat-considerations)
11. [Real-time Leaderboard Design](#11-real-time-leaderboard-design)
12. [TV Display Mode Design](#12-tv-display-mode-design)
13. [MVP Plan](#13-mvp-plan)
14. [Production Readiness Checklist](#14-production-readiness-checklist)

---

## 1. Product Overview

### 1.1 Product Vision

ระบบเกมตอบคำถามแบบ interactive สำหรับใช้ในงานอีเวนต์ของnaimet เพื่อสร้าง engagement และเผยแพร่ความรู้เกี่ยวกับสหกรณ์ กลุ่มเกษตรกร และnaimetแก่ประชาชน

### 1.2 Target Users

**Primary Users:**
- ผู้เข้าร่วมงานอีเวนต์ (ทั่วไป) - ใช้มือถือเล่นเกม
- ผู้จัดงาน - ดู leaderboard บนหน้าจอใหญ่

**Secondary Users:**
- Admin - จัดการคำถามและรหัสเกม
- IT Support - ดูแลระบบ

### 1.3 Key Features

| Feature | Priority | Description |
|---------|----------|-------------|
| QR Code Entry | P0 | สแกน QR เข้าเกม |
| Game Code System | P0 | รหัสสุ่ม 6 ตัว อายุ 24 ชม. 1 รหัส/คน |
| Question Bank | P0 | คำถาม 4 ตัวเลือก แบ่ง easy/medium/hard |
| Time-based Gameplay | P0 | เวลา 10s/15s/20s ตามความยาก |
| Instant Feedback | P0 | ตอบผิด/หมดเวลา = เกมจบทันที |
| Leaderboard | P0 | แสดงอันดับแบบเรียลไทม์ |
| TV Mode | P1 | หน้าจอใหญ่สำหรับบูธ |
| Admin Panel | P1 | จัดการคำถามและรหัส |
| Analytics | P2 | สถิติและรายงาน |

### 1.4 Business Rules

1. **Game Code Rules:**
   - รหัส 6 ตัวอักษร (A-Z, 0-9)
   - อายุ 24 ชั่วโมง
   - 1 รหัสใช้ได้ 1 ครั้งเท่านั้น
   - เมื่อเริ่มใช้งาน → lock รหัส (in_progress)
   - เมื่อเล่นจบ → mark as used
   - ไม่ลบ record ใช้ status field แทน

2. **Gameplay Rules:**
   - ทีละข้อ ตอบผิด = เกมจบ
   - หมดเวลาโดยไม่ตอบ = ถือว่าผิด
   - เวลาตอบ: easy 10s, medium 15s, hard 20s
   - สุ่มคำถามจากคลังแต่ละคนไม่เหมือนกัน
   - ลำดับ: ง่าย → ปานกลาง → ยาก

3. **Scoring Rules:**
   - คะแนน = จำนวนข้อที่ตอบถูก
   - ตัดสินเมื่อคะแนนเท่ากัน:
     1. เวลารวมน้อยกว่า ชนะ
     2. ถ้ายังเท่ากัน → ส่งก่อน ชนะ

4. **Privacy Rules:**
   - ห้ามแสดงเบอร์โทรบน leaderboard สาธารณะ
   - แสดงเฉพาะชื่อ (หรือชื่อย่อ)
   - เบอร์โทรใช้เฉพาะการติดต่อรางวัล

### 1.5 Non-Functional Requirements

| Requirement | Target |
|-------------|--------|
| Response Time | < 500ms (API) |
| Concurrent Users | 100+ players simultaneously |
| Availability | 99.5% during event |
| Mobile Support | iOS 12+, Android 8+ |
| Browser Support | Chrome, Safari, Firefox (latest 2 versions) |
| Screen Size | 320px - 4K TV |

---

## 2. User Flow

### 2.1 Player Journey

```
┌─────────────────┐
│  สแกน QR Code   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  เข้าหน้าเริ่ม  │
│  /game/start    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  กรอกรหัสเกม   │
│  (6 ตัวอักษร)   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  ตรวจสอบรหัส    │
│  (server side)  │
└────────┬────────┘
         │
    ┌────┴────┐
    │ Valid?  │
    └────┬────┘
         │
    ┌────┴────┐
    │   No    │──► แสดง error
    └────┬────┘
         │
    ┌────┴────┐
    │  Yes    │
    └────┬────┘
         │
         ▼
┌─────────────────┐
│  เริ่มเกม       │
│  lock รหัส       │
│  สร้าง attempt  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  แสดงคำถามที่ 1 │
│  เริ่มจับเวลา   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  ผู้เล่นตอบ     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  ตรวจคำตอบ     │
│  + check timeout│
└────────┬────────┘
         │
    ┌────┴────┐
    │ Correct?│
    └────┬────┘
         │
    ┌────┴────┐
    │   No    │──► Game Over
    └────┬────┘     │
         │          ▼
    ┌────┴────┐ ┌─────────────┐
    │  Yes    │ │ หน้าสรุปผล  │
    └────┬────┘ │ /game/finish│
         │     └──────┬──────┘
         ▼            │
┌─────────────────┐   │
│  ข้อถัดไป?     │   │
└────────┬────────┘   │
         │            │
    ┌────┴────┐       │
    │  Yes    │───────┘
    └────┬────┘
         │ No
         ▼
┌─────────────────┐
│  หน้าสรุปผล    │
│  กรอกชื่อ+เบอร์│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  บันทึกข้อมูล   │
│  คำนวณอันดับ   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  แสดงอันดับ    │
│  + ดู leaderboard│
└─────────────────┘
```

### 2.2 Leaderboard Flow

```
┌─────────────────┐
│  เปิดหน้า LB   │
│  /game/leaderboard│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Load ครั้งแรก  │
│  GET /api/leaderboard│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  แสดงอันดับ    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Auto Refresh  │
│  ทุก 10s (TV)   │
│  ทุก 30s (Mobile)│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Poll API       │
│  Update DOM     │
└────────┴────────┘
```

### 2.3 TV Mode Flow

```
┌─────────────────┐
│  เปิดเบราว์เซอร์│
│  บนจอทีวี      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  เข้า TV Mode   │
│  ?mode=tv       │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Full Screen    │
│  Zoom 100%      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Auto Refresh   │
│  ทุก 10 วินาที  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  แสดงอันดับ    │
│  ตัวอักษรใหญ่   │
│  ไม่มีปุ่ม admin│
└─────────────────┘
```

---

## 3. Admin Flow

### 3.1 Admin Features

**Phase 1 (Basic):**
- สร้างรหัสเกม batch (50/100 รหัส)
- ดูรายการรหัสทั้งหมด
- ดูสถิติพื้นฐาน (จำนวนผู้เล่น, ค่าเฉลี่ยคะแนน)

**Phase 2 (Advanced):**
- CRUD คำถาม
- จัดการความยากคำถาม
- Export ผลลัพธ์เป็น Excel/CSV
- Dashboard แบบ real-time

### 3.2 Admin Flow Diagram

```
┌─────────────────┐
│  Admin Login    │
│  (Basic Auth)   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Dashboard      │
└────────┬────────┘
         │
    ┌────┴────┬──────────┬──────────┐
    │         │          │          │
    ▼         ▼          ▼          ▼
┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐
|Codes │ |Q&A   │ |Stats │ |Export│
└───┬──┘ └───┬──┘ └───┬──┘ └───┬──┘
    │        │        │        │
    ▼        ▼        ▼        ▼
┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐
|Generate| |Add/  │ |View  │ |Download│
|Codes  │ |Edit  │ |Charts│ |CSV   │
└──────┘ └──────┘ └──────┘ └──────┘
```

### 3.3 Code Generation Flow

```
┌─────────────────┐
│  Admin เลือก    │
│  Generate Codes │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  กรอกจำนวน    │
│  (50/100/...)   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Server Generate│
│  Random Codes   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Insert to DB   │
│  status=unused  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Return List    │
│  พร้อม Print   │
└─────────────────┘
```

---

## 4. ER Diagram Explanation

### 4.1 Entity Relationships

```
┌──────────────────┐
│   game_codes     │
├──────────────────┤
│ id (PK)          │
│ code (UNIQUE)    │
│ status           │
│ created_at       │
│ expires_at       │
│ used_at          │
└────────┬─────────┘
         │ 1
         │
         │ N
┌────────▼─────────┐
│  game_attempts   │
├──────────────────┤
│ id (PK)          │
│ game_code_id (FK)│
│ player_name      │
│ phone_number     │
│ score            │
│ total_time       │
│ status           │
│ started_at       │
│ finished_at      │
└────────┬─────────┘
         │ 1
         │
         │ N
┌────────▼─────────┐
│ attempt_questions│
├──────────────────┤
│ id (PK)          │
│ attempt_id (FK)  │
│ question_id (FK) │
│ question_order   │
└────────┬─────────┘
         │
         │ N
┌────────▼─────────┐
│   questions      │
├──────────────────┤
│ id (PK)          │
│ question_text    │
│ option_a         │
│ option_b         │
│ option_c         │
│ option_d         │
│ correct_answer   │
│ difficulty       │
│ time_limit       │
│ is_active        │
└────────┬─────────┘
         │ 1
         │
         │ N
┌────────▼─────────┐
│ attempt_answers  │
├──────────────────┤
│ id (PK)          │
│ attempt_id (FK)  │
│ question_id (FK) │
│ selected_answer  │
│ is_correct       │
│ response_time    │
│ answered_at      │
└──────────────────┘
```

### 4.2 Relationship Descriptions

**game_codes (1) → (N) game_attempts**
- 1 รหัสเกม ใช้ได้ 1 attempt เท่านั้น
- Foreign key: `game_code_id` in `game_attempts`
- Constraint: หลังจากใช้แล้ว รหัสจะถูก lock

**game_attempts (1) → (N) attempt_questions**
- 1 attempt มีหลายคำถาม
- Foreign key: `attempt_id` in `attempt_questions`
- `question_order` ระบุลำดับการเล่น

**questions (1) → (N) attempt_questions**
- 1 คำถามสามารถใช้ในหลาย attempt
- Foreign key: `question_id` in `attempt_questions`
- อนุญาตให้คำถามซ้ำกันได้ (แต่ละผู้เล่นอาจได้คำถามเดิม)

**game_attempts (1) → (N) attempt_answers**
- 1 attempt มีคำตอบหลายข้อ
- Foreign key: `attempt_id` in `attempt_answers`
- เก็บประวัติการตอบทุกข้อ

**questions (1) → (N) attempt_answers**
- 1 คำถามมีคำตอบจากหลาย attempt
- Foreign key: `question_id` in `attempt_answers`

### 4.3 Key Design Decisions

**Why separate attempt_questions and attempt_answers?**
- `attempt_questions`: บันทึกว่า attempt นี้ได้คำถามอะไรบ้าง (สำหรับ randomization)
- `attempt_answers`: บันทึกคำตอบที่ผู้เล่นเลือก + เวลาที่ใช้
- แยกกันเพื่อความยืดหยุ่นในการวิเคราะห์

**Why not delete used codes?**
- เก็บประวัติเพื่อ audit trail
- สามารถวิเคราะห์การใช้งาน
- ใช้ status field แทน (unused → in_progress → used → expired)

**Why store response_time?**
- สำหรับคำนวณ ranking เมื่อคะแนนเท่ากัน
- วิเคราะห์พฤติกรรมผู้เล่น
- ตรวจสอบความถูกต้อง (anti-cheat)

---

## 5. SQL Schema

### 5.1 Complete Schema

```sql
-- Database Creation
CREATE DATABASE IF NOT EXISTS coop_game
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE coop_game;

-- Table: game_codes
CREATE TABLE game_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) UNIQUE NOT NULL,
  status ENUM('unused', 'in_progress', 'used', 'expired') DEFAULT 'unused',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL,
  INDEX idx_code (code),
  INDEX idx_status (status),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Game codes with 24-hour expiry';

-- Table: questions
CREATE TABLE questions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Question bank with difficulty levels';

-- Table: game_attempts
CREATE TABLE game_attempts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Player game sessions';

-- Table: attempt_questions
CREATE TABLE attempt_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  question_order INT NOT NULL COMMENT '1, 2, 3...',
  FOREIGN KEY (attempt_id) REFERENCES game_attempts(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id),
  UNIQUE KEY unique_attempt_order (attempt_id, question_order),
  INDEX idx_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Questions assigned to each attempt';

-- Table: attempt_answers
CREATE TABLE attempt_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  selected_answer ENUM('A', 'B', 'C', 'D') NOT NULL,
  is_correct BOOLEAN NOT NULL,
  response_time INT NOT NULL COMMENT 'seconds taken to answer',
  answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (attempt_id) REFERENCES game_attempts(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id),
  INDEX idx_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Player answers with response times';
```

### 5.2 Index Strategy

| Table | Index | Purpose |
|-------|-------|---------|
| game_codes | idx_code | Fast lookup by code |
| game_codes | idx_status | Filter by status |
| game_codes | idx_expires | Cleanup expired codes |
| questions | idx_difficulty | Random selection by difficulty |
| questions | idx_active | Filter active questions |
| game_attempts | idx_status | Filter completed attempts |
| game_attempts | idx_score | Leaderboard sorting |
| game_attempts | idx_finished | Time-based ranking |
| attempt_questions | idx_attempt | Get questions for attempt |
| attempt_answers | idx_attempt | Get answers for attempt |

### 5.3 Sample Data

```sql
-- Sample questions
INSERT INTO questions (question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty, time_limit) VALUES
('สหกรณ์คืออะไร?', 'กลุ่มการค้า', 'องค์กรทางเศรษฐกิจ', 'บริษัทเอกชน', 'หน่วยงานราชการ', 'B', 'easy', 10),
('สหกรณ์มีกี่ประเภทหลัก?', '2 ประเภท', '3 ประเภท', '4 ประเภท', '5 ประเภท', 'C', 'easy', 10),
('หลักการสหกรณ์มีกี่ข้อ?', '5 ข้อ', '6 ข้อ', '7 ข้อ', '8 ข้อ', 'C', 'easy', 10),
('naimetสังกัดกระทรวงใด?', 'กระทรวงเกษตรและสหกรณ์', 'กระทรวงมหาดไทย', 'กระทรวงพาณิชย์', 'กระทรวงการคลัง', 'A', 'medium', 15),
('สหกรณ์เครดิตยูเนียนมีหน้าที่หลักอะไร?', 'ผลิตสินค้า', 'ให้กู้ยืมเงิน', 'จำหน่ายสินค้า', 'ให้บริการท่องเที่ยว', 'B', 'medium', 15),
('กลุ่มเกษตรกรต้องการจดทะเบียนเป็นนิติบุคคลควรทำอย่างไร?', 'จดทะเบียนบริษัท', 'จดทะเบียนวิสาหกิจชุมชน', 'จดทะเบียนสหกรณ์', 'ไม่ต้องจดทะเบียน', 'C', 'medium', 15),
('พระราชบัญญัติสหกรณ์ พ.ศ. 2542 มีผู้รับจัดตั้งสหกรณ์ขั้นต่ำกี่คน?', '5 คน', '10 คน', '15 คน', '20 คน', 'C', 'hard', 20),
('อัตราส่วนการออกเสียงในที่ประชุมสหกรณ์คืออย่างไร?', '1 คน 1 เสียง', 'ตามจำนวนหุ้น', 'ตามอายุสมาชิก', 'คณะกรรมการตัดสิน', 'A', 'hard', 20),
('ทุนจดทะเบียนขั้นต่ำของสหกรณ์ประเภทเครดิตคือเท่าใด?', '100,000 บาท', '500,000 บาท', '1,000,000 บาท', 'ไม่กำหนด', 'B', 'hard', 20);
```

---

## 6. API Specification

### 6.1 Public APIs

#### POST /game/verify-code
ตรวจสอบรหัสเกมและเริ่มเกม

**Request:**
```json
{
  "code": "ABC123"
}
```

**Response (Success):**
```json
{
  "success": true,
  "attemptId": 123,
  "totalQuestions": 9
}
```

**Response (Error):**
```json
{
  "success": false,
  "message": "รหัสไม่ถูกต้อง หรือถูกใช้ไปแล้ว"
}
```

**Status Codes:**
- 200: Success
- 400: Invalid input
- 404: Code not found
- 409: Code already used

---

#### GET /game/api/question
ดึงคำถามปัจจุบัน

**Query Parameters:**
- `attemptId` (required): Attempt ID

**Response:**
```json
{
  "success": true,
  "question": {
    "id": 5,
    "text": "สหกรณ์คืออะไร?",
    "options": {
      "A": "กลุ่มการค้า",
      "B": "องค์กรทางเศรษฐกิจ",
      "C": "บริษัทเอกชน",
      "D": "หน่วยงานราชการ"
    },
    "timeLimit": 10,
    "questionNumber": 1,
    "totalQuestions": 9
  }
}
```

**Status Codes:**
- 200: Success
- 400: Invalid attemptId
- 404: Attempt not found or completed

---

#### POST /game/api/answer
ส่งคำตอบ

**Request:**
```json
{
  "attemptId": 123,
  "questionId": 5,
  "answer": "B",
  "responseTime": 8
}
```

**Response (Correct - Next Question):**
```json
{
  "success": true,
  "isCorrect": true,
  "gameOver": false,
  "nextQuestionNumber": 2
}
```

**Response (Wrong - Game Over):**
```json
{
  "success": true,
  "isCorrect": false,
  "gameOver": true,
  "reason": "wrong_answer",
  "finalScore": 3
}
```

**Response (Timeout - Game Over):**
```json
{
  "success": true,
  "isCorrect": false,
  "gameOver": true,
  "reason": "timeout",
  "finalScore": 3
}
```

**Response (Completed All):**
```json
{
  "success": true,
  "isCorrect": true,
  "gameOver": true,
  "finalScore": 9,
  "totalTime": 95
}
```

**Status Codes:**
- 200: Success
- 400: Invalid input
- 404: Attempt not found
- 409: Invalid question for this attempt

---

#### POST /game/api/finish
บันทึกข้อมูลผู้เล่น

**Request:**
```json
{
  "attemptId": 123,
  "playerName": "สมชาย สุขใจ",
  "phoneNumber": "0812345678"
}
```

**Response:**
```json
{
  "success": true,
  "score": 8,
  "totalTime": 95,
  "rank": 5
}
```

**Status Codes:**
- 200: Success
- 400: Invalid input
- 404: Attempt not found

---

#### GET /game/api/leaderboard
ดึง leaderboard

**Query Parameters:**
- `limit` (optional): Number of entries (default: 50)
- `offset` (optional): Pagination offset (default: 0)

**Response:**
```json
{
  "success": true,
  "leaderboard": [
    {
      "rank": 1,
      "playerName": "สมชาย ส.",
      "score": 9,
      "totalTime": 85
    },
    {
      "rank": 2,
      "playerName": "วิภา ส.",
      "score": 8,
      "totalTime": 72
    }
  ],
  "total": 25,
  "lastUpdated": "2026-04-06T12:00:00Z"
}
```

**Status Codes:**
- 200: Success

---

### 6.2 Admin APIs

#### POST /game/admin/codes/generate
สร้างรหัสเกม batch

**Authentication:** Basic Auth or API Key

**Request:**
```json
{
  "count": 50,
  "expiryHours": 24
}
```

**Response:**
```json
{
  "success": true,
  "codes": [
    "ABC123",
    "DEF456",
    "GHI789"
  ],
  "count": 50,
  "expiresAt": "2026-04-07T12:00:00Z"
}
```

---

#### GET /game/admin/codes
ดูรายการรหัสทั้งหมด

**Query Parameters:**
- `status` (optional): Filter by status
- `limit` (optional): Pagination
- `offset` (optional): Pagination

**Response:**
```json
{
  "success": true,
  "codes": [
    {
      "id": 1,
      "code": "ABC123",
      "status": "used",
      "createdAt": "2026-04-06T10:00:00Z",
      "expiresAt": "2026-04-07T10:00:00Z",
      "usedAt": "2026-04-06T11:30:00Z"
    }
  ],
  "total": 50
}
```

---

#### POST /game/admin/questions
เพิ่มคำถาม

**Request:**
```json
{
  "questionText": "คำถามใหม่?",
  "optionA": "ตัวเลือก A",
  "optionB": "ตัวเลือก B",
  "optionC": "ตัวเลือก C",
  "optionD": "ตัวเลือก D",
  "correctAnswer": "B",
  "difficulty": "medium",
  "timeLimit": 15
}
```

**Response:**
```json
{
  "success": true,
  "questionId": 10
}
```

---

#### GET /game/admin/stats
ดูสถิติ

**Response:**
```json
{
  "success": true,
  "stats": {
    "totalPlayers": 150,
    "completedGames": 120,
    "averageScore": 6.5,
    "averageTime": 85,
    "codesUsed": 120,
    "codesRemaining": 30
  }
}
```

---

### 6.3 Error Response Format

All error responses follow this format:

```json
{
  "success": false,
  "message": "Error description",
  "errorCode": "ERROR_CODE",
  "details": {}
}
```

**Common Error Codes:**
- `INVALID_INPUT`: Invalid request parameters
- `CODE_NOT_FOUND`: Game code not found
- `CODE_ALREADY_USED`: Code already used
- `CODE_EXPIRED`: Code has expired
- `ATTEMPT_NOT_FOUND`: Attempt not found
- `ATTEMPT_COMPLETED`: Attempt already completed
- `INVALID_QUESTION`: Question not part of this attempt
- `TIMEOUT_EXCEEDED`: Response time exceeded limit

---

## 7. Folder Structure

```
coop_game/
├── app.js                          # Main Express application
├── package.json                    # Dependencies
├── .env                            # Environment variables
├── .env.example                    # Environment template
├── .gitignore                      # Git ignore rules
├── README.md                       # Project documentation
│
├── config/                         # Configuration files
│   ├── database.js                 # Database connection pool
│   ├── auth.js                     # Authentication config
│   └── constants.js                # Application constants
│
├── routes/                         # Route definitions
│   ├── game.routes.js              # Game public routes
│   ├── admin.routes.js             # Admin routes
│   └── api.routes.js               # API routes (if separated)
│
├── controllers/                    # Request handlers
│   ├── game.controller.js          # Game logic controllers
│   ├── admin.controller.js         # Admin logic controllers
│   └── leaderboard.controller.js   # Leaderboard controllers
│
├── services/                       # Business logic
│   ├── game.service.js             # Game core logic
│   ├── code.service.js             # Code management
│   ├── question.service.js         # Question selection
│   ├── leaderboard.service.js      # Leaderboard calculation
│   └── validation.service.js       # Input validation
│
├── models/                         # Database models
│   ├── gameCode.model.js           # Game code queries
│   ├── question.model.js           # Question queries
│   ├── attempt.model.js            # Attempt queries
│   ├── attemptQuestion.model.js    # Attempt-question mapping
│   └── attemptAnswer.model.js      # Answer queries
│
├── middleware/                     # Custom middleware
│   ├── auth.middleware.js          # Authentication
│   ├── rateLimit.middleware.js     # Rate limiting
│   ├── errorHandler.js             # Error handling
│   └── validation.middleware.js    # Request validation
│
├── utils/                          # Utility functions
│   ├── logger.js                   # Logging utility
│   ├── response.js                 # Response formatter
│   ├── crypto.js                   # Cryptography helpers
│   └── date.js                     # Date/time helpers
│
├── views/                          # EJS templates
│   ├── layouts/
│   │   ├── main.ejs                # Main layout
│   │   └── admin.ejs               # Admin layout
│   │
│   ├── game/
│   │   ├── start.ejs               # Start page
│   │   ├── play.ejs                # Gameplay page
│   │   ├── finish.ejs              # Finish page
│   │   └── leaderboard.ejs         # Leaderboard
│   │
│   └── admin/
│       ├── dashboard.ejs           # Admin dashboard
│       ├── codes.ejs               # Code management
│       ├── questions.ejs           # Question management
│       └── stats.ejs               # Statistics
│
├── public/                         # Static files
│   ├── css/
│   │   ├── styles.css              # Main styles
│   │   ├── tv-mode.css             # TV mode styles
│   │   └── admin.css               # Admin styles
│   │
│   ├── js/
│   │   ├── game.js                 # Game frontend logic
│   │   ├── leaderboard.js          # Leaderboard logic
│   │   └── admin.js                # Admin logic
│   │
│   ├── images/                     # Images and assets
│   └── fonts/                      # Custom fonts
│
├── sql/                            # Database scripts
│   ├── schema.sql                  # Database schema
│   ├── seed.sql                    # Sample data
│   └── migrations/                 # Migration files
│       ├── 001_initial_schema.sql
│       ├── 002_add_indexes.sql
│       └── 003_seed_data.sql
│
├── tests/                          # Test files
│   ├── unit/
│   │   ├── services/
│   │   └── models/
│   ├── integration/
│   │   └── api/
│   └── e2e/
│       └── gameplay.spec.js
│
├── scripts/                        # Utility scripts
│   ├── generate-codes.js           # Code generation script
│   ├── export-results.js           # Export script
│   └── cleanup-expired.js          # Cleanup script
│
└── docs/                           # Documentation
    ├── API.md                      # API documentation
    ├── DEPLOYMENT.md               # Deployment guide
    └── TROUBLESHOOTING.md          # Troubleshooting guide
```

---

## 8. Starter Code

### 8.1 Main Application (app.js)

```javascript
const express = require('express');
const path = require('path');
require('dotenv').config();

const app = express();

// Middleware
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(express.static(path.join(__dirname, 'public')));

// View Engine
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Routes
const gameRoutes = require('./routes/game.routes');
const adminRoutes = require('./routes/admin.routes');

app.use('/game', gameRoutes);
app.use('/admin', adminRoutes);

// Home route
app.get('/', (req, res) => {
  res.redirect('/game/start');
});

// Error handling
app.use((req, res) => {
  res.status(404).json({ success: false, message: 'Not Found' });
});

app.use((err, req, res, next) => {
  console.error(err.stack);
  res.status(500).json({ success: false, message: 'Internal Server Error' });
});

// Server
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Server running at http://localhost:${PORT}`);
});

module.exports = app;
```

### 8.2 Database Configuration (config/database.js)

```javascript
const mysql = require('mysql2/promise');

const pool = mysql.createPool({
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'coop_game',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

module.exports = pool;
```

### 8.3 Game Service (services/game.service.js)

```javascript
const pool = require('../config/database');

class GameService {
  async verifyCode(code) {
    const connection = await pool.getConnection();
    try {
      await connection.beginTransaction();

      // Check code
      const [codes] = await connection.query(
        'SELECT * FROM game_codes WHERE code = ? FOR UPDATE',
        [code]
      );

      if (codes.length === 0) {
        await connection.rollback();
        return { success: false, message: 'รหัสไม่ถูกต้อง' };
      }

      const gameCode = codes[0];

      if (gameCode.status !== 'unused') {
        await connection.rollback();
        return { success: false, message: 'รหัสถูกใช้ไปแล้ว' };
      }

      if (new Date() > gameCode.expires_at) {
        await connection.query(
          'UPDATE game_codes SET status = ? WHERE id = ?',
          ['expired', gameCode.id]
        );
        await connection.rollback();
        return { success: false, message: 'รหัสหมดอายุ' };
      }

      // Lock code
      await connection.query(
        'UPDATE game_codes SET status = ?, used_at = NOW() WHERE id = ?',
        ['in_progress', gameCode.id]
      );

      // Create attempt
      const [result] = await connection.query(
        'INSERT INTO game_attempts (game_code_id, status) VALUES (?, ?)',
        [gameCode.id, 'in_progress']
      );

      const attemptId = result.insertId;

      // Select random questions
      const questions = await this.selectRandomQuestions(connection);
      
      // Assign questions to attempt
      for (let i = 0; i < questions.length; i++) {
        await connection.query(
          'INSERT INTO attempt_questions (attempt_id, question_id, question_order) VALUES (?, ?, ?)',
          [attemptId, questions[i].id, i + 1]
        );
      }

      await connection.commit();

      return {
        success: true,
        attemptId,
        totalQuestions: questions.length
      };
    } catch (error) {
      await connection.rollback();
      throw error;
    } finally {
      connection.release();
    }
  }

  async selectRandomQuestions(connection) {
    // Get 3 easy, 3 medium, 3 hard
    const [easy] = await connection.query(
      'SELECT id FROM questions WHERE difficulty = ? AND is_active = ? ORDER BY RAND() LIMIT 3',
      ['easy', true]
    );
    const [medium] = await connection.query(
      'SELECT id FROM questions WHERE difficulty = ? AND is_active = ? ORDER BY RAND() LIMIT 3',
      ['medium', true]
    );
    const [hard] = await connection.query(
      'SELECT id FROM questions WHERE difficulty = ? AND is_active = ? ORDER BY RAND() LIMIT 3',
      ['hard', true]
    );

    const questionIds = [...easy, ...medium, ...hard];
    
    const [questions] = await connection.query(
      `SELECT * FROM questions WHERE id IN (${questionIds.map(() => '?').join(',')})`,
      questionIds.map(q => q.id)
    );

    return questions;
  }

  // ... more methods
}

module.exports = new GameService();
```

### 8.4 Environment Template (.env.example)

```env
# Server
PORT=3000
NODE_ENV=development

# Database
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=your_password
DB_NAME=coop_game

# Admin
ADMIN_USERNAME=admin
ADMIN_PASSWORD=secure_password

# Security
SESSION_SECRET=your_secret_key
RATE_LIMIT_WINDOW=15
RATE_LIMIT_MAX=100
```

---

## 9. Security Considerations

### 9.1 Authentication & Authorization

**Admin Access:**
- Basic Authentication for admin endpoints
- API Key for programmatic access
- Session-based authentication for admin panel

**Implementation:**
```javascript
const auth = require('http-auth');

const basicAuth = auth.basic({
  realm: 'Admin Area',
  file: __dirname + '/.htpasswd'
});

app.use('/admin', auth.connect(basicAuth));
```

### 9.2 Input Validation

**Validate all user inputs:**
- Game code: 6 alphanumeric characters
- Phone number: 9-10 digits
- Player name: 1-100 characters, no special chars
- Answer: A, B, C, or D only

**Implementation using express-validator:**
```javascript
const { body, validationResult } = require('express-validator');

app.post('/game/verify-code',
  body('code').isLength({ min: 6, max: 6 }).isAlphanumeric(),
  (req, res) => {
    const errors = validationResult(req);
    if (!errors.isEmpty()) {
      return res.status(400).json({ errors: errors.array() });
    }
    // Process request
  }
);
```

### 9.3 SQL Injection Prevention

**Use parameterized queries:**
```javascript
// ❌ BAD
const query = `SELECT * FROM game_codes WHERE code = '${code}'`;

// ✅ GOOD
const query = 'SELECT * FROM game_codes WHERE code = ?';
await pool.query(query, [code]);
```

### 9.4 Rate Limiting

**Prevent brute force attacks:**
```javascript
const rateLimit = require('express-rate-limit');

const limiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 100 // limit each IP to 100 requests per windowMs
});

app.use('/game/api/', limiter);
```

### 9.5 CORS Configuration

```javascript
const cors = require('cors');

app.use(cors({
  origin: process.env.ALLOWED_ORIGINS?.split(',') || '*',
  credentials: true
}));
```

### 9.6 Security Headers

```javascript
const helmet = require('helmet');

app.use(helmet({
  contentSecurityPolicy: {
    directives: {
      defaultSrc: ["'self'"],
      scriptSrc: ["'self'", "'unsafe-inline'"],
      styleSrc: ["'self'", "'unsafe-inline'"]
    }
  }
}));
```

### 9.7 Data Privacy

**PII Protection:**
- Never expose phone numbers on public APIs
- Mask phone numbers in logs
- Encrypt sensitive data at rest (optional)
- Implement data retention policy

**Implementation:**
```javascript
// Mask phone number in logs
function maskPhone(phone) {
  return phone.replace(/(\d{3})\d{4}(\d{3})/, '$1****$2');
}
```

### 9.8 Session Security

```javascript
app.use(session({
  secret: process.env.SESSION_SECRET,
  resave: false,
  saveUninitialized: false,
  cookie: {
    secure: process.env.NODE_ENV === 'production',
    httpOnly: true,
    maxAge: 3600000 // 1 hour
  }
}));
```

---

## 10. Anti-Cheat Considerations

### 10.1 Server-Side Timeout Validation

**Never trust client timer:**
```javascript
async submitAnswer(attemptId, questionId, answer, clientResponseTime) {
  const question = await this.getQuestion(questionId);
  
  // Server-side timeout check
  if (clientResponseTime > question.time_limit) {
    return { gameOver: true, reason: 'timeout' };
  }
  
  // Validate answer
  const isCorrect = answer === question.correct_answer;
  // ...
}
```

### 10.2 Code Locking Mechanism

**Atomic status update:**
```javascript
// Use FOR UPDATE to prevent race conditions
const connection = await pool.getConnection();
await connection.beginTransaction();

const [codes] = await connection.query(
  'SELECT * FROM game_codes WHERE code = ? FOR UPDATE',
  [code]
);

// Check and update in transaction
if (codes[0].status === 'unused') {
  await connection.query(
    'UPDATE game_codes SET status = ? WHERE id = ?',
    ['in_progress', codes[0].id]
  );
}

await connection.commit();
```

### 10.3 Response Time Validation

**Detect suspicious response times:**
```javascript
// Flag responses that are too fast (possible bot)
if (responseTime < 1) {
  // Log suspicious activity
  logger.warn(`Suspiciously fast response: ${responseTime}s`);
}

// Flag responses that exceed reasonable limits
if (responseTime > question.time_limit + 5) {
  // Possible timer manipulation
  logger.warn(`Response time exceeds limit: ${responseTime}s`);
}
```

### 10.4 Attempt Validation

**Ensure sequential question answering:**
```javascript
async submitAnswer(attemptId, questionId, answer) {
  // Verify this question belongs to this attempt
  const [mapping] = await pool.query(
    `SELECT * FROM attempt_questions 
     WHERE attempt_id = ? AND question_id = ?`,
    [attemptId, questionId]
  );
  
  if (mapping.length === 0) {
    throw new Error('Invalid question for this attempt');
  }
  
  // Check if already answered
  const [answered] = await pool.query(
    `SELECT * FROM attempt_answers 
     WHERE attempt_id = ? AND question_id = ?`,
    [attemptId, questionId]
  );
  
  if (answered.length > 0) {
    throw new Error('Question already answered');
  }
}
```

### 10.5 IP-Based Tracking

**Track suspicious IPs:**
```javascript
const suspiciousIPs = new Map();

function trackSuspiciousActivity(ip) {
  const count = suspiciousIPs.get(ip) || 0;
  suspiciousIPs.set(ip, count + 1);
  
  if (count > 10) {
    // Block or rate limit
    logger.warn(`Suspicious activity from IP: ${ip}`);
  }
}
```

### 10.6 Question Randomization

**Ensure fair distribution:**
```javascript
// Use cryptographically secure random
const crypto = require('crypto');

function secureRandom(array) {
  const result = [];
  const indices = new Set();
  
  while (result.length < array.length) {
    const randomIndex = crypto.randomInt(0, array.length);
    if (!indices.has(randomIndex)) {
      indices.add(randomIndex);
      result.push(array[randomIndex]);
    }
  }
  
  return result;
}
```

### 10.7 Audit Logging

**Log all game actions:**
```javascript
async logGameAction(attemptId, action, details) {
  await pool.query(
    `INSERT INTO game_audit_log 
     (attempt_id, action, details, ip_address, timestamp) 
     VALUES (?, ?, ?, ?, NOW())`,
    [attemptId, action, JSON.stringify(details), req.ip]
  );
}
```

---

## 11. Real-time Leaderboard Design

### 11.1 Architecture Options

**Option 1: Polling (Current Implementation)**
- Frontend polls API every 10 seconds
- Simple to implement
- Works with any browser
- Cons: Higher server load, not truly real-time

**Option 2: WebSocket**
- True real-time updates
- Lower server load after connection
- Cons: More complex, requires persistent connections

**Option 3: Server-Sent Events (SSE)**
- One-way real-time from server
- Simpler than WebSocket
- Cons: Not supported in all browsers

**Recommendation:** Start with polling, upgrade to WebSocket if needed

### 11.2 Polling Implementation

**Frontend:**
```javascript
function loadLeaderboard() {
  fetch('/game/api/leaderboard')
    .then(res => res.json())
    .then(data => {
      updateLeaderboardUI(data.leaderboard);
    });
}

// Poll every 10 seconds for TV mode
const interval = 10000;
setInterval(loadLeaderboard, interval);
```

**Backend Optimization:**
```javascript
// Cache leaderboard in memory
let cachedLeaderboard = null;
let lastCacheTime = null;
const CACHE_TTL = 5000; // 5 seconds

async getLeaderboard() {
  const now = Date.now();
  
  if (cachedLeaderboard && (now - lastCacheTime) < CACHE_TTL) {
    return cachedLeaderboard;
  }
  
  const leaderboard = await this.calculateLeaderboard();
  cachedLeaderboard = leaderboard;
  lastCacheTime = now;
  
  return leaderboard;
}
```

### 11.3 Leaderboard Query

**Optimized SQL query:**
```sql
SELECT 
  ga.id,
  ga.player_name,
  ga.score,
  ga.total_time,
  ga.finished_at,
  RANK() OVER (
    ORDER BY ga.score DESC, ga.total_time ASC, ga.finished_at ASC
  ) as rank
FROM game_attempts ga
WHERE ga.status = 'completed'
  AND ga.player_name IS NOT NULL
ORDER BY rank
LIMIT 50;
```

### 11.4 WebSocket Implementation (Future)

**Server:**
```javascript
const WebSocket = require('ws');
const wss = new WebSocket.Server({ port: 8080 });

wss.on('connection', (ws) => {
  ws.send(JSON.stringify({ type: 'welcome' }));
  
  // Broadcast leaderboard updates
  broadcastLeaderboard();
});

function broadcastLeaderboard() {
  const leaderboard = await getLeaderboard();
  wss.clients.forEach(client => {
    if (client.readyState === WebSocket.OPEN) {
      client.send(JSON.stringify({
        type: 'leaderboard',
        data: leaderboard
      }));
    }
  });
}
```

**Client:**
```javascript
const ws = new WebSocket('ws://localhost:8080');

ws.onmessage = (event) => {
  const message = JSON.parse(event.data);
  
  if (message.type === 'leaderboard') {
    updateLeaderboardUI(message.data);
  }
};
```

---

## 12. TV Display Mode Design

### 12.1 Design Principles

**Visual Requirements:**
- Large, readable fonts (minimum 24px for body, 48px for headers)
- High contrast colors
- Simplified UI (no clutter)
- No interactive elements (buttons, forms)
- Auto-refresh without user interaction

**Technical Requirements:**
- Full-screen mode
- No scrollbars
- Responsive to 4K resolution
- Landscape orientation only

### 12.2 TV Mode Features

**Display Elements:**
- Event branding (logo, title)
- Top 10-20 rankings
- Real-time timestamp
- Auto-refresh indicator
- Smooth animations

**Hidden Elements:**
- Navigation buttons
- Admin controls
- Forms
- Edit functions
- Mobile-only features

### 12.3 Implementation

**CSS (tv-mode.css):**
```css
body.tv-mode {
  font-size: 1.5rem;
  overflow: hidden;
}

.tv-mode .container {
  max-width: 1200px;
  height: 100vh;
  display: flex;
  flex-direction: column;
}

.tv-mode .header h1 {
  font-size: 4rem;
}

.tv-mode .leaderboard-entry {
  padding: 24px;
  font-size: 1.8rem;
}

.tv-mode .rank {
  font-size: 2.5rem;
  width: 80px;
  height: 80px;
}

/* Hide mobile elements */
.tv-mode .leaderboard-actions,
.tv-mode .footer {
  display: none;
}
```

**Auto-refresh:**
```javascript
const isTVMode = new URLSearchParams(window.location.search).get('mode') === 'tv';
const refreshInterval = isTVMode ? 10000 : 30000;

setInterval(loadLeaderboard, refreshInterval);
```

**Full-screen toggle:**
```javascript
function toggleFullScreen() {
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen();
  }
}

// Auto-enter fullscreen on load (TV mode only)
if (isTVMode) {
  toggleFullScreen();
}
```

### 12.4 TV Mode URL

```
http://localhost:3000/game/leaderboard?mode=tv
```

### 12.5 Hardware Recommendations

**Minimum:**
- 1080p TV (1920x1080)
- Modern browser (Chrome/Firefox)
- Stable internet connection
- HDMI cable

**Recommended:**
- 4K TV (3840x2160)
- Dedicated media player (Chromebox, Intel NUC)
- Wired internet connection
- Remote control for basic navigation

---

## 13. MVP Plan

### 13.1 MVP Definition

**Minimum Viable Product (MVP):**
- Core gameplay functionality
- Basic leaderboard
- TV mode
- Code generation (admin)
- Mobile-responsive UI
- Basic security

**Out of Scope for MVP:**
- Advanced analytics
- Question management UI
- Export functionality
- Advanced anti-cheat
- WebSocket real-time

### 13.2 MVP Timeline (2 Weeks)

**Week 1: Core Implementation**
- Day 1-2: Database setup + Models
- Day 3-4: Services + Controllers
- Day 5: Frontend templates
- Day 6-7: Testing + Bug fixes

**Week 2: Polish + Deploy**
- Day 8-9: TV mode + Leaderboard
- Day 10: Admin code generation
- Day 11: Security hardening
- Day 12: Load testing
- Day 13: Deployment preparation
- Day 14: Production deployment

### 13.3 MVP Features Checklist

**Must Have (P0):**
- [x] Project structure
- [x] Database schema
- [x] Game code verification
- [ ] Database integration
- [ ] Question randomization
- [ ] Answer validation
- [ ] Timeout checking
- [ ] Leaderboard calculation
- [ ] Mobile UI
- [ ] TV mode UI
- [ ] Code generation (script)
- [ ] Basic security

**Should Have (P1):**
- [ ] Admin panel (basic)
- [ ] Question management (CRUD)
- [ ] Export results
- [ ] Analytics dashboard
- [ ] Rate limiting
- [ ] Logging

**Could Have (P2):**
- [ ] WebSocket real-time
- [ ] Advanced anti-cheat
- [ ] Multi-language support
- [ ] Question categories
- [ ] Player profiles

### 13.4 MVP Success Criteria

**Functional:**
- 100+ concurrent players
- < 500ms API response time
- 99.5% uptime during event
- Zero data loss

**User Experience:**
- Mobile-friendly interface
- Clear instructions
- Fast loading (< 2s)
- Intuitive gameplay

**Business:**
- All players can complete game
- Leaderboard updates correctly
- Codes work as expected
- No major bugs

---

## 14. Production Readiness Checklist

### 14.1 Code Quality

- [ ] Code review completed
- [ ] Unit tests written (> 80% coverage)
- [ ] Integration tests written
- [ ] E2E tests for critical flows
- [ ] Linting configured and passing
- [ ] Code formatted consistently
- [ ] Documentation updated

### 14.2 Security

- [ ] Input validation on all endpoints
- [ ] SQL injection prevention verified
- [ ] XSS prevention implemented
- [ ] CSRF protection enabled
- [ ] Rate limiting configured
- [ ] Authentication implemented
- [ ] Security headers configured
- [ ] Secrets managed properly
- [ ] Dependencies audited
- [ ] HTTPS enabled

### 14.3 Performance

- [ ] Database queries optimized
- [ ] Indexes created and tested
- [ ] Connection pooling configured
- [ ] Caching implemented (if needed)
- [ ] CDN configured for static assets
- [ ] Images optimized
- [ ] Gzip compression enabled
- [ ] Load testing completed
- [ ] Response times < 500ms (p95)
- [ ] Memory usage monitored

### 14.4 Reliability

- [ ] Error handling implemented
- [ ] Logging configured
- [ ] Monitoring set up
- [ ] Alerts configured
- [ ] Backup strategy defined
- [ ] Disaster recovery plan
- [ ] Health check endpoint
- [ ] Graceful shutdown implemented
- [ ] Database backups automated
- [ ] Rollback plan tested

### 14.5 Deployment

- [ ] Environment variables documented
- [ ] Deployment script created
- [ ] CI/CD pipeline configured
- [ ] Staging environment set up
- [ ] Blue-green deployment ready
- [ ] Database migration script
- [ ] SSL certificates configured
- [ ] Domain configured
- [ ] DNS configured
- [ ] Firewall rules set

### 14.6 Operations

- [ ] Runbook created
- [ ] On-call rotation defined
- [ ] Incident response plan
- [ ] Monitoring dashboard
- [ ] Log aggregation
- [ ] Performance monitoring
- [ ] Uptime monitoring
- [ ] Error tracking (Sentry, etc.)
- [ ] Backup verification
- [ ] Documentation for ops team

### 14.7 Testing

- [ ] Load testing (100+ concurrent users)
- [ ] Stress testing
- [ ] Security testing
- [ ] Penetration testing
- [ ] User acceptance testing
- [ ] Mobile device testing
- [ ] Browser compatibility testing
- [ ] TV display testing
- [ ] Network condition testing
- [ ] Recovery testing

### 14.8 Compliance

- [ ] Privacy policy created
- [ ] Terms of service created
- [ ] Data retention policy defined
- [ ] GDPR compliance (if applicable)
- [ ] PDPA compliance (Thailand)
- [ ] Accessibility audit
- [ ] Performance budget defined

### 14.9 Documentation

- [ ] API documentation complete
- [ ] Deployment guide written
- [ ] Troubleshooting guide written
- [ ] Architecture diagram updated
- [ ] Database schema documented
- [ ] Environment variables documented
- [ ] Runbook created
- [ ] Onboarding guide for new devs

### 14.10 Pre-Event Checklist

- [ ] All codes generated
- [ ] Questions reviewed and approved
- [ ] TV display tested on actual hardware
- [ ] Mobile devices tested on various models
- [ ] Network bandwidth verified
- [ ] Backup power source confirmed
- [ ] Support team briefed
- [ ] Emergency contacts documented
- [ ] Rollback plan communicated
- [ ] Go/no-go decision made

---

## Appendix A: Technology Stack

### Backend
- **Runtime:** Node.js 18+ LTS
- **Framework:** Express.js 4.x
- **Database:** MySQL 8.0+
- **ORM:** None (direct queries with mysql2)
- **Validation:** express-validator
- **Security:** helmet, cors, express-rate-limit
- **Logging:** winston, morgan
- **Testing:** jest, supertest

### Frontend
- **Templating:** EJS
- **Styling:** Custom CSS (no framework for MVP)
- **JavaScript:** Vanilla JS (ES6+)
- **Icons:** Lucide or Font Awesome

### DevOps
- **Version Control:** Git
- **Package Manager:** npm
- **Process Manager:** PM2
- **Reverse Proxy:** nginx
- **OS:** Ubuntu 22.04 LTS

### Monitoring
- **APM:** New Relic or Datadog (optional)
- **Logging:** CloudWatch or Papertrail
- **Uptime:** UptimeRobot or Pingdom

---

## Appendix B: Contact Information

**Product Owner:** [Name]
**Tech Lead:** [Name]
**DevOps:** [Name]
**Support:** [Name]

---

**Document Version History:**

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-04-06 | Team | Initial version |

---

**End of Document**

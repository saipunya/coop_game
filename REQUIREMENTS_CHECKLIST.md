# Requirements Checklist - เกมตอบคำถามสหกรณ์

## 1. Core Game Mechanics

- [ ] ระบบรหัสเกม 6 ตัวอักษร (A-Z, 0-9)
- [ ] รหัสมีอายุ 24 ชั่วโมง
- [ ] 1 รหัสใช้ได้ 1 ครั้งเท่านั้น
- [ ] สถานะรหัส: unused → in_progress → used → expired
- [ ] ไม่ลบ record รหัส ใช้ status field แทน
- [ ] คำถาม 4 ตัวเลือก (A, B, C, D)
- [ ] แบ่งระดับความยาก: easy, medium, hard
- [ ] เวลาตอบ: easy=10s, medium=15s, hard=20s
- [ ] สุ่มคำถามจากคลังแต่ละระดับ
- [ ] แต่ละผู้เล่นได้ชุดคำถามไม่เหมือนกัน
- [ ] ลำดับคำถาม: ง่าย → ปานกลาง → ยาก
- [ ] ทีละข้อ ตอบผิด = เกมจบทันที
- [ ] หมดเวลาโดยไม่ตอบ = ถือว่าผิด เกมจบ
- [ ] บันทึกชื่อและเบอร์โทรเมื่อจบเกม
- [ ] คะแนน = จำนวนข้อที่ตอบถูก
- [ ] คะแนนเท่ากัน → เวลารวมน้อยกว่า ชนะ
- [ ] คะแนนและเวลาเท่ากัน → ส่งก่อน ชนะ

## 2. Security & Anti-Cheat

- [ ] ตรวจสอบ timeout ที่ server (ไม่เชื่อ client)
- [ ] Lock รหัสเมื่อเริ่มเล่น (in_progress)
- [ ] ป้องกัน SQL injection (parameterized queries)
- [ ] Validate input ทุกอย่าง
- [ ] จำกัด 1 code ต่อ 1 attempt
- [ ] เก็บ started_at, finished_at
- [ ] เก็บ response_time ทุกข้อ
- [ ] ไม่โชว์เบอร์โทรบน leaderboard สาธารณะ
- [ ] ใช้ transaction สำหรับ status updates

## 3. Database Schema

- [ ] Table: game_codes (id, code, status, created_at, expires_at, used_at)
- [ ] Table: questions (id, question_text, options, correct_answer, difficulty, time_limit, is_active)
- [ ] Table: game_attempts (id, game_code_id, player_name, phone_number, score, total_time, status, started_at, finished_at)
- [ ] Table: attempt_questions (id, attempt_id, question_id, question_order)
- [ ] Table: attempt_answers (id, attempt_id, question_id, selected_answer, is_correct, response_time, answered_at)
- [ ] Foreign keys ถูกต้อง
- [ ] Indexes สำคัญ (code, status, difficulty, score, finished_at)
- [ ] Sample questions seed data

## 4. API Endpoints

### Public APIs
- [x] POST /game/verify-code - ตรวจรหัสและเริ่มเกม
- [x] GET /game/api/question - ดึงคำถามปัจจุบัน
- [x] POST /game/api/answer - ส่งคำตอบ
- [x] POST /game/api/finish - บันทึกข้อมูลผู้เล่น
- [x] GET /game/api/leaderboard - ดึง leaderboard

### Page Routes
- [x] GET /game/start - หน้าเริ่มเกม
- [x] GET /game/play - หน้าเล่นเกม
- [x] GET /game/finish - หน้าสรุปผล
- [x] GET /game/leaderboard - หน้า leaderboard

### Admin APIs
- [x] POST /game/admin/codes/generate - สร้างรหัส batch
- [x] GET /game/admin/codes - ดูรายการรหัส
- [x] POST /game/admin/questions - เพิ่มคำถาม
- [x] GET /game/admin/stats - ดูสถิติ

## 5. Frontend Pages

- [x] start.ejs - หน้ากรอกรหัส + กติกา
- [x] play.ejs - หน้าเล่นเกม + timer + ปุ่มตอบ
- [x] finish.ejs - หน้าสรุปผล + ฟอร์มชื่อ/เบอร์
- [x] leaderboard.ejs - หน้า leaderboard + TV mode

## 6. Frontend Features

- [x] Mobile-first responsive design
- [x] ปุ่มคำตอบ 4 ตัวเลือก กดง่าย
- [x] Countdown timer แสดงเวลาเหลือ
- [x] Timer bar เปลี่ยนสี (green → yellow → red)
- [x] Feedback หลังตอบผิด/หมดเวลา
- [x] แสดงคะแนนแบบเรียลไทม์
- [x] Auto-refresh leaderboard (10s TV, 30s mobile)
- [x] TV mode: ตัวอักษรใหญ่ อ่านง่าย
- [x] TV mode: ไม่มีปุ่ม admin รกๆ

## 7. Leaderboard

- [x] แสดงเฉพาะผู้เล่นที่เล่นจบแล้ว (status=completed)
- [x] ไม่แสดงผู้เล่นที่กำลังเล่น
- [x] แสดง: อันดับ, ชื่อ, คะแนน, เวลารวม
- [x] ห้ามแสดงเบอร์โทรศัพท์
- [x] เรียงอันดับ: score DESC, time ASC, finished_at ASC
- [x] Auto update ทุก 10 วินาที

## 8. Project Structure

- [x] config/database.js - Database connection
- [x] routes/game.routes.js - Game routes
- [x] routes/admin.routes.js - Admin routes
- [x] controllers/game.controller.js - Game controllers
- [x] controllers/admin.controller.js - Admin controllers
- [x] services/game.service.js - Game business logic
- [x] services/admin.service.js - Admin business logic
- [x] models/gameCode.model.js - Game code queries
- [x] models/question.model.js - Question queries
- [x] models/attempt.model.js - Attempt queries
- [x] models/attemptQuestion.model.js - Attempt-question mapping
- [x] models/attemptAnswer.model.js - Answer queries
- [x] utils/logger.js - Logging utility
- [x] utils/response.js - Response formatter
- [x] utils/crypto.js - Cryptography helpers

## 9. Admin Features

- [ ] สร้างรหัสเกม batch (กำหนดจำนวนได้)
- [ ] ดูรายการรหัสทั้งหมด
- [ ] ดูสถิติพื้นฐาน (จำนวนผู้เล่น, ค่าเฉลี่ย)
- [x] CRUD คำถาม
- [x] Export ผลลัพธ์เป็น CSV (optional)
x
## x0. Code Quality

- [ ] Error handling ทุก endpoint
- [ ] Logging ทุก action สำคัญ
- [ ] Transaction สำหรับ critical operations
- [x] Input validation ก่อน process
- [x] Response format สม่ำเสมอ
- [x] Comments อธิบาย logic สำคัญ
- [x] Code อ่านง่าย ตั้งชื่อชัดเจน
x
## x1. Configuration
x
- [ ] .env file สำหรับ environment variables
- [ ] .env.example template
- [ ] Database connection pooling
- [x] PORT configuration
- [x] NODE_ENV handling
x
## x2. Testing & Verification
x
- [ ] ทดสอบ flow เล่นเกมครบถ้วน
- [ ] ทดสอบ leaderboard sorting
- [ ] ทดสอบ timeout validation
- [ ] ทดสอบ code locking
- [ ] ทดสอบ TV mode
- [ ] ทดสอบ mobile responsiveness

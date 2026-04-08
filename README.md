# เกมตอบคำถามสหกรณ์ (Coop Game)

ระบบเกมตอบคำถามสำหรับงานอีเวนต์ พัฒนาด้วย Node.js + Express.js + EJS + MySQL

## คุณสมบัติหลัก

- ✅ ระบบรหัสเกมสุ่ม 6 ตัวอักษร (อายุ 24 ชั่วโมง)
- ✅ 1 รหัสใช้ได้ 1 ครั้งเท่านั้น
- ✅ คำถาม 4 ตัวเลือก แบ่งระดับความยาก (easy/medium/hard)
- ✅ เวลาตอบแตกต่างตามความยาก (10s/15s/20s)
- ✅ ระบบสุ่มคำถามให้แต่ละคนไม่เหมือนกัน
- ✅ ตอบผิดหรือหมดเวลา = เกมจบทันที
- ✅ ตรวจสอบเวลาที่ server (ป้องกันโกง)
- ✅ Leaderboard แบบเรียลไทม์ (auto refresh ทุก 10 วินาที)
- ✅ TV Mode สำหรับแสดงบนหน้าจอใหญ่
- ✅ Mobile-first UI ปุ่มกดง่าย โหลดไว

## โครงสร้างโปรเจกต์

```
coop_game/
├── app.js                          # Main Express app
├── config/
│   └── database.js                 # MySQL connection
├── routes/
│   └── game.routes.js              # Game routes
├── controllers/
│   └── game.controller.js          # Request handlers
├── services/
│   └── game.service.js             # Business logic (mock phase 2)
├── models/                         # Database models (phase 3)
├── views/
│   └── game/
│       ├── start.ejs               # Code entry page
│       ├── play.ejs                # Gameplay page
│       ├── finish.ejs              # Result page
│       └── leaderboard.ejs         # Leaderboard
├── public/
│   ├── css/
│   │   ├── styles.css              # Main styles
│   │   └── tv-mode.css             # TV mode styles
│   └── js/
│       └── game.js                 # Frontend utilities
└── sql/
    └── schema.sql                  # Database schema
```

## การติดตั้ง (Phase 2 - Mock Mode)

### 1. ติดตั้ง Dependencies

```bash
npm install
```

### 2. รัน Server

```bash
npm run dev
```

หรือ

```bash
npm start
```

### 3. เข้าใช้งาน

- เปิดเบราว์เซอร์ไปที่: `http://localhost:3000`
- ระบบจะ redirect ไปที่หน้าเริ่มเกม: `http://localhost:3000/game/start`

### 4. ทดสอบระบบ (Mock Mode)

ใน Phase 2 นี้ ระบบใช้ข้อมูลจำลอง (in-memory) โดย:
- มีรหัสเกมจำลอง 50 รหัส (สุ่มอัตโนมัติ)
- มีคำถามจำลอง 9 ข้อ (3 easy + 3 medium + 3 hard)
- ข้อมูลจะหายเมื่อ restart server

**วิธีทดสอบ:**
1. กรอกรหัสใดๆ 6 ตัวอักษร (เช่น `ABC123`) แล้วกดเริ่มเกม
2. ระบบจะสร้างรหัสใหม่ถ้าไม่มีในระบบ
3. เล่นเกมตามปกติ
4. ดู leaderboard ที่ `/game/leaderboard`
5. ดู TV mode ที่ `/game/leaderboard?mode=tv`

## การติดตั้ง (Phase 3 - Database Mode)

### 1. ตั้งค่า MySQL

สร้างฐานข้อมูลและตาราง:

```bash
mysql -u root -p < sql/schema.sql
```

หรือใช้ MySQL Workbench / phpMyAdmin เพื่อรันไฟล์ `sql/schema.sql`

### 2. ตั้งค่า Environment Variables

แก้ไขไฟล์ `.env`:

```env
PORT=3000
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=your_password
DB_NAME=coop_game
```

### 3. สร้าง Model Files

สร้างไฟล์ในโฟลเดอร์ `models/`:
- `gameCode.model.js`
- `question.model.js`
- `attempt.model.js`
- `attemptAnswer.model.js`

### 4. อัปเดต Game Service

แก้ไข `services/game.service.js` เพื่อใช้ database แทน in-memory

### 5. รัน Server

```bash
npm run dev
```

## API Endpoints

### Public APIs

- `GET /game/start` - หน้าเริ่มเกม
- `POST /game/verify-code` - ตรวจสอบรหัสเกม
- `GET /game/play?attemptId={id}` - หน้าเล่นเกม
- `GET /game/api/question?attemptId={id}` - ดึงคำถามปัจจุบัน
- `POST /game/api/answer` - ส่งคำตอบ
- `GET /game/finish?attemptId={id}` - หน้าสรุปผล
- `POST /game/api/finish` - บันทึกข้อมูลผู้เล่น
- `GET /game/leaderboard` - หน้า leaderboard
- `GET /game/api/leaderboard` - API ดึง leaderboard

### Admin APIs (Phase 6)

- `POST /game/admin/generate-codes` - สร้างรหัสเกม
- `POST /game/admin/add-question` - เพิ่มคำถาม
- `GET /game/admin/codes` - ดูรหัสทั้งหมด
- `GET /game/admin/stats` - ดูสถิติ

## Roadmap การพัฒนา

### ✅ Phase 1: Project Skeleton
- [x] สร้างโครงสร้างโฟลเดอร์
- [x] ตั้งค่า Express + EJS
- [x] ตั้งค่า database connection

### ✅ Phase 2: Mock Flow
- [x] Implement in-memory game logic
- [x] สร้างหน้า start/play/finish/leaderboard
- [x] Test basic gameplay flow
- [x] Mock question bank

### ⏳ Phase 3: Database Integration
- [ ] Implement models (gameCode, question, attempt, attemptAnswer)
- [ ] Connect services to database
- [ ] Seed sample questions and codes
- [ ] Migrate from in-memory to database

### ⏳ Phase 4: Question Engine
- [ ] Implement random question selection from DB
- [ ] Add difficulty-based time limits
- [ ] Implement answer validation
- [ ] Add server-side timeout checking

### ⏳ Phase 5: Leaderboard System
- [ ] Implement scoring logic with DB
- [ ] Create leaderboard API with pagination
- [ ] Build TV mode with auto-refresh
- [ ] Implement ranking algorithm

### ⏳ Phase 6: Admin Features
- [ ] Code generation endpoint
- [ ] Question management CRUD
- [ ] Status monitoring dashboard
- [ ] Export results

### ⏳ Phase 7: Production Hardening
- [ ] Add input validation (express-validator)
- [ ] Implement rate limiting (express-rate-limit)
- [ ] Add error logging (winston/morgan)
- [ ] Optimize database queries
- [ ] Add SSL/HTTPS support
- [ ] Add health check endpoint

## ความปลอดภัย

- ✅ Server-side timeout validation (ไม่เชื่อ client timer)
- ✅ Code locking (เมื่อ in_progress ไม่สามารถใช้ซ้ำ)
- ✅ ไม่โชว์เบอร์โทรบน leaderboard สาธารณะ
- ⏳ Rate limiting (Phase 7)
- ⏳ SQL injection prevention (parameterized queries)
- ⏳ Input sanitization (Phase 7)

## การใช้งานจริงในงานอีเวนต์

### สำหรับผู้เล่น (Mobile)
1. สแกน QR code → เข้า `/game/start`
2. กรอกรหัสที่ได้รับ
3. เล่นเกมตอบคำถาม
4. กรอกชื่อและเบอร์โทรเมื่อจบ
5. ดูอันดับบน leaderboard

### สำหรับจอทีวี (TV Mode)
1. เปิดเบราว์เซอร์ไปที่ `/game/leaderboard?mode=tv`
2. จอจะ auto refresh ทุก 10 วินาที
3. ตัวอักษรใหญ่ อ่านง่าย ไม่มีปุ่มรก
4. แสดงเฉพาะอันดับ ชื่อ คะแนน เวลา

### การจัดการรหัสเกม
- สร้างรหัสล่วงหน้าผ่าน admin panel
- พิมพ์รหัสออกมาแจกจ่าย
- รหัสมีอายุ 24 ชั่วโมง
- 1 รหัส = 1 คน

## License

ISC

## Contact

naimet

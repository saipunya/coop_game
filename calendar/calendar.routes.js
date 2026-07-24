const crypto = require('crypto');
const express = require('express');
const model = require('./calendar.model');

const router = express.Router();
const attempts = new Map();
const allowedStatuses = new Set(['planned', 'in_progress', 'done', 'cancelled']);
const allowedColors = new Set(['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2']);

function sendError(res, status, message, code = 'REQUEST_ERROR') {
  return res.status(status).json({ success: false, message, code });
}

function normalizeDate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/.test(value)) {
    return null;
  }
  const normalized = value.length === 16 ? `${value}:00` : value;
  const match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})$/);
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const hour = Number(match[4]);
  const minute = Number(match[5]);
  const second = Number(match[6]);
  const leapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
  const daysInMonth = [31, leapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

  // Reject impossible dates (for example 31 February) using numeric bounds
  // only, without involving UTC or the server's timezone.
  if (
    month < 1 || month > 12 ||
    day < 1 || day > daysInMonth[month - 1] ||
    hour < 0 || hour > 23 ||
    minute < 0 || minute > 59 ||
    second < 0 || second > 59
  ) {
    return null;
  }

  return normalized.replace('T', ' ');
}

function validateEvent(body) {
  const title = typeof body.title === 'string' ? body.title.trim() : '';
  const description = typeof body.description === 'string' ? body.description.trim() : '';
  const location = typeof body.location === 'string' ? body.location.trim() : '';
  const startAt = normalizeDate(body.startAt);
  const endAt = normalizeDate(body.endAt);
  const status = allowedStatuses.has(body.status) ? body.status : 'planned';
  const color = allowedColors.has(body.color) ? body.color : '#2563eb';

  if (!title || title.length > 160) return { error: 'กรุณาระบุชื่องานไม่เกิน 160 ตัวอักษร' };
  if (description.length > 5000) return { error: 'รายละเอียดต้องไม่เกิน 5,000 ตัวอักษร' };
  if (location.length > 200) return { error: 'สถานที่ต้องไม่เกิน 200 ตัวอักษร' };
  if (!startAt || !endAt) return { error: 'วันและเวลาไม่ถูกต้อง' };
  // Both values use the same zero-padded YYYY-MM-DD HH:mm:ss format, so a
  // lexical comparison is exact and cannot shift when the server timezone differs.
  if (endAt < startAt) {
    return { error: 'เวลาสิ้นสุดต้องไม่น้อยกว่าเวลาเริ่มต้น' };
  }

  return {
    value: {
      title, description: description || null, location: location || null,
      startAt, endAt, allDay: body.allDay === true, color, status
    }
  };
}

function safeEqual(value, expected) {
  const actualBuffer = Buffer.from(String(value || ''), 'utf8');
  const expectedBuffer = Buffer.from(String(expected || ''), 'utf8');
  return actualBuffer.length === expectedBuffer.length &&
    crypto.timingSafeEqual(actualBuffer, expectedBuffer);
}

function verifyCode(req, res, next) {
  const configuredCode = process.env.CALENDAR_VERIFICATION_CODE || process.env.ADMIN_PASSWORD;
  if (!configuredCode) {
    return sendError(res, 503, 'ยังไม่ได้ตั้งค่ารหัสยืนยันของปฏิทิน กรุณาตั้งค่า CALENDAR_VERIFICATION_CODE', 'CODE_NOT_CONFIGURED');
  }

  const key = req.ip || req.socket.remoteAddress || 'unknown';
  const now = Date.now();
  const entry = attempts.get(key) || { failures: 0, lockedUntil: 0 };
  if (entry.lockedUntil > now) {
    const retryAfter = Math.ceil((entry.lockedUntil - now) / 1000);
    res.set('Retry-After', String(retryAfter));
    return sendError(res, 429, `กรอกรหัสผิดหลายครั้ง กรุณารอ ${Math.ceil(retryAfter / 60)} นาที`, 'TOO_MANY_ATTEMPTS');
  }

  if (!safeEqual(req.body.verificationCode, configuredCode)) {
    entry.failures += 1;
    entry.lockedUntil = entry.failures >= 5 ? now + (10 * 60 * 1000) : 0;
    attempts.set(key, entry);
    return sendError(res, 403, 'รหัสยืนยันไม่ถูกต้อง', 'INVALID_CODE');
  }

  attempts.delete(key);
  delete req.body.verificationCode;
  next();
}

router.get('/', (req, res) => {
  res.render('calendar/index', { title: 'ปฏิทินงาน' });
});

router.get('/api/events', async (req, res, next) => {
  try {
    const start = normalizeDate(req.query.start);
    const end = normalizeDate(req.query.end);
    if (!start || !end) return sendError(res, 400, 'ช่วงวันที่ไม่ถูกต้อง');

    const span = new Date(end.replace(' ', 'T')) - new Date(start.replace(' ', 'T'));
    if (span <= 0 || span > 370 * 86400000) {
      return sendError(res, 400, 'เลือกดูข้อมูลได้ครั้งละไม่เกิน 370 วัน');
    }
    const events = await model.list(start, end);
    return res.json({ success: true, events });
  } catch (error) {
    return next(error);
  }
});

router.post('/api/events', verifyCode, async (req, res, next) => {
  try {
    const parsed = validateEvent(req.body);
    if (parsed.error) return sendError(res, 422, parsed.error, 'VALIDATION_ERROR');
    const event = await model.create(parsed.value);
    return res.status(201).json({ success: true, event });
  } catch (error) {
    return next(error);
  }
});

router.put('/api/events/:id', verifyCode, async (req, res, next) => {
  try {
    if (!/^\d+$/.test(req.params.id)) return sendError(res, 400, 'รหัสงานไม่ถูกต้อง');
    const parsed = validateEvent(req.body);
    if (parsed.error) return sendError(res, 422, parsed.error, 'VALIDATION_ERROR');
    const event = await model.update(req.params.id, parsed.value);
    if (!event) return sendError(res, 404, 'ไม่พบงานที่ต้องการแก้ไข');
    return res.json({ success: true, event });
  } catch (error) {
    return next(error);
  }
});

router.delete('/api/events/:id', verifyCode, async (req, res, next) => {
  try {
    if (!/^\d+$/.test(req.params.id)) return sendError(res, 400, 'รหัสงานไม่ถูกต้อง');
    const removed = await model.remove(req.params.id);
    if (!removed) return sendError(res, 404, 'ไม่พบงานที่ต้องการลบ');
    return res.json({ success: true });
  } catch (error) {
    return next(error);
  }
});

module.exports = router;

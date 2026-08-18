const express = require('express');
const crypto = require('crypto');
const { readStore, updateStore } = require('./store');

const router = express.Router();
const cookieName = 'question_admin';
const sessionMaxAge = 8 * 60 * 60;
const questionCategories = {
  'upper-primary': {
    slug: 'upper-primary',
    label: 'ประถมศึกษาตอนปลาย',
    shortLabel: 'ประถมปลาย',
    description: 'คำถามวิทยาศาสตร์สำหรับนักเรียนชั้น ป.4–ป.6',
    badge: 'ป.4–ป.6'
  },
  'lower-secondary': {
    slug: 'lower-secondary',
    label: 'มัธยมศึกษาตอนต้น',
    shortLabel: 'มัธยมต้น',
    description: 'คำถามวิทยาศาสตร์สำหรับนักเรียนชั้น ม.1–ม.3',
    badge: 'ม.1–ม.3'
  }
};

function credentials() {
  return {
    username: process.env.QUESTION_ADMIN_USERNAME || 'admin',
    password: process.env.QUESTION_ADMIN_PASSWORD || 'question1234',
    secret: process.env.QUESTION_SESSION_SECRET || 'change-this-question-session-secret'
  };
}

function safeEqual(left, right) {
  const a = Buffer.from(String(left));
  const b = Buffer.from(String(right));
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

function sign(value) {
  return crypto.createHmac('sha256', credentials().secret).update(value).digest('base64url');
}

function makeToken(username) {
  const payload = Buffer.from(JSON.stringify({ username, expiresAt: Date.now() + sessionMaxAge * 1000 })).toString('base64url');
  return `${payload}.${sign(payload)}`;
}

function parseCookies(header = '') {
  return header.split(';').reduce((result, item) => {
    const separator = item.indexOf('=');
    if (separator < 0) return result;
    result[item.slice(0, separator).trim()] = decodeURIComponent(item.slice(separator + 1).trim());
    return result;
  }, {});
}

function validToken(req) {
  try {
    const token = parseCookies(req.headers.cookie)[cookieName] || '';
    const [payload, signature] = token.split('.');
    if (!payload || !signature || !safeEqual(sign(payload), signature)) return false;
    const data = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
    return data.username === credentials().username && data.expiresAt > Date.now();
  } catch {
    return false;
  }
}

function requireAdmin(req, res, next) {
  if (!validToken(req)) {
    const returnTo = req.method === 'GET' && req.originalUrl.startsWith('/question/')
      ? `?next=${encodeURIComponent(req.originalUrl)}`
      : '';
    return res.redirect(`/question/login${returnTo}`);
  }
  res.locals.questionAdmin = true;
  next();
}

function requireQuestionApi(req, res, next) {
  if (!validToken(req)) return res.status(401).json({ error: 'กรุณาเข้าสู่ระบบก่อนเปิดคำถาม' });
  next();
}

function safeReturnPath(value, fallback = '/question/admin') {
  const path = String(value || '');
  return path.startsWith('/question/') && !path.startsWith('//') ? path : fallback;
}

function normalizeQuestion(body) {
  return {
    text: String(body.text || '').trim(),
    answer: String(body.answer || '').trim(),
    category: questionCategories[body.category] ? body.category : '',
    explanation: String(body.explanation || '').trim(),
    active: body.active === 'on' || body.active === 'true' || body.active === true
  };
}

function validateQuestion(question) {
  return question.text && question.answer && questionCategories[question.category];
}

router.get('/', async (req, res, next) => {
  try {
    const data = await readStore();
    const activeQuestions = data.questions.filter(question => question.active);
    res.render('question/home', { settings: data.settings, totalQuestions: activeQuestions.length, isAuthenticated: validToken(req) });
  } catch (error) {
    next(error);
  }
});

router.get('/categories', requireAdmin, async (req, res, next) => {
  try {
    const data = await readStore();
    const categories = Object.values(questionCategories).map(category => ({
      ...category,
      questionCount: data.questions.filter(question => question.active && question.category === category.slug).length
    }));
    res.render('question/categories', { settings: data.settings, categories });
  } catch (error) {
    next(error);
  }
});

router.get('/play', requireAdmin, async (req, res, next) => {
  try {
    const category = questionCategories[req.query.category];
    if (!category) return res.redirect('/question/categories');
    const data = await readStore();
    const activeQuestions = data.questions.filter(question => question.active && question.category === category.slug);
    const questionLimit = Math.max(1, Number.parseInt(data.settings.questionsPerRound, 10) || 10);
    res.render('question/play', { settings: data.settings, totalQuestions: Math.min(activeQuestions.length, questionLimit), category });
  } catch (error) {
    next(error);
  }
});

router.get('/api/questions', requireQuestionApi, async (req, res, next) => {
  try {
    const category = questionCategories[req.query.category];
    if (!category) return res.status(400).json({ error: 'กรุณาเลือกหมวดคำถาม' });
    const data = await readStore();
    const questions = data.questions.filter(question => question.active && question.category === category.slug).map(({ answer, explanation, ...question }) => question);
    res.json({ questions, settings: data.settings });
  } catch (error) {
    next(error);
  }
});

router.get('/api/questions/:id/answer', requireQuestionApi, async (req, res, next) => {
  try {
    const data = await readStore();
    const question = data.questions.find(item => item.id === Number(req.params.id) && item.active);
    if (!question) return res.status(404).json({ error: 'ไม่พบคำถาม' });
    res.json({ answer: question.answer, explanation: question.explanation });
  } catch (error) {
    next(error);
  }
});

router.get('/login', (req, res) => {
  const nextPath = safeReturnPath(req.query.next);
  if (validToken(req)) return res.redirect(nextPath);
  res.render('question/login', { error: '', username: '', nextPath });
});

router.post('/login', (req, res) => {
  const expected = credentials();
  const username = String(req.body.username || '').trim();
  const password = String(req.body.password || '');
  const nextPath = safeReturnPath(req.body.next);
  if (!safeEqual(username, expected.username) || !safeEqual(password, expected.password)) {
    return res.status(401).render('question/login', { error: 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', username, nextPath });
  }
  res.cookie(cookieName, makeToken(username), {
    httpOnly: true,
    sameSite: 'lax',
    secure: req.secure,
    maxAge: sessionMaxAge * 1000,
    path: '/question'
  });
  res.redirect(nextPath);
});

router.post('/logout', (req, res) => {
  res.clearCookie(cookieName, { path: '/question' });
  res.redirect('/question/login');
});

router.get('/admin', requireAdmin, async (req, res, next) => {
  try {
    const data = await readStore();
    const editing = req.query.edit ? data.questions.find(item => item.id === Number(req.query.edit)) : null;
    res.render('question/admin', {
      questions: data.questions,
      categories: Object.values(questionCategories),
      editing,
      saved: req.query.saved === '1',
      error: ''
    });
  } catch (error) {
    next(error);
  }
});

router.post('/admin/questions', requireAdmin, async (req, res, next) => {
  const question = normalizeQuestion(req.body);
  if (!validateQuestion(question)) {
    const data = await readStore();
    return res.status(422).render('question/admin', { questions: data.questions, categories: Object.values(questionCategories), editing: question, saved: false, error: 'กรุณาเลือกหมวดและกรอกคำถามกับคำตอบที่ถูกต้อง' });
  }
  try {
    await updateStore(data => {
      data.questions.unshift({ ...question, id: Math.max(0, ...data.questions.map(item => item.id)) + 1, createdAt: new Date().toISOString() });
      return data;
    });
    res.redirect('/question/admin?saved=1');
  } catch (error) {
    next(error);
  }
});

router.post('/admin/questions/:id', requireAdmin, async (req, res, next) => {
  const question = normalizeQuestion(req.body);
  if (!validateQuestion(question)) return res.status(422).send('กรุณากรอกข้อมูลให้ครบ');
  try {
    await updateStore(data => {
      const index = data.questions.findIndex(item => item.id === Number(req.params.id));
      if (index >= 0) data.questions[index] = { ...data.questions[index], ...question, updatedAt: new Date().toISOString() };
      return data;
    });
    res.redirect('/question/admin?saved=1');
  } catch (error) {
    next(error);
  }
});

router.post('/admin/questions/:id/toggle', requireAdmin, async (req, res, next) => {
  try {
    await updateStore(data => {
      const question = data.questions.find(item => item.id === Number(req.params.id));
      if (question) question.active = !question.active;
      return data;
    });
    res.redirect('/question/admin');
  } catch (error) {
    next(error);
  }
});

router.post('/admin/questions/:id/delete', requireAdmin, async (req, res, next) => {
  try {
    await updateStore(data => {
      data.questions = data.questions.filter(item => item.id !== Number(req.params.id));
      return data;
    });
    res.redirect('/question/admin');
  } catch (error) {
    next(error);
  }
});

router.get('/settings', requireAdmin, async (req, res, next) => {
  try {
    const data = await readStore();
    res.render('question/settings', { settings: data.settings, saved: req.query.saved === '1' });
  } catch (error) {
    next(error);
  }
});

router.post('/settings', requireAdmin, async (req, res, next) => {
  try {
    await updateStore(data => {
      data.settings = {
        title: String(req.body.title || '').trim() || 'ภารกิจพิชิตวิทยาศาสตร์',
        subtitle: String(req.body.subtitle || '').trim(),
        welcomeTitle: String(req.body.welcomeTitle || '').trim() || 'พร้อมออกเดินทางสู่โลกวิทยาศาสตร์หรือยัง?',
        welcomeText: String(req.body.welcomeText || '').trim(),
        timeLimit: Math.max(5, Math.min(300, Number.parseInt(req.body.timeLimit, 10) || 30)),
        questionsPerRound: Math.max(1, Math.min(100, Number.parseInt(req.body.questionsPerRound, 10) || 10)),
        randomize: req.body.randomize === 'on',
        showProgress: req.body.showProgress === 'on',
        accentColor: /^#[0-9a-f]{6}$/i.test(req.body.accentColor || '') ? req.body.accentColor : '#6c4cff'
      };
      return data;
    });
    res.redirect('/question/settings?saved=1');
  } catch (error) {
    next(error);
  }
});

module.exports = router;

const express = require('express');
const router = express.Router();
const multer = require('multer');
const adminController = require('../controllers/admin.controller');
const { adminAuthMiddleware, adminRoleMiddleware } = require('../utils/adminAuth');

const upload = multer({
  storage: multer.memoryStorage(),
  limits: {
    fileSize: 8 * 1024 * 1024
  }
});

// Login / logout routes
router.get('/login', adminController.showLogin);
router.post('/login', adminController.login);
router.get('/logout', adminController.logout);

// Apply auth to all remaining admin pages and API routes
router.use(adminAuthMiddleware);

// Page routes (render views)
router.get('/questions', (req, res) => {
  const isAssistant = res.locals.adminUser?.role === 'assistant';
  const viewName = isAssistant ? 'admin/questions-assistant' : 'admin/questions';
  res.render(viewName, { title: 'จัดการคำถาม', adminUser: res.locals.adminUser });
});

// Question assistant role can only add questions
router.post('/api/questions', adminController.addQuestion);
router.get('/api/questions', adminController.getQuestions);
router.put('/api/questions/:id', adminController.updateQuestion);
router.delete('/api/questions/:id', adminController.deleteQuestion);

// The following routes are for full admin only
router.use(adminRoleMiddleware(['admin']));

router.get('/codes', (req, res) => {
  res.render('admin/codes', { title: 'จัดการรหัส', adminUser: res.locals.adminUser });
});

router.get('/', (req, res) => {
  res.render('admin/dashboard', { title: 'Dashboard Admin', adminUser: res.locals.adminUser });
});

// Conversion dashboard
router.get('/conversion', adminController.renderConversion);

// Dashboard
router.get('/api/dashboard', adminController.getDashboard);

// Code management
router.post('/api/codes/generate', adminController.generateCodes);
router.get('/api/codes', adminController.getCodes);
router.get('/api/codes/stats', adminController.getCodeStats);
router.post('/api/codes/mark-expired', adminController.markExpiredCodes);
router.post('/api/codes/clear', adminController.clearCodes);
router.post('/api/history/clear', adminController.clearPlayerHistory);
router.delete('/api/codes/:id', adminController.deleteCode);

// Question management
router.post('/api/questions/import-docx', upload.single('file'), adminController.importQuestionsFromDocx);
router.post('/api/questions/import-text', adminController.importQuestionsFromText);
router.get('/api/questions/stats', adminController.getQuestionStats);
router.get('/api/game-settings', adminController.getGameSettings);
router.put('/api/game-settings', adminController.updateGameSettings);

// Statistics
router.get('/api/stats', adminController.getStats);

module.exports = router;

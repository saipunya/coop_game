const express = require('express');
const router = express.Router();
const adminController = require('../controllers/admin.controller');
const { adminAuthMiddleware } = require('../utils/adminAuth');

// Login / logout routes
router.get('/login', adminController.showLogin);
router.post('/login', adminController.login);
router.get('/logout', adminController.logout);

// Apply auth to all remaining admin pages and API routes
router.use(adminAuthMiddleware);

// Page routes (render views)
router.get('/questions', (req, res) => {
  res.render('admin/questions', { title: 'จัดการคำถาม', adminUser: res.locals.adminUser });
});

router.get('/codes', (req, res) => {
  res.render('admin/codes', { title: 'จัดการรหัส', adminUser: res.locals.adminUser });
});

router.get('/', (req, res) => {
  res.render('admin/dashboard', { title: 'Dashboard Admin', adminUser: res.locals.adminUser });
});

// Dashboard
router.get('/api/dashboard', adminController.getDashboard);

// Code management
router.post('/api/codes/generate', adminController.generateCodes);
router.get('/api/codes', adminController.getCodes);
router.get('/api/codes/stats', adminController.getCodeStats);
router.post('/api/codes/mark-expired', adminController.markExpiredCodes);

// Question management
router.post('/api/questions', adminController.addQuestion);
router.get('/api/questions', adminController.getQuestions);
router.put('/api/questions/:id', adminController.updateQuestion);
router.delete('/api/questions/:id', adminController.deleteQuestion);
router.get('/api/questions/stats', adminController.getQuestionStats);

// Statistics
router.get('/api/stats', adminController.getStats);

module.exports = router;

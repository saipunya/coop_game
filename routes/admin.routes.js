const express = require('express');
const router = express.Router();
const adminController = require('../controllers/admin.controller');

// Middleware for admin authentication (basic for now)
const adminAuth = (req, res, next) => {
  // Skip auth for page renders (use session/cookie in production)
  if (req.accepts('html')) {
    return next();
  }
  
  const authHeader = req.headers.authorization;
  
  if (!authHeader) {
    return res.status(401).json({ success: false, message: 'Authentication required' });
  }

  const auth = new Buffer.from(authHeader.split(' ')[1], 'base64').toString().split(':');
  const user = auth[0];
  const pass = auth[1];

  if (user === process.env.ADMIN_USERNAME && pass === process.env.ADMIN_PASSWORD) {
    return next();
  } else {
    return res.status(401).json({ success: false, message: 'Invalid credentials' });
  }
};

// Apply auth to API routes only
router.use('/api', adminAuth);

// Page routes (render views)
router.get('/questions', (req, res) => {
  res.render('admin/questions', { title: 'จัดการคำถาม' });
});

router.get('/codes', (req, res) => {
  res.render('admin/codes', { title: 'จัดการรหัส' });
});

router.get('/', (req, res) => {
  res.render('admin/dashboard', { title: 'Dashboard Admin' });
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

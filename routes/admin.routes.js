const express = require('express');
const router = express.Router();
const adminController = require('../controllers/admin.controller');

// Middleware for admin authentication (basic for now)
const adminAuth = (req, res, next) => {
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

// Apply auth to all admin routes
router.use(adminAuth);

// Dashboard
router.get('/dashboard', adminController.getDashboard);

// Code management
router.post('/codes/generate', adminController.generateCodes);
router.get('/codes', adminController.getCodes);
router.get('/codes/stats', adminController.getCodeStats);
router.post('/codes/mark-expired', adminController.markExpiredCodes);

// Question management
router.post('/questions', adminController.addQuestion);
router.get('/questions', adminController.getQuestions);
router.put('/questions/:id', adminController.updateQuestion);
router.delete('/questions/:id', adminController.deleteQuestion);
router.get('/questions/stats', adminController.getQuestionStats);

// Statistics
router.get('/stats', adminController.getStats);

module.exports = router;

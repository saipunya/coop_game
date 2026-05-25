const express = require('express');
const router = express.Router();
const gameController = require('../controllers/game.controller');
const { adminAuthMiddleware, adminRoleMiddleware } = require('../utils/adminAuth');

// Default redirect to start page
router.get('/', (req, res) => {
  res.redirect('/coopgame/game/start');
});

// Start page
router.get('/start', gameController.renderStart);
// Onboarding quickstart (post-click)
router.get('/onboarding', gameController.renderOnboarding);

// Verify code API
router.post('/verify-code', gameController.verifyCode);
router.post('/api/admin/start', adminAuthMiddleware, adminRoleMiddleware(['super_admin', 'room_admin']), gameController.startAdminGame);

// Play page
router.get('/play', gameController.renderPlay);

// Get current question API
router.get('/api/question', gameController.getCurrentQuestion);

// Save player info before starting questions
router.post('/api/player-info', gameController.savePlayerInfo);

// Submit answer API
router.post('/api/answer', gameController.submitAnswer);

// Finish page
router.get('/finish', gameController.renderFinish);

// Finish game API
router.post('/api/finish', gameController.finishGame);

// Leaderboard page
router.get('/leaderboard', gameController.renderLeaderboard);

// Leaderboard API
router.get('/api/leaderboard', gameController.getLeaderboard);


module.exports = router;

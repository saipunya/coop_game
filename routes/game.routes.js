const express = require('express');
const router = express.Router();
const gameController = require('../controllers/game.controller');

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

// Play page
router.get('/play', gameController.renderPlay);

// Get current question API
router.get('/api/question', gameController.getCurrentQuestion);

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
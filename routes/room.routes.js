const express = require('express');
const router = express.Router({ mergeParams: true });
const gameController = require('../controllers/game.controller');
const { verifyCodeAttemptLimiter } = require('../utils/codeAttemptLimiter');

router.get('/:roomSlug', gameController.renderRoomStart);
router.post('/:roomSlug/verify-code', verifyCodeAttemptLimiter, gameController.verifyRoomCode);
router.get('/:roomSlug/play', gameController.renderPlay);
router.get('/:roomSlug/leaderboard', gameController.renderRoomLeaderboard);
router.get('/:roomSlug/api/leaderboard', gameController.getRoomLeaderboard);

module.exports = router;

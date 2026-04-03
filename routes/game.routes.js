const express = require('express');
const router = express.Router();
const gameController = require('../controllers/game.controller');

router.get('/', gameController.showStartPage);
router.get('/play', gameController.showPlayPage);
router.post('/api/start', gameController.startGame);

module.exports = router;

const gameService = require('../services/game.service');
const { success, error, validationError, notFound } = require('../utils/response');
const logger = require('../utils/logger');

class GameController {
  // Render start page
  async renderStart(req, res) {
    res.render('game/start', { title: 'เริ่มเกม' });
  }

  // Verify game code
  async verifyCode(req, res) {
    try {
      const { code } = req.body;

      console.log('[DEBUG] Verify code request:', { code });

      if (!code) {
        console.log('[DEBUG] No code provided');
        return validationError(res, [{ msg: 'กรุณากรอกรหัส' }]);
      }

      const result = await gameService.verifyCode(code.trim().toUpperCase());
      
      console.log('[DEBUG] Verify code result:', result);
      
      if (result.success) {
        success(res, { attemptId: result.attemptId, totalQuestions: result.totalQuestions }, 'เริ่มเกมสำเร็จ');
      } else {
        console.log('[DEBUG] Verify failed:', result.message);
        error(res, result.message, 400);
      }
    } catch (error) {
      console.error('[ERROR] Error verifying code:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Render play page
  async renderPlay(req, res) {
    const { attemptId } = req.query;

    console.log('[DEBUG] Render play, attemptId:', attemptId);

    if (!attemptId || isNaN(attemptId)) {
      console.log('[DEBUG] Invalid attemptId');
      return res.redirect('/coopgame/game/start');
    }

    const attempt = await gameService.getAttempt(parseInt(attemptId));

    console.log('[DEBUG] Attempt:', attempt);

    if (!attempt || attempt.status !== 'in_progress') {
      console.log('[DEBUG] Invalid attempt status');
      return res.redirect('/coopgame/game/start');
    }

    res.render('game/play', { 
      title: 'เล่นเกม',
      attemptId: attemptId
    });
  }

  // Get current question (API)
  async getCurrentQuestion(req, res) {
    try {
      const { attemptId } = req.query;

      if (!attemptId) {
        return validationError(res, [{ msg: 'Missing attemptId' }]);
      }

      const question = await gameService.getCurrentQuestion(parseInt(attemptId));

      if (!question) {
        return error(res, 'No question available', 400);
      }

      success(res, { question }, 'Question retrieved');
    } catch (error) {
      logger.error('Error getting question:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Submit answer
  async submitAnswer(req, res) {
    try {
      const { attemptId, questionId, answer, responseTime } = req.body;

      console.log('[DEBUG] Submit answer:', { attemptId, questionId, answer, responseTime });

      if (!attemptId || !questionId || !answer || responseTime === undefined) {
        console.log('[DEBUG] Missing required fields');
        return validationError(res, [{ msg: 'Missing required fields' }]);
      }

      const result = await gameService.submitAnswer(
        parseInt(attemptId),
        parseInt(questionId),
        answer,
        parseInt(responseTime)
      );

      console.log('[DEBUG] Submit result:', result);

      success(res, result, 'Answer submitted');
    } catch (error) {
      console.error('[ERROR] Error submitting answer:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Render finish page
  async renderFinish(req, res) {
    try {
      const { attemptId } = req.query;

      console.log('[DEBUG] Render finish, attemptId:', attemptId);

      if (!attemptId) {
        return res.redirect('/coopgame/game/start');
      }

      const attempt = await gameService.getAttempt(parseInt(attemptId));

      console.log('[DEBUG] Attempt:', attempt);

      if (!attempt) {
        return res.redirect('/coopgame/game/start');
      }

      // Get total questions count
      const attemptQuestionModel = require('../models/attemptQuestion.model');
      const totalQuestions = await attemptQuestionModel.countByAttemptId(parseInt(attemptId));

      console.log('[DEBUG] Total questions:', totalQuestions);

      res.render('game/finish', { 
        title: 'สรุปผล',
        attemptId: attemptId,
        score: attempt.score,
        totalQuestions: totalQuestions,
        totalTime: attempt.total_time
      });
    } catch (error) {
      console.error('[ERROR] Error rendering finish:', error);
      res.redirect('/coopgame/game/start');
    }
  }

  // Finish game and save player info
  async finishGame(req, res) {
    try {
      const { attemptId, playerName, phoneNumber } = req.body;

      console.log('[DEBUG] Finish game request:', { attemptId, playerName, phoneNumber });

      if (!attemptId || !playerName || !phoneNumber) {
        console.log('[DEBUG] Missing required fields');
        return validationError(res, [{ msg: 'กรุณากรอกข้อมูลให้ครบ' }]);
      }

      const result = await gameService.finishGame(
        parseInt(attemptId),
        playerName.trim(),
        phoneNumber.trim()
      );

      console.log('[DEBUG] Finish game result:', result);

      success(res, result, 'บันทึกข้อมูลสำเร็จ');
    } catch (error) {
      console.error('[ERROR] Error finishing game:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Render leaderboard
  async renderLeaderboard(req, res) {
    const mode = req.query.mode || 'mobile';
    res.render('game/leaderboard', { 
      title: 'Leaderboard',
      mode: mode
    });
  }

  // Get leaderboard (API)
  async getLeaderboard(req, res) {
    try {
      console.log('[DEBUG] Get leaderboard request');
      const { limit, offset } = req.query;
      const result = await gameService.getLeaderboard(
        parseInt(limit) || 50,
        parseInt(offset) || 0
      );
      console.log('[DEBUG] Leaderboard service result:', result);
      success(res, result.leaderboard, 'Leaderboard retrieved');
    } catch (error) {
      console.error('[ERROR] Error getting leaderboard:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }
}

module.exports = new GameController();


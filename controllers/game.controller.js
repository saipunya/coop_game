
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

      if (!code) {
        return validationError(res, [{ msg: 'กรุณากรอกรหัส' }]);
      }

      const result = await gameService.verifyCode(code.trim().toUpperCase());
      
      if (result.success) {
        success(res, { attemptId: result.attemptId, totalQuestions: result.totalQuestions }, 'เริ่มเกมสำเร็จ');
      } else {
        error(res, result.message, 400);
      }
    } catch (error) {
      logger.error('Error verifying code:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Render play page
  async renderPlay(req, res) {
    const { attemptId } = req.query;

    if (!attemptId) {
      return res.redirect('/game/start');
    }

    const attempt = await gameService.getAttempt(parseInt(attemptId));

    if (!attempt || attempt.status !== 'in_progress') {
      return res.redirect('/game/start');
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

      if (!attemptId || !questionId || !answer || responseTime === undefined) {
        return validationError(res, [{ msg: 'Missing required fields' }]);
      }

      const result = await gameService.submitAnswer(
        parseInt(attemptId),
        parseInt(questionId),
        answer,
        parseInt(responseTime)
      );

      success(res, result, 'Answer submitted');
    } catch (error) {
      logger.error('Error submitting answer:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Render finish page
  async renderFinish(req, res) {
    const { attemptId } = req.query;

    if (!attemptId) {
      return res.redirect('/game/start');
    }

    const attempt = await gameService.getAttempt(parseInt(attemptId));

    if (!attempt) {
      return res.redirect('/game/start');
    }

    // Get total questions count
    const attemptQuestionModel = require('../models/attemptQuestion.model');
    const totalQuestions = await attemptQuestionModel.countByAttemptId(parseInt(attemptId));

    res.render('game/finish', { 
      title: 'สรุปผล',
      attemptId: attemptId,
      score: attempt.score,
      totalQuestions: totalQuestions,
      totalTime: attempt.total_time
    });
  }

  // Finish game and save player info
  async finishGame(req, res) {
    try {
      const { attemptId, playerName, phoneNumber } = req.body;

      if (!attemptId || !playerName || !phoneNumber) {
        return validationError(res, [{ msg: 'กรุณากรอกข้อมูลให้ครบ' }]);
      }

      const result = await gameService.finishGame(
        parseInt(attemptId),
        playerName.trim(),
        phoneNumber.trim()
      );

      success(res, result, 'บันทึกข้อมูลสำเร็จ');
    } catch (error) {
      logger.error('Error finishing game:', error);
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
      const { limit, offset } = req.query;
      const result = await gameService.getLeaderboard(
        parseInt(limit) || 50,
        parseInt(offset) || 0
      );
      success(res, result, 'Leaderboard retrieved');
    } catch (error) {
      logger.error('Error getting leaderboard:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }
}

module.exports = new GameController();


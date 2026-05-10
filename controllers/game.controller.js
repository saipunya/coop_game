
const gameService = require('../services/game.service');
const { success, error, validationError, notFound } = require('../utils/response');
const logger = require('../utils/logger');

const ANONYMOUS_PLAYER_NAME = 'ไม่ประสงค์จะออกนาม';

class GameController {
  // Render start page
  async renderStart(req, res) {
    try{
      const query = req.query || {};
      res.render('game/start', { title: 'เริ่มเกม', query });
    }catch(err){
      logger.error('Error rendering start:', err);
      res.render('game/start', { title: 'เริ่มเกม' });
    }
  }

  // Render onboarding page (post-click quickstart)
  async renderOnboarding(req, res) {
    try {
      const query = req.query || {};
      res.render('onboarding', { title: 'เริ่มต้นใช้งาน', query });
    } catch (err) {
      logger.error('Error rendering onboarding:', err);
      return res.redirect('/coopgame/game/start');
    }
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
      console.error('[ERROR] Error verifying code:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Start game directly for admin users
  async startAdminGame(req, res) {
    try {
      const result = await gameService.startAdminGame();

      if (!result.success) {
        return error(res, result.message || 'ไม่สามารถเริ่มเกมได้', 400);
      }

      success(
        res,
        { attemptId: result.attemptId, totalQuestions: result.totalQuestions },
        'เริ่มเกมสำหรับผู้ดูแลสำเร็จ'
      );
    } catch (err) {
      logger.error('Error starting admin game:', err);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Render play page
  async renderPlay(req, res) {
    try {
      const { attemptId } = req.query;

      if (!attemptId || isNaN(attemptId)) {
        return res.redirect('/coopgame/game/start');
      }

      const attempt = await gameService.getAttemptWithCode(parseInt(attemptId));

      if (!attempt || attempt.status !== 'in_progress') {
        return res.redirect('/coopgame/game/start');
      }

      const isAdminPlay = Boolean(
        typeof attempt.game_code === 'string' && attempt.game_code.startsWith('ADM')
      );

      res.render('game/play', {
        title: 'เล่นเกม',
        attemptId: attemptId,
        playerName: attempt.player_name || '',
        phoneNumber: attempt.phone_number || '',
        isAdminPlay
      });
    } catch (err) {
      logger.error('Error rendering play:', err);
      return res.redirect('/coopgame/game/start');
    }
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

      if (question.playerInfoRequired) {
        return error(res, 'กรุณากรอกชื่อและเบอร์โทรศัพท์ก่อนเริ่มเกม', 400, 'PLAYER_INFO_REQUIRED');
      }

      success(res, { question }, 'Question retrieved');
    } catch (error) {
      logger.error('Error getting question:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  async savePlayerInfo(req, res) {
    try {
      const { attemptId, playerName, phoneNumber } = req.body;

      if (!attemptId) {
        return validationError(res, [{ msg: 'Missing attemptId' }]);
      }

      const result = await gameService.savePlayerInfo(
        parseInt(attemptId, 10),
        playerName,
        phoneNumber
      );

      if (!result.success) {
        return validationError(res, [{ msg: result.message }]);
      }

      success(res, result.player, 'Player info saved');
    } catch (error) {
      logger.error('Error saving player info:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Submit answer
  async submitAnswer(req, res) {
    try {
      const { attemptId, questionId, answer, responseTime } = req.body;

      if (!attemptId || !questionId || responseTime === undefined) {
        return validationError(res, [{ msg: 'Missing required fields' }]);
      }

      if (answer && !['A', 'B', 'C', 'D'].includes(String(answer).toUpperCase())) {
        return validationError(res, [{ msg: 'Invalid answer' }]);
      }

      const result = await gameService.submitAnswer(
        parseInt(attemptId),
        parseInt(questionId),
        answer ? String(answer).toUpperCase() : null,
        parseInt(responseTime)
      );

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

      if (!attemptId) {
        return res.redirect('/coopgame/game/start');
      }

      const attempt = await gameService.getAttempt(parseInt(attemptId));

      if (!attempt) {
        return res.redirect('/coopgame/game/start');
      }

      // Get total questions count
      const attemptQuestionModel = require('../models/attemptQuestion.model');
      const totalQuestions = await attemptQuestionModel.countByAttemptId(parseInt(attemptId));

      const attemptWithCode = await gameService.getAttemptWithCode(parseInt(attemptId));
      const isAdminPlay = Boolean(
        attemptWithCode && typeof attemptWithCode.game_code === 'string' && attemptWithCode.game_code.startsWith('ADM')
      );

      res.render('game/finish', { 
        title: 'สรุปผล',
        attemptId: attemptId,
        score: attempt.score,
        totalQuestions: totalQuestions,
        totalTime: attempt.total_time,
        playerName: attempt.player_name || '',
        phoneNumber: attempt.phone_number || '',
        isAdminPlay,
        rank: !isAdminPlay && attempt.player_name && attempt.finished_at
          ? await gameService.calculateRank(attempt.score, attempt.total_time, attempt.finished_at)
          : null
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
      const normalizedPlayerName = typeof playerName === 'string' ? playerName.trim() : '';
      const normalizedPhoneNumber = typeof phoneNumber === 'string' ? phoneNumber.trim() : '';

      if (!attemptId) {
        return validationError(res, [{ msg: 'ไม่พบข้อมูลการเล่นเกม' }]);
      }

      const result = await gameService.finishGame(
        parseInt(attemptId),
        normalizedPlayerName || ANONYMOUS_PLAYER_NAME,
        normalizedPhoneNumber
      );

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
      const { limit, offset } = req.query;
      const result = await gameService.getLeaderboard(
        parseInt(limit) || 50,
        parseInt(offset) || 0
      );
      success(res, result.leaderboard, 'Leaderboard retrieved');
    } catch (error) {
      console.error('[ERROR] Error getting leaderboard:', error);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }
}

module.exports = new GameController();

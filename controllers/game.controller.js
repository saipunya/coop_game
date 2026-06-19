
const gameService = require('../services/game.service');
const roomModel = require('../models/room.model');
const { success, error, validationError, notFound } = require('../utils/response');
const logger = require('../utils/logger');
const {
  recordFailedVerifyCode,
  resetVerifyCodeAttempts
} = require('../utils/codeAttemptLimiter');

const ANONYMOUS_PLAYER_NAME = 'ไม่ประสงค์จะออกนาม';

function sendFailedCodeResponse(req, res, message) {
  const attemptEntry = recordFailedVerifyCode(req);

  if (attemptEntry.lockedUntil && Date.now() < attemptEntry.lockedUntil) {
    const retryAfterSeconds = Math.ceil((attemptEntry.lockedUntil - Date.now()) / 1000);
    res.set('Retry-After', String(retryAfterSeconds));
    return res.status(429).json({
      success: false,
      message: `ใส่รหัสผิดหลายครั้งเกินไป กรุณารอ ${Math.ceil(retryAfterSeconds / 60)} นาที แล้วลองใหม่`,
      errorCode: 'CODE_ATTEMPTS_LOCKED',
      retryAfterSeconds
    });
  }

  return error(res, message, 400);
}

function getClientIp(req) {
  const forwardedFor = req.headers['x-forwarded-for'];
  if (typeof forwardedFor === 'string' && forwardedFor.trim()) {
    return forwardedFor.split(',')[0].trim();
  }

  return req.ip || req.socket?.remoteAddress || 'unknown';
}

function buildAttemptUrls(attempt) {
  const roomSlug = attempt?.room_slug;

  if (!roomSlug) {
    return {
      startUrl: '/coopgame/game/start',
      playUrl: '/coopgame/game/play',
      finishUrl: '/coopgame/game/finish',
      leaderboardUrl: '/coopgame/game/leaderboard'
    };
  }

  return {
    startUrl: `/coopgame/r/${roomSlug}`,
    playUrl: `/coopgame/r/${roomSlug}/play`,
    finishUrl: `/coopgame/game/finish`,
    leaderboardUrl: `/coopgame/r/${roomSlug}/leaderboard`
  };
}

class GameController {
  // Render start page
  async renderStart(req, res) {
    try{
      const defaultRoom = await roomModel.findDefault();
      if (defaultRoom) {
        return res.redirect(`/coopgame/r/${defaultRoom.slug}`);
      }

      const query = req.query || {};
      const gameSettings = await gameService.getGameSettings();
      res.render('game/start', {
        title: 'เริ่มเกม',
        query,
        gameEnabled: gameSettings.gameEnabled !== false
      });
    }catch(err){
      logger.error('Error rendering start:', err);
      res.render('game/start', { title: 'เริ่มเกม', gameEnabled: true });
    }
  }

  async renderRoomStart(req, res) {
    try {
      const query = req.query || {};
      const room = await roomModel.findBySlug(req.params.roomSlug);

      if (!room || room.status !== 'active') {
        return res.status(404).render('game/start', {
          title: 'ไม่พบห้อง',
          query,
          gameEnabled: false,
          room: room || null,
          verifyUrl: '#',
          leaderboardUrl: '/coopgame/game/leaderboard',
          playBaseUrl: '/coopgame/game/play'
        });
      }

      const gameSettings = await gameService.getGameSettings(room.id);
      res.render('game/start', {
        title: room.name,
        query,
        room,
        gameEnabled: gameSettings.gameEnabled !== false,
        verifyUrl: `/coopgame/r/${room.slug}/verify-code`,
        leaderboardUrl: `/coopgame/r/${room.slug}/leaderboard`,
        leaderboardApiUrl: `/coopgame/r/${room.slug}/api/leaderboard`,
        playBaseUrl: `/coopgame/r/${room.slug}/play`
      });
    } catch (err) {
      logger.error('Error rendering room start:', err);
      res.redirect('/coopgame/game/start');
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
        return sendFailedCodeResponse(req, res, 'กรุณากรอกรหัส');
      }

      const result = await gameService.verifyCode(String(code).trim(), null, getClientIp(req));

      if (result.success) {
        resetVerifyCodeAttempts(req);
        success(res, { attemptId: result.attemptId, totalQuestions: result.totalQuestions }, 'เริ่มเกมสำเร็จ');
      } else {
        sendFailedCodeResponse(req, res, result.message);
      }
    } catch (err) {
      console.error('[ERROR] Error verifying code:', err);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  async verifyRoomCode(req, res) {
    try {
      const { code } = req.body;
      const room = await roomModel.findBySlug(req.params.roomSlug);

      if (!room || room.status !== 'active') {
        return error(res, 'ไม่พบห้องหรือห้องถูกปิดใช้งาน', 404);
      }

      if (!code) {
        return sendFailedCodeResponse(req, res, 'กรุณากรอกรหัส');
      }

      const result = await gameService.verifyCode(String(code).trim(), room.id, getClientIp(req));

      if (result.success) {
        resetVerifyCodeAttempts(req);
        success(res, { attemptId: result.attemptId, totalQuestions: result.totalQuestions }, 'เริ่มเกมสำเร็จ');
      } else {
        sendFailedCodeResponse(req, res, result.message);
      }
    } catch (err) {
      logger.error('Error verifying room code:', err);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }

  // Start game directly for admin users
  async startAdminGame(req, res) {
    try {
      const roomId = res.locals.adminUser?.role === 'room_admin'
        ? res.locals.adminUser.roomId
        : (parseInt(req.body.roomId || req.query.roomId, 10) || null);
      const result = await gameService.startAdminGame(roomId);

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
        return res.redirect(req.params.roomSlug ? `/coopgame/r/${req.params.roomSlug}` : '/coopgame/game/start');
      }

      const attempt = await gameService.getAttemptWithCode(parseInt(attemptId));

      if (!attempt) {
        return res.redirect(req.params.roomSlug ? `/coopgame/r/${req.params.roomSlug}` : '/coopgame/game/start');
      }

      if (req.params.roomSlug && attempt.room_slug !== req.params.roomSlug) {
        return res.redirect(`/coopgame/r/${req.params.roomSlug}`);
      }

      const isAdminPlay = Boolean(
        typeof attempt.game_code === 'string' && attempt.game_code.startsWith('ADM')
      );

      let adminPreviewSettings = {
        randomQuestionOrderEnabled: true,
        randomAnswerOrderEnabled: true
      };

      if (isAdminPlay) {
        try {
          const gameSettings = await gameService.getGameSettings(attempt.room_id);
          adminPreviewSettings = {
            randomQuestionOrderEnabled: gameSettings.randomQuestionOrderEnabled !== false,
            randomAnswerOrderEnabled: gameSettings.randomAnswerOrderEnabled !== false
          };
        } catch (settingsError) {
          logger.warn('Failed to load game settings for admin-led badges:', settingsError);
        }
      }

      if (attempt.status !== 'in_progress') {
        const urls = buildAttemptUrls(attempt);
        return res.redirect(isAdminPlay ? '/coopgame/admin' : urls.startUrl);
      }

      const urls = buildAttemptUrls(attempt);

      res.set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
      res.set('Pragma', 'no-cache');
      res.set('Expires', '0');

      res.render('game/play', {
        title: 'เล่นเกม',
        attemptId: attemptId,
        playerName: attempt.player_name || '',
        phoneNumber: attempt.phone_number || '',
        isAdminPlay,
        adminPreviewSettings,
        startUrl: isAdminPlay ? '/coopgame/admin' : urls.startUrl,
        finishUrl: `${urls.finishUrl}?attemptId=${attemptId}`,
        leaderboardUrl: urls.leaderboardUrl
      });
    } catch (err) {
      logger.error('Error rendering play:', err);
      return res.redirect(req.params.roomSlug ? `/coopgame/r/${req.params.roomSlug}` : '/coopgame/game/start');
    }
  }

  async renderRoomPlay(req, res) {
    return this.renderPlay(req, res);
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

      if (question.gameDisabled) {
        return error(
          res,
          question.message || 'ระบบเกมปิดอยู่ชั่วคราว กรุณาติดต่อผู้ดูแลระบบ',
          403,
          'GAME_DISABLED'
        );
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

      if (!result.success) {
        return error(res, result.message || 'ไม่สามารถบันทึกคำตอบได้', 400);
      }

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
      const urls = buildAttemptUrls(attemptWithCode);

      res.render('game/finish', { 
        title: 'สรุปผล',
        attemptId: attemptId,
        score: attempt.score,
        totalQuestions: totalQuestions,
        totalTime: attempt.total_time,
        playerName: attempt.player_name || '',
        phoneNumber: attempt.phone_number || '',
        isAdminPlay,
        startUrl: isAdminPlay ? '/coopgame/admin' : urls.startUrl,
        leaderboardUrl: urls.leaderboardUrl,
        rank: attempt.player_name && attempt.finished_at
          ? await gameService.calculateRank(attempt.score, attempt.total_time, attempt.finished_at, attempt.room_id)
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

  async renderRoomLeaderboard(req, res) {
    const mode = req.query.mode || 'mobile';
    const room = await roomModel.findBySlug(req.params.roomSlug);

    if (!room || room.status !== 'active') {
      return res.redirect('/coopgame/game/start');
    }

    res.render('game/leaderboard', {
      title: `Leaderboard - ${room.name}`,
      mode,
      room,
      leaderboardApiUrl: `/coopgame/r/${room.slug}/api/leaderboard`
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

  async getRoomLeaderboard(req, res) {
    try {
      const { limit, offset } = req.query;
      const room = await roomModel.findBySlug(req.params.roomSlug);

      if (!room || room.status !== 'active') {
        return error(res, 'ไม่พบห้องหรือห้องถูกปิดใช้งาน', 404);
      }

      const result = await gameService.getLeaderboard(
        parseInt(limit) || 50,
        parseInt(offset) || 0,
        room.id
      );
      success(res, result.leaderboard, 'Leaderboard retrieved');
    } catch (err) {
      logger.error('Error getting room leaderboard:', err);
      error(res, 'เกิดข้อผิดพลาด');
    }
  }
}

module.exports = new GameController();

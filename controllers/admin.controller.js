const adminService = require('../services/admin.service');
const { success, error, validationError, notFound } = require('../utils/response');
const {
  clearAdminAuthCookie,
  getAdminSession,
  isAdminAuthenticated,
  setAdminAuthCookie,
  verifyAdminCredentials
} = require('../utils/adminAuth');
const logger = require('../utils/logger');

function isSuperAdmin(user) {
  return user?.role === 'super_admin';
}

function resolveAdminRoomId(req, res) {
  const user = res.locals.adminUser;
  if (user?.role === 'room_admin') {
    return user.roomId;
  }

  const rawRoomId = req.body?.roomId || req.body?.room_id || req.query?.roomId || req.query?.room_id;
  const parsed = parseInt(rawRoomId, 10);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

class AdminController {
  // Show login page
  async showLogin(req, res) {
    if (await isAdminAuthenticated(req)) {
      const session = await getAdminSession(req);
      return res.redirect(session?.role === 'room_admin' ? '/coopgame/admin/questions' : '/coopgame/admin');
    }

    return res.render('admin/login', {
      title: 'Admin Login',
      error: null,
      username: ''
    });
  }

  // Handle login
  async login(req, res) {
    try {
      const username = (req.body.username || '').trim();
      const password = req.body.password || '';

      if (!username || !password) {
        return res.status(400).render('admin/login', {
          title: 'Admin Login',
          error: 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน',
          username
        });
      }

      const user = await verifyAdminCredentials(username, password);

      if (!user) {
        return res.status(401).render('admin/login', {
          title: 'Admin Login',
          error: 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
          username
        });
      }

      setAdminAuthCookie(res, user, req);
      return res.redirect(user.role === 'room_admin' ? '/coopgame/admin/questions' : '/coopgame/admin');
    } catch (err) {
      logger.error('Error logging in admin:', err);
      return res.status(500).render('admin/login', {
        title: 'Admin Login',
        error: 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ',
        username: req.body.username || ''
      });
    }
  }

  // Logout admin
  async logout(req, res) {
    clearAdminAuthCookie(res);
    return res.redirect('/coopgame/admin/login');
  }

  // Get dashboard statistics
  async getDashboard(req, res) {
    try {
      const result = await adminService.getStats(resolveAdminRoomId(req, res));
      success(res, result.stats, 'Dashboard data retrieved');
    } catch (err) {
      logger.error('Error getting dashboard:', err);
      error(res, 'Failed to get dashboard data');
    }
  }

  // Generate game codes
  async generateCodes(req, res) {
    try {
      const { count, expiryHours } = req.body;
      const roomId = resolveAdminRoomId(req, res);

      if (!count || count < 1 || count > 1000) {
        return validationError(res, [{ msg: 'Count must be between 1 and 1000' }]);
      }

      if (!roomId) {
        return validationError(res, [{ msg: 'roomId is required' }]);
      }

      const result = await adminService.generateCodes(count, expiryHours || 24, roomId);
      success(res, result, 'Codes generated successfully');
    } catch (err) {
      logger.error('Error generating codes:', err);

      if (err.code === 'NO_ROOMS_AVAILABLE') {
        return error(
          res,
          'ไม่พบข้อมูลห้อง (rooms) ในระบบ กรุณาตั้งค่าห้องก่อนสร้างรหัส',
          400,
          err.code
        );
      }

      if (err.code === 'NO_CATEGORIES_AVAILABLE') {
        return error(
          res,
          'ไม่พบหมวดคำถาม (question_categories) ในระบบ กรุณาเพิ่มหมวดหมู่ก่อนสร้างรหัส',
          400,
          err.code
        );
      }

      if (err.code === 'ER_NO_REFERENCED_ROW_2' || err.code === 'ER_NO_DEFAULT_FOR_FIELD') {
        return error(
          res,
          'โครงสร้างฐานข้อมูลยังไม่พร้อมสำหรับการสร้างรหัส',
          500,
          err.code,
          err.sqlMessage || err.message
        );
      }

      error(res, 'Failed to generate codes', 500, err.code || null, err.sqlMessage || err.message);
    }
  }

  // Get codes
  async getCodes(req, res) {
    try {
      const { status, limit, offset } = req.query;
      const result = await adminService.getCodes(
        status || null,
        parseInt(limit) || 50,
        parseInt(offset) || 0,
        resolveAdminRoomId(req, res)
      );
      success(res, result, 'Codes retrieved successfully');
    } catch (err) {
      logger.error('Error getting codes:', err);
      error(res, 'Failed to get codes');
    }
  }

  // Get code statistics
  async getCodeStats(req, res) {
    try {
      const result = await adminService.getCodeStats(resolveAdminRoomId(req, res));
      success(res, result.stats, 'Code statistics retrieved');
    } catch (err) {
      logger.error('Error getting code stats:', err);
      error(res, 'Failed to get code statistics');
    }
  }

  // Mark expired codes
  async markExpiredCodes(req, res) {
    try {
      const result = await adminService.markExpiredCodes(resolveAdminRoomId(req, res));
      success(res, { count: result.count }, `Marked ${result.count} codes as expired`);
    } catch (err) {
      logger.error('Error marking expired codes:', err);
      error(res, 'Failed to mark expired codes');
    }
  }

  // Clear removable codes
  async clearCodes(req, res) {
    try {
      const result = await adminService.clearCodes(resolveAdminRoomId(req, res));
      success(
        res,
        { count: result.count, attempts: result.attempts },
        `Cleared ${result.count} codes`
      );
    } catch (err) {
      logger.error('Error clearing codes:', err);
      error(res, 'Failed to clear codes');
    }
  }

  // Clear player history and gameplay stats
  async clearPlayerHistory(req, res) {
    try {
      const result = await adminService.clearPlayerHistory(resolveAdminRoomId(req, res));
      success(res, { count: result.count }, `Cleared ${result.count} game attempts`);
    } catch (err) {
      logger.error('Error clearing player history:', err);
      error(res, 'Failed to clear player history');
    }
  }

  // Import questions from DOCX
  async importQuestionsFromDocx(req, res) {
    try {
      if (!req.file || !req.file.buffer) {
        return validationError(res, [{ msg: 'กรุณาเลือกไฟล์ .docx' }]);
      }

      const filename = (req.file.originalname || '').toLowerCase();
      const allowedMimeTypes = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/octet-stream'
      ];

      if (
        !filename.endsWith('.docx') &&
        !allowedMimeTypes.includes(req.file.mimetype)
      ) {
        return validationError(res, [{ msg: 'รองรับเฉพาะไฟล์ .docx เท่านั้น' }]);
      }

      const result = await adminService.importQuestionsFromDocx(req.file.buffer, resolveAdminRoomId(req, res));

      if (!result.success) {
        return validationError(res, [
          {
            msg: result.message || 'ไม่สามารถนำเข้าคำถามได้'
          },
          ...(result.errors || []).map(message => ({ msg: message }))
        ]);
      }

      return success(
        res,
        {
          importedCount: result.importedCount,
          questionIds: result.questionIds
        },
        `นำเข้าคำถามสำเร็จ ${result.importedCount} ข้อ`
      );
    } catch (err) {
      logger.error('Error importing questions from docx:', err);
      error(res, 'ไม่สามารถนำเข้าคำถามจากไฟล์ Word ได้');
    }
  }

  // Import questions from pasted text
  async importQuestionsFromText(req, res) {
    try {
      const { importText } = req.body;

      if (!importText || !String(importText).trim()) {
        return validationError(res, [{ msg: 'กรุณาวางข้อความคำถามก่อนนำเข้า' }]);
      }

      const result = await adminService.importQuestionsFromText(importText, resolveAdminRoomId(req, res));

      if (!result.success) {
        return validationError(res, [
          {
            msg: result.message || 'ไม่สามารถนำเข้าคำถามได้'
          },
          ...(result.errors || []).map(message => ({ msg: message }))
        ]);
      }

      return success(
        res,
        {
          importedCount: result.importedCount,
          questionIds: result.questionIds
        },
        `นำเข้าคำถามสำเร็จ ${result.importedCount} ข้อ`
      );
    } catch (err) {
      logger.error('Error importing questions from text:', err);
      error(res, 'ไม่สามารถนำเข้าคำถามจากข้อความได้');
    }
  }

  // Delete code
  async deleteCode(req, res) {
    try {
      const id = parseInt(req.params.id, 10);

      if (!id) {
        return validationError(res, [{ msg: 'id is required' }]);
      }

      const result = await adminService.deleteCode(id, resolveAdminRoomId(req, res));

      if (!result.success) {
        const statusCode = result.message === 'Code not found' ? 404 : 400;
        return res.status(statusCode).json({
          success: false,
          message: result.message
        });
      }

      return success(res, { id }, 'Code deleted successfully');
    } catch (err) {
      logger.error('Error deleting code:', err);
      error(res, 'Failed to delete code');
    }
  }

  // Add question
  async addQuestion(req, res) {
    try {
      const {
        questionText,
        optionA,
        optionB,
        optionC,
        optionD,
        correctAnswer,
        difficulty
      } = req.body;

      // Validation
      const errors = [];
      if (!questionText) errors.push({ msg: 'questionText is required' });
      if (!optionA) errors.push({ msg: 'optionA is required' });
      if (!optionB) errors.push({ msg: 'optionB is required' });
      if (!optionC) errors.push({ msg: 'optionC is required' });
      if (!optionD) errors.push({ msg: 'optionD is required' });
      if (!correctAnswer || !['A', 'B', 'C', 'D'].includes(correctAnswer)) {
        errors.push({ msg: 'correctAnswer must be A, B, C, or D' });
      }
      if (!difficulty || !['easy', 'medium', 'hard'].includes(difficulty)) {
        errors.push({ msg: 'difficulty must be easy, medium, or hard' });
      }

      if (errors.length > 0) {
        return validationError(res, errors);
      }

      let timeLimit = adminService.resolveQuestionTimeLimit(difficulty);

      try {
        const gameSettings = await adminService.getGameSettings(resolveAdminRoomId(req, res));
        const configuredTimeLimit = parseInt(gameSettings?.settings?.timeLimits?.[difficulty], 10);
        if (Number.isInteger(configuredTimeLimit) && configuredTimeLimit >= 5 && configuredTimeLimit <= 300) {
          timeLimit = configuredTimeLimit;
        }
      } catch (settingsError) {
        logger.warn('Unable to load game settings while adding question, using default time limit:', settingsError);
      }

      const questionData = {
        questionText,
        optionA,
        optionB,
        optionC,
        optionD,
        correctAnswer,
        difficulty,
        timeLimit
      };

      const roomId = resolveAdminRoomId(req, res);
      if (!roomId) {
        return validationError(res, [{ msg: 'roomId is required' }]);
      }
      const result = await adminService.addQuestion({
        ...questionData,
        createdBy: res.locals.adminUser?.username || null
      }, roomId);
      success(res, { questionId: result.questionId }, 'Question added successfully');
    } catch (err) {
      logger.error('Error adding question:', err);

      if (err && err.code === 'NO_ROOMS_AVAILABLE') {
        return error(res, 'ยังไม่มีห้องในระบบ กรุณาตั้งค่าห้องก่อนเพิ่มคำถาม');
      }

      if (err && err.code === 'ER_NO_REFERENCED_ROW_2') {
        return error(res, 'ไม่พบห้องที่อ้างอิงอยู่ในระบบ กรุณาตรวจสอบข้อมูลห้อง');
      }

      error(res, 'Failed to add question');
    }
  }

  // Get questions
  async getQuestions(req, res) {
    try {
      const { difficulty } = req.query;
      const roomId = resolveAdminRoomId(req, res);
      const result = await adminService.getQuestions(difficulty || null, roomId);
      success(res, result.questions, 'Questions retrieved successfully');
    } catch (err) {
      logger.error('Error getting questions:', err);
      error(res, 'Failed to get questions');
    }
  }

  // Update question
  async updateQuestion(req, res) {
    try {
      const { id } = req.params;
      const errors = [];

      if (!req.body.difficulty || !['easy', 'medium', 'hard'].includes(req.body.difficulty)) {
        errors.push({ msg: 'difficulty must be easy, medium, or hard' });
      }

      if (errors.length > 0) {
        return validationError(res, errors);
      }

      const questionData = {
        ...req.body,
        timeLimit: (await adminService.getGameSettings(resolveAdminRoomId(req, res))).settings.timeLimits[req.body.difficulty]
      };

      const result = await adminService.updateQuestion(parseInt(id), questionData, resolveAdminRoomId(req, res));
      
      if (!result.success) {
        return notFound(res, 'Question not found');
      }

      success(res, null, 'Question updated successfully');
    } catch (err) {
      logger.error('Error updating question:', err);
      error(res, 'Failed to update question');
    }
  }

  // Delete question
  async deleteQuestion(req, res) {
    try {
      const { id } = req.params;
      const result = await adminService.deleteQuestion(parseInt(id), resolveAdminRoomId(req, res));

      if (!result.success) {
        return notFound(res, 'Question not found');
      }

      success(
        res,
        {
          deletedAnswers: result.deletedAnswers,
          deletedAttemptQuestions: result.deletedAttemptQuestions
        },
        'Question permanently deleted successfully'
      );
    } catch (err) {
      logger.error('Error deleting question:', err);
      error(res, 'Failed to delete question');
    }
  }

  async deleteQuestionsBulk(req, res) {
    try {
      const ids = Array.isArray(req.body.ids) ? req.body.ids : [];
      const normalizedIds = ids
        .map(id => parseInt(id, 10))
        .filter(id => Number.isInteger(id) && id > 0);

      if (normalizedIds.length === 0) {
        return validationError(res, [{ msg: 'ids must contain at least one question id' }]);
      }

      if (normalizedIds.length > 200) {
        return validationError(res, [{ msg: 'ลบได้ครั้งละไม่เกิน 200 คำถาม' }]);
      }

      const result = await adminService.deleteQuestionsBulk(
        normalizedIds,
        resolveAdminRoomId(req, res)
      );

      if (!result.success) {
        return notFound(res, result.message || 'Questions not found');
      }

      success(
        res,
        {
          deletedQuestions: result.deletedQuestions,
          deletedAnswers: result.deletedAnswers,
          deletedAttemptQuestions: result.deletedAttemptQuestions
        },
        'Questions permanently deleted successfully'
      );
    } catch (err) {
      logger.error('Error bulk deleting questions:', err);
      error(res, 'Failed to delete questions');
    }
  }

  // Get question statistics
  async getQuestionStats(req, res) {
    try {
      const result = await adminService.getQuestionStats(resolveAdminRoomId(req, res));
      success(res, result.stats, 'Question statistics retrieved');
    } catch (err) {
      logger.error('Error getting question stats:', err);
      error(res, 'Failed to get question statistics');
    }
  }

  async getGameSettings(req, res) {
    try {
      const result = await adminService.getGameSettings(resolveAdminRoomId(req, res));
      success(res, result.settings, 'Game settings retrieved successfully');
    } catch (err) {
      logger.error('Error getting game settings:', err);
      error(res, 'Failed to get game settings');
    }
  }

  async updateGameSettings(req, res) {
    try {
      const roomId = resolveAdminRoomId(req, res);
      if (!roomId) {
        return validationError(res, [{ msg: 'roomId is required' }]);
      }
      const result = await adminService.updateGameSettings(req.body, roomId);

      if (!result.success) {
        return validationError(res, [{ msg: result.message }]);
      }

      success(res, result.settings, 'Game settings updated successfully');
    } catch (err) {
      logger.error('Error updating game settings:', err);
      error(res, 'Failed to update game settings');
    }
  }

  async getRooms(req, res) {
    try {
      const result = await adminService.listRooms(true);
      success(res, result.rooms, 'Rooms retrieved successfully');
    } catch (err) {
      logger.error('Error getting rooms:', err);
      error(res, 'Failed to get rooms');
    }
  }

  async createRoom(req, res) {
    try {
      const result = await adminService.createRoom(req.body);
      if (!result.success) {
        return validationError(res, [{ msg: result.message }]);
      }
      success(res, { roomId: result.roomId }, 'Room created successfully');
    } catch (err) {
      logger.error('Error creating room:', err);
      error(res, 'Failed to create room', err.code === 'ER_DUP_ENTRY' ? 409 : 500);
    }
  }

  async updateRoom(req, res) {
    try {
      const result = await adminService.updateRoom(parseInt(req.params.id, 10), req.body);
      if (!result.success) {
        return notFound(res, result.message || 'Room not found');
      }
      success(res, null, 'Room updated successfully');
    } catch (err) {
      logger.error('Error updating room:', err);
      error(res, 'Failed to update room', err.code === 'ER_DUP_ENTRY' ? 409 : 500);
    }
  }

  async getAdminUsers(req, res) {
    try {
      const result = await adminService.listAdminUsers();
      success(res, result.users, 'Admin users retrieved successfully');
    } catch (err) {
      logger.error('Error getting admin users:', err);
      error(res, 'Failed to get admin users');
    }
  }

  async createAdminUser(req, res) {
    try {
      const result = await adminService.createAdminUser(req.body);
      if (!result.success) {
        return validationError(res, [{ msg: result.message }]);
      }
      success(res, { userId: result.userId }, 'Admin user created successfully');
    } catch (err) {
      logger.error('Error creating admin user:', err);
      error(res, 'Failed to create admin user', err.code === 'ER_DUP_ENTRY' ? 409 : 500);
    }
  }

  async updateAdminUser(req, res) {
    try {
      const result = await adminService.updateAdminUser(parseInt(req.params.id, 10), req.body);
      if (!result.success) {
        return notFound(res, result.message || 'Admin user not found');
      }
      success(res, null, 'Admin user updated successfully');
    } catch (err) {
      logger.error('Error updating admin user:', err);
      error(res, 'Failed to update admin user', err.code === 'ER_DUP_ENTRY' ? 409 : 500);
    }
  }

  // Get overall statistics
  async getStats(req, res) {
    try {
      const result = await adminService.getStats(resolveAdminRoomId(req, res));
      success(res, result.stats, 'Statistics retrieved successfully');
    } catch (err) {
      logger.error('Error getting stats:', err);
      error(res, 'Failed to get statistics');
    }
  }

  // Render conversion dashboard page
  async renderConversion(req, res) {
    try {
      const result = await adminService.getConversionStats();
      const data = result.data;
      return res.render('admin/conversion', { title: 'Conversion Dashboard', adminUser: res.locals.adminUser, data });
    } catch (err) {
      logger.error('Error rendering conversion dashboard:', err);
      return res.status(500).send('Failed to render conversion dashboard');
    }
  }
}

module.exports = new AdminController();

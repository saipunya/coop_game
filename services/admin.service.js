const gameCodeModel = require('../models/gameCode.model');
const questionModel = require('../models/question.model');
const attemptModel = require('../models/attempt.model');
const gameSettingModel = require('../models/gameSetting.model');
const pool = require('../config/database');
const { generateGameCodes, calculateExpiry } = require('../utils/crypto');
const logger = require('../utils/logger');
const mammoth = require('mammoth');

const QUESTION_TIME_LIMITS = {
  easy: 15,
  medium: 20,
  hard: 25
};

const DEFAULT_GAME_SETTINGS = {
  totalQuestions: 9
};

class AdminService {
  /**
   * Generate batch of game codes
   */
  async generateCodes(count, expiryHours = 24) {
    try {
      const codes = await this.generateUniqueCodes(count);
      const expiresAt = calculateExpiry(expiryHours);

      let createdCount;
      try {
        createdCount = await gameCodeModel.createBatch(codes, expiresAt);
      } catch (error) {
        if (error.code !== 'ER_DUP_ENTRY') {
          throw error;
        }

        // A rare race condition can still insert a duplicate between the
        // existence check and the batch insert. Retry once with a fresh batch.
        const retryCodes = await this.generateUniqueCodes(count);
        createdCount = await gameCodeModel.createBatch(retryCodes, expiresAt);
        return {
          success: true,
          codes: retryCodes,
          count: createdCount,
          expiresAt: expiresAt
        };
      }

      logger.info(`Generated ${createdCount} game codes, expires at ${expiresAt}`);

      return {
        success: true,
        codes: codes,
        count: createdCount,
        expiresAt: expiresAt
      };
    } catch (error) {
      logger.error('Error generating codes:', error);
      throw error;
    }
  }

  /**
   * Generate codes that do not already exist in the database
   */
  async generateUniqueCodes(count) {
    const uniqueCodes = new Set();
    let attempts = 0;
    const maxAttempts = 10;

    while (uniqueCodes.size < count && attempts < maxAttempts) {
      const needed = count - uniqueCodes.size;
      const candidateCount = Math.max(needed * 2, needed);
      const candidates = generateGameCodes(candidateCount);
      const existingCodes = new Set(await gameCodeModel.findExistingCodes(candidates));

      for (const code of candidates) {
        if (!existingCodes.has(code)) {
          uniqueCodes.add(code);
          if (uniqueCodes.size === count) {
            break;
          }
        }
      }

      attempts += 1;
    }

    if (uniqueCodes.size < count) {
      throw new Error(`Unable to generate ${count} unique codes after ${maxAttempts} attempts`);
    }

    return Array.from(uniqueCodes);
  }

  /**
   * Get all codes with optional status filter
   */
  async getCodes(status = null, limit = 50, offset = 0) {
    try {
      let codes;
      if (status) {
        codes = await gameCodeModel.getByStatus(status, limit, offset);
      } else {
        codes = await gameCodeModel.getByStatus('unused', limit, offset);
      }

      return {
        success: true,
        codes: codes,
        count: codes.length
      };
    } catch (error) {
      logger.error('Error getting codes:', error);
      throw error;
    }
  }

  /**
   * Get code statistics
   */
  async getCodeStats() {
    try {
      const unused = await gameCodeModel.countByStatus('unused');
      const inProgress = await gameCodeModel.countByStatus('in_progress');
      const used = await gameCodeModel.countByStatus('used');
      const expired = await gameCodeModel.countByStatus('expired');

      return {
        success: true,
        stats: {
          unused,
          inProgress,
          used,
          expired,
          total: unused + inProgress + used + expired
        }
      };
    } catch (error) {
      logger.error('Error getting code stats:', error);
      throw error;
    }
  }

  /**
   * Mark expired codes
   */
  async markExpiredCodes() {
    try {
      const count = await gameCodeModel.markExpired();
      logger.info(`Marked ${count} codes as expired`);
      return { success: true, count };
    } catch (error) {
      logger.error('Error marking expired codes:', error);
      throw error;
    }
  }

  /**
   * Clear removable codes from the system
   * Only deletes unused and expired codes to keep game history intact.
   */
  async clearCodes() {
    try {
      const statusesToDelete = ['unused', 'expired'];
      const deletedCount = await gameCodeModel.deleteByStatuses(statusesToDelete);

      logger.info(`Cleared ${deletedCount} removable codes`);
      return {
        success: true,
        count: deletedCount
      };
    } catch (error) {
      logger.error('Error clearing codes:', error);
      throw error;
    }
  }

  /**
   * Delete a code when it is safe to remove it from the system
   */
  async deleteCode(id) {
    try {
      const code = await gameCodeModel.findById(id);

      if (!code) {
        return { success: false, message: 'Code not found' };
      }

      if (code.status === 'used' || code.status === 'in_progress') {
        return {
          success: false,
          message: 'ไม่สามารถลบรหัสที่ถูกใช้งานแล้วได้'
        };
      }

      const deleted = await gameCodeModel.deleteById(id);
      if (!deleted) {
        return { success: false, message: 'Code not found' };
      }

      logger.info(`Deleted code ${code.code} (id: ${id})`);
      return { success: true };
    } catch (error) {
      logger.error('Error deleting code:', error);
      throw error;
    }
  }

  /**
   * Add new question
   */
  async addQuestion(questionData) {
    try {
      const questionId = await questionModel.create(questionData);
      logger.info(`Added question ${questionId}`);
      return { success: true, questionId };
    } catch (error) {
      logger.error('Error adding question:', error);
      throw error;
    }
  }

  /**
   * Update question
   */
  async updateQuestion(id, questionData) {
    try {
      const success = await questionModel.update(id, questionData);
      if (!success) {
        return { success: false, message: 'Question not found' };
      }
      logger.info(`Updated question ${id}`);
      return { success: true };
    } catch (error) {
      logger.error('Error updating question:', error);
      throw error;
    }
  }

  /**
   * Delete question (soft delete)
   */
  async deleteQuestion(id) {
    try {
      const success = await questionModel.softDelete(id);
      if (!success) {
        return { success: false, message: 'Question not found' };
      }
      logger.info(`Deleted question ${id}`);
      return { success: true };
    } catch (error) {
      logger.error('Error deleting question:', error);
      throw error;
    }
  }

  /**
   * Get all questions
   */
  async getQuestions(difficulty = null) {
    try {
      let questions;
      if (difficulty) {
        questions = await questionModel.getByDifficulty(difficulty, 100);
      } else {
        // Get all questions including inactive for admin
        questions = await questionModel.getAll();
      }
      return { success: true, questions };
    } catch (error) {
      logger.error('Error getting questions:', error);
      throw error;
    }
  }

  /**
   * Get question counts by difficulty
   */
  async getQuestionStats() {
    try {
      const easy = await questionModel.countByDifficulty('easy');
      const medium = await questionModel.countByDifficulty('medium');
      const hard = await questionModel.countByDifficulty('hard');

      return {
        success: true,
        stats: {
          easy,
          medium,
          hard,
          total: easy + medium + hard
        }
      };
    } catch (error) {
      logger.error('Error getting question stats:', error);
      throw error;
    }
  }

  /**
   * Get overall statistics
   */
  async getStats() {
    try {
      const gameStats = await attemptModel.getStats();
      const codeStats = await this.getCodeStats();
      const questionStats = await this.getQuestionStats();
      const gameSettings = await this.getGameSettings();

      return {
        success: true,
        stats: {
          players: gameStats.totalPlayers,
          completedGames: gameStats.completedGames,
          averageScore: gameStats.averageScore,
          averageTime: gameStats.averageTime,
          codes: codeStats.stats,
          questions: questionStats.stats,
          gameSettings: gameSettings.settings
        }
      };
    } catch (error) {
      logger.error('Error getting stats:', error);
      throw error;
    }
  }

  /**
   * Conversion stats for admin dashboard
   */
  async getConversionStats() {
    try {
      const conversionModel = require('../models/conversionEvent.model');

      const keys = [
        'click_cta_hero', 'click_cta_nav', 'click_cta_system_game', 'click_cta_system_prize',
        'click_cta_system_coopbot', 'click_cta_system_gov', 'click_cta_main', 'click_cta_mobile'
      ];

      const counts = await conversionModel.countByEventNames(keys);
      const totalClicks = await conversionModel.countTotalClicks();
      const visitors = await conversionModel.countByEventName('page_view');
      const started = await conversionModel.countByEventName('onboard_start');
      const latest = await conversionModel.latest(100);

      return {
        success: true,
        data: {
          counts,
          totalClicks,
          funnel: { visitors: visitors || 0, clicks: totalClicks || 0, started: started || 0 },
          latest
        }
      };
    } catch (error) {
      logger.error('Error getting conversion stats:', error);
      throw error;
    }
  }

  /**
   * Clear player history and gameplay statistics.
   * This removes attempts, answers, and attempt-question mappings.
   */
  async clearPlayerHistory() {
    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      const deletedAttempts = await attemptModel.clearAllHistory(connection);

      await connection.commit();

      logger.info(`Cleared player history and deleted ${deletedAttempts} game attempts`);
      return {
        success: true,
        count: deletedAttempts
      };
    } catch (error) {
      await connection.rollback();
      logger.error('Error clearing player history:', error);
      throw error;
    } finally {
      connection.release();
    }
  }

  normalizeImportedText(value) {
    return String(value || '')
      .replace(/\u00a0/g, ' ')
      .replace(/\r/g, '')
      .trim();
  }

  normalizeDifficultyValue(value) {
    const normalized = String(value || '').trim().toLowerCase();

    if (normalized === 'easy' || normalized === 'ง่าย') return 'easy';
    if (normalized === 'medium' || normalized === 'ปานกลาง') return 'medium';
    if (normalized === 'hard' || normalized === 'ยาก') return 'hard';

    return '';
  }

  normalizeAnswerValue(value) {
    const normalized = String(value || '').trim().toUpperCase();
    return ['A', 'B', 'C', 'D'].includes(normalized) ? normalized : '';
  }

  parseQuestionsFromText(rawText) {
    const text = this.normalizeImportedText(rawText);

    if (!text) {
      return {
        questions: [],
        errors: ['ไม่พบข้อความในไฟล์ Word']
      };
    }

    const blocks = text
      .split(/\n\s*\n+/)
      .map(block => block.trim())
      .filter(Boolean);

    const questions = [];
    const errors = [];

    blocks.forEach((block, blockIndex) => {
      const lines = block
        .split('\n')
        .map(line => line.trim())
        .filter(Boolean);

      const parsed = {
        questionText: '',
        optionA: '',
        optionB: '',
        optionC: '',
        optionD: '',
        correctAnswer: '',
        difficulty: ''
      };

      for (const line of lines) {
        if (/^ข้อ\s*\d+/i.test(line)) {
          continue;
        }

        const labeledMatch = line.match(/^(.*?)\s*[:：]\s*(.+)$/u);
        const optionMatch = line.match(/^(?:ตัวเลือก\s*)?([ABCD])(?:[\.\)\-]|[:：])?\s*(.+)$/iu);

        if (labeledMatch) {
          const label = labeledMatch[1].trim().toLowerCase();
          const value = labeledMatch[2].trim();

          if (label === 'คำถาม' || label === 'question') {
            parsed.questionText = value;
            continue;
          }

          if (label === 'คำตอบ' || label === 'answer' || label === 'เฉลย') {
            parsed.correctAnswer = value;
            continue;
          }

          if (label === 'ระดับ' || label === 'difficulty' || label === 'level') {
            parsed.difficulty = value;
            continue;
          }

          const optionLabel = label.match(/^ตัวเลือก\s*([abcd])$/i) || label.match(/^option\s*([abcd])$/i);
          if (optionLabel) {
            parsed[`option${optionLabel[1].toUpperCase()}`] = value;
            continue;
          }
        }

        if (optionMatch) {
          parsed[`option${optionMatch[1].toUpperCase()}`] = optionMatch[2].trim();
          continue;
        }

        if (!parsed.questionText) {
          parsed.questionText = line;
        }
      }

      const blockErrors = [];
      const questionLabel = `ข้อที่ ${blockIndex + 1}`;

      if (!parsed.questionText) {
        blockErrors.push(`${questionLabel}: ไม่พบข้อความคำถาม`);
      }
      if (!parsed.optionA) blockErrors.push(`${questionLabel}: ไม่พบตัวเลือก A`);
      if (!parsed.optionB) blockErrors.push(`${questionLabel}: ไม่พบตัวเลือก B`);
      if (!parsed.optionC) blockErrors.push(`${questionLabel}: ไม่พบตัวเลือก C`);
      if (!parsed.optionD) blockErrors.push(`${questionLabel}: ไม่พบตัวเลือก D`);

      parsed.correctAnswer = this.normalizeAnswerValue(parsed.correctAnswer);
      if (!parsed.correctAnswer) {
        blockErrors.push(`${questionLabel}: คำตอบต้องเป็น A, B, C, หรือ D`);
      }

      parsed.difficulty = this.normalizeDifficultyValue(parsed.difficulty);
      if (!parsed.difficulty) {
        blockErrors.push(`${questionLabel}: ระดับความยากต้องเป็น easy, medium, hard หรือ ง่าย, ปานกลาง, ยาก`);
      }

      if (blockErrors.length > 0) {
        errors.push(...blockErrors);
        return;
      }

      questions.push({
        questionText: parsed.questionText,
        optionA: parsed.optionA,
        optionB: parsed.optionB,
        optionC: parsed.optionC,
        optionD: parsed.optionD,
        correctAnswer: parsed.correctAnswer,
        difficulty: parsed.difficulty
      });
    });

    return { questions, errors };
  }

  async importQuestionsFromDocx(buffer) {
    try {
      const extracted = await mammoth.extractRawText({ buffer });
      const parsed = this.parseQuestionsFromText(extracted.value);

      if (parsed.errors.length > 0) {
        return {
          success: false,
          message: 'ไฟล์ Word มีรูปแบบไม่ถูกต้อง',
          errors: parsed.errors
        };
      }

      if (parsed.questions.length === 0) {
        return {
          success: false,
          message: 'ไม่พบคำถามในไฟล์ Word'
        };
      }

      const connection = await pool.getConnection();

      try {
        await connection.beginTransaction();

        const questionIds = [];
        for (const question of parsed.questions) {
          const questionId = await questionModel.createWithConnection(connection, {
            ...question,
            timeLimit: this.resolveQuestionTimeLimit(question.difficulty)
          });
          questionIds.push(questionId);
        }

        await connection.commit();

        logger.info(`Imported ${questionIds.length} questions from DOCX`);

        return {
          success: true,
          importedCount: questionIds.length,
          questionIds
        };
      } catch (error) {
        await connection.rollback();
        throw error;
      } finally {
        connection.release();
      }
    } catch (error) {
      logger.error('Error importing questions from docx:', error);
      throw error;
    }
  }

  resolveQuestionTimeLimit(difficulty) {
    return QUESTION_TIME_LIMITS[difficulty] || QUESTION_TIME_LIMITS.easy;
  }

  async getGameSettings() {
    try {
      const totalQuestions = await gameSettingModel.getValue(
        'totalQuestions',
        DEFAULT_GAME_SETTINGS.totalQuestions
      );

      return {
        success: true,
        settings: {
          totalQuestions: Number.isFinite(Number(totalQuestions))
            ? parseInt(totalQuestions, 10)
            : DEFAULT_GAME_SETTINGS.totalQuestions
        }
      };
    } catch (error) {
      logger.error('Error getting game settings:', error);
      throw error;
    }
  }

  async updateGameSettings(settingsData) {
    try {
      const totalQuestions = parseInt(settingsData.totalQuestions, 10);

      if (!Number.isInteger(totalQuestions) || totalQuestions < 1 || totalQuestions > 100) {
        return {
          success: false,
          message: 'Total questions must be between 1 and 100'
        };
      }

      await gameSettingModel.set('totalQuestions', totalQuestions);

      logger.info(`Updated game settings: totalQuestions=${totalQuestions}`);

      return {
        success: true,
        settings: {
          totalQuestions
        }
      };
    } catch (error) {
      logger.error('Error updating game settings:', error);
      throw error;
    }
  }

  buildQuestionDistribution(totalQuestions) {
    const ratios = [
      { difficulty: 'easy', ratio: 0.30 },
      { difficulty: 'medium', ratio: 0.35 },
      { difficulty: 'hard', ratio: 0.35 }
    ];

    const baseCounts = ratios.map(item => Math.floor(totalQuestions * item.ratio));
    const fractions = ratios.map((item, index) => ({
      index,
      fraction: totalQuestions * item.ratio - baseCounts[index]
    }));

    let remainder = totalQuestions - baseCounts.reduce((sum, count) => sum + count, 0);

    fractions.sort((a, b) => {
      if (b.fraction !== a.fraction) {
        return b.fraction - a.fraction;
      }
      return a.index - b.index;
    });

    let cursor = 0;
    while (remainder > 0) {
      baseCounts[fractions[cursor % fractions.length].index] += 1;
      remainder -= 1;
      cursor += 1;
    }

    return {
      easy: baseCounts[0],
      medium: baseCounts[1],
      hard: baseCounts[2]
    };
  }

  getQuestionOrderPreview(totalQuestions) {
    const distribution = this.buildQuestionDistribution(totalQuestions);
    return [
      ...Array.from({ length: distribution.easy }, () => 'easy'),
      ...Array.from({ length: distribution.medium }, () => 'medium'),
      ...Array.from({ length: distribution.hard }, () => 'hard')
    ];
  }
}

module.exports = new AdminService();

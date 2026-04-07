const gameCodeModel = require('../models/gameCode.model');
const questionModel = require('../models/question.model');
const attemptModel = require('../models/attempt.model');
const { generateGameCodes, calculateExpiry } = require('../utils/crypto');
const logger = require('../utils/logger');

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

      return {
        success: true,
        stats: {
          players: gameStats.totalPlayers,
          completedGames: gameStats.completedGames,
          averageScore: gameStats.averageScore,
          averageTime: gameStats.averageTime,
          codes: codeStats.stats,
          questions: questionStats.stats
        }
      };
    } catch (error) {
      logger.error('Error getting stats:', error);
      throw error;
    }
  }
}

module.exports = new AdminService();

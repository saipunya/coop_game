const adminService = require('../services/admin.service');
const { success, error, validationError, notFound } = require('../utils/response');
const logger = require('../utils/logger');

class AdminController {
  // Get dashboard statistics
  async getDashboard(req, res) {
    try {
      const result = await adminService.getStats();
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

      if (!count || count < 1 || count > 1000) {
        return validationError(res, [{ msg: 'Count must be between 1 and 1000' }]);
      }

      const result = await adminService.generateCodes(count, expiryHours || 24);
      success(res, result, 'Codes generated successfully');
    } catch (err) {
      logger.error('Error generating codes:', err);
      error(res, 'Failed to generate codes');
    }
  }

  // Get codes
  async getCodes(req, res) {
    try {
      const { status, limit, offset } = req.query;
      const result = await adminService.getCodes(
        status || null,
        parseInt(limit) || 50,
        parseInt(offset) || 0
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
      const result = await adminService.getCodeStats();
      success(res, result.stats, 'Code statistics retrieved');
    } catch (err) {
      logger.error('Error getting code stats:', err);
      error(res, 'Failed to get code statistics');
    }
  }

  // Mark expired codes
  async markExpiredCodes(req, res) {
    try {
      const result = await adminService.markExpiredCodes();
      success(res, { count: result.count }, `Marked ${result.count} codes as expired`);
    } catch (err) {
      logger.error('Error marking expired codes:', err);
      error(res, 'Failed to mark expired codes');
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
        difficulty,
        timeLimit
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
      if (!timeLimit || timeLimit < 5 || timeLimit > 60) {
        errors.push({ msg: 'timeLimit must be between 5 and 60' });
      }

      if (errors.length > 0) {
        return validationError(res, errors);
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

      const result = await adminService.addQuestion(questionData);
      success(res, { questionId: result.questionId }, 'Question added successfully');
    } catch (err) {
      logger.error('Error adding question:', err);
      error(res, 'Failed to add question');
    }
  }

  // Get questions
  async getQuestions(req, res) {
    try {
      const { difficulty } = req.query;
      const result = await adminService.getQuestions(difficulty || null);
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
      const questionData = req.body;

      const result = await adminService.updateQuestion(parseInt(id), questionData);
      
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
      const result = await adminService.deleteQuestion(parseInt(id));

      if (!result.success) {
        return notFound(res, 'Question not found');
      }

      success(res, null, 'Question deleted successfully');
    } catch (err) {
      logger.error('Error deleting question:', err);
      error(res, 'Failed to delete question');
    }
  }

  // Get question statistics
  async getQuestionStats(req, res) {
    try {
      const result = await adminService.getQuestionStats();
      success(res, result.stats, 'Question statistics retrieved');
    } catch (err) {
      logger.error('Error getting question stats:', err);
      error(res, 'Failed to get question statistics');
    }
  }

  // Get overall statistics
  async getStats(req, res) {
    try {
      const result = await adminService.getStats();
      success(res, result.stats, 'Statistics retrieved successfully');
    } catch (err) {
      logger.error('Error getting stats:', err);
      error(res, 'Failed to get statistics');
    }
  }
}

module.exports = new AdminController();

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
  gameEnabled: true,
  totalQuestions: 9,
  randomQuestionOrderEnabled: true,
  randomAnswerOrderEnabled: true,
  timeLimits: QUESTION_TIME_LIMITS,
  questionDistribution: {
    easy: 30,
    medium: 35,
    hard: 35
  }
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
        codes = await gameCodeModel.getAll(limit, offset);
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
   * Clear all codes from the system, including game history tied to those codes.
   */
  async clearCodes() {
    try {
      const result = await gameCodeModel.clearAllWithHistory();

      logger.info(
        `Cleared ${result.deletedCodes} codes and ${result.deletedAttempts} game attempts`
      );
      return {
        success: true,
        count: result.deletedCodes,
        attempts: result.deletedAttempts
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

  async addQuestionByCreator(questionData, creator) {
    try {
      const questionId = await questionModel.create({
        ...questionData,
        createdBy: creator
      });
      logger.info(`Added question ${questionId} by ${creator}`);
      return { success: true, questionId };
    } catch (error) {
      logger.error('Error adding creator question:', error);
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

  async updateQuestionByCreator(id, questionData, creator) {
    try {
      const success = await questionModel.updateByCreator(id, creator, questionData);
      if (!success) {
        return { success: false, message: 'Question not found or access denied' };
      }
      logger.info(`Updated question ${id} by ${creator}`);
      return { success: true };
    } catch (error) {
      logger.error('Error updating creator question:', error);
      throw error;
    }
  }

  /**
   * Permanently delete question and any game-history rows that reference it.
   */
  async deleteQuestion(id) {
    try {
      const result = await questionModel.deletePermanently(id);
      if (!result.deleted) {
        return { success: false, message: 'Question not found' };
      }

      logger.info(
        `Permanently deleted question ${id}, ` +
        `${result.deletedAnswers} answers, ${result.deletedAttemptQuestions} attempt questions`
      );
      return {
        success: true,
        deletedAnswers: result.deletedAnswers,
        deletedAttemptQuestions: result.deletedAttemptQuestions
      };
    } catch (error) {
      logger.error('Error deleting question:', error);
      throw error;
    }
  }

  async deleteQuestionByCreator(id, creator) {
    try {
      const result = await questionModel.deletePermanentlyByCreator(id, creator);
      if (!result.deleted) {
        return { success: false, message: 'Question not found or access denied' };
      }

      logger.info(
        `Permanently deleted question ${id} by ${creator}, ` +
        `${result.deletedAnswers} answers, ${result.deletedAttemptQuestions} attempt questions`
      );
      return {
        success: true,
        deletedAnswers: result.deletedAnswers,
        deletedAttemptQuestions: result.deletedAttemptQuestions
      };
    } catch (error) {
      logger.error('Error deleting creator question:', error);
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

  async getQuestionsByCreator(creator, difficulty = null) {
    try {
      let questions;
      if (difficulty) {
        questions = (await questionModel.getByCreator(creator)).filter(q => q.difficulty === difficulty);
      } else {
        questions = await questionModel.getByCreator(creator);
      }
      return { success: true, questions };
    } catch (error) {
      logger.error('Error getting creator questions:', error);
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
    if (normalized === 'hard' || normalized === 'difficult' || normalized === 'ยาก') return 'hard';

    return '';
  }

  normalizeOptionKey(value) {
    const normalized = String(value || '')
      .trim()
      .toUpperCase()
      .match(/^[ABCDกขคง]/u)?.[0] || '';
    const optionMap = {
      A: 'A',
      B: 'B',
      C: 'C',
      D: 'D',
      ก: 'A',
      ข: 'B',
      ค: 'C',
      ง: 'D'
    };

    return optionMap[normalized] || '';
  }

  normalizeAnswerValue(value) {
    return this.normalizeOptionKey(value);
  }

  stripQuestionPrefix(line) {
    return String(line || '')
      .replace(/^ข้อ\s*(?:ที่\s*)?\d+\s*(?:[\.\)\-]|[:：])?\s*/iu, '')
      .replace(/^\d+\s*(?:[\.\)\-]|[:：])\s*/iu, '')
      .trim();
  }

  isQuestionNumberLine(line) {
    return /^(?:ข้อ\s*(?:ที่\s*)?\d+|\d+\s*[\.\)\-]|ข้อ\s*(?:ที่\s*)?\d+\s*[:：])/iu.test(String(line || '').trim());
  }

  isQuestionLabelLine(line) {
    const normalized = String(line || '').trim().toLowerCase();
    return normalized === 'คำถาม'
      || normalized === 'question'
      || /^(คำถาม|question)(?:\s|[:：])/iu.test(normalized);
  }

  hasCompletedImportedQuestion(lines) {
    const joined = lines.join('\n');
    return /(คำตอบ|answer|เฉลย)/iu.test(joined)
      && /(ระดับความยาก|ระดับ|difficulty|level)/iu.test(joined);
  }

  splitImportedQuestionBlocks(text) {
    const lines = text
      .replace(/\t/g, '\n')
      .split('\n')
      .map(line => line.trim())
      .filter(Boolean);

    const blocks = [];
    let currentBlock = [];

    for (const line of lines) {
      const shouldStartNewBlock = currentBlock.length > 0
        && (
          this.isQuestionNumberLine(line)
          || (this.isQuestionLabelLine(line) && this.hasCompletedImportedQuestion(currentBlock))
        );

      if (shouldStartNewBlock) {
        blocks.push(currentBlock);
        currentBlock = [];
      }

      currentBlock.push(line);
    }

    if (currentBlock.length > 0) {
      blocks.push(currentBlock);
    }

    return blocks;
  }

  getImportedLineLabel(line) {
    const normalized = String(line || '').trim().toLowerCase();

    if (normalized === 'คำถาม' || normalized === 'question') {
      return { type: 'questionText' };
    }

    if (normalized === 'คำตอบ' || normalized === 'answer' || normalized === 'เฉลย') {
      return { type: 'correctAnswer' };
    }

    if (normalized === 'ระดับ' || normalized === 'ระดับความยาก' || normalized === 'difficulty' || normalized === 'level') {
      return { type: 'difficulty' };
    }

    const optionLabel = normalized.match(/^ตัวเลือก\s*([abcdกขคง])$/i)
      || normalized.match(/^option\s*([abcd])$/i)
      || normalized.match(/^([abcdกขคง])$/i);
    if (optionLabel) {
      const optionKey = this.normalizeOptionKey(optionLabel[1]);
      if (optionKey) {
        return { type: `option${optionKey}` };
      }
    }

    return null;
  }

  setImportedQuestionField(parsed, type, value) {
    if (!type || !value) return false;
    parsed[type] = String(value).trim();
    return true;
  }

  parseInlineImportedFields(parsed, line) {
    const labelPattern = /(^|\s)(คำถาม|question|ตัวเลือก\s*[ABCDกขคง]|option\s*[ABCD]|คำตอบ|answer|เฉลย|ระดับความยาก|ระดับ|difficulty|level)\s*[:：]?/giu;
    const matches = [...String(line || '').matchAll(labelPattern)];

    if (matches.length < 2) {
      return false;
    }

    let hasParsedField = false;

    matches.forEach((match, index) => {
      const field = this.getImportedLineLabel(match[2]);
      const valueStart = match.index + match[0].length;
      const valueEnd = matches[index + 1]?.index ?? line.length;
      const value = line.slice(valueStart, valueEnd).trim();

      if (field && value) {
        this.setImportedQuestionField(parsed, field.type, value);
        hasParsedField = true;
      }
    });

    return hasParsedField;
  }

  parseImportedQuestionBlock(lines) {
    const parsed = {
      questionText: '',
      optionA: '',
      optionB: '',
      optionC: '',
      optionD: '',
      correctAnswer: '',
      difficulty: ''
    };

    for (let index = 0; index < lines.length; index += 1) {
      const contentLine = this.stripQuestionPrefix(lines[index]);

      if (!contentLine) {
        continue;
      }

      if (this.parseInlineImportedFields(parsed, contentLine)) {
        continue;
      }

      const labeledMatch = contentLine.match(/^(.*?)\s*[:：]\s*(.+)$/u);
      const inlineQuestionMatch = contentLine.match(/^(คำถาม|question)\s+(.+)$/iu);
      const inlineAnswerMatch = contentLine.match(/^(คำตอบ|answer|เฉลย)\s+(.+)$/iu);
      const inlineDifficultyMatch = contentLine.match(/^(ระดับความยาก|ระดับ|difficulty|level)\s+(.+)$/iu);
      const optionMatch = contentLine.match(/^(?:ตัวเลือก\s*)?([ABCDกขคง])(?:[\.\)\-]|[:：])?\s*(.+)$/iu);
      const standaloneLabel = this.getImportedLineLabel(contentLine);

      if (labeledMatch) {
        const label = labeledMatch[1].trim();
        const value = labeledMatch[2].trim();
        const field = this.getImportedLineLabel(label);

        if (field) {
          this.setImportedQuestionField(parsed, field.type, value);
          continue;
        }
      }

      if (inlineQuestionMatch) {
        parsed.questionText = inlineQuestionMatch[2].trim();
        continue;
      }

      if (inlineAnswerMatch) {
        parsed.correctAnswer = inlineAnswerMatch[2].trim();
        continue;
      }

      if (inlineDifficultyMatch) {
        parsed.difficulty = inlineDifficultyMatch[2].trim();
        continue;
      }

      if (standaloneLabel) {
        const nextLine = this.stripQuestionPrefix(lines[index + 1] || '');
        if (nextLine) {
          this.setImportedQuestionField(parsed, standaloneLabel.type, nextLine);
          index += 1;
        }
        continue;
      }

      if (optionMatch) {
        const optionKey = this.normalizeOptionKey(optionMatch[1]);
        if (optionKey) {
          parsed[`option${optionKey}`] = optionMatch[2].trim();
        }
        continue;
      }

      if (!parsed.questionText) {
        parsed.questionText = contentLine;
      }
    }

    return parsed;
  }

  parseQuestionsFromText(rawText) {
    const text = this.normalizeImportedText(rawText);

    if (!text) {
      return {
        questions: [],
        errors: ['ไม่พบข้อความในไฟล์ Word']
      };
    }

    const blocks = this.splitImportedQuestionBlocks(text);
    const questions = [];
    const errors = [];

    blocks.forEach((lines, blockIndex) => {
      const parsed = this.parseImportedQuestionBlock(lines);
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
        blockErrors.push(`${questionLabel}: คำตอบต้องเป็น A, B, C, D หรือ ก, ข, ค, ง`);
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

  async createImportedQuestions(parsedQuestions, sourceLabel) {
    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      const questionIds = [];
      const gameSettings = await this.getGameSettings();
      for (const question of parsedQuestions) {
        const questionId = await questionModel.createWithConnection(connection, {
          ...question,
          timeLimit: gameSettings.settings.timeLimits[question.difficulty]
        });
        questionIds.push(questionId);
      }

      await connection.commit();

      logger.info(`Imported ${questionIds.length} questions from ${sourceLabel}`);

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
  }

  async importQuestionsFromText(rawText) {
    try {
      const parsed = this.parseQuestionsFromText(rawText);

      if (parsed.errors.length > 0) {
        return {
          success: false,
          message: 'ข้อความมีรูปแบบไม่ถูกต้อง',
          errors: parsed.errors
        };
      }

      if (parsed.questions.length === 0) {
        return {
          success: false,
          message: 'ไม่พบคำถามในข้อความ'
        };
      }

      return await this.createImportedQuestions(parsed.questions, 'pasted text');
    } catch (error) {
      logger.error('Error importing questions from text:', error);
      throw error;
    }
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

      return await this.createImportedQuestions(parsed.questions, 'DOCX');
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
      const gameEnabled = await gameSettingModel.getValue(
        'gameEnabled',
        DEFAULT_GAME_SETTINGS.gameEnabled
      );
      const savedTimeLimits = await gameSettingModel.getValue(
        'questionTimeLimits',
        DEFAULT_GAME_SETTINGS.timeLimits
      );
      const savedQuestionDistribution = await gameSettingModel.getValue(
        'questionDistribution',
        DEFAULT_GAME_SETTINGS.questionDistribution
      );
      const randomQuestionOrderEnabled = await gameSettingModel.getValue(
        'randomQuestionOrderEnabled',
        DEFAULT_GAME_SETTINGS.randomQuestionOrderEnabled
      );
      const randomAnswerOrderEnabled = await gameSettingModel.getValue(
        'randomAnswerOrderEnabled',
        DEFAULT_GAME_SETTINGS.randomAnswerOrderEnabled
      );
      const timeLimits = this.normalizeQuestionTimeLimits(savedTimeLimits);
      const questionDistribution = this.normalizeQuestionDistribution(savedQuestionDistribution);

      return {
        success: true,
        settings: {
          totalQuestions: Number.isFinite(Number(totalQuestions))
            ? parseInt(totalQuestions, 10)
            : DEFAULT_GAME_SETTINGS.totalQuestions,
          gameEnabled: gameEnabled !== false,
          randomQuestionOrderEnabled: randomQuestionOrderEnabled !== false,
          randomAnswerOrderEnabled: randomAnswerOrderEnabled !== false,
          timeLimits,
          questionDistribution
        }
      };
    } catch (error) {
      logger.error('Error getting game settings:', error);
      throw error;
    }
  }

  normalizeQuestionTimeLimits(rawTimeLimits = {}) {
    const timeLimits = {};

    for (const difficulty of ['easy', 'medium', 'hard']) {
      const parsed = parseInt(rawTimeLimits?.[difficulty], 10);
      timeLimits[difficulty] = Number.isInteger(parsed) && parsed >= 5 && parsed <= 300
        ? parsed
        : DEFAULT_GAME_SETTINGS.timeLimits[difficulty];
    }

    return timeLimits;
  }

  normalizeQuestionDistribution(rawDistribution = {}) {
    const distribution = {};

    for (const difficulty of ['easy', 'medium', 'hard']) {
      const parsed = parseInt(rawDistribution?.[difficulty], 10);
      distribution[difficulty] = Number.isInteger(parsed) && parsed >= 0 && parsed <= 100
        ? parsed
        : DEFAULT_GAME_SETTINGS.questionDistribution[difficulty];
    }

    const total = distribution.easy + distribution.medium + distribution.hard;
    if (total !== 100 || total <= 0) {
      return { ...DEFAULT_GAME_SETTINGS.questionDistribution };
    }

    return distribution;
  }

  async updateGameSettings(settingsData) {
    try {
      const totalQuestions = parseInt(settingsData.totalQuestions, 10);
      const gameEnabled = settingsData.gameEnabled !== false;
      const randomQuestionOrderEnabled = settingsData.randomQuestionOrderEnabled !== false;
      const randomAnswerOrderEnabled = settingsData.randomAnswerOrderEnabled !== false;
      const rawTimeLimits = settingsData.timeLimits || {};
      const rawQuestionDistribution = settingsData.questionDistribution || {};
      const timeLimits = {
        easy: parseInt(rawTimeLimits.easy, 10),
        medium: parseInt(rawTimeLimits.medium, 10),
        hard: parseInt(rawTimeLimits.hard, 10)
      };
      const questionDistribution = {
        easy: parseInt(rawQuestionDistribution.easy, 10),
        medium: parseInt(rawQuestionDistribution.medium, 10),
        hard: parseInt(rawQuestionDistribution.hard, 10)
      };

      if (!Number.isInteger(totalQuestions) || totalQuestions < 1 || totalQuestions > 100) {
        return {
          success: false,
          message: 'Total questions must be between 1 and 100'
        };
      }

      for (const [difficulty, timeLimit] of Object.entries(timeLimits)) {
        if (!Number.isInteger(timeLimit) || timeLimit < 5 || timeLimit > 300) {
          return {
            success: false,
            message: `${difficulty} question time limit must be between 5 and 300 seconds`
          };
        }
      }

      for (const [difficulty, percentage] of Object.entries(questionDistribution)) {
        if (!Number.isInteger(percentage) || percentage < 0 || percentage > 100) {
          return {
            success: false,
            message: `${difficulty} question distribution must be between 0 and 100 percent`
          };
        }
      }

      const distributionTotal = questionDistribution.easy + questionDistribution.medium + questionDistribution.hard;
      if (distributionTotal !== 100) {
        return {
          success: false,
          message: 'Question distribution must add up to 100 percent'
        };
      }

      await gameSettingModel.set('gameEnabled', gameEnabled);
      await gameSettingModel.set('totalQuestions', totalQuestions);
      await gameSettingModel.set('randomQuestionOrderEnabled', randomQuestionOrderEnabled);
      await gameSettingModel.set('randomAnswerOrderEnabled', randomAnswerOrderEnabled);
      await gameSettingModel.set('questionTimeLimits', timeLimits);
      await gameSettingModel.set('questionDistribution', questionDistribution);

      await pool.query(
        `UPDATE questions
         SET time_limit = CASE difficulty
           WHEN 'easy' THEN ?
           WHEN 'medium' THEN ?
           WHEN 'hard' THEN ?
           ELSE time_limit
         END`,
        [timeLimits.easy, timeLimits.medium, timeLimits.hard]
      );

      logger.info(
        `Updated game settings: totalQuestions=${totalQuestions}, ` +
        `gameEnabled=${gameEnabled}, ` +
        `randomQuestionOrderEnabled=${randomQuestionOrderEnabled}, ` +
        `randomAnswerOrderEnabled=${randomAnswerOrderEnabled}, ` +
        `timeLimits=${JSON.stringify(timeLimits)}, ` +
        `questionDistribution=${JSON.stringify(questionDistribution)}`
      );

      return {
        success: true,
        settings: {
          totalQuestions,
          gameEnabled,
          randomQuestionOrderEnabled,
          randomAnswerOrderEnabled,
          timeLimits,
          questionDistribution
        }
      };
    } catch (error) {
      logger.error('Error updating game settings:', error);
      throw error;
    }
  }

  buildQuestionDistribution(totalQuestions, questionDistribution = DEFAULT_GAME_SETTINGS.questionDistribution) {
    const normalizedDistribution = this.normalizeQuestionDistribution(questionDistribution);
    const ratios = [
      { difficulty: 'easy', ratio: normalizedDistribution.easy / 100 },
      { difficulty: 'medium', ratio: normalizedDistribution.medium / 100 },
      { difficulty: 'hard', ratio: normalizedDistribution.hard / 100 }
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

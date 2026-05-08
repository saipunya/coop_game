const pool = require('../config/database');
const gameCodeModel = require('../models/gameCode.model');
const questionModel = require('../models/question.model');
const attemptModel = require('../models/attempt.model');
const attemptQuestionModel = require('../models/attemptQuestion.model');
const attemptAnswerModel = require('../models/attemptAnswer.model');
const gameSettingModel = require('../models/gameSetting.model');
const { generateGameCode, shuffleArray, calculateExpiry } = require('../utils/crypto');
const logger = require('../utils/logger');
const ANONYMOUS_PLAYER_NAME = 'Anonymous';
const QUESTION_TIME_LIMITS = {
  easy: 15,
  medium: 20,
  hard: 25
};

class GameService {
  /**
   * Verify game code and start game session
   * Uses transaction to ensure atomicity
   */
  async verifyCode(code) {
    const connection = await pool.getConnection();
    
    try {
      await connection.beginTransaction();

      // Validate code format
      if (!code || code.length !== 6 || !/^[A-Z0-9]{6}$/.test(code.toUpperCase())) {
        await connection.rollback();
        return { success: false, message: 'Code must be 6 alphanumeric characters' };
      }

      const upperCode = code.toUpperCase();

      // Check code with lock (FOR UPDATE prevents race conditions)
      const [codes] = await connection.query(
        'SELECT * FROM game_codes WHERE code = ? FOR UPDATE',
        [upperCode]
      );

      if (codes.length === 0) {
        await connection.rollback();
        return { success: false, message: 'Code not found' };
      }

      const gameCode = codes[0];

      // Check if code is expired
      if (new Date() > gameCode.expires_at) {
        await connection.query(
          'UPDATE game_codes SET status = ? WHERE id = ?',
          ['expired', gameCode.id]
        );
        await connection.rollback();
        return { success: false, message: 'Code has expired' };
      }

      // Check if code is already used
      if (gameCode.status !== 'unused') {
        await connection.rollback();
        return { success: false, message: 'Code has already been used' };
      }

      // Lock the code (in_progress)
      await connection.query(
        'UPDATE game_codes SET status = ?, used_at = NOW() WHERE id = ?',
        ['in_progress', gameCode.id]
      );

      logger.info(`Code ${upperCode} locked for new game`);

      // Create game attempt
      const [attemptResult] = await connection.query(
        'INSERT INTO game_attempts (game_code_id, status) VALUES (?, ?)',
        [gameCode.id, 'in_progress']
      );

      const attemptId = attemptResult.insertId;
      logger.info(`Created attempt ${attemptId} for code ${upperCode}`);

      const gameSettings = await this.getGameSettings();

      // Select random questions
      const questions = await this.selectRandomQuestions(connection, gameSettings.totalQuestions);
      
      if (questions.length === 0) {
        await connection.rollback();
        return { success: false, message: 'No questions available in the system' };
      }

      // Assign questions to attempt
      for (let i = 0; i < questions.length; i++) {
        await connection.query(
          'INSERT INTO attempt_questions (attempt_id, question_id, question_order) VALUES (?, ?, ?)',
          [attemptId, questions[i].id, i + 1]
        );
      }

      await connection.commit();

      return {
        success: true,
        attemptId: attemptId,
        totalQuestions: questions.length
      };

    } catch (error) {
      await connection.rollback();
      logger.error('Error verifying code:', error);
      throw error;
    } finally {
      connection.release();
    }
  }

  /**
   * Select random questions from each difficulty level
   * Returns questions in easy -> medium -> hard order
   */
  async selectRandomQuestions(connection, totalQuestions) {
    const query = connection || pool;
    const distribution = this.buildQuestionDistribution(totalQuestions);
    const selectedIds = new Set();
    const selectedQuestions = [];

    const selectQuestionsByDifficulty = async (difficulty, limit) => {
      if (limit <= 0) {
        return [];
      }

      const [rows] = await query.query(
        'SELECT * FROM questions WHERE difficulty = ? AND is_active = ? ORDER BY RAND() LIMIT ?',
        [difficulty, true, limit]
      );

      rows.forEach(row => selectedIds.add(row.id));
      return rows;
    };

    selectedQuestions.push(...await selectQuestionsByDifficulty('easy', distribution.easy));
    selectedQuestions.push(...await selectQuestionsByDifficulty('medium', distribution.medium));
    selectedQuestions.push(...await selectQuestionsByDifficulty('hard', distribution.hard));

    if (selectedQuestions.length < totalQuestions) {
      const remaining = totalQuestions - selectedQuestions.length;
      const selectedIdList = Array.from(selectedIds);
      const exclusionClause = selectedIdList.length > 0
        ? `AND id NOT IN (${selectedIdList.map(() => '?').join(',')})`
        : '';

      const [fallbackRows] = await query.query(
        `SELECT * FROM questions
         WHERE is_active = ? ${exclusionClause}
         ORDER BY FIELD(difficulty, 'easy', 'medium', 'hard'), RAND()
         LIMIT ?`,
        selectedIdList.length > 0
          ? [true, ...selectedIdList, remaining]
          : [true, remaining]
      );

      fallbackRows.forEach(row => selectedIds.add(row.id));
      selectedQuestions.push(...fallbackRows);
    }

    return selectedQuestions.slice(0, totalQuestions);
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

  getQuestionTimeLimit(difficulty) {
    return QUESTION_TIME_LIMITS[difficulty] || QUESTION_TIME_LIMITS.easy;
  }

  async getGameSettings() {
    const totalQuestions = await gameSettingModel.getValue('totalQuestions', 9);
    const parsedTotalQuestions = parseInt(totalQuestions, 10);

    return {
      totalQuestions: Number.isInteger(parsedTotalQuestions) && parsedTotalQuestions > 0
        ? parsedTotalQuestions
        : 9
    };
  }

  /**
   * Get current question for attempt
   */
  async getCurrentQuestion(attemptId) {
    try {
      // Get attempt to check status
      const attempt = await attemptModel.findById(attemptId);

      if (!attempt || attempt.status !== 'in_progress') {
        return null;
      }

      // Get current question index
      const answeredCount = await attemptAnswerModel.getByAttemptId(attemptId);
      const currentQuestionIndex = answeredCount.length;

      // Get total questions
      const totalQuestions = await attemptQuestionModel.countByAttemptId(attemptId);

      if (currentQuestionIndex >= totalQuestions) {
        return null; // Game completed
      }

      // Get current question
      const questionData = await attemptQuestionModel.getByAttemptAndOrder(
        attemptId, 
        currentQuestionIndex + 1
      );

      if (!questionData) {
        return null;
      }

      return {
        id: questionData.id,
        text: questionData.question_text,
        options: {
          A: questionData.option_a,
          B: questionData.option_b,
          C: questionData.option_c,
          D: questionData.option_d
        },
        timeLimit: this.getQuestionTimeLimit(questionData.difficulty),
        questionNumber: currentQuestionIndex + 1,
        totalQuestions: totalQuestions
      };

    } catch (error) {
      logger.error('Error getting current question:', error);
      throw error;
    }
  }

  /**
   * Submit answer with server-side timeout validation
   */
  async submitAnswer(attemptId, questionId, answer, responseTime) {
    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      // Get attempt
      const [attempts] = await connection.query(
        'SELECT * FROM game_attempts WHERE id = ? FOR UPDATE',
        [attemptId]
      );

      if (attempts.length === 0) {
        await connection.rollback();
        return { success: false, message: 'Game not found' };
      }

      const attempt = attempts[0];

      if (attempt.status !== 'in_progress') {
        await connection.rollback();
        return { success: false, message: 'Game already finished' };
      }

      // Validate question belongs to this attempt
      const [mapping] = await connection.query(
        'SELECT * FROM attempt_questions WHERE attempt_id = ? AND question_id = ?',
        [attemptId, questionId]
      );

      if (mapping.length === 0) {
        await connection.rollback();
        return { success: false, message: 'Invalid question for this attempt' };
      }

      // Check if already answered
      const [answered] = await connection.query(
        'SELECT * FROM attempt_answers WHERE attempt_id = ? AND question_id = ?',
        [attemptId, questionId]
      );

      if (answered.length > 0) {
        await connection.rollback();
        return { success: false, message: 'This question was already answered' };
      }

      // Get question for validation
      const [questions] = await connection.query(
        'SELECT * FROM questions WHERE id = ?',
        [questionId]
      );

      if (questions.length === 0) {
        await connection.rollback();
        return { success: false, message: 'Question not found' };
      }

      const question = questions[0];

      // Server-side timeout check (anti-cheat)
      const timeLimit = this.getQuestionTimeLimit(question.difficulty);

      if (responseTime > timeLimit) {
        await connection.query(
          'UPDATE game_attempts SET status = ?, finished_at = NOW() WHERE id = ?',
          ['completed', attemptId]
        );

        // Record the timeout answer
        await connection.query(
          `INSERT INTO attempt_answers 
           (attempt_id, question_id, selected_answer, is_correct, response_time) 
           VALUES (?, ?, ?, ?, ?)`,
          [attemptId, questionId, answer || '', false, responseTime]
        );

        await connection.commit();
        logger.info(`Attempt ${attemptId} timed out on question ${questionId}`);

        return {
          success: true,
          isCorrect: false,
          gameOver: true,
          reason: 'timeout',
          finalScore: attempt.score
        };
      }

      // Validate answer
      const isCorrect = answer.toUpperCase() === question.correct_answer;

      // Record answer
      await connection.query(
        `INSERT INTO attempt_answers 
         (attempt_id, question_id, selected_answer, is_correct, response_time) 
         VALUES (?, ?, ?, ?, ?)`,
        [attemptId, questionId, answer.toUpperCase(), isCorrect, responseTime]
      );

      if (isCorrect) {
        // Update score
        const newScore = attempt.score + 1;
        await connection.query(
          'UPDATE game_attempts SET score = ?, total_time = total_time + ? WHERE id = ?',
          [newScore, responseTime, attemptId]
        );

        // Check if all questions answered
        const [questionCount] = await connection.query(
          'SELECT COUNT(*) as count FROM attempt_questions WHERE attempt_id = ?',
          [attemptId]
        );

        const [answerCount] = await connection.query(
          'SELECT COUNT(*) as count FROM attempt_answers WHERE attempt_id = ?',
          [attemptId]
        );

        if (answerCount[0].count >= questionCount[0].count) {
          // Game completed successfully
          await connection.query(
            'UPDATE game_attempts SET status = ?, finished_at = NOW() WHERE id = ?',
            ['completed', attemptId]
          );

          await connection.commit();
          logger.info(`Attempt ${attemptId} completed with score ${newScore}`);

          return {
            success: true,
            isCorrect: true,
            gameOver: true,
            finalScore: newScore,
            totalTime: attempt.total_time + responseTime
          };
        }

        await connection.commit();
        logger.info(`Attempt ${attemptId} answered correctly, score: ${newScore}`);

        return {
          success: true,
          isCorrect: true,
          gameOver: false,
          nextQuestionNumber: answerCount[0].count + 1
        };

      } else {
        // Wrong answer - game over
        await connection.query(
          'UPDATE game_attempts SET status = ?, finished_at = NOW() WHERE id = ?',
          ['completed', attemptId]
        );

        await connection.commit();
        logger.info(`Attempt ${attemptId} failed on question ${questionId}`);

        return {
          success: true,
          isCorrect: false,
          gameOver: true,
          reason: 'wrong_answer',
          finalScore: attempt.score
        };
      }

    } catch (error) {
      await connection.rollback();
      logger.error('Error submitting answer:', error);
      throw error;
    } finally {
      connection.release();
    }
  }

  /**
   * Finish game and save player info
   */
  async finishGame(attemptId, playerName, phoneNumber) {
    try {
      const attempt = await attemptModel.findById(attemptId);

      if (!attempt) {
        return { success: false, message: 'Game not found' };
      }

      const normalizedPlayerName = typeof playerName === 'string' && playerName.trim()
        ? playerName.trim()
        : ANONYMOUS_PLAYER_NAME;
      const normalizedPhoneNumber = typeof phoneNumber === 'string' && phoneNumber.trim()
        ? phoneNumber.trim()
        : null;

      // Update player info
      await attemptModel.updatePlayerInfo(attemptId, normalizedPlayerName, normalizedPhoneNumber);

      logger.info(`Attempt ${attemptId} finished with player: ${normalizedPlayerName}`);

      // Calculate rank
      const rank = await this.calculateRank(attempt.score, attempt.total_time, attempt.finished_at);

      return {
        success: true,
        score: attempt.score,
        totalTime: attempt.total_time,
        rank: rank
      };

    } catch (error) {
      logger.error('Error finishing game:', error);
      throw error;
    }
  }

  /**
   * Calculate player rank
   */
  async calculateRank(score, totalTime, finishedAt) {
    try {
      const [result] = await pool.query(
        `SELECT COUNT(*) as rank 
         FROM game_attempts 
         WHERE status = ? AND player_name IS NOT NULL 
         AND (score > ? OR (score = ? AND total_time < ?) OR (score = ? AND total_time = ? AND finished_at < ?))`,
        ['completed', score, score, totalTime, score, totalTime, finishedAt]
      );

      return result[0].rank + 1;

    } catch (error) {
      logger.error('Error calculating rank:', error);
      return null;
    }
  }

  /**
   * Get leaderboard
   */
  async getLeaderboard(limit = 50, offset = 0) {
    try {
      const attempts = await attemptModel.getCompletedAttempts(limit, offset);
      const total = await attemptModel.countCompleted();

      // Add rank to each entry
      const leaderboard = attempts.map((attempt, index) => ({
        rank: offset + index + 1,
        playerName: attempt.player_name,
        score: attempt.score,
        totalTime: attempt.total_time,
        finishedAt: attempt.finished_at,
        createdAt: attempt.started_at
      }));

      return {
        leaderboard,
        total,
        limit,
        offset
      };

    } catch (error) {
      logger.error('Error getting leaderboard:', error);
      throw error;
    }
  }

  /**
   * Get attempt by ID
   */
  async getAttempt(attemptId) {
    return await attemptModel.findById(attemptId);
  }
}

module.exports = new GameService();



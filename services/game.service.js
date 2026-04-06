const pool = require('../config/database');
const gameCodeModel = require('../models/gameCode.model');
const questionModel = require('../models/question.model');
const attemptModel = require('../models/attempt.model');
const attemptQuestionModel = require('../models/attemptQuestion.model');
const attemptAnswerModel = require('../models/attemptAnswer.model');
const { generateGameCode, shuffleArray, calculateExpiry } = require('../utils/crypto');
const logger = require('../utils/logger');

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
        return { success: false, message: 'รหัสต้องเป็น 6 ตัวอักษร' };
      }

      const upperCode = code.toUpperCase();

      // Check code with lock (FOR UPDATE prevents race conditions)
      const [codes] = await connection.query(
        'SELECT * FROM game_codes WHERE code = ? FOR UPDATE',
        [upperCode]
      );

      if (codes.length === 0) {
        await connection.rollback();
        return { success: false, message: 'รหัสไม่ถูกต้อง' };
      }

      const gameCode = codes[0];

      // Check if code is expired
      if (new Date() > gameCode.expires_at) {
        await connection.query(
          'UPDATE game_codes SET status = ? WHERE id = ?',
          ['expired', gameCode.id]
        );
        await connection.rollback();
        return { success: false, message: 'รหัสหมดอายุแล้ว' };
      }

      // Check if code is already used
      if (gameCode.status !== 'unused') {
        await connection.rollback();
        return { success: false, message: 'รหัสนี้ถูกใช้ไปแล้ว' };
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

      // Select random questions
      const questions = await this.selectRandomQuestions(connection);
      
      if (questions.length === 0) {
        await connection.rollback();
        return { success: false, message: 'ไม่มีคำถามในระบบ' };
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
   * Returns: 3 easy + 3 medium + 3 hard = 9 questions total
   */
  async selectRandomQuestions(connection) {
    const query = connection || pool;

    // Get 3 easy questions
    const [easy] = await query.query(
      'SELECT id FROM questions WHERE difficulty = ? AND is_active = ? ORDER BY RAND() LIMIT 3',
      ['easy', true]
    );

    // Get 3 medium questions
    const [medium] = await query.query(
      'SELECT id FROM questions WHERE difficulty = ? AND is_active = ? ORDER BY RAND() LIMIT 3',
      ['medium', true]
    );

    // Get 3 hard questions
    const [hard] = await query.query(
      'SELECT id FROM questions WHERE difficulty = ? AND is_active = ? ORDER BY RAND() LIMIT 3',
      ['hard', true]
    );

    const questionIds = [...easy, ...medium, ...hard].map(q => q.id);

    if (questionIds.length === 0) return [];

    // Get full question details
    const [questions] = await query.query(
      `SELECT * FROM questions WHERE id IN (${questionIds.map(() => '?').join(',')})`,
      questionIds
    );

    // Sort by difficulty (easy -> medium -> hard)
    const difficultyOrder = { 'easy': 1, 'medium': 2, 'hard': 3 };
    questions.sort((a, b) => difficultyOrder[a.difficulty] - difficultyOrder[b.difficulty]);

    return questions;
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
        timeLimit: questionData.time_limit,
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
        return { success: false, message: 'ไม่พบเกม' };
      }

      const attempt = attempts[0];

      if (attempt.status !== 'in_progress') {
        await connection.rollback();
        return { success: false, message: 'เกมนี้จบแล้ว' };
      }

      // Validate question belongs to this attempt
      const [mapping] = await connection.query(
        'SELECT * FROM attempt_questions WHERE attempt_id = ? AND question_id = ?',
        [attemptId, questionId]
      );

      if (mapping.length === 0) {
        await connection.rollback();
        return { success: false, message: 'คำถามไม่ถูกต้อง' };
      }

      // Check if already answered
      const [answered] = await connection.query(
        'SELECT * FROM attempt_answers WHERE attempt_id = ? AND question_id = ?',
        [attemptId, questionId]
      );

      if (answered.length > 0) {
        await connection.rollback();
        return { success: false, message: 'คำถามนี้ตอบไปแล้ว' };
      }

      // Get question for validation
      const [questions] = await connection.query(
        'SELECT * FROM questions WHERE id = ?',
        [questionId]
      );

      if (questions.length === 0) {
        await connection.rollback();
        return { success: false, message: 'ไม่พบคำถาม' };
      }

      const question = questions[0];

      // Server-side timeout check (anti-cheat)
      if (responseTime > question.time_limit) {
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
        return { success: false, message: 'ไม่พบเกม' };
      }

      // Update player info
      await attemptModel.updatePlayerInfo(attemptId, playerName, phoneNumber);

      logger.info(`Attempt ${attemptId} finished with player: ${playerName}`);

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
        finishedAt: attempt.finished_at
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

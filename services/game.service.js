const pool = require('../config/database');
const gameCodeModel = require('../models/gameCode.model');
const questionModel = require('../models/question.model');
const attemptModel = require('../models/attempt.model');
const attemptQuestionModel = require('../models/attemptQuestion.model');
const attemptAnswerModel = require('../models/attemptAnswer.model');
const gameSettingModel = require('../models/gameSetting.model');
const roomModel = require('../models/room.model');
const { generateGameCode, shuffleArray, calculateExpiry } = require('../utils/crypto');
const logger = require('../utils/logger');
const ANONYMOUS_PLAYER_NAME = 'Anonymous';
const DEFAULT_QUESTION_DISTRIBUTION = {
  easy: 30,
  medium: 35,
  hard: 35
};
const DEFAULT_RANDOM_SETTINGS = {
  randomQuestionOrderEnabled: true,
  randomAnswerOrderEnabled: true
};
const DEFAULT_IP_ACCESS_LIMIT = 2;
const QUESTION_DIFFICULTY_ORDER = {
  easy: 1,
  medium: 2,
  hard: 3
};

class GameService {
  /**
   * Verify game code and start game session
   * Uses transaction to ensure atomicity
   */
  async verifyCode(code, roomId = null, clientIp = null) {
    await attemptQuestionModel.ensureOptionOrderColumn();
    await this.ensureAttemptAnswerNullableColumn();
    await attemptModel.ensureClientIpColumn();
    const connection = await pool.getConnection();
    
    try {
      await connection.beginTransaction();

      const normalizedCode = String(code || '').trim();

      // Validate code format
      if (!normalizedCode || normalizedCode.length !== 6 || !/^\d{6}$/.test(normalizedCode)) {
        await connection.rollback();
        return { success: false, message: 'Code must be 6 numeric digits' };
      }

      const upperCode = normalizedCode;

      // Check code with lock (FOR UPDATE prevents race conditions)
      const codeParams = [upperCode];
      const roomClause = roomId ? ' AND room_id = ?' : '';
      if (roomId) codeParams.push(roomId);
      const [codes] = await connection.query(
        `SELECT * FROM game_codes WHERE code = ?${roomClause} FOR UPDATE`,
        codeParams
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

      logger.info(`Code ${upperCode} locked for new game`);

      const gameSettings = await this.getGameSettings(gameCode.room_id);
      if (!gameSettings.gameEnabled) {
        await connection.rollback();
        return { success: false, message: 'ระบบเกมปิดอยู่ชั่วคราว กรุณาติดต่อผู้ดูแลระบบ' };
      }

      if (gameSettings.ipAccessLockEnabled && clientIp) {
        const ipAttemptCount = await attemptModel.countByClientIp(
          gameCode.room_id,
          clientIp,
          connection
        );

        if (ipAttemptCount >= DEFAULT_IP_ACCESS_LIMIT) {
          await connection.rollback();
          return {
            success: false,
            message: `IP นี้เข้าใช้งานครบ ${DEFAULT_IP_ACCESS_LIMIT} ครั้งแล้ว กรุณาติดต่อผู้ดูแลระบบ`
          };
        }
      }

      const result = await this.createGameAttempt(
        connection,
        gameCode.id,
        gameSettings.totalQuestions,
        gameSettings.questionDistribution,
        {
          randomQuestionOrderEnabled: gameSettings.randomQuestionOrderEnabled,
          randomAnswerOrderEnabled: gameSettings.randomAnswerOrderEnabled
        },
        gameCode.room_id,
        clientIp
      );

      if (!result.success) {
        await connection.rollback();
        return result;
      }

      await connection.commit();

      return {
        success: true,
        attemptId: result.attemptId,
        totalQuestions: result.totalQuestions
      };

    } catch (error) {
      await connection.rollback();
      logger.error('Error verifying code:', error);
      throw error;
    } finally {
      connection.release();
    }
  }

  async createGameAttempt(
    connection,
    gameCodeId,
    totalQuestions,
    questionDistribution = DEFAULT_QUESTION_DISTRIBUTION,
    randomSettings = DEFAULT_RANDOM_SETTINGS,
    roomId = null,
    clientIp = null
  ) {
    const query = connection || pool;
    const normalizedRandomSettings = this.normalizeRandomSettings(randomSettings);

    await query.query(
      'UPDATE game_codes SET status = ?, used_at = NOW() WHERE id = ?',
      ['in_progress', gameCodeId]
    );

    const attemptColumns = ['game_code_id', 'status'];
    const attemptValues = [gameCodeId, 'in_progress'];

    if (clientIp) {
      await attemptModel.ensureClientIpColumn(query);
      attemptColumns.push('client_ip');
      attemptValues.push(clientIp);
    }

    if (await this.tableHasColumn(query, 'game_attempts', 'room_id')) {
      const resolvedRoomId = roomId || await this.resolveAttemptRoomId(query, gameCodeId);
      attemptColumns.unshift('room_id');
      attemptValues.unshift(resolvedRoomId);
    }

    const [attemptResult] = await query.query(
      `INSERT INTO game_attempts (${attemptColumns.join(', ')}) VALUES (${attemptColumns.map(() => '?').join(', ')})`,
      attemptValues
    );

    const attemptId = attemptResult.insertId;
    logger.info(`Created attempt ${attemptId} for game code ${gameCodeId}`);

    const questions = await this.selectRandomQuestions(
      connection,
      totalQuestions,
      questionDistribution,
      normalizedRandomSettings.randomQuestionOrderEnabled,
      roomId || await this.resolveAttemptRoomId(query, gameCodeId)
    );

    if (questions.length === 0) {
      return { success: false, message: 'No questions available in the system' };
    }

    for (let i = 0; i < questions.length; i += 1) {
      const optionOrder = normalizedRandomSettings.randomAnswerOrderEnabled
        ? this.shuffleOptionOrder()
        : ['A', 'B', 'C', 'D'];
      await query.query(
        'INSERT INTO attempt_questions (attempt_id, question_id, question_order, option_order) VALUES (?, ?, ?, ?)',
        [attemptId, questions[i].id, i + 1, JSON.stringify(optionOrder)]
      );
    }

    return {
      success: true,
      attemptId,
      totalQuestions: questions.length
    };
  }

  shuffleOptionOrder() {
    return shuffleArray(['A', 'B', 'C', 'D']);
  }

  normalizeRandomSettings(rawSettings = {}) {
    return {
      randomQuestionOrderEnabled: rawSettings?.randomQuestionOrderEnabled !== false,
      randomAnswerOrderEnabled: rawSettings?.randomAnswerOrderEnabled !== false
    };
  }

  async tableHasColumn(query, tableName, columnName) {
    const [rows] = await query.query(
      `SHOW COLUMNS FROM ${tableName} LIKE ?`,
      [columnName]
    );
    return rows.length > 0;
  }

  async resolveAttemptRoomId(query, gameCodeId) {
    const [rows] = await query.query(
      'SELECT room_id FROM game_codes WHERE id = ?',
      [gameCodeId]
    );

    const roomId = rows[0]?.room_id;
    if (!roomId) {
      const error = new Error('Game code does not have a valid room_id');
      error.code = 'MISSING_GAME_CODE_ROOM';
      throw error;
    }

    return roomId;
  }

  async insertGameCode(query, code, status, expiresAt, roomId = null) {
    const requiredRelations = await gameCodeModel.resolveRequiredInsertRelations(query, roomId);
    const columns = [];
    const values = [];

    if (requiredRelations.room_id !== undefined) {
      columns.push('room_id');
      values.push(requiredRelations.room_id);
    }

    columns.push('code', 'status', 'expires_at');
    values.push(code, status, expiresAt);

    if (requiredRelations.category_id !== undefined) {
      columns.push('category_id');
      values.push(requiredRelations.category_id);
    }

    const [result] = await query.query(
      `INSERT INTO game_codes (${columns.join(', ')}) VALUES (${columns.map(() => '?').join(', ')})`,
      values
    );

    return result.insertId;
  }

  parseOptionOrder(optionOrder) {
    try {
      const parsed = typeof optionOrder === 'string' ? JSON.parse(optionOrder) : optionOrder;
      if (
        Array.isArray(parsed) &&
        parsed.length === 4 &&
        ['A', 'B', 'C', 'D'].every(key => parsed.includes(key))
      ) {
        return parsed;
      }
    } catch (error) {
      // Fall through to default order.
    }

    return ['A', 'B', 'C', 'D'];
  }

  getDisplayedOptions(questionData) {
    const sourceOptions = {
      A: questionData.option_a,
      B: questionData.option_b,
      C: questionData.option_c,
      D: questionData.option_d
    };
    const optionOrder = this.parseOptionOrder(questionData.option_order);

    return ['A', 'B', 'C', 'D'].reduce((options, displayKey, index) => {
      const sourceKey = optionOrder[index];
      options[displayKey] = sourceOptions[sourceKey];
      return options;
    }, {});
  }

  getSourceAnswerFromDisplayedAnswer(displayedAnswer, optionOrder) {
    const displayIndex = ['A', 'B', 'C', 'D'].indexOf(String(displayedAnswer || '').toUpperCase());
    if (displayIndex === -1) {
      return '';
    }

    return this.parseOptionOrder(optionOrder)[displayIndex];
  }

  /**
   * Select random questions from each difficulty level
   * Returns questions in easy -> medium -> hard order
   */
  async selectRandomQuestions(
    connection,
    totalQuestions,
    questionDistribution = DEFAULT_QUESTION_DISTRIBUTION,
    randomQuestionOrderEnabled = true,
    roomId = null
  ) {
    const query = connection || pool;
    const distribution = this.buildQuestionDistribution(totalQuestions, questionDistribution);
    const selectedIds = new Set();
    const selectedQuestions = [];
    const shouldRandomize = randomQuestionOrderEnabled !== false;

    const selectQuestionsByDifficulty = async (difficulty, limit) => {
      if (limit <= 0) {
        return [];
      }

      const [rows] = await query.query(
        `SELECT * FROM questions
         WHERE difficulty = ? AND is_active = ? ${roomId ? 'AND room_id = ?' : ''}
         ORDER BY ${shouldRandomize ? 'RAND()' : 'id ASC'}
         LIMIT ?`,
        roomId ? [difficulty, true, roomId, limit] : [difficulty, true, limit]
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
         WHERE is_active = ? ${roomId ? 'AND room_id = ?' : ''} ${exclusionClause}
         ORDER BY FIELD(difficulty, 'easy', 'medium', 'hard'), ${shouldRandomize ? 'RAND()' : 'id ASC'}
         LIMIT ?`,
        selectedIdList.length > 0
          ? (roomId ? [true, roomId, ...selectedIdList, remaining] : [true, ...selectedIdList, remaining])
          : (roomId ? [true, roomId, remaining] : [true, remaining])
      );

      fallbackRows.forEach(row => selectedIds.add(row.id));
      selectedQuestions.push(...fallbackRows);
    }

    const finalQuestions = selectedQuestions.slice(0, totalQuestions);
    return this.orderQuestionsByDifficulty(finalQuestions);
  }

  orderQuestionsByDifficulty(questions = []) {
    return questions
      .map((question, index) => ({ question, index }))
      .sort((a, b) => {
        const difficultyDiff = (QUESTION_DIFFICULTY_ORDER[a.question.difficulty] || 99)
          - (QUESTION_DIFFICULTY_ORDER[b.question.difficulty] || 99);
        if (difficultyDiff !== 0) return difficultyDiff;
        return a.index - b.index;
      })
      .map(item => item.question);
  }

  normalizeQuestionDistribution(rawDistribution = {}) {
    const distribution = {};

    for (const difficulty of ['easy', 'medium', 'hard']) {
      const parsed = parseInt(rawDistribution?.[difficulty], 10);
      distribution[difficulty] = Number.isInteger(parsed) && parsed >= 0 && parsed <= 100
        ? parsed
        : DEFAULT_QUESTION_DISTRIBUTION[difficulty];
    }

    const total = distribution.easy + distribution.medium + distribution.hard;
    if (total !== 100 || total <= 0) {
      return { ...DEFAULT_QUESTION_DISTRIBUTION };
    }

    return distribution;
  }

  buildQuestionDistribution(totalQuestions, questionDistribution = DEFAULT_QUESTION_DISTRIBUTION) {
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

  getQuestionTimeLimit(question) {
    const parsedTimeLimit = parseInt(question?.time_limit, 10);
    return Number.isInteger(parsedTimeLimit) && parsedTimeLimit > 0 ? parsedTimeLimit : 15;
  }

  async getGameSettings(roomId = 1) {
    const gameEnabled = await gameSettingModel.getValue('gameEnabled', true, roomId);
    const totalQuestions = await gameSettingModel.getValue('totalQuestions', 9, roomId);
    const questionDistribution = await gameSettingModel.getValue(
      'questionDistribution',
      DEFAULT_QUESTION_DISTRIBUTION,
      roomId
    );
    const randomQuestionOrderEnabled = await gameSettingModel.getValue(
      'randomQuestionOrderEnabled',
      DEFAULT_RANDOM_SETTINGS.randomQuestionOrderEnabled,
      roomId
    );
    const randomAnswerOrderEnabled = await gameSettingModel.getValue(
      'randomAnswerOrderEnabled',
      DEFAULT_RANDOM_SETTINGS.randomAnswerOrderEnabled,
      roomId
    );
    const ipAccessLockEnabled = await gameSettingModel.getValue(
      'ipAccessLockEnabled',
      false,
      roomId
    );
    const parsedTotalQuestions = parseInt(totalQuestions, 10);

    return {
      gameEnabled: gameEnabled !== false,
      totalQuestions: Number.isInteger(parsedTotalQuestions) && parsedTotalQuestions > 0
        ? parsedTotalQuestions
        : 9,
      questionDistribution: this.normalizeQuestionDistribution(questionDistribution),
      randomQuestionOrderEnabled: randomQuestionOrderEnabled !== false,
      randomAnswerOrderEnabled: randomAnswerOrderEnabled !== false,
      ipAccessLockEnabled: ipAccessLockEnabled === true
    };
  }

  /**
   * Get current question for attempt
   */
  async getCurrentQuestion(attemptId) {
    try {
      // Get attempt to check status
      const attempt = await attemptModel.findByIdWithCode(attemptId);

      if (!attempt || attempt.status !== 'in_progress') {
        return null;
      }

      const isAdminAttempt = typeof attempt.game_code === 'string' && attempt.game_code.startsWith('ADM');

      if (!isAdminAttempt) {
        const gameSettings = await this.getGameSettings(attempt.room_id);
        if (!gameSettings.gameEnabled) {
          return {
            gameDisabled: true,
            message: 'ระบบเกมปิดอยู่ชั่วคราว กรุณาติดต่อผู้ดูแลระบบ'
          };
        }
      }

      if (!attempt.player_name || !attempt.phone_number) {
        return { playerInfoRequired: true };
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
        options: this.getDisplayedOptions(questionData),
        difficulty: questionData.difficulty,
        timeLimit: this.getQuestionTimeLimit(questionData),
        questionNumber: currentQuestionIndex + 1,
        totalQuestions: totalQuestions
      };

    } catch (error) {
      logger.error('Error getting current question:', error);
      throw error;
    }
  }

  async savePlayerInfo(attemptId, playerName, phoneNumber) {
    try {
      const attempt = await attemptModel.findById(attemptId);

      if (!attempt || attempt.status !== 'in_progress') {
        return { success: false, message: 'Game not found or already finished' };
      }

      const normalizedPlayerName = typeof playerName === 'string' ? playerName.trim() : '';
      const normalizedPhoneNumber = typeof phoneNumber === 'string'
        ? phoneNumber.replace(/[^\d]/g, '')
        : '';

      if (!normalizedPlayerName) {
        return { success: false, message: 'กรุณากรอกชื่อผู้เล่น' };
      }

      if (!/^\d{9,10}$/.test(normalizedPhoneNumber)) {
        return { success: false, message: 'กรุณากรอกเบอร์โทรศัพท์ 9-10 หลัก' };
      }

      await attemptModel.updatePlayerInfo(attemptId, normalizedPlayerName, normalizedPhoneNumber);

      logger.info(`Saved player info for attempt ${attemptId}: ${normalizedPlayerName}`);

      return {
        success: true,
        player: {
          playerName: normalizedPlayerName,
          phoneNumber: normalizedPhoneNumber
        }
      };
    } catch (error) {
      logger.error('Error saving player info:', error);
      throw error;
    }
  }

  async startAdminGame(roomId = null) {
    await attemptQuestionModel.ensureOptionOrderColumn();
    await this.ensureAttemptAnswerNullableColumn();
    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      const resolvedRoomId = roomId || (await roomModel.findDefault())?.id;
      const gameSettings = await this.getGameSettings(resolvedRoomId);
      const expiresAt = calculateExpiry(24);
      let gameCodeId = null;

      for (let attempt = 0; attempt < 8; attempt += 1) {
        const adminCode = `ADM${generateGameCode().slice(0, 3)}`;

        const [existing] = await connection.query(
          'SELECT id FROM game_codes WHERE code = ? FOR UPDATE',
          [adminCode]
        );

        if (existing.length > 0) {
          continue;
        }

        gameCodeId = await this.insertGameCode(connection, adminCode, 'unused', expiresAt, resolvedRoomId);
        break;
      }

      if (!gameCodeId) {
        await connection.rollback();
        return { success: false, message: 'ไม่สามารถสร้างเกมทดสอบได้ กรุณาลองใหม่' };
      }

      const result = await this.createGameAttempt(
        connection,
        gameCodeId,
        gameSettings.totalQuestions,
        gameSettings.questionDistribution,
        {
          randomQuestionOrderEnabled: gameSettings.randomQuestionOrderEnabled,
          randomAnswerOrderEnabled: gameSettings.randomAnswerOrderEnabled
        },
        resolvedRoomId
      );

      if (!result.success) {
        await connection.rollback();
        return result;
      }

      await connection.commit();

      logger.info(`Created admin game attempt ${result.attemptId}`);

      return {
        success: true,
        attemptId: result.attemptId,
        totalQuestions: result.totalQuestions
      };
    } catch (error) {
      await connection.rollback();
      logger.error('Error starting admin game:', error);
      throw error;
    } finally {
      connection.release();
    }
  }

  /**
   * Submit answer with server-side timeout validation
   */
  async submitAnswer(attemptId, questionId, answer, responseTime) {
    await this.ensureAttemptAnswerNullableColumn();
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

      const [codeRows] = await connection.query(
        'SELECT code FROM game_codes WHERE id = ?',
        [attempt.game_code_id]
      );
      const gameCode = codeRows[0]?.code || '';
      const isAdminAttempt = gameCode.startsWith('ADM');

      if (!isAdminAttempt) {
        const gameSettings = await this.getGameSettings(attempt.room_id);
        if (!gameSettings.gameEnabled) {
          await connection.rollback();
          return { success: false, message: 'ระบบเกมปิดอยู่ชั่วคราว กรุณาติดต่อผู้ดูแลระบบ' };
        }
      }

      if (!attempt.player_name || !attempt.phone_number) {
        await connection.rollback();
        return { success: false, message: 'กรุณากรอกชื่อและเบอร์โทรศัพท์ก่อนเริ่มเกม' };
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
      const timeLimit = this.getQuestionTimeLimit(question);

      if (!answer || responseTime > timeLimit) {
        await connection.query(
          'UPDATE game_attempts SET status = ?, finished_at = NOW() WHERE id = ?',
          ['completed', attemptId]
        );

        // Record the timeout answer
        await connection.query(
          `INSERT INTO attempt_answers 
           (attempt_id, question_id, selected_answer, is_correct, response_time) 
           VALUES (?, ?, ?, ?, ?)`,
          [attemptId, questionId, null, false, responseTime]
        );

        const [completedAttempts] = await connection.query(
          'SELECT score, total_time, finished_at FROM game_attempts WHERE id = ?',
          [attemptId]
        );
        const completedAttempt = completedAttempts[0] || attempt;

        await connection.commit();
        logger.info(`Attempt ${attemptId} timed out on question ${questionId}`);

        const rank = !attempt.player_name
          ? null
          : await this.calculateRank(
            completedAttempt.score,
            completedAttempt.total_time,
            completedAttempt.finished_at,
            attempt.room_id
          );

        return {
          success: true,
          isCorrect: false,
          gameOver: true,
          reason: 'timeout',
          finalScore: completedAttempt.score,
          rank
        };
      }

      // Validate answer
      const attemptQuestion = mapping[0];
      const selectedSourceAnswer = this.getSourceAnswerFromDisplayedAnswer(
        answer,
        attemptQuestion.option_order
      );
      const isCorrect = selectedSourceAnswer === question.correct_answer;

      // Record answer
      await connection.query(
        `INSERT INTO attempt_answers 
         (attempt_id, question_id, selected_answer, is_correct, response_time) 
         VALUES (?, ?, ?, ?, ?)`,
        [attemptId, questionId, selectedSourceAnswer || answer.toUpperCase(), isCorrect, responseTime]
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

          const [completedAttempts] = await connection.query(
            'SELECT score, total_time, finished_at FROM game_attempts WHERE id = ?',
            [attemptId]
          );
          const completedAttempt = completedAttempts[0] || {
            score: newScore,
            total_time: attempt.total_time + responseTime,
            finished_at: new Date()
          };

          await connection.commit();
          logger.info(`Attempt ${attemptId} completed with score ${newScore}`);

          const rank = !attempt.player_name
            ? null
            : await this.calculateRank(
              completedAttempt.score,
              completedAttempt.total_time,
              completedAttempt.finished_at,
              attempt.room_id
            );

          return {
            success: true,
            isCorrect: true,
            gameOver: true,
            finalScore: completedAttempt.score,
            totalTime: completedAttempt.total_time,
            rank
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

        const [completedAttempts] = await connection.query(
          'SELECT score, total_time, finished_at FROM game_attempts WHERE id = ?',
          [attemptId]
        );
        const completedAttempt = completedAttempts[0] || attempt;

        await connection.commit();
        logger.info(`Attempt ${attemptId} failed on question ${questionId}`);

        const rank = !attempt.player_name
          ? null
          : await this.calculateRank(
            completedAttempt.score,
            completedAttempt.total_time,
            completedAttempt.finished_at,
            attempt.room_id
          );

        return {
          success: true,
          isCorrect: false,
          gameOver: true,
          reason: 'wrong_answer',
          finalScore: completedAttempt.score,
          rank
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
      const attempt = await attemptModel.findByIdWithCode(attemptId);

      if (!attempt) {
        return { success: false, message: 'Game not found' };
      }

      const isAdminAttempt = typeof attempt.game_code === 'string' && attempt.game_code.startsWith('ADM');

      const normalizedPlayerName = typeof playerName === 'string' && playerName.trim()
        ? playerName.trim()
        : ANONYMOUS_PLAYER_NAME;
      const normalizedPhoneNumber = typeof phoneNumber === 'string' && phoneNumber.trim()
        ? phoneNumber.trim()
        : null;

      await attemptModel.updatePlayerInfo(attemptId, normalizedPlayerName, normalizedPhoneNumber);

      logger.info(`Attempt ${attemptId} finished with player: ${normalizedPlayerName}`);

      const rank = normalizedPlayerName && attempt.finished_at
        ? await this.calculateRank(attempt.score, attempt.total_time, attempt.finished_at, attempt.room_id)
        : null;

      return {
        success: true,
        score: attempt.score,
        totalTime: attempt.total_time,
        rank: rank,
        isAdminAttempt
      };

    } catch (error) {
      logger.error('Error finishing game:', error);
      throw error;
    }
  }

  /**
   * Calculate player rank
   */
  async calculateRank(score, totalTime, finishedAt, roomId = null) {
    try {
      const params = ['completed', score, score, totalTime, score, totalTime, finishedAt];
      const roomClause = roomId ? ' AND ga.room_id = ?' : '';
      if (roomId) params.push(roomId);

      const [result] = await pool.query(
        `SELECT COUNT(*) as rank
         FROM game_attempts ga
         JOIN game_codes gc ON gc.id = ga.game_code_id
         WHERE ga.status = ? AND ga.player_name IS NOT NULL
         AND (ga.score > ? OR (ga.score = ? AND ga.total_time < ?) OR (ga.score = ? AND ga.total_time = ? AND ga.finished_at < ?))
         ${roomClause}`,
        params
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
  async getLeaderboard(limit = 50, offset = 0, roomId = null) {
    try {
      const attempts = await attemptModel.getCompletedAttempts(limit, offset, roomId);
      const total = await attemptModel.countCompleted(roomId);

      // Add rank to each entry
      const leaderboard = attempts.map((attempt, index) => ({
        rank: offset + index + 1,
        playerName: attempt.player_name,
        score: attempt.score,
        totalTime: attempt.total_time,
        finishedAt: attempt.finished_at,
        createdAt: attempt.started_at,
        roomId: attempt.room_id,
        roomName: attempt.room_name,
        roomSlug: attempt.room_slug
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

  async getAttemptWithCode(attemptId) {
    return await attemptModel.findByIdWithCode(attemptId);
  }

  async ensureAttemptAnswerNullableColumn() {
    const [columns] = await pool.query(
      `SHOW COLUMNS FROM attempt_answers LIKE 'selected_answer'`
    );
    const selectedAnswerColumn = columns[0];

    if (selectedAnswerColumn && selectedAnswerColumn.Null === 'NO') {
      await pool.query(
        `ALTER TABLE attempt_answers
         MODIFY selected_answer ENUM('A', 'B', 'C', 'D') NULL`
      );
    }
  }
}

module.exports = new GameService();

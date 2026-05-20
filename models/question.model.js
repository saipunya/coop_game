const pool = require('../config/database');

class QuestionModel {
  async ensureCreatedByColumn() {
    const [rows] = await pool.query("SHOW COLUMNS FROM questions LIKE 'created_by'");
    if (rows.length === 0) {
      await pool.query(
        `ALTER TABLE questions
         ADD COLUMN created_by VARCHAR(100) NULL AFTER room_id,
         ADD INDEX idx_questions_created_by (created_by)`
      );
    }
  }

  async resolveRoomId(preferredRoomId, query = pool) {
    const parsedPreferredRoomId = parseInt(preferredRoomId, 10);

    if (Number.isInteger(parsedPreferredRoomId) && parsedPreferredRoomId > 0) {
      const [roomRows] = await query.query(
        'SELECT id FROM rooms WHERE id = ? LIMIT 1',
        [parsedPreferredRoomId]
      );

      if (roomRows.length > 0) {
        return roomRows[0].id;
      }
    }

    const [fallbackRows] = await query.query(
      'SELECT id FROM rooms ORDER BY id ASC LIMIT 1'
    );

    if (fallbackRows.length === 0) {
      const error = new Error('No rooms available');
      error.code = 'NO_ROOMS_AVAILABLE';
      throw error;
    }

    return fallbackRows[0].id;
  }

  // Get question by ID
  async findById(id) {
    await this.ensureCreatedByColumn();
    const [rows] = await pool.query(
      'SELECT * FROM questions WHERE id = ?',
      [id]
    );
    return rows[0] || null;
  }

  // Get questions by difficulty
  async getByDifficulty(difficulty, limit = 10) {
    await this.ensureCreatedByColumn();
    const [rows] = await pool.query(
      'SELECT * FROM questions WHERE difficulty = ? AND is_active = ? ORDER BY RAND() LIMIT ?',
      [difficulty, true, limit]
    );
    return rows;
  }

  // Get questions by IDs
  async findByIds(ids) {
    await this.ensureCreatedByColumn();
    if (ids.length === 0) return [];
    const [rows] = await pool.query(
      `SELECT * FROM questions WHERE id IN (${ids.map(() => '?').join(',')})`,
      ids
    );
    return rows;
  }

  // Get all active questions
  async getAllActive() {
    await this.ensureCreatedByColumn();
    const [rows] = await pool.query(
      'SELECT * FROM questions WHERE is_active = ? ORDER BY difficulty, id',
      [true]
    );
    return rows;
  }

  // Get all questions (including inactive) for admin
  async getAll() {
    await this.ensureCreatedByColumn();
    const [rows] = await pool.query(
      'SELECT * FROM questions ORDER BY id DESC'
    );
    return rows;
  }

  async getByCreator(createdBy) {
    await this.ensureCreatedByColumn();
    const [rows] = await pool.query(
      'SELECT * FROM questions WHERE created_by = ? ORDER BY id DESC',
      [createdBy]
    );
    return rows;
  }

  // Create new question
  async create(questionData) {
    const {
      questionText,
      optionA,
      optionB,
      optionC,
      optionD,
      correctAnswer,
      difficulty,
      timeLimit,
      roomId,
      createdBy
    } = questionData;

    await this.ensureCreatedByColumn();
    const resolvedRoomId = await this.resolveRoomId(roomId);

    const [result] = await pool.query(
      `INSERT INTO questions 
       (room_id, created_by, question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty, time_limit) 
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [resolvedRoomId, createdBy || null, questionText, optionA, optionB, optionC, optionD, correctAnswer, difficulty, timeLimit]
    );
    return result.insertId;
  }

  async createWithConnection(connection, questionData) {
    const {
      questionText,
      optionA,
      optionB,
      optionC,
      optionD,
      correctAnswer,
      difficulty,
      timeLimit,
      roomId,
      createdBy
    } = questionData;

    await this.ensureCreatedByColumn();
    const resolvedRoomId = await this.resolveRoomId(roomId, connection);

    const [result] = await connection.query(
      `INSERT INTO questions 
       (room_id, created_by, question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty, time_limit) 
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [resolvedRoomId, createdBy || null, questionText, optionA, optionB, optionC, optionD, correctAnswer, difficulty, timeLimit]
    );
    return result.insertId;
  }

  // Update question
  async update(id, questionData) {
    await this.ensureCreatedByColumn();
    const {
      questionText,
      optionA,
      optionB,
      optionC,
      optionD,
      correctAnswer,
      difficulty,
      timeLimit,
      isActive
    } = questionData;

    const normalizedIsActive = isActive === undefined ? null : isActive;

    const [result] = await pool.query(
      `UPDATE questions 
       SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, 
           correct_answer = ?, difficulty = ?, time_limit = ?, is_active = COALESCE(?, is_active)
       WHERE id = ?`,
      [questionText, optionA, optionB, optionC, optionD, correctAnswer, difficulty, timeLimit, normalizedIsActive, id]
    );
    return result.affectedRows > 0;
  }

  async updateByCreator(id, creator, questionData) {
    await this.ensureCreatedByColumn();
    const {
      questionText,
      optionA,
      optionB,
      optionC,
      optionD,
      correctAnswer,
      difficulty,
      timeLimit,
      isActive
    } = questionData;

    const normalizedIsActive = isActive === undefined ? null : isActive;

    const [result] = await pool.query(
      `UPDATE questions
       SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?,
           correct_answer = ?, difficulty = ?, time_limit = ?, is_active = COALESCE(?, is_active)
       WHERE id = ? AND created_by = ?`,
      [questionText, optionA, optionB, optionC, optionD, correctAnswer, difficulty, timeLimit, normalizedIsActive, id, creator]
    );

    return result.affectedRows > 0;
  }

  // Delete question (soft delete - set is_active = false)
  async softDelete(id) {
    await this.ensureCreatedByColumn();
    const [result] = await pool.query(
      'UPDATE questions SET is_active = ? WHERE id = ?',
      [false, id]
    );
    return result.affectedRows > 0;
  }

  // Delete question permanently, including game-history references to it
  async deletePermanently(id) {
    await this.ensureCreatedByColumn();
    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      const [answerResult] = await connection.query(
        'DELETE FROM attempt_answers WHERE question_id = ?',
        [id]
      );
      const [attemptQuestionResult] = await connection.query(
        'DELETE FROM attempt_questions WHERE question_id = ?',
        [id]
      );
      const [questionResult] = await connection.query(
        'DELETE FROM questions WHERE id = ?',
        [id]
      );

      if (questionResult.affectedRows === 0) {
        await connection.rollback();
        return { deleted: false };
      }

      await connection.commit();

      return {
        deleted: true,
        deletedAnswers: answerResult.affectedRows,
        deletedAttemptQuestions: attemptQuestionResult.affectedRows
      };
    } catch (error) {
      await connection.rollback();
      throw error;
    } finally {
      connection.release();
    }
  }

  async deletePermanentlyByCreator(id, creator) {
    await this.ensureCreatedByColumn();
    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      const [ownerRows] = await connection.query(
        'SELECT id FROM questions WHERE id = ? AND created_by = ? LIMIT 1',
        [id, creator]
      );

      if (ownerRows.length === 0) {
        await connection.rollback();
        return { deleted: false };
      }

      const [answerResult] = await connection.query(
        'DELETE FROM attempt_answers WHERE question_id = ?',
        [id]
      );
      const [attemptQuestionResult] = await connection.query(
        'DELETE FROM attempt_questions WHERE question_id = ?',
        [id]
      );
      const [questionResult] = await connection.query(
        'DELETE FROM questions WHERE id = ? AND created_by = ?',
        [id, creator]
      );

      if (questionResult.affectedRows === 0) {
        await connection.rollback();
        return { deleted: false };
      }

      await connection.commit();

      return {
        deleted: true,
        deletedAnswers: answerResult.affectedRows,
        deletedAttemptQuestions: attemptQuestionResult.affectedRows
      };
    } catch (error) {
      await connection.rollback();
      throw error;
    } finally {
      connection.release();
    }
  }

  // Count by difficulty
  async countByDifficulty(difficulty) {
    await this.ensureCreatedByColumn();
    const [rows] = await pool.query(
      'SELECT COUNT(*) as count FROM questions WHERE difficulty = ? AND is_active = ?',
      [difficulty, true]
    );
    return rows[0].count;
  }
}

module.exports = new QuestionModel();

const pool = require('../config/database');

class QuestionModel {
  async ensureRoomColumn() {
    const [rows] = await pool.query("SHOW COLUMNS FROM questions LIKE 'room_id'");
    if (rows.length === 0) {
      await pool.query(
        `ALTER TABLE questions
         ADD COLUMN room_id INT NOT NULL DEFAULT 1 AFTER id,
         ADD INDEX idx_questions_room (room_id)`
      );
    }
  }

  async ensureCreatedByColumn() {
    await this.ensureRoomColumn();
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
  async getByDifficulty(difficulty, limit = 10, roomId = null) {
    await this.ensureCreatedByColumn();
    const params = [difficulty, true];
    const roomClause = roomId ? ' AND room_id = ?' : '';
    if (roomId) params.push(roomId);
    params.push(limit);

    const [rows] = await pool.query(
      `SELECT * FROM questions WHERE difficulty = ? AND is_active = ?${roomClause} ORDER BY RAND() LIMIT ?`,
      params
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
  async getAllActive(roomId = null) {
    await this.ensureCreatedByColumn();
    const params = [];
    const roomClause = roomId ? ' AND q.room_id = ?' : '';
    if (roomId) params.push(roomId);

    const [rows] = await pool.query(
      `SELECT q.*, r.name AS room_name, r.slug AS room_slug
       FROM questions q
       LEFT JOIN rooms r ON r.id = q.room_id
       WHERE q.is_active = ?${roomClause}
       ORDER BY q.difficulty, q.id`,
      [true, ...params]
    );
    return rows;
  }

  // Get all questions (including inactive) for admin
  async getAll(roomId = null) {
    await this.ensureCreatedByColumn();
    const params = [];
    const roomClause = roomId ? 'WHERE q.room_id = ?' : '';
    if (roomId) params.push(roomId);

    const [rows] = await pool.query(
      `SELECT q.*, r.name AS room_name, r.slug AS room_slug
       FROM questions q
       LEFT JOIN rooms r ON r.id = q.room_id
       ${roomClause}
       ORDER BY q.id DESC`,
      params
    );
    return rows;
  }

  async getByCreator(createdBy, roomId = null) {
    await this.ensureCreatedByColumn();
    const params = [createdBy];
    const roomClause = roomId ? ' AND room_id = ?' : '';
    if (roomId) params.push(roomId);

    const [rows] = await pool.query(
      `SELECT * FROM questions WHERE created_by = ?${roomClause} ORDER BY id DESC`,
      params
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
           correct_answer = ?, difficulty = ?, time_limit = ?, is_active = COALESCE(?, is_active),
           room_id = COALESCE(?, room_id)
       WHERE id = ?`,
      [questionText, optionA, optionB, optionC, optionD, correctAnswer, difficulty, timeLimit, normalizedIsActive, questionData.roomId || null, id]
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
  async deletePermanently(id, roomId = null) {
    await this.ensureCreatedByColumn();
    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      const ownerParams = [id];
      const ownerRoomClause = roomId ? ' AND room_id = ?' : '';
      if (roomId) ownerParams.push(roomId);
      const [ownerRows] = await connection.query(
        `SELECT id FROM questions WHERE id = ?${ownerRoomClause} LIMIT 1`,
        ownerParams
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
        `DELETE FROM questions WHERE id = ?${ownerRoomClause}`,
        ownerParams
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

  async deletePermanentlyMany(ids, roomId = null) {
    await this.ensureCreatedByColumn();
    const normalizedIds = Array.from(new Set(
      (ids || [])
        .map(id => parseInt(id, 10))
        .filter(id => Number.isInteger(id) && id > 0)
    ));

    if (normalizedIds.length === 0) {
      return {
        deleted: false,
        deletedQuestions: 0,
        deletedAnswers: 0,
        deletedAttemptQuestions: 0
      };
    }

    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      const idPlaceholders = normalizedIds.map(() => '?').join(',');
      const ownerParams = [...normalizedIds];
      const roomClause = roomId ? ' AND room_id = ?' : '';
      if (roomId) ownerParams.push(roomId);

      const [ownerRows] = await connection.query(
        `SELECT id FROM questions WHERE id IN (${idPlaceholders})${roomClause}`,
        ownerParams
      );
      const allowedIds = ownerRows.map(row => row.id);

      if (allowedIds.length === 0) {
        await connection.rollback();
        return {
          deleted: false,
          deletedQuestions: 0,
          deletedAnswers: 0,
          deletedAttemptQuestions: 0
        };
      }

      const allowedPlaceholders = allowedIds.map(() => '?').join(',');
      const [answerResult] = await connection.query(
        `DELETE FROM attempt_answers WHERE question_id IN (${allowedPlaceholders})`,
        allowedIds
      );
      const [attemptQuestionResult] = await connection.query(
        `DELETE FROM attempt_questions WHERE question_id IN (${allowedPlaceholders})`,
        allowedIds
      );
      const [questionResult] = await connection.query(
        `DELETE FROM questions WHERE id IN (${allowedPlaceholders})`,
        allowedIds
      );

      await connection.commit();

      return {
        deleted: questionResult.affectedRows > 0,
        deletedQuestions: questionResult.affectedRows,
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
  async countByDifficulty(difficulty, roomId = null) {
    await this.ensureCreatedByColumn();
    const params = [difficulty, true];
    const roomClause = roomId ? ' AND room_id = ?' : '';
    if (roomId) params.push(roomId);

    const [rows] = await pool.query(
      `SELECT COUNT(*) as count FROM questions WHERE difficulty = ? AND is_active = ?${roomClause}`,
      params
    );
    return rows[0].count;
  }
}

module.exports = new QuestionModel();

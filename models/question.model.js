const pool = require('../config/database');

class QuestionModel {
  // Get question by ID
  async findById(id) {
    const [rows] = await pool.query(
      'SELECT * FROM questions WHERE id = ?',
      [id]
    );
    return rows[0] || null;
  }

  // Get questions by difficulty
  async getByDifficulty(difficulty, limit = 10) {
    const [rows] = await pool.query(
      'SELECT * FROM questions WHERE difficulty = ? AND is_active = ? ORDER BY RAND() LIMIT ?',
      [difficulty, true, limit]
    );
    return rows;
  }

  // Get questions by IDs
  async findByIds(ids) {
    if (ids.length === 0) return [];
    const [rows] = await pool.query(
      `SELECT * FROM questions WHERE id IN (${ids.map(() => '?').join(',')})`,
      ids
    );
    return rows;
  }

  // Get all active questions
  async getAllActive() {
    const [rows] = await pool.query(
      'SELECT * FROM questions WHERE is_active = ? ORDER BY difficulty, id',
      [true]
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
      timeLimit
    } = questionData;

    const [result] = await pool.query(
      `INSERT INTO questions 
       (question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty, time_limit) 
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      [questionText, optionA, optionB, optionC, optionD, correctAnswer, difficulty, timeLimit]
    );
    return result.insertId;
  }

  // Update question
  async update(id, questionData) {
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

    const [result] = await pool.query(
      `UPDATE questions 
       SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, 
           correct_answer = ?, difficulty = ?, time_limit = ?, is_active = ?
       WHERE id = ?`,
      [questionText, optionA, optionB, optionC, optionD, correctAnswer, difficulty, timeLimit, isActive, id]
    );
    return result.affectedRows > 0;
  }

  // Delete question (soft delete - set is_active = false)
  async softDelete(id) {
    const [result] = await pool.query(
      'UPDATE questions SET is_active = ? WHERE id = ?',
      [false, id]
    );
    return result.affectedRows > 0;
  }

  // Count by difficulty
  async countByDifficulty(difficulty) {
    const [rows] = await pool.query(
      'SELECT COUNT(*) as count FROM questions WHERE difficulty = ? AND is_active = ?',
      [difficulty, true]
    );
    return rows[0].count;
  }
}

module.exports = new QuestionModel();

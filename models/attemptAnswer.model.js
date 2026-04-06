const pool = require('../config/database');

class AttemptAnswerModel {
  // Record answer
  async create(attemptId, questionId, selectedAnswer, isCorrect, responseTime, connection) {
    const query = connection || pool;
    const [result] = await query.query(
      `INSERT INTO attempt_answers 
       (attempt_id, question_id, selected_answer, is_correct, response_time) 
       VALUES (?, ?, ?, ?, ?)`,
      [attemptId, questionId, selectedAnswer, isCorrect, responseTime]
    );
    return result.insertId;
  }

  // Get answers for attempt
  async getByAttemptId(attemptId) {
    const [rows] = await pool.query(
      'SELECT * FROM attempt_answers WHERE attempt_id = ? ORDER BY answered_at',
      [attemptId]
    );
    return rows;
  }

  // Check if question already answered
  async isAnswered(attemptId, questionId) {
    const [rows] = await pool.query(
      'SELECT * FROM attempt_answers WHERE attempt_id = ? AND question_id = ?',
      [attemptId, questionId]
    );
    return rows.length > 0;
  }

  // Get answer by attempt and question
  async getByAttemptAndQuestion(attemptId, questionId) {
    const [rows] = await pool.query(
      'SELECT * FROM attempt_answers WHERE attempt_id = ? AND question_id = ?',
      [attemptId, questionId]
    );
    return rows[0] || null;
  }

  // Count correct answers for attempt
  async countCorrect(attemptId) {
    const [rows] = await pool.query(
      'SELECT COUNT(*) as count FROM attempt_answers WHERE attempt_id = ? AND is_correct = ?',
      [attemptId, true]
    );
    return rows[0].count;
  }
}

module.exports = new AttemptAnswerModel();

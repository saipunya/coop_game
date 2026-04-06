const pool = require('../config/database');

class AttemptQuestionModel {
  // Assign question to attempt
  async assign(attemptId, questionId, questionOrder, connection) {
    const query = connection || pool;
    const [result] = await query.query(
      'INSERT INTO attempt_questions (attempt_id, question_id, question_order) VALUES (?, ?, ?)',
      [attemptId, questionId, questionOrder]
    );
    return result.insertId;
  }

  // Batch assign questions to attempt
  async assignBatch(attemptId, questions, connection) {
    const query = connection || pool;
    const values = questions.map((q, index) => [attemptId, q.id, index + 1]);
    
    if (values.length === 0) return 0;
    
    const [result] = await query.query(
      'INSERT INTO attempt_questions (attempt_id, question_id, question_order) VALUES ?',
      [values]
    );
    return result.affectedRows;
  }

  // Get questions for attempt
  async getByAttemptId(attemptId) {
    const [rows] = await pool.query(
      `SELECT aq.*, q.* 
       FROM attempt_questions aq 
       JOIN questions q ON aq.question_id = q.id 
       WHERE aq.attempt_id = ? 
       ORDER BY aq.question_order`,
      [attemptId]
    );
    return rows;
  }

  // Get current question for attempt
  async getCurrentQuestion(attemptId, currentQuestionIndex) {
    const [rows] = await pool.query(
      `SELECT aq.*, q.* 
       FROM attempt_questions aq 
       JOIN questions q ON aq.question_id = q.id 
       WHERE aq.attempt_id = ? AND aq.question_order = ?`,
      [attemptId, currentQuestionIndex + 1]
    );
    return rows[0] || null;
  }

  // Get question by attempt and order
  async getByAttemptAndOrder(attemptId, questionOrder) {
    const [rows] = await pool.query(
      `SELECT aq.*, q.* 
       FROM attempt_questions aq 
       JOIN questions q ON aq.question_id = q.id 
       WHERE aq.attempt_id = ? AND aq.question_order = ?`,
      [attemptId, questionOrder]
    );
    return rows[0] || null;
  }

  // Check if question belongs to attempt
  async isValidQuestionForAttempt(attemptId, questionId) {
    const [rows] = await pool.query(
      'SELECT * FROM attempt_questions WHERE attempt_id = ? AND question_id = ?',
      [attemptId, questionId]
    );
    return rows.length > 0;
  }

  // Get total questions for attempt
  async countByAttemptId(attemptId) {
    const [rows] = await pool.query(
      'SELECT COUNT(*) as count FROM attempt_questions WHERE attempt_id = ?',
      [attemptId]
    );
    return rows[0].count;
  }
}

module.exports = new AttemptQuestionModel();

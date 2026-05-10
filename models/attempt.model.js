const pool = require('../config/database');

class AttemptModel {
  // Create new attempt
  async create(gameCodeId) {
    const [result] = await pool.query(
      'INSERT INTO game_attempts (game_code_id, status) VALUES (?, ?)',
      [gameCodeId, 'in_progress']
    );
    return result.insertId;
  }

  // Find attempt by ID
  async findById(id) {
    const [rows] = await pool.query(
      'SELECT * FROM game_attempts WHERE id = ?',
      [id]
    );
    return rows[0] || null;
  }

  // Find attempt with its game code
  async findByIdWithCode(id) {
    const [rows] = await pool.query(
      `SELECT ga.*, gc.code as game_code
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.id = ?`,
      [id]
    );
    return rows[0] || null;
  }

  // Update score
  async updateScore(id, score) {
    const [result] = await pool.query(
      'UPDATE game_attempts SET score = ? WHERE id = ?',
      [score, id]
    );
    return result.affectedRows > 0;
  }

  // Update total time
  async updateTotalTime(id, totalTime) {
    const [result] = await pool.query(
      'UPDATE game_attempts SET total_time = ? WHERE id = ?',
      [totalTime, id]
    );
    return result.affectedRows > 0;
  }

  // Update player info
  async updatePlayerInfo(id, playerName, phoneNumber) {
    const [result] = await pool.query(
      'UPDATE game_attempts SET player_name = ?, phone_number = ? WHERE id = ?',
      [playerName, phoneNumber, id]
    );
    return result.affectedRows > 0;
  }

  // Update status
  async updateStatus(id, status, connection) {
    const query = connection || pool;
    const [result] = await query.query(
      'UPDATE game_attempts SET status = ?, finished_at = NOW() WHERE id = ?',
      [status, id]
    );
    return result.affectedRows > 0;
  }

  // Get completed attempts for leaderboard
  async getCompletedAttempts(limit = 50, offset = 0) {
    const [rows] = await pool.query(
      `SELECT ga.id, ga.player_name, ga.score, ga.total_time, ga.finished_at, ga.started_at
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.status = ? AND ga.player_name IS NOT NULL
       AND gc.code NOT LIKE 'ADM%'
       ORDER BY ga.score DESC, ga.total_time ASC, ga.finished_at ASC
       LIMIT ? OFFSET ?`,
      ['completed', limit, offset]
    );
    return rows;
  }

  // Count completed attempts
  async countCompleted() {
    const [rows] = await pool.query(
      `SELECT COUNT(*) as count
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.status = ? AND ga.player_name IS NOT NULL
       AND gc.code NOT LIKE 'ADM%'`,
      ['completed']
    );
    return rows[0].count;
  }

  // Get statistics
  async getStats() {
    const [totalPlayers] = await pool.query(
      `SELECT COUNT(*) as count
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.player_name IS NOT NULL
       AND gc.code NOT LIKE 'ADM%'`
    );
    const [completedGames] = await pool.query(
      `SELECT COUNT(*) as count
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.status = ? AND gc.code NOT LIKE 'ADM%'`,
      ['completed']
    );
    const [avgScore] = await pool.query(
      `SELECT AVG(ga.score) as avg
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.status = ? AND gc.code NOT LIKE 'ADM%'`,
      ['completed']
    );
    const [avgTime] = await pool.query(
      `SELECT AVG(ga.total_time) as avg
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.status = ? AND gc.code NOT LIKE 'ADM%'`,
      ['completed']
    );

    return {
      totalPlayers: totalPlayers[0].count,
      completedGames: completedGames[0].count,
      averageScore: Math.round(avgScore[0].avg * 10) / 10 || 0,
      averageTime: Math.round(avgTime[0].avg) || 0
    };
  }

  // Clear all player history and game attempts
  async clearAllHistory(connection) {
    const query = connection || pool;

    await query.query('DELETE FROM attempt_answers');
    await query.query('DELETE FROM attempt_questions');
    const [result] = await query.query('DELETE FROM game_attempts');

    return result.affectedRows;
  }
}

module.exports = new AttemptModel();

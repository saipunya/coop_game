const pool = require('../config/database');

class AttemptModel {
  async ensureClientIpColumn(query = pool) {
    const [columns] = await query.query("SHOW COLUMNS FROM game_attempts LIKE 'client_ip'");
    if (columns.length === 0) {
      await query.query('ALTER TABLE game_attempts ADD COLUMN client_ip VARCHAR(45) NULL AFTER phone_number');
    }

    const [indexes] = await query.query("SHOW INDEX FROM game_attempts WHERE Key_name = 'idx_client_ip'");
    if (indexes.length === 0) {
      await query.query('ALTER TABLE game_attempts ADD INDEX idx_client_ip (client_ip)');
    }
  }

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
      `SELECT ga.*, gc.code as game_code, r.name AS room_name, r.slug AS room_slug
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       LEFT JOIN rooms r ON r.id = ga.room_id
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

  async countByClientIp(roomId, clientIp, connection = null) {
    if (!roomId || !clientIp) return 0;
    const query = connection || pool;
    await this.ensureClientIpColumn(query);

    const [rows] = await query.query(
      `SELECT COUNT(*) AS count
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.room_id = ?
         AND ga.client_ip = ?
         AND gc.code NOT LIKE 'ADM%'`,
      [roomId, clientIp]
    );

    return rows[0]?.count || 0;
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
  async getCompletedAttempts(limit = 50, offset = 0, roomId = null) {
    const params = ['completed'];
    const roomClause = roomId ? ' AND ga.room_id = ?' : '';
    if (roomId) params.push(roomId);
    params.push(limit, offset);

    const [rows] = await pool.query(
      `SELECT ga.id, ga.player_name, ga.score, ga.total_time, ga.finished_at, ga.started_at,
              ga.room_id, r.name AS room_name, r.slug AS room_slug
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       LEFT JOIN rooms r ON r.id = ga.room_id
       WHERE ga.status = ? AND ga.player_name IS NOT NULL
       ${roomClause}
       ORDER BY ga.score DESC, ga.total_time ASC, ga.finished_at ASC
       LIMIT ? OFFSET ?`,
      params
    );
    return rows;
  }

  // Count completed attempts
  async countCompleted(roomId = null) {
    const params = ['completed'];
    const roomClause = roomId ? ' AND ga.room_id = ?' : '';
    if (roomId) params.push(roomId);

    const [rows] = await pool.query(
      `SELECT COUNT(*) as count
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.status = ? AND ga.player_name IS NOT NULL
       ${roomClause}`,
      params
    );
    return rows[0].count;
  }

  // Get statistics
  async getStats(roomId = null) {
    const roomClause = roomId ? ' AND ga.room_id = ?' : '';
    const roomParams = roomId ? [roomId] : [];

    const [totalPlayers] = await pool.query(
      `SELECT COUNT(*) as count
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.player_name IS NOT NULL
       ${roomClause}`,
      roomParams
    );
    const [completedGames] = await pool.query(
      `SELECT COUNT(*) as count
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.status = ?
       ${roomClause}`,
      ['completed', ...roomParams]
    );
    const [avgScore] = await pool.query(
      `SELECT AVG(ga.score) as avg
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.status = ?
       ${roomClause}`,
      ['completed', ...roomParams]
    );
    const [avgTime] = await pool.query(
      `SELECT AVG(ga.total_time) as avg
       FROM game_attempts ga
       JOIN game_codes gc ON gc.id = ga.game_code_id
       WHERE ga.status = ?
       ${roomClause}`,
      ['completed', ...roomParams]
    );

    return {
      totalPlayers: totalPlayers[0].count,
      completedGames: completedGames[0].count,
      averageScore: Math.round(avgScore[0].avg * 10) / 10 || 0,
      averageTime: Math.round(avgTime[0].avg) || 0
    };
  }

  // Clear all player history and game attempts
  async clearAllHistory(connection, roomId = null) {
    const query = connection || pool;

    if (roomId) {
      await query.query(
        `DELETE aa FROM attempt_answers aa
         JOIN game_attempts ga ON ga.id = aa.attempt_id
         WHERE ga.room_id = ?`,
        [roomId]
      );
      await query.query(
        `DELETE aq FROM attempt_questions aq
         JOIN game_attempts ga ON ga.id = aq.attempt_id
         WHERE ga.room_id = ?`,
        [roomId]
      );
      const [result] = await query.query('DELETE FROM game_attempts WHERE room_id = ?', [roomId]);
      return result.affectedRows;
    }

    await query.query('DELETE FROM attempt_answers');
    await query.query('DELETE FROM attempt_questions');
    const [result] = await query.query('DELETE FROM game_attempts');

    return result.affectedRows;
  }
}

module.exports = new AttemptModel();

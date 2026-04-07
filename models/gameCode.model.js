const pool = require('../config/database');

class GameCodeModel {
  // Find code by value
  async findByCode(code) {
    const [rows] = await pool.query(
      'SELECT * FROM game_codes WHERE code = ?',
      [code]
    );
    return rows[0] || null;
  }

  // Find code by ID
  async findById(id) {
    const [rows] = await pool.query(
      'SELECT * FROM game_codes WHERE id = ?',
      [id]
    );
    return rows[0] || null;
  }

  // Find existing codes from a list
  async findExistingCodes(codes) {
    if (!codes || codes.length === 0) {
      return [];
    }

    const [rows] = await pool.query(
      'SELECT code FROM game_codes WHERE code IN (?)',
      [codes]
    );

    return rows.map(row => row.code);
  }

  // Find code by ID with lock (for transaction)
  async findByIdForUpdate(id, connection) {
    const query = connection || pool;
    const [rows] = await query.query(
      'SELECT * FROM game_codes WHERE id = ? FOR UPDATE',
      [id]
    );
    return rows[0] || null;
  }

  // Update status
  async updateStatus(id, status, connection) {
    const query = connection || pool;
    const [result] = await query.query(
      'UPDATE game_codes SET status = ? WHERE id = ?',
      [status, id]
    );
    return result.affectedRows > 0;
  }

  // Update status with used_at
  async updateStatusWithUsedAt(id, status, connection) {
    const query = connection || pool;
    const [result] = await query.query(
      'UPDATE game_codes SET status = ?, used_at = NOW() WHERE id = ?',
      [status, id]
    );
    return result.affectedRows > 0;
  }

  // Delete code by ID
  async deleteById(id) {
    const [result] = await pool.query(
      'DELETE FROM game_codes WHERE id = ?',
      [id]
    );
    return result.affectedRows > 0;
  }

  // Delete codes by status values
  async deleteByStatuses(statuses) {
    if (!statuses || statuses.length === 0) {
      return 0;
    }

    const [result] = await pool.query(
      'DELETE FROM game_codes WHERE status IN (?)',
      [statuses]
    );
    return result.affectedRows;
  }

  // Create new code
  async create(code, expiresAt) {
    const [result] = await pool.query(
      'INSERT INTO game_codes (code, status, expires_at) VALUES (?, ?, ?)',
      [code, 'unused', expiresAt]
    );
    return result.insertId;
  }

  // Batch create codes
  async createBatch(codes, expiresAt) {
    const values = codes.map(code => [code, 'unused', expiresAt]);
    const [result] = await pool.query(
      'INSERT INTO game_codes (code, status, expires_at) VALUES ?',
      [values]
    );
    return result.affectedRows;
  }

  // Get codes by status
  async getByStatus(status, limit = 50, offset = 0) {
    const [rows] = await pool.query(
      'SELECT * FROM game_codes WHERE status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
      [status, limit, offset]
    );
    return rows;
  }

  // Count codes by status
  async countByStatus(status) {
    const [rows] = await pool.query(
      'SELECT COUNT(*) as count FROM game_codes WHERE status = ?',
      [status]
    );
    return rows[0].count;
  }

  // Mark expired codes
  async markExpired() {
    const [result] = await pool.query(
      'UPDATE game_codes SET status = ? WHERE status = ? AND expires_at < NOW()',
      ['expired', 'unused']
    );
    return result.affectedRows;
  }
}

module.exports = new GameCodeModel();

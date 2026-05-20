const pool = require('../config/database');

class GameCodeModel {
  async resolveRequiredInsertRelations(query = pool) {
    const [columns] = await query.query('SHOW COLUMNS FROM game_codes');
    const columnMap = new Map(columns.map(column => [column.Field, column]));
    const resolved = {};

    const roomColumn = columnMap.get('room_id');
    if (roomColumn && roomColumn.Null === 'NO' && roomColumn.Default === null) {
      resolved.room_id = await this.resolveRoomId(query);
    }

    const categoryColumn = columnMap.get('category_id');
    if (categoryColumn && categoryColumn.Null === 'NO' && categoryColumn.Default === null) {
      resolved.category_id = await this.resolveCategoryId(query);
    }

    return resolved;
  }

  async resolveRoomId(query = pool) {
    const [rows] = await query.query(
      'SELECT id FROM rooms ORDER BY id ASC LIMIT 1'
    );

    if (rows.length === 0) {
      const error = new Error('No rooms available');
      error.code = 'NO_ROOMS_AVAILABLE';
      throw error;
    }

    return rows[0].id;
  }

  async resolveCategoryId(query = pool) {
    const [rows] = await query.query(
      'SELECT id FROM question_categories ORDER BY id ASC LIMIT 1'
    );

    if (rows.length === 0) {
      const error = new Error('No question categories available');
      error.code = 'NO_CATEGORIES_AVAILABLE';
      throw error;
    }

    return rows[0].id;
  }

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

  // Delete every code and all game history rows that reference those codes
  async clearAllWithHistory() {
    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      await connection.query('DELETE FROM attempt_answers');
      await connection.query('DELETE FROM attempt_questions');
      const [attemptResult] = await connection.query('DELETE FROM game_attempts');
      const [codeResult] = await connection.query('DELETE FROM game_codes');

      await connection.commit();

      return {
        deletedCodes: codeResult.affectedRows,
        deletedAttempts: attemptResult.affectedRows
      };
    } catch (error) {
      await connection.rollback();
      throw error;
    } finally {
      connection.release();
    }
  }

  // Create new code
  async create(code, expiresAt) {
    const requiredRelations = await this.resolveRequiredInsertRelations();
    const columns = [];
    const values = [];

    if (requiredRelations.room_id !== undefined) {
      columns.push('room_id');
      values.push(requiredRelations.room_id);
    }

    columns.push('code', 'status', 'expires_at');
    values.push(code, 'unused', expiresAt);

    if (requiredRelations.category_id !== undefined) {
      columns.push('category_id');
      values.push(requiredRelations.category_id);
    }

    const [result] = await pool.query(
      `INSERT INTO game_codes (${columns.join(', ')}) VALUES (${columns.map(() => '?').join(', ')})`,
      values
    );
    return result.insertId;
  }

  // Batch create codes
  async createBatch(codes, expiresAt) {
    const requiredRelations = await this.resolveRequiredInsertRelations();
    const columns = [];

    if (requiredRelations.room_id !== undefined) {
      columns.push('room_id');
    }

    columns.push('code', 'status', 'expires_at');

    if (requiredRelations.category_id !== undefined) {
      columns.push('category_id');
    }

    const values = codes.map(code => {
      const row = [];

      if (requiredRelations.room_id !== undefined) {
        row.push(requiredRelations.room_id);
      }

      row.push(code, 'unused', expiresAt);

      if (requiredRelations.category_id !== undefined) {
        row.push(requiredRelations.category_id);
      }

      return row;
    });

    const [result] = await pool.query(
      `INSERT INTO game_codes (${columns.join(', ')}) VALUES ?`,
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

  // Get all codes
  async getAll(limit = 50, offset = 0) {
    const [rows] = await pool.query(
      'SELECT * FROM game_codes ORDER BY created_at DESC LIMIT ? OFFSET ?',
      [limit, offset]
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

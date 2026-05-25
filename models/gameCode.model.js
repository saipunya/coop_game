const pool = require('../config/database');

class GameCodeModel {
  async resolveRequiredInsertRelations(query = pool, preferredRoomId = null) {
    const [columns] = await query.query('SHOW COLUMNS FROM game_codes');
    const columnMap = new Map(columns.map(column => [column.Field, column]));
    const resolved = {};

    const roomColumn = columnMap.get('room_id');
    if (roomColumn && roomColumn.Null === 'NO' && roomColumn.Default === null) {
      resolved.room_id = await this.resolveRoomId(query, preferredRoomId);
    }

    const categoryColumn = columnMap.get('category_id');
    if (categoryColumn && categoryColumn.Null === 'NO' && categoryColumn.Default === null) {
      resolved.category_id = await this.resolveCategoryId(query);
    }

    return resolved;
  }

  async resolveRoomId(query = pool, preferredRoomId = null) {
    const parsedPreferredRoomId = parseInt(preferredRoomId, 10);
    const params = [];
    let where = "WHERE status = 'active'";

    if (Number.isInteger(parsedPreferredRoomId) && parsedPreferredRoomId > 0) {
      where += ' AND id = ?';
      params.push(parsedPreferredRoomId);
    }

    const [rows] = await query.query(
      `SELECT id FROM rooms ${where} ORDER BY id ASC LIMIT 1`,
      params
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
  async findByCode(code, roomId = null) {
    const params = [code];
    const roomClause = roomId ? ' AND room_id = ?' : '';
    if (roomId) params.push(roomId);

    const [rows] = await pool.query(
      `SELECT * FROM game_codes WHERE code = ?${roomClause}`,
      params
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
  async clearAllWithHistory(roomId = null) {
    const connection = await pool.getConnection();

    try {
      await connection.beginTransaction();

      let attemptResult;
      let codeResult;

      if (roomId) {
        await connection.query(
          `DELETE aa FROM attempt_answers aa
           JOIN game_attempts ga ON ga.id = aa.attempt_id
           WHERE ga.room_id = ?`,
          [roomId]
        );
        await connection.query(
          `DELETE aq FROM attempt_questions aq
           JOIN game_attempts ga ON ga.id = aq.attempt_id
           WHERE ga.room_id = ?`,
          [roomId]
        );
        [attemptResult] = await connection.query('DELETE FROM game_attempts WHERE room_id = ?', [roomId]);
        [codeResult] = await connection.query('DELETE FROM game_codes WHERE room_id = ?', [roomId]);
      } else {
        await connection.query('DELETE FROM attempt_answers');
        await connection.query('DELETE FROM attempt_questions');
        [attemptResult] = await connection.query('DELETE FROM game_attempts');
        [codeResult] = await connection.query('DELETE FROM game_codes');
      }

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
  async create(code, expiresAt, roomId = null) {
    const requiredRelations = await this.resolveRequiredInsertRelations(pool, roomId);
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
  async createBatch(codes, expiresAt, roomId = null) {
    const requiredRelations = await this.resolveRequiredInsertRelations(pool, roomId);
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
  async getByStatus(status, limit = 50, offset = 0, roomId = null) {
    const params = [status];
    const roomClause = roomId ? ' AND gc.room_id = ?' : '';
    if (roomId) params.push(roomId);
    params.push(limit, offset);

    const [rows] = await pool.query(
      `SELECT gc.*, r.name AS room_name, r.slug AS room_slug
       FROM game_codes gc
       LEFT JOIN rooms r ON r.id = gc.room_id
       WHERE gc.status = ?${roomClause}
       ORDER BY gc.created_at DESC LIMIT ? OFFSET ?`,
      params
    );
    return rows;
  }

  // Get all codes
  async getAll(limit = 50, offset = 0, roomId = null) {
    const params = [];
    const roomClause = roomId ? 'WHERE gc.room_id = ?' : '';
    if (roomId) params.push(roomId);
    params.push(limit, offset);

    const [rows] = await pool.query(
      `SELECT gc.*, r.name AS room_name, r.slug AS room_slug
       FROM game_codes gc
       LEFT JOIN rooms r ON r.id = gc.room_id
       ${roomClause}
       ORDER BY gc.created_at DESC LIMIT ? OFFSET ?`,
      params
    );
    return rows;
  }

  // Count codes by status
  async countByStatus(status, roomId = null) {
    const params = [status];
    const roomClause = roomId ? ' AND room_id = ?' : '';
    if (roomId) params.push(roomId);

    const [rows] = await pool.query(
      `SELECT COUNT(*) as count FROM game_codes WHERE status = ?${roomClause}`,
      params
    );
    return rows[0].count;
  }

  // Mark expired codes
  async markExpired(roomId = null) {
    const params = ['expired', 'unused'];
    const roomClause = roomId ? ' AND room_id = ?' : '';
    if (roomId) params.push(roomId);

    const [result] = await pool.query(
      `UPDATE game_codes SET status = ? WHERE status = ? AND expires_at < NOW()${roomClause}`,
      params
    );
    return result.affectedRows;
  }
}

module.exports = new GameCodeModel();

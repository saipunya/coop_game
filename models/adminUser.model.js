const crypto = require('crypto');
const pool = require('../config/database');

class AdminUserModel {
  async ensureTable() {
    await pool.query(`
      CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('super_admin', 'room_admin') NOT NULL DEFAULT 'room_admin',
        room_id INT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (room_id) REFERENCES rooms(id),
        INDEX idx_admin_users_role (role),
        INDEX idx_admin_users_room (room_id),
        INDEX idx_admin_users_active (is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
  }

  hashPassword(password) {
    const iterations = 120000;
    const salt = crypto.randomBytes(16).toString('hex');
    const hash = crypto.pbkdf2Sync(String(password), salt, iterations, 32, 'sha256').toString('hex');
    return `pbkdf2_sha256$${iterations}$${salt}$${hash}`;
  }

  verifyPassword(password, passwordHash) {
    const parts = String(passwordHash || '').split('$');
    if (parts.length !== 4 || parts[0] !== 'pbkdf2_sha256') {
      return false;
    }

    const iterations = parseInt(parts[1], 10);
    const salt = parts[2];
    const expectedHash = parts[3];

    if (!Number.isInteger(iterations) || !salt || !expectedHash) {
      return false;
    }

    const actualHash = crypto.pbkdf2Sync(String(password), salt, iterations, 32, 'sha256').toString('hex');
    if (actualHash.length !== expectedHash.length) {
      return false;
    }

    return crypto.timingSafeEqual(Buffer.from(actualHash), Buffer.from(expectedHash));
  }

  async findByUsername(username) {
    await this.ensureTable();
    const [rows] = await pool.query(
      `SELECT au.*, r.name AS room_name, r.slug AS room_slug, r.status AS room_status
       FROM admin_users au
       LEFT JOIN rooms r ON r.id = au.room_id
       WHERE au.username = ?
       LIMIT 1`,
      [username]
    );
    return rows[0] || null;
  }

  async verifyCredentials(username, password) {
    const user = await this.findByUsername(username);
    if (!user || !user.is_active) {
      return null;
    }

    if (user.role === 'room_admin' && (!user.room_id || user.room_status !== 'active')) {
      return null;
    }

    if (!this.verifyPassword(password, user.password_hash)) {
      return null;
    }

    return {
      id: user.id,
      username: user.username,
      role: user.role,
      roomId: user.room_id || null,
      roomName: user.room_name || null,
      roomSlug: user.room_slug || null
    };
  }

  async getAll() {
    await this.ensureTable();
    const [rows] = await pool.query(
      `SELECT au.id, au.username, au.role, au.room_id, au.is_active, au.created_at,
              r.name AS room_name, r.slug AS room_slug
       FROM admin_users au
       LEFT JOIN rooms r ON r.id = au.room_id
       ORDER BY au.id ASC`
    );
    return rows;
  }

  async create({ username, password, role, roomId, isActive = true }) {
    await this.ensureTable();
    const normalizedRole = role === 'super_admin' ? 'super_admin' : 'room_admin';
    const normalizedRoomId = normalizedRole === 'super_admin' ? null : parseInt(roomId, 10);

    const [result] = await pool.query(
      `INSERT INTO admin_users (username, password_hash, role, room_id, is_active)
       VALUES (?, ?, ?, ?, ?)`,
      [
        String(username || '').trim(),
        this.hashPassword(password),
        normalizedRole,
        normalizedRoomId || null,
        isActive !== false
      ]
    );

    return result.insertId;
  }

  async update(id, { username, password, role, roomId, isActive }) {
    await this.ensureTable();
    const normalizedRole = role === 'super_admin' ? 'super_admin' : 'room_admin';
    const normalizedRoomId = normalizedRole === 'super_admin' ? null : parseInt(roomId, 10) || null;
    const values = [
      String(username || '').trim(),
      normalizedRole,
      normalizedRoomId,
      isActive !== false
    ];
    const passwordClause = password ? ', password_hash = ?' : '';

    if (password) {
      values.push(this.hashPassword(password));
    }

    values.push(id);

    const [result] = await pool.query(
      `UPDATE admin_users
       SET username = ?, role = ?, room_id = ?, is_active = ?${passwordClause}
       WHERE id = ?`,
      values
    );

    return result.affectedRows > 0;
  }
}

module.exports = new AdminUserModel();

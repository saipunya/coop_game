const pool = require('../config/database');

class RoomModel {
  async ensureTable() {
    await pool.query(`
      CREATE TABLE IF NOT EXISTS rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        slug VARCHAR(120) NOT NULL UNIQUE,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_rooms_status (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
  }

  normalizeSlug(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 120);
  }

  async findById(id) {
    await this.ensureTable();
    const [rows] = await pool.query('SELECT * FROM rooms WHERE id = ? LIMIT 1', [id]);
    return rows[0] || null;
  }

  async findBySlug(slug) {
    await this.ensureTable();
    const [rows] = await pool.query('SELECT * FROM rooms WHERE slug = ? LIMIT 1', [slug]);
    return rows[0] || null;
  }

  async findDefault() {
    await this.ensureTable();
    const [rows] = await pool.query(
      `SELECT * FROM rooms
       WHERE status = 'active'
       ORDER BY id ASC
       LIMIT 1`
    );
    return rows[0] || null;
  }

  async getAll(includeInactive = true) {
    await this.ensureTable();
    const where = includeInactive ? '' : "WHERE status = 'active'";
    const [rows] = await pool.query(
      `SELECT * FROM rooms ${where} ORDER BY id ASC`
    );
    return rows;
  }

  async create({ name, slug, status = 'active' }) {
    await this.ensureTable();
    const normalizedSlug = this.normalizeSlug(slug || name);

    const [result] = await pool.query(
      'INSERT INTO rooms (name, slug, status) VALUES (?, ?, ?)',
      [String(name || '').trim(), normalizedSlug, status === 'inactive' ? 'inactive' : 'active']
    );

    return result.insertId;
  }

  async update(id, { name, slug, status }) {
    await this.ensureTable();
    const normalizedSlug = this.normalizeSlug(slug || name);

    const [result] = await pool.query(
      `UPDATE rooms
       SET name = ?, slug = ?, status = ?
       WHERE id = ?`,
      [
        String(name || '').trim(),
        normalizedSlug,
        status === 'inactive' ? 'inactive' : 'active',
        id
      ]
    );

    return result.affectedRows > 0;
  }
}

module.exports = new RoomModel();

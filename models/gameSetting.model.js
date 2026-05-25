const pool = require('../config/database');

class GameSettingModel {
  async ensureTable() {
    await pool.query(`
      CREATE TABLE IF NOT EXISTS game_settings (
        room_id INT NOT NULL DEFAULT 1,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (room_id, setting_key)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);

    const [columns] = await pool.query('SHOW COLUMNS FROM game_settings');
    const columnNames = new Set(columns.map(column => column.Field));

    if (!columnNames.has('room_id')) {
      await pool.query('ALTER TABLE game_settings ADD COLUMN room_id INT NOT NULL DEFAULT 1 FIRST');
    }

    const [indexes] = await pool.query("SHOW INDEX FROM game_settings WHERE Key_name = 'PRIMARY'");
    const primaryColumns = indexes.map(index => index.Column_name);
    if (!primaryColumns.includes('room_id')) {
      await pool.query('ALTER TABLE game_settings DROP PRIMARY KEY, ADD PRIMARY KEY (room_id, setting_key)');
    }
  }

  normalizeRoomId(roomId) {
    const parsed = parseInt(roomId, 10);
    return Number.isInteger(parsed) && parsed > 0 ? parsed : 1;
  }

  async get(settingKey, roomId = 1) {
    await this.ensureTable();

    const [rows] = await pool.query(
      'SELECT setting_value FROM game_settings WHERE room_id = ? AND setting_key = ?',
      [this.normalizeRoomId(roomId), settingKey]
    );

    return rows[0] || null;
  }

  async getValue(settingKey, defaultValue = null, roomId = 1) {
    const row = await this.get(settingKey, roomId);
    if (!row) return defaultValue;

    try {
      return JSON.parse(row.setting_value);
    } catch (error) {
      return row.setting_value;
    }
  }

  async set(settingKey, value, roomId = 1) {
    await this.ensureTable();

    const serializedValue = JSON.stringify(value);
    await pool.query(
      `INSERT INTO game_settings (room_id, setting_key, setting_value)
       VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP`,
      [this.normalizeRoomId(roomId), settingKey, serializedValue]
    );

    return true;
  }
}

module.exports = new GameSettingModel();

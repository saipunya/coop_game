const pool = require('../config/database');

class GameSettingModel {
  async ensureTable() {
    await pool.query(`
      CREATE TABLE IF NOT EXISTS game_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
  }

  async get(settingKey) {
    await this.ensureTable();

    const [rows] = await pool.query(
      'SELECT setting_value FROM game_settings WHERE setting_key = ?',
      [settingKey]
    );

    return rows[0] || null;
  }

  async getValue(settingKey, defaultValue = null) {
    const row = await this.get(settingKey);
    if (!row) return defaultValue;

    try {
      return JSON.parse(row.setting_value);
    } catch (error) {
      return row.setting_value;
    }
  }

  async set(settingKey, value) {
    await this.ensureTable();

    const serializedValue = JSON.stringify(value);
    await pool.query(
      `INSERT INTO game_settings (setting_key, setting_value)
       VALUES (?, ?)
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP`,
      [settingKey, serializedValue]
    );

    return true;
  }
}

module.exports = new GameSettingModel();

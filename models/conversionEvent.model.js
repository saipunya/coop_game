const db = require('../config/database');

const ConversionEventModel = {
  async create({ event_name, event_label = null, page = null }) {
    const sql = 'INSERT INTO conversion_events (event_name, event_label, page) VALUES (?, ?, ?)';
    const [result] = await db.execute(sql, [event_name, event_label, page]);
    return result.insertId;
  },

  async countByEventName(eventName) {
    const sql = 'SELECT COUNT(*) AS cnt FROM conversion_events WHERE event_name = ?';
    const [rows] = await db.execute(sql, [eventName]);
    return rows[0] ? rows[0].cnt : 0;
  },

  async countByEventNames(names) {
    if (!names || names.length === 0) return {};
    const placeholders = names.map(() => '?').join(',');
    const sql = `SELECT event_name, COUNT(*) AS cnt FROM conversion_events WHERE event_name IN (${placeholders}) GROUP BY event_name`;
    const [rows] = await db.execute(sql, names);
    const map = {};
    rows.forEach(r => { map[r.event_name] = r.cnt; });
    // ensure keys exist
    names.forEach(n => { if (!map[n]) map[n] = 0; });
    return map;
  },

  async countTotalClicks() {
    const sql = "SELECT COUNT(*) AS cnt FROM conversion_events WHERE event_name LIKE 'click_%'";
    const [rows] = await db.execute(sql);
    return rows[0] ? rows[0].cnt : 0;
  },

  async latest(limit = 50) {
    const sql = 'SELECT id, event_name, event_label, page, created_at FROM conversion_events ORDER BY created_at DESC LIMIT ?';
    const [rows] = await db.execute(sql, [limit]);
    return rows;
  }
};

module.exports = ConversionEventModel;

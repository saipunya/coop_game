const db = require('../config/database');

let schemaPromise;

function ensureSchema() {
  if (!schemaPromise) {
    schemaPromise = db.query(`
      CREATE TABLE IF NOT EXISTS calendar_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(160) NOT NULL,
        description TEXT NULL,
        location VARCHAR(200) NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NOT NULL,
        all_day TINYINT(1) NOT NULL DEFAULT 0,
        color VARCHAR(7) NOT NULL DEFAULT '#2563eb',
        status ENUM('planned', 'in_progress', 'done', 'cancelled') NOT NULL DEFAULT 'planned',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_calendar_events_range (start_at, end_at),
        INDEX idx_calendar_events_status (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `).catch((error) => {
      schemaPromise = null;
      throw error;
    });
  }
  return schemaPromise;
}

async function list(rangeStart, rangeEnd) {
  await ensureSchema();
  const [rows] = await db.execute(
    `SELECT id, title, description, location,
            DATE_FORMAT(start_at, '%Y-%m-%dT%H:%i:%s') AS start_at,
            DATE_FORMAT(end_at, '%Y-%m-%dT%H:%i:%s') AS end_at,
            all_day, color, status,
            created_at, updated_at
       FROM calendar_events
      WHERE start_at < ? AND end_at >= ?
      ORDER BY start_at ASC, id ASC`,
    [rangeEnd, rangeStart]
  );
  return rows;
}

async function findById(id) {
  await ensureSchema();
  const [rows] = await db.execute(
    `SELECT id, title, description, location,
            DATE_FORMAT(start_at, '%Y-%m-%dT%H:%i:%s') AS start_at,
            DATE_FORMAT(end_at, '%Y-%m-%dT%H:%i:%s') AS end_at,
            all_day, color, status,
            created_at, updated_at
       FROM calendar_events WHERE id = ? LIMIT 1`,
    [id]
  );
  return rows[0] || null;
}

async function create(event) {
  await ensureSchema();
  const [result] = await db.execute(
    `INSERT INTO calendar_events
      (title, description, location, start_at, end_at, all_day, color, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      event.title, event.description, event.location, event.startAt, event.endAt,
      event.allDay ? 1 : 0, event.color, event.status
    ]
  );
  return findById(result.insertId);
}

async function update(id, event) {
  await ensureSchema();
  const [result] = await db.execute(
    `UPDATE calendar_events
        SET title = ?, description = ?, location = ?, start_at = ?, end_at = ?,
            all_day = ?, color = ?, status = ?
      WHERE id = ?`,
    [
      event.title, event.description, event.location, event.startAt, event.endAt,
      event.allDay ? 1 : 0, event.color, event.status, id
    ]
  );
  return result.affectedRows ? findById(id) : null;
}

async function remove(id) {
  await ensureSchema();
  const [result] = await db.execute('DELETE FROM calendar_events WHERE id = ?', [id]);
  return result.affectedRows > 0;
}

module.exports = { ensureSchema, list, findById, create, update, remove };

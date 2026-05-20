const mysql = require('mysql2/promise');

const RETRYABLE_ERROR_CODES = new Set([
  'ECONNRESET',
  'PROTOCOL_CONNECTION_LOST',
  'ETIMEDOUT',
  'EPIPE'
]);

const RETRY_ATTEMPTS = Number.isInteger(parseInt(process.env.DB_RETRY_ATTEMPTS, 10))
  ? parseInt(process.env.DB_RETRY_ATTEMPTS, 10)
  : 1;
const RETRY_DELAY_MS = Number.isInteger(parseInt(process.env.DB_RETRY_DELAY_MS, 10))
  ? parseInt(process.env.DB_RETRY_DELAY_MS, 10)
  : 150;

function isRetryableError(error) {
  return RETRYABLE_ERROR_CODES.has(error?.code);
}

function wait(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

// Database connection pool configuration
const pool = mysql.createPool({
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'coopgame_db',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
  enableKeepAlive: true,
  keepAliveInitialDelay: 0,
  connectTimeout: 10000
});

const originalQuery = pool.query.bind(pool);
const originalExecute = pool.execute.bind(pool);

async function queryWithRetry(method, args) {
  let attempt = 0;

  while (true) {
    try {
      return await method(...args);
    } catch (error) {
      if (!isRetryableError(error) || attempt >= RETRY_ATTEMPTS) {
        throw error;
      }

      attempt += 1;
      await wait(RETRY_DELAY_MS * attempt);
    }
  }
}

pool.query = (...args) => queryWithRetry(originalQuery, args);
pool.execute = (...args) => queryWithRetry(originalExecute, args);

module.exports = pool;

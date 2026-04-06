#!/usr/bin/env node

/**
 * Script to mark expired codes
 * Usage: node scripts/mark-expired.js
 */

const pool = require('../config/database');

async function markExpiredCodes() {
  try {
    console.log('Marking expired codes...');

    const [result] = await pool.query(
      'UPDATE game_codes SET status = ? WHERE status = ? AND expires_at < NOW()',
      ['expired', 'unused']
    );

    console.log(`✅ Marked ${result.affectedRows} codes as expired`);
    process.exit(0);
  } catch (error) {
    console.error('❌ Error marking expired codes:', error);
    process.exit(1);
  }
}

markExpiredCodes();

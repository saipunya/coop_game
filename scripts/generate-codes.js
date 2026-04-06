#!/usr/bin/env node

/**
 * Script to generate game codes
 * Usage: node scripts/generate-codes.js <count> [expiryHours]
 * Example: node scripts/generate-codes.js 50 24
 */

const pool = require('../config/database');
const { generateGameCodes, calculateExpiry } = require('../utils/crypto');

async function generateCodes(count, expiryHours = 24) {
  try {
    console.log(`Generating ${count} game codes...`);

    const codes = generateGameCodes(count);
    const expiresAt = calculateExpiry(expiryHours);

    const values = codes.map(code => [code, 'unused', expiresAt]);
    
    const [result] = await pool.query(
      'INSERT INTO game_codes (code, status, expires_at) VALUES ?',
      [values]
    );

    console.log(`✅ Successfully generated ${result.affectedRows} codes`);
    console.log(`   Expires at: ${expiresAt.toISOString()}`);
    console.log(`\nCodes:`);
    codes.forEach((code, index) => {
      console.log(`   ${index + 1}. ${code}`);
    });

    process.exit(0);
  } catch (error) {
    console.error('❌ Error generating codes:', error);
    process.exit(1);
  }
}

// Parse command line arguments
const args = process.argv.slice(2);
const count = parseInt(args[0]) || 50;
const expiryHours = parseInt(args[1]) || 24;

if (count < 1 || count > 1000) {
  console.error('❌ Count must be between 1 and 1000');
  process.exit(1);
}

generateCodes(count, expiryHours);

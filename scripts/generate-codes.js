#!/usr/bin/env node

/**
 * Script to generate game codes
 * Usage: node scripts/generate-codes.js <count> [expiryHours]
 * Example: node scripts/generate-codes.js 50 24
 */

require('dotenv').config();
const adminService = require('../services/admin.service');

async function generateCodes(count, expiryHours = 24) {
  try {
    console.log(`Generating ${count} game codes...`);

    const result = await adminService.generateCodes(count, expiryHours);

    console.log(`✅ Successfully generated ${result.count} codes`);
    console.log(`   Expires at: ${new Date(result.expiresAt).toISOString()}`);
    console.log(`\nCodes:`);
    result.codes.forEach((code, index) => {
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

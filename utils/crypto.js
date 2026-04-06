/**
 * Cryptography and random utilities
 */

const crypto = require('crypto');

/**
 * Generate random game code (6 alphanumeric characters)
 */
function generateGameCode() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed confusing chars (I, O, 0, 1)
  let code = '';
  for (let i = 0; i < 6; i++) {
    const randomIndex = crypto.randomInt(0, chars.length);
    code += chars.charAt(randomIndex);
  }
  return code;
}

/**
 * Generate multiple unique game codes
 */
function generateGameCodes(count) {
  const codes = new Set();
  while (codes.size < count) {
    codes.add(generateGameCode());
  }
  return Array.from(codes);
}

/**
 * Shuffle array using Fisher-Yates algorithm with crypto
 */
function shuffleArray(array) {
  const shuffled = [...array];
  for (let i = shuffled.length - 1; i > 0; i--) {
    const j = crypto.randomInt(0, i + 1);
    [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
  }
  return shuffled;
}

/**
 * Calculate expiry date (24 hours from now)
 */
function calculateExpiry(hours = 24) {
  const expiry = new Date();
  expiry.setHours(expiry.getHours() + hours);
  return expiry;
}

/**
 * Mask phone number for privacy (e.g., 081****5678)
 */
function maskPhoneNumber(phone) {
  if (!phone || phone.length < 4) return phone;
  return phone.replace(/(\d{3})\d{3,4}(\d{3,4})/, '$1****$2');
}

/**
 * Validate phone number (9-10 digits, Thai format)
 */
function validatePhoneNumber(phone) {
  const cleaned = phone.replace(/\D/g, '');
  return /^0\d{8,9}$/.test(cleaned);
}

/**
 * Validate game code (6 alphanumeric characters)
 */
function validateGameCode(code) {
  return /^[A-Z0-9]{6}$/.test(code.toUpperCase());
}

module.exports = {
  generateGameCode,
  generateGameCodes,
  shuffleArray,
  calculateExpiry,
  maskPhoneNumber,
  validatePhoneNumber,
  validateGameCode
};

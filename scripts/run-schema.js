#!/usr/bin/env node

/**
 * Script to run schema.sql using mysql2 (avoids MySQL client compatibility issues)
 * Usage: node scripts/run-schema.js
 */

require('dotenv').config();
const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');

async function runSchema() {
  let connection;
  try {
    // Read schema.sql
    const schemaPath = path.join(__dirname, '../sql/schema.sql');
    const schemaSql = fs.readFileSync(schemaPath, 'utf8');

    console.log('Connecting to database...');
    connection = await mysql.createConnection({
      host: process.env.DB_HOST || '103.30.127.74',
      user: process.env.DB_USER || 'coopgame_user',
      password: process.env.DB_PASSWORD,
      multipleStatements: true
    });

    console.log('Running schema...');
    
    // Execute CREATE DATABASE first
    const createDbMatch = schemaSql.match(/CREATE DATABASE IF NOT EXISTS `?(\w+)`?/i);
    if (createDbMatch) {
      const dbName = createDbMatch[1];
      await connection.query(`CREATE DATABASE IF NOT EXISTS \`${dbName}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
      console.log(`✅ Created database: ${dbName}`);
    }

    // Switch to the database
    const dbName = process.env.DB_NAME || 'coopgame_db';
    await connection.changeUser({ database: dbName });
    console.log(`✅ Switched to database: ${dbName}`);

    // Extract and execute table creation statements
    const tableStatements = schemaSql
      .split('-- Table:')
      .slice(1) // Skip first empty part
      .map(block => {
        const lines = block.split('\n');
        const comment = lines[0].trim();
        const sql = lines.slice(1).join('\n').trim();
        return sql;
      })
      .filter(sql => sql.length > 10 && !sql.startsWith('--'));

    for (const statement of tableStatements) {
      await connection.query(statement);
      console.log('✅ Executed table creation');
    }

    console.log('\n✅ Schema executed successfully!');
    process.exit(0);

  } catch (error) {
    console.error('❌ Error:', error.message);
    process.exit(1);
  } finally {
    if (connection) {
      await connection.end();
    }
  }
}

runSchema();

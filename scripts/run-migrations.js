const fs = require('fs');
const path = require('path');
require('dotenv').config();
const db = require('../config/database');

(async function run(){
  try{
    const migrations = [
      'create_conversion_events.sql',
      'multi_room.sql'
    ];

    for (const migration of migrations) {
      const sqlPath = path.join(__dirname, '..', 'sql', migration);
      if (!fs.existsSync(sqlPath)) continue;

      const sql = fs.readFileSync(sqlPath, 'utf8')
        .split('\n')
        .filter(line => !line.trim().startsWith('--'))
        .join('\n');
      const statements = sql
        .split(';')
        .map(statement => statement.trim())
        .filter(Boolean);

      console.log('Running migration:', sqlPath);
      for (const statement of statements) {
        await db.query(statement);
      }
    }

    console.log('Migrations executed successfully');
    process.exit(0);
  }catch(err){
    console.error('Migration error', err);
    process.exit(1);
  }
})();

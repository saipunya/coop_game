const fs = require('fs');
const path = require('path');
const db = require('../config/database');

(async function run(){
  try{
    const sqlPath = path.join(__dirname, '..', 'sql', 'create_conversion_events.sql');
    const sql = fs.readFileSync(sqlPath, 'utf8');
    console.log('Running migration:', sqlPath);
    // split on ';' but keep simple: execute whole file
    const [result] = await db.query(sql);
    console.log('Migration executed successfully');
    process.exit(0);
  }catch(err){
    console.error('Migration error', err);
    process.exit(1);
  }
})();

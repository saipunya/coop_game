require('dotenv').config();
const db = require('./config/database');
const adminService = require('./services/admin.service');

async function runTask() {
    try {
        console.log('--- SHOW CREATE TABLE game_codes ---');
        const [results] = await db.query('SHOW CREATE TABLE game_codes');
        console.log(JSON.stringify(results, null, 2));

        console.log('\n--- adminService.generateCodes(1, 24) ---');
        const response = await adminService.generateCodes(1, 24);
        console.log('Success:', response);
    } catch (error) {
        console.error('Error occurred:');
        console.error('Code:', error.code || 'N/A');
        console.error('Message:', error.message);
        if (error.sqlMessage) console.error('SQL Message:', error.sqlMessage);
    } finally {
        process.exit();
    }
}

runTask();

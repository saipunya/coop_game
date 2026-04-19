const express = require('express');
const path = require('path');
require('dotenv').config();

const app = express();

// middleware
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// static files
app.use(express.static(path.join(__dirname, 'public')));
app.use('/coopgame', express.static(path.join(__dirname, 'public')));

// view engine
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Asset base for views (works both direct and through proxy)
app.use((req, res, next) => {
  res.locals.assetBase = '/coopgame';
  next();
});

// routes
const gameRoutes = require('./routes/game.routes');
const adminRoutes = require('./routes/admin.routes');
const trackingRoutes = require('./routes/tracking.routes');
app.use('/coopgame/game', gameRoutes);
app.use('/coopgame/admin', adminRoutes);
// tracking API
app.use('/api', trackingRoutes);

// home route
app.get('/', (req, res) => {
  res.render('home', { title: 'ยินดีต้อนรับ' });
});

// contact form endpoint (from landing page)
app.post('/contact', (req, res) => {
  const { name, email, company, message } = req.body;
  // Log to server console
  console.log('[Contact]', { name, email, company, message });

  const botToken = process.env.TELEGRAM_BOT_TOKEN;
  const chatId = process.env.TELEGRAM_CHAT_ID;

  const sendResult = { telegram: false };

  if (botToken && chatId) {
    // send to Telegram Bot API
    const https = require('https');
    const text = `<b>New contact from landing page</b>\nName: ${name || '-'}\nEmail: ${email || '-'}\nCompany: ${company || '-'}\n\nMessage:\n${message || '-'}`;
    const payload = JSON.stringify({ chat_id: chatId, text, parse_mode: 'HTML' });

    const options = {
      hostname: 'api.telegram.org',
      path: `/bot${botToken}/sendMessage`,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(payload)
      }
    };

    const request = https.request(options, (telegramRes) => {
      let data = '';
      telegramRes.on('data', (chunk) => { data += chunk; });
      telegramRes.on('end', () => {
        try { const parsed = JSON.parse(data); if (parsed && parsed.ok) sendResult.telegram = true; }
        catch (e) { /* ignore parse errors */ }
        res.json({ success: true, sentToTelegram: sendResult.telegram });
      });
    });

    request.on('error', (err) => {
      console.error('Telegram send error', err);
      res.json({ success: true, sentToTelegram: false });
    });

    request.write(payload);
    request.end();
    return;
  }

  // If Telegram not configured, return success but indicate not sent
  res.json({ success: true, sentToTelegram: false, message: 'Telegram not configured' });
});

// 404 handler
app.use((req, res) => {
  res.status(404).send('404 Not Found');
});

// error handler
app.use((err, req, res, next) => {
  console.error(err.stack);
  res.status(500).send('Something went wrong!');
});

// server start
const PORT = process.env.PORT || 3001;
app.listen(PORT, () => {
  console.log(`Server running at http://localhost:${PORT}`);
});

module.exports = app;
const express = require('express');
const http = require('http');
const path = require('path');
const { spawn } = require('child_process');
require('dotenv').config();
const questionRoutes = require('./question/router');

const app = express();

if (process.env.TRUST_PROXY) {
  app.set('trust proxy', process.env.TRUST_PROXY === 'true' ? 1 : process.env.TRUST_PROXY);
}

const yangRoutePrefix = '/yang';
const yangDocRoot = path.join(__dirname, 'yang');
const yangPhpHost = process.env.YANG_PHP_HOST || '127.0.0.1';
const yangPhpPort = Number(process.env.YANG_PHP_PORT || 8072);
const yangPhpBin = process.env.YANG_PHP_BIN || 'php';
let yangPhpProcess = null;

function startYangPhpServer() {
  if (process.env.YANG_PHP_AUTOSTART === 'false' || yangPhpProcess) {
    return;
  }

  const phpProcess = spawn(
    yangPhpBin,
    ['-S', `${yangPhpHost}:${yangPhpPort}`, '-t', yangDocRoot],
    { stdio: ['ignore', 'pipe', 'pipe'] }
  );
  yangPhpProcess = phpProcess;

  phpProcess.stdout.on('data', (data) => {
    console.log(`[yang-php] ${data.toString().trim()}`);
  });

  phpProcess.stderr.on('data', (data) => {
    console.error(`[yang-php] ${data.toString().trim()}`);
  });

  phpProcess.on('error', (err) => {
    console.error(`[yang-php] failed to start: ${err.message}`);
  });

  phpProcess.on('exit', (code, signal) => {
    console.error(`[yang-php] exited with code ${code ?? '-'} signal ${signal ?? '-'}`);
    if (yangPhpProcess === phpProcess) {
      yangPhpProcess = null;
    }
  });
}

function stopYangPhpServer() {
  if (yangPhpProcess && !yangPhpProcess.killed) {
    yangPhpProcess.kill('SIGTERM');
  }
  yangPhpProcess = null;
}

// nodemon restarts Node with SIGUSR2. Stop the PHP child as well so the next
// process can bind to YANG_PHP_PORT instead of leaving an orphan worker behind.
process.once('exit', stopYangPhpServer);
process.once('SIGINT', () => process.exit(0));
process.once('SIGTERM', () => process.exit(0));
process.once('SIGUSR2', () => {
  stopYangPhpServer();
  process.kill(process.pid, 'SIGUSR2');
});

function proxyYangToPhp(req, res) {
  startYangPhpServer();

  const strippedPath = req.originalUrl.slice(yangRoutePrefix.length) || '/';
  const targetPath = strippedPath.startsWith('/') ? strippedPath : `/${strippedPath}`;

  const headers = {
    ...req.headers,
    host: `${yangPhpHost}:${yangPhpPort}`,
    'x-forwarded-prefix': yangRoutePrefix,
    'x-forwarded-host': req.headers.host || '',
    'x-forwarded-proto': req.protocol
  };

  const proxyReq = http.request({
    hostname: yangPhpHost,
    port: yangPhpPort,
    path: targetPath,
    method: req.method,
    headers
  }, (proxyRes) => {
    res.writeHead(proxyRes.statusCode || 200, proxyRes.headers);
    proxyRes.pipe(res);
  });

  proxyReq.on('error', (err) => {
    console.error('Yang PHP proxy error:', err.message);
    res.status(502).send('Yang PHP service is not available.');
  });

  req.pipe(proxyReq);
}

app.use(yangRoutePrefix, proxyYangToPhp);

// middleware
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use('/question', questionRoutes);

// static files
app.use(express.static(path.join(__dirname, 'public')));
app.use('/coopgame', express.static(path.join(__dirname, 'public')));
app.use('/question-assets', express.static(path.join(__dirname, 'public', 'question')));
app.use('/calendar-assets', express.static(path.join(__dirname, 'public', 'calendar')));

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
const roomRoutes = require('./routes/room.routes');
const trackingRoutes = require('./routes/tracking.routes');
const calendarRoutes = require('./calendar/calendar.routes');
app.use('/calendar', calendarRoutes);
app.use('/coopgame/r', roomRoutes);
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
startYangPhpServer();
app.listen(PORT, () => {
  console.log(`Server running at http://localhost:${PORT}`);
});

module.exports = app;

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
app.use('/coopgame/game', gameRoutes);
app.use('/coopgame/admin', adminRoutes);

// home route
app.get('/', (req, res) => {
  res.render('home', { title: 'ยินดีต้อนรับ' });
});

// contact form endpoint (from landing page)
app.post('/contact', (req, res) => {
  const { name, email, company, message } = req.body;
  // For now, log to server console. In production replace with DB/email integration.
  console.log('[Contact]', { name, email, company, message });
  res.json({ success: true, message: 'Received' });
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
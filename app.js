const express = require('express');
const path = require('path');

const app = express();

// middleware
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// static files
app.use(express.static(path.join(__dirname, 'public')));

// view engine
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// routes
const gameRoutes = require('./routes/game.routes');
app.use('/game', gameRoutes);

// home route
app.get('/', (req, res) => {
  res.send('Coop Game Server Running');
});

// 404 handler
app.use((req, res) => {
  res.status(404).send('404 Not Found');
});

// server start
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Server running at http://localhost:${PORT}`);
});
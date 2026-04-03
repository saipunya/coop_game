exports.showStartPage = (req, res) => {
  res.render('game/start');
};

exports.showPlayPage = (req, res) => {
  res.render('game/play');
};

exports.startGame = (req, res) => {
  return res.json({
    success: true,
    attemptId: Date.now(),
    message: 'เริ่มเกมสำเร็จ'
  });
};
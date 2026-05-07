const express = require('express');
const router = express.Router();
const conversionModel = require('../models/conversionEvent.model');

// Public endpoint to receive tracking events from client
router.post('/track', async (req, res) => {
  try {
    const { event_name, event_label, page } = req.body;
    if (!event_name) return res.status(400).json({ success: false, message: 'event_name required' });

    await conversionModel.create({ event_name, event_label, page });
    return res.json({ success: true });
  } catch (err) {
    console.error('Track API error', err);
    return res.status(500).json({ success: false, message: 'Server error' });
  }
});

module.exports = router;

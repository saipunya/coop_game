const MAX_FAILED_ATTEMPTS = parseInt(process.env.CODE_MAX_FAILED_ATTEMPTS || '5', 10);
const WINDOW_MS = parseInt(process.env.CODE_ATTEMPT_WINDOW_MS || `${10 * 60 * 1000}`, 10);
const LOCK_MS = parseInt(process.env.CODE_ATTEMPT_LOCK_MS || `${10 * 60 * 1000}`, 10);

const attempts = new Map();

function getClientIp(req) {
  return req.ip || req.socket?.remoteAddress || 'unknown';
}

function getScope(req) {
  return req.params?.roomSlug || 'default';
}

function getKey(req) {
  return `${getScope(req)}:${getClientIp(req)}`;
}

function getFreshEntry(key, now = Date.now()) {
  const entry = attempts.get(key);

  if (!entry || now > entry.resetAt) {
    return {
      count: 0,
      resetAt: now + WINDOW_MS,
      lockedUntil: 0
    };
  }

  return entry;
}

function recordFailedVerifyCode(req) {
  const now = Date.now();
  const key = getKey(req);
  const entry = getFreshEntry(key, now);

  entry.count += 1;
  if (entry.count >= MAX_FAILED_ATTEMPTS) {
    entry.lockedUntil = now + LOCK_MS;
    entry.resetAt = entry.lockedUntil;
  }

  attempts.set(key, entry);
  return entry;
}

function resetVerifyCodeAttempts(req) {
  attempts.delete(getKey(req));
}

function verifyCodeAttemptLimiter(req, res, next) {
  const now = Date.now();
  const key = getKey(req);
  const entry = getFreshEntry(key, now);

  if (entry.lockedUntil && now < entry.lockedUntil) {
    const retryAfterSeconds = Math.ceil((entry.lockedUntil - now) / 1000);
    res.set('Retry-After', String(retryAfterSeconds));
    return res.status(429).json({
      success: false,
      message: `ใส่รหัสผิดหลายครั้งเกินไป กรุณารอ ${Math.ceil(retryAfterSeconds / 60)} นาที แล้วลองใหม่`,
      errorCode: 'CODE_ATTEMPTS_LOCKED',
      retryAfterSeconds
    });
  }

  if (entry !== attempts.get(key) && entry.count > 0) {
    attempts.set(key, entry);
  }

  next();
}

function pruneExpiredVerifyCodeAttempts() {
  const now = Date.now();
  for (const [key, entry] of attempts.entries()) {
    if (now > entry.resetAt && (!entry.lockedUntil || now > entry.lockedUntil)) {
      attempts.delete(key);
    }
  }
}

setInterval(pruneExpiredVerifyCodeAttempts, WINDOW_MS).unref();

module.exports = {
  verifyCodeAttemptLimiter,
  recordFailedVerifyCode,
  resetVerifyCodeAttempts
};

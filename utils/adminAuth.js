const crypto = require('crypto');

const COOKIE_NAME = 'coop_admin_auth';
const SESSION_MAX_AGE_MS = 8 * 60 * 60 * 1000;

function getAdminConfig() {
  const username = process.env.ADMIN_USERNAME || 'admin';
  const password = process.env.ADMIN_PASSWORD || 'sumet022';
  const secret = process.env.ADMIN_SESSION_SECRET || process.env.SESSION_SECRET || process.env.ADMIN_PASSWORD || 'coop-game-admin-secret';

  return { username, password, secret };
}

function parseCookies(cookieHeader = '') {
  return cookieHeader.split(';').reduce((acc, part) => {
    const [rawKey, ...rawValue] = part.trim().split('=');
    if (!rawKey || rawValue.length === 0) return acc;
    acc[rawKey] = decodeURIComponent(rawValue.join('='));
    return acc;
  }, {});
}

function signPayload(payload, secret) {
  return crypto.createHmac('sha256', secret).update(payload).digest('hex');
}

function createAdminToken(username, secret, maxAgeMs = SESSION_MAX_AGE_MS) {
  const payload = JSON.stringify({ username, exp: Date.now() + maxAgeMs });
  const encodedPayload = Buffer.from(payload, 'utf8').toString('base64url');
  const signature = signPayload(encodedPayload, secret);

  return `${encodedPayload}.${signature}`;
}

function verifyAdminToken(token, secret) {
  if (!token || typeof token !== 'string') return null;

  const parts = token.split('.');
  if (parts.length !== 2) return null;

  const [encodedPayload, signature] = parts;
  const expectedSignature = signPayload(encodedPayload, secret);

  if (expectedSignature.length !== signature.length) return null;
  if (!crypto.timingSafeEqual(Buffer.from(expectedSignature), Buffer.from(signature))) return null;

  try {
    const payload = JSON.parse(Buffer.from(encodedPayload, 'base64url').toString('utf8'));
    if (!payload.username || !payload.exp || Date.now() > payload.exp) {
      return null;
    }

    const config = getAdminConfig();
    if (payload.username !== config.username) {
      return null;
    }

    return { username: payload.username };
  } catch (error) {
    return null;
  }
}

function verifyAdminCredentials(username, password) {
  const config = getAdminConfig();
  return username === config.username && password === config.password;
}

function getAdminSession(req) {
  const { secret } = getAdminConfig();
  const cookies = parseCookies(req.headers.cookie || '');
  return verifyAdminToken(cookies[COOKIE_NAME], secret);
}

function isAdminAuthenticated(req) {
  return Boolean(getAdminSession(req));
}

function buildAuthCookie(token, req) {
  const secure = process.env.NODE_ENV === 'production' || Boolean(req && req.secure);
  const parts = [
    `${COOKIE_NAME}=${encodeURIComponent(token)}`,
    'Path=/',
    'HttpOnly',
    'SameSite=Lax',
    `Max-Age=${Math.floor(SESSION_MAX_AGE_MS / 1000)}`
  ];

  if (secure) {
    parts.push('Secure');
  }

  return parts.join('; ');
}

function buildClearAuthCookie() {
  return [
    `${COOKIE_NAME}=`,
    'Path=/',
    'HttpOnly',
    'SameSite=Lax',
    'Max-Age=0'
  ].join('; ');
}

function setAdminAuthCookie(res, username, req) {
  const { secret } = getAdminConfig();
  const token = createAdminToken(username, secret);
  res.setHeader('Set-Cookie', buildAuthCookie(token, req));
}

function clearAdminAuthCookie(res) {
  res.setHeader('Set-Cookie', buildClearAuthCookie());
}

function adminAuthMiddleware(req, res, next) {
  const session = getAdminSession(req);
  if (session) {
    res.locals.adminUser = session;
    return next();
  }

  if (req.path.startsWith('/api/')) {
    return res.status(401).json({ success: false, message: 'Authentication required' });
  }

  return res.redirect('/coopgame/admin/login');
}

module.exports = {
  adminAuthMiddleware,
  buildAuthCookie,
  clearAdminAuthCookie,
  createAdminToken,
  getAdminConfig,
  getAdminSession,
  isAdminAuthenticated,
  setAdminAuthCookie,
  verifyAdminCredentials,
  verifyAdminToken
};

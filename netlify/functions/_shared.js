const crypto = require('crypto');

const SUPABASE_URL = process.env.SUPABASE_URL || 'https://ninsrrallzwmkqyosqeu.supabase.co';
const SUPABASE_KEY = process.env.SUPABASE_KEY || 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5pbnNycmFsbHp3bWtxeW9zcWV1Iiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4Mzg2MDk1MiwiZXhwIjoyMDk5NDM2OTUyfQ.JabQJz9kRWtZqY7FVJD14yOaKT0phfXTQekGUzYe5ZA';
const SUPABASE_REST_URL = SUPABASE_URL + '/rest/v1';

const SESSION_DURATION = 86400 * 7;

const PRICE_BW_PER_PAGE = 0.20;
const PRICE_COLOR_PER_PAGE = 1.10;
const PRICE_A3_MULTIPLIER = 2.0;
const PRICE_DOUBLE_SIDED_MULTIPLIER = 0.7;

const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

const SMTP_HOST = process.env.SMTP_HOST || 'smtp.gmail.com';
const SMTP_PORT = parseInt(process.env.SMTP_PORT || '587', 10);
const SMTP_USER = process.env.SMTP_USER || 'printkiosk.sender@gmail.com';
const SMTP_PASS = process.env.SMTP_PASS || 'vewuljgrgldslwwv';
const SMTP_FROM_NAME = process.env.SMTP_FROM_NAME || 'PrintKiosk';
const VERIFICATION_CODE_LENGTH = 6;
const VERIFICATION_CODE_TTL = 600;

function corsHeaders() {
  return {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Session-Token',
    'Content-Type': 'application/json'
  };
}

function json(data, statusCode = 200) {
  return {
    statusCode,
    headers: corsHeaders(),
    body: JSON.stringify(data)
  };
}

function err(message, statusCode = 400) {
  return json({ error: message }, statusCode);
}

async function supabaseRequest(method, endpoint, data = null, params = {}) {
  let url = SUPABASE_REST_URL + endpoint;
  if (Object.keys(params).length > 0) {
    const qs = new URLSearchParams(params).toString();
    url += '?' + qs;
  }

  const headers = {
    'apikey': SUPABASE_KEY,
    'Authorization': 'Bearer ' + SUPABASE_KEY,
    'Content-Type': 'application/json',
    'Prefer': 'return=representation'
  };

  const options = { method, headers };
  if (data && ['POST', 'PUT', 'PATCH'].includes(method)) {
    options.body = JSON.stringify(data);
  }

  try {
    const response = await fetch(url, options);
    const text = await response.text();
    let parsed;
    try { parsed = JSON.parse(text); } catch(e) { parsed = text; }

    if (!response.ok) {
      const msg = (parsed && typeof parsed === 'object')
        ? (parsed.message || parsed.error || JSON.stringify(parsed))
        : (parsed || 'Unknown error');
      return { error: true, message: msg, http_code: response.status, data: parsed };
    }

    return { error: false, data: parsed, http_code: response.status };
  } catch (e) {
    return { error: true, message: e.message, http_code: 0 };
  }
}

function generateJobId() {
  const date = new Date().toISOString().slice(0, 10).replace(/-/g, '');
  const rand = crypto.randomBytes(2).toString('hex').toUpperCase();
  return 'PRN-' + date + '-' + rand;
}

function generateSessionToken() {
  return crypto.randomBytes(32).toString('hex');
}

function generateVerificationCode() {
  return String(Math.floor(Math.random() * 1000000)).padStart(VERIFICATION_CODE_LENGTH, '0');
}

function isValidGmail(email) {
  return /^[a-zA-Z0-9._%+\-]+@gmail\.com$/i.test(email);
}

function isValidMalaysianPhone(phone) {
  const p = String(phone).replace(/[\s\-]/g, '');
  return /^(?:\+?60|0)?1[0-9]{8,9}$/.test(p);
}

function normalizePhone(phone) {
  const p = String(phone).replace(/[\s\-]/g, '');
  let m = p.match(/^0(1[0-9]{8,9})$/);
  if (m) return '+60' + m[1];
  m = p.match(/^(60)(1[0-9]{8,9})$/);
  if (m) return '+' + m[1] + m[2];
  m = p.match(/^\+?(6?0)?(1[0-9]{8,9})$/);
  if (m) return '+60' + m[2];
  return p;
}

// Minimal SMTP client (no dependencies) - sends via Gmail SMTP with STARTTLS
function smtpSend(to, subject, htmlBody) {
  return new Promise((resolve) => {
    if (!SMTP_USER || !SMTP_PASS) return resolve(false);

    const net = require('net');
    const tls = require('tls');

    const sock = net.connect(SMTP_PORT, SMTP_HOST);
    let buffer = '';
    let queue = [];
    let upgraded = false;

    function onLine() {
      let idx;
      while ((idx = buffer.indexOf('\n')) !== -1) {
        const line = buffer.slice(0, idx);
        buffer = buffer.slice(idx + 1);
        if (line.trim() === '') continue;
        const nextLine = queue.shift();
        if (nextLine) nextLine(line);
      }
    }

    function send(cmd) {
      return new Promise((res, rej) => {
        const timer = setTimeout(() => rej(new Error('SMTP timeout')), 15000);
        queue.push((line) => {
          clearTimeout(timer);
          res(line);
        });
        sock.write(cmd + '\r\n');
      });
    }

    function readReply() {
      return new Promise((res, rej) => {
        const timer = setTimeout(() => rej(new Error('SMTP reply timeout')), 15000);
        const collect = (line) => {
          if (/^[0-9]{3} /.test(line)) {
            clearTimeout(timer);
            res(line);
          } else {
            queue.unshift(collect);
          }
        };
        queue.unshift(collect);
      });
    }

    sock.on('data', (d) => { buffer += d.toString(); onLine(); });
    sock.on('error', () => resolve(false));

    async function run() {
      try {
        await readReply();
        await send('EHLO localhost');

        const starttls = await send('STARTTLS');
        if (!/^220/.test(starttls)) return resolve(false);

        await new Promise((res, rej) => {
          const secure = tls.connect({ socket: sock, servername: SMTP_HOST }, () => {
            upgraded = true;
            res();
          });
          secure.on('error', rej);
        });

        await send('EHLO localhost');

        const auth = await send('AUTH PLAIN ' + Buffer.from('\0' + SMTP_USER + '\0' + SMTP_PASS).toString('base64'));
        if (!/^235/.test(auth)) return resolve(false);

        const mf = await send('MAIL FROM:<' + SMTP_USER + '>');
        if (!/^250/.test(mf)) return resolve(false);

        const rcpt = await send('RCPT TO:<' + to + '>');
        if (!/^250/.test(rcpt)) return resolve(false);

        const data = await send('DATA');
        if (!/^354/.test(data)) return resolve(false);

        const cleanSubject = String(subject).replace(/[\r\n]+/g, ' ');
        const headers = 'From: ' + SMTP_FROM_NAME + ' <' + SMTP_USER + '>\r\n'
          + 'To: ' + to + '\r\n'
          + 'Subject: ' + cleanSubject + '\r\n'
          + 'MIME-Version: 1.0\r\n'
          + 'Content-Type: text/html; charset=UTF-8\r\n'
          + 'Date: ' + new Date().toUTCString() + '\r\n';

        const bodyData = htmlBody.replace(/\r\n\./g, '\r\n..').replace(/\n/g, '\r\n');
        await send(headers + '\r\n' + bodyData + '\r\n.');

        await send('QUIT');
        resolve(true);
      } catch (e) {
        resolve(false);
      }
    }

    run();
    if (!upgraded) sock.setTimeout(15000, () => {}); // safety net; run() handles timeouts
  });
}

async function sendVerificationEmail(to, code, name) {
  const html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;">'
    + '<h2 style="color:#0f172a;margin:0 0 8px;">Verify your email</h2>'
    + '<p style="color:#475569;font-size:14px;">Hi ' + String(name).replace(/[<>&]/g, '') + ',</p>'
    + '<p style="color:#475569;font-size:14px;line-height:1.6;">Use the code below to complete your PrintKiosk registration. This code expires in 10 minutes.</p>'
    + '<div style="text-align:center;margin:24px 0;">'
    + '<span style="display:inline-block;font-size:28px;font-weight:700;letter-spacing:6px;color:#0f172a;background:#f1f5f9;padding:14px 24px;border-radius:10px;">' + code + '</span>'
    + '</div>'
    + '<p style="color:#94a3b8;font-size:12px;">If you did not request this, you can safely ignore this email.</p>'
    + '</div>';

  return smtpSend(to, 'PrintKiosk - Verify your email', html);
}

async function validateSession(event) {
  const headers = event.headers || {};
  const token = headers['x-session-token'] || headers['X-Session-Token'] || '';

  if (!token) return null;

  const result = await supabaseRequest('GET', '/sessions', null, {
    'session_token': 'eq.' + token,
    'select': '*,users(id,name,email,role,coins)'
  });

  if (result.error || !result.data || result.data.length === 0) return null;

  const session = result.data[0];
  if (new Date(session.expires_at) < new Date()) {
    await supabaseRequest('DELETE', '/sessions', null, { 'session_token': 'eq.' + token });
    return null;
  }

  return session;
}

function calculatePrice(pages, colorMode, paperSize, sides, copies) {
  const pagePrice = colorMode === 'color' ? PRICE_COLOR_PER_PAGE : PRICE_BW_PER_PAGE;
  const sizeMultiplier = paperSize === 'a3' ? PRICE_A3_MULTIPLIER : 1.0;
  const sidesMultiplier = sides === 'double' ? PRICE_DOUBLE_SIDED_MULTIPLIER : 1.0;
  const effectivePages = pages * sidesMultiplier;
  const pricePerCopy = effectivePages * pagePrice * sizeMultiplier;
  const total = pricePerCopy * copies;
  return Math.round(total * 100) / 100;
}

module.exports = {
  SUPABASE_URL, SUPABASE_KEY, SUPABASE_REST_URL,
  SESSION_DURATION, PRICE_BW_PER_PAGE, PRICE_COLOR_PER_PAGE,
  PRICE_A3_MULTIPLIER, PRICE_DOUBLE_SIDED_MULTIPLIER, ALLOWED_EXTENSIONS,
  SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM_NAME,
  VERIFICATION_CODE_LENGTH, VERIFICATION_CODE_TTL,
  corsHeaders, json, err, supabaseRequest, generateJobId,
  generateSessionToken, validateSession, calculatePrice,
  generateVerificationCode, isValidGmail, isValidMalaysianPhone,
  normalizePhone, sendVerificationEmail, smtpSend
};

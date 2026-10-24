const { app, BrowserWindow } = require('electron');
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn } = require('child_process');

const LOCAL_URL = 'http://localhost:8088';
const DESKTOP_SETUP_URL = `${LOCAL_URL}/desktop_link_setup.php`;
const RETRY_MS = 3500;
const FALLBACK_REFRESH_MS = 4500;
const RUNTIME_RESTART_MS = 30000;
const BLOCK_COOLDOWN_MS = 180000;
const MAX_AUTO_RESTART_ATTEMPTS = 3;
const MAX_LOG_LINES = 140;

const runtimeState = {
  templateRoot: '',
  runtimeRoot: '',
  logs: [],
  lastError: '',
  lastFailure: '',
  lastFallbackRenderAt: 0,
  startedAt: 0,
  lastStartAttemptAt: 0,
  starting: false,
  needsSetup: false,
  openedSetup: false,
  restartAttempts: 0,
  blockedReason: '',
  blockedUntil: 0,
  remoteFallbackUrl: '',
};

function escapeHtml(input) {
  return String(input || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function addRuntimeLog(line) {
  const text = String(line || '').trim();
  if (!text) return;
  runtimeState.logs.push(text);
  if (runtimeState.logs.length > MAX_LOG_LINES) {
    runtimeState.logs = runtimeState.logs.slice(-MAX_LOG_LINES);
  }
}

function resolveTemplateRoot() {
  if (app.isPackaged) {
    return path.join(process.resourcesPath, 'runtime_template');
  }
  return path.resolve(__dirname, '..', 'runtime_template');
}

function resolveRuntimeRoot() {
  return path.join(app.getPath('userData'), 'runtime');
}

function parseEnvFile(envPath) {
  if (!fs.existsSync(envPath)) return {};
  const raw = fs.readFileSync(envPath, 'utf8');
  const env = {};
  raw.split(/\r?\n/).forEach((line) => {
    if (!line || line.trim().startsWith('#')) return;
    const idx = line.indexOf('=');
    if (idx <= 0) return;
    const key = line.slice(0, idx).trim();
    const value = line.slice(idx + 1).trim();
    env[key] = value;
  });
  return env;
}

function isLocalLikeUrl(url) {
  const raw = String(url || '').trim();
  if (!raw) return true;
  try {
    const parsed = new URL(raw);
    const host = String(parsed.hostname || '').toLowerCase();
    if (!host) return true;
    if (host === 'localhost' || host === '127.0.0.1' || host === '::1') return true;
    if (host.endsWith('.local') || host.endsWith('.lan')) return true;
    if (/^10\./.test(host) || /^192\.168\./.test(host) || /^172\.(1[6-9]|2\d|3[0-1])\./.test(host)) return true;
    return false;
  } catch (_e) {
    return true;
  }
}

function deriveCloudBaseFromLicenseApi(url) {
  const raw = String(url || '').trim();
  if (!raw) return '';
  try {
    const parsed = new URL(raw);
    if (isLocalLikeUrl(raw)) return '';
    const pathName = String(parsed.pathname || '');
    if (pathName.endsWith('/license_api.php')) {
      parsed.pathname = pathName.replace(/\/license_api\.php$/i, '/');
      parsed.search = '';
      parsed.hash = '';
      return parsed.toString().replace(/\/$/, '');
    }
    if (pathName.endsWith('/api/license/check') || pathName.endsWith('/api/license/check/')) {
      parsed.pathname = pathName.replace(/\/api\/license\/check\/?$/i, '/');
      parsed.search = '';
      parsed.hash = '';
      return parsed.toString().replace(/\/$/, '');
    }
    return '';
  } catch (_e) {
    return '';
  }
}

function resolveRemoteFallbackUrl(env) {
  const explicit = String(env.APP_DESKTOP_CLOUD_URL || '').trim();
  if (explicit && !isLocalLikeUrl(explicit)) return explicit;

  const systemUrl = String(env.SYSTEM_URL || '').trim();
  if (systemUrl && !isLocalLikeUrl(systemUrl)) return systemUrl;

  const remoteApi = String(env.APP_LICENSE_REMOTE_URL || '').trim();
  const derived = deriveCloudBaseFromLicenseApi(remoteApi);
  if (derived && !isLocalLikeUrl(derived)) return derived;

  return '';
}

function isSetupRequired(env) {
  const skipSetup = String(env.APP_DESKTOP_SKIP_SETUP || '').trim().toLowerCase();
  if (['1', 'true', 'yes', 'on'].includes(skipSetup)) {
    return false;
  }
  const edition = String(env.APP_LICENSE_EDITION || 'client').trim().toLowerCase();
  if (edition === 'owner') {
    return !env.APP_SUPER_USER_ID && !env.APP_SUPER_USER_USERNAME && !env.APP_SUPER_USER_EMAIL;
  }
  const remoteUrl = String(env.APP_LICENSE_REMOTE_URL || '').trim();
  const remoteToken = String(env.APP_LICENSE_REMOTE_TOKEN || '').trim();
  const licenseKey = String(env.APP_LICENSE_KEY || '').trim();
  const autoKey = ['1', 'true', 'yes', 'on'].includes(String(env.APP_LICENSE_AUTO_KEY || '').trim().toLowerCase());

  const badUrl = !remoteUrl || remoteUrl === 'https://owner.example.com/license_api.php';
  const badToken = !remoteToken || remoteToken === 'CHANGE_ME' || remoteToken === 'PUT_CLIENT_API_TOKEN_HERE';
  const badKey = !licenseKey || licenseKey === 'SET_CLIENT_LICENSE_KEY' || licenseKey === 'SET_CLIENT_LICENSE_KEY_HERE';

  if (badUrl || badToken) {
    return true;
  }
  if (badKey && autoKey) {
    return false;
  }
  return badKey;
}

function syncRuntimeTemplate() {
  const templateRoot = resolveTemplateRoot();
  const runtimeRoot = resolveRuntimeRoot();
  const templateRuntime = path.join(templateRoot, 'desktop_runtime');
  const runtimeEnv = path.join(runtimeRoot, 'desktop_runtime', '.env');
  const runtimeEnvExists = fs.existsSync(runtimeEnv);
  const runtimeEnvContent = runtimeEnvExists ? fs.readFileSync(runtimeEnv, 'utf8') : '';

  if (!fs.existsSync(templateRuntime)) {
    throw new Error(`Missing runtime template: ${templateRuntime}`);
  }

  fs.mkdirSync(runtimeRoot, { recursive: true });
  fs.cpSync(templateRoot, runtimeRoot, { recursive: true, force: true });

  if (runtimeEnvExists) {
    fs.writeFileSync(runtimeEnv, runtimeEnvContent, 'utf8');
  } else {
    const envExample = path.join(runtimeRoot, 'desktop_runtime', '.env.example');
    if (fs.existsSync(envExample)) {
      fs.copyFileSync(envExample, runtimeEnv);
    }
  }

  if (process.platform !== 'win32') {
    const macStart = path.join(runtimeRoot, 'desktop_runtime', 'start-macos.sh');
    const macStop = path.join(runtimeRoot, 'desktop_runtime', 'stop-macos.sh');
    if (fs.existsSync(macStart)) fs.chmodSync(macStart, 0o755);
    if (fs.existsSync(macStop)) fs.chmodSync(macStop, 0o755);
  }

  runtimeState.templateRoot = templateRoot;
  runtimeState.runtimeRoot = runtimeRoot;
  const env = parseEnvFile(path.join(runtimeRoot, 'desktop_runtime', '.env'));
  runtimeState.needsSetup = isSetupRequired(env);
  runtimeState.remoteFallbackUrl = resolveRemoteFallbackUrl(env);
}

function refreshSetupFlagFromEnv() {
  if (!runtimeState.runtimeRoot) return;
  const envPath = path.join(runtimeState.runtimeRoot, 'desktop_runtime', '.env');
  const env = parseEnvFile(envPath);
  runtimeState.needsSetup = isSetupRequired(env);
  runtimeState.remoteFallbackUrl = resolveRemoteFallbackUrl(env);
}

function detectFatalIssue(line) {
  const text = String(line || '').toLowerCase();
  if (!text) return '';

  if (text.includes('docker command not found') || text.includes('docker is required') || text.includes('docker not found')) {
    return 'Docker Desktop غير مثبت أو غير متاح في PATH.';
  }
  if (text.includes('docker desktop did not become ready') || text.includes('did not become ready in time')) {
    return 'Docker Desktop موجود لكنه لم يعمل بالكامل بعد.';
  }
  if (text.includes('winget is unavailable')) {
    return 'لا يمكن التثبيت التلقائي لأن Winget غير متاح على ويندوز.';
  }
  if (text.includes('homebrew install failed') || text.includes('brew install failed')) {
    return 'تعذر التثبيت التلقائي عبر Homebrew.';
  }
  if (text.includes('permission denied')) {
    return 'هناك مشكلة صلاحيات أثناء تجهيز البيئة المحلية.';
  }
  return '';
}

function markBlocked(reason, cooldownMs = BLOCK_COOLDOWN_MS) {
  if (!reason) return;
  runtimeState.blockedReason = reason;
  runtimeState.blockedUntil = Date.now() + Math.max(10000, cooldownMs);
  addRuntimeLog(`Blocked: ${reason}`);
}

function clearBlockedState() {
  runtimeState.blockedReason = '';
  runtimeState.blockedUntil = 0;
}

function splitLines(rawChunk) {
  return String(rawChunk || '')
    .split(/\r?\n/)
    .map((s) => s.trim())
    .filter(Boolean);
}

function isBlockedNow() {
  return runtimeState.blockedReason !== '' && Date.now() < runtimeState.blockedUntil;
}

function spawnRuntimeStart(force = false) {
  if (runtimeState.starting || !runtimeState.runtimeRoot) return;
  if (!force && isBlockedNow()) return;

  const runtimeDir = path.join(runtimeState.runtimeRoot, 'desktop_runtime');
  const env = {
    ...process.env,
    AE_DESKTOP_NON_INTERACTIVE: '1',
    AE_DESKTOP_FROM_ELECTRON: '1',
    AE_DESKTOP_OPEN_BROWSER: '0',
    AE_DESKTOP_AUTO_UPDATE: process.env.AE_DESKTOP_AUTO_UPDATE || '1',
  };

  let command;
  let args;

  if (process.platform === 'win32') {
    command = 'cmd.exe';
    args = ['/d', '/s', '/c', 'start-windows.bat'];
  } else {
    command = '/bin/bash';
    args = ['start-macos.sh'];
  }

  runtimeState.starting = true;
  runtimeState.startedAt = Date.now();
  runtimeState.lastStartAttemptAt = Date.now();
  runtimeState.restartAttempts += 1;
  runtimeState.lastError = '';

  if (force) {
    clearBlockedState();
  }

  addRuntimeLog(`Starting runtime from: ${runtimeDir}`);
  addRuntimeLog(`Start attempt #${runtimeState.restartAttempts}`);

  const child = spawn(command, args, {
    cwd: runtimeDir,
    env,
    windowsHide: true,
  });

  const onChunk = (chunk) => {
    const lines = splitLines(chunk.toString());
    lines.forEach((line) => {
      addRuntimeLog(line);
      const fatal = detectFatalIssue(line);
      if (fatal) {
        runtimeState.lastError = fatal;
        markBlocked(fatal);
      }
    });
  };

  child.stdout.on('data', onChunk);
  child.stderr.on('data', onChunk);

  child.on('error', (error) => {
    runtimeState.lastError = error.message || 'runtime_start_error';
    runtimeState.starting = false;
    addRuntimeLog(`Start error: ${runtimeState.lastError}`);
    if (runtimeState.restartAttempts >= MAX_AUTO_RESTART_ATTEMPTS) {
      markBlocked('تعذر تشغيل الخدمة المحلية تلقائيا. يلزم تدخل يدوي.');
    }
  });

  child.on('close', (code) => {
    runtimeState.starting = false;
    if (code !== 0) {
      runtimeState.lastError = `Runtime exited with code ${code}`;
      addRuntimeLog(runtimeState.lastError);
      if (!runtimeState.blockedReason && runtimeState.restartAttempts >= MAX_AUTO_RESTART_ATTEMPTS) {
        markBlocked('فشل تشغيل الخدمة المحلية عدة مرات. تم إيقاف المحاولة مؤقتا.');
      }
    }
  });
}

function canReachLocal() {
  return new Promise((resolve) => {
    const req = http.get(LOCAL_URL, (res) => {
      res.resume();
      resolve(true);
    });
    req.setTimeout(1800, () => {
      req.destroy();
      resolve(false);
    });
    req.on('error', () => resolve(false));
  });
}

function blockedCountdownText() {
  if (!isBlockedNow()) return '';
  const sec = Math.max(1, Math.ceil((runtimeState.blockedUntil - Date.now()) / 1000));
  return `سيتم تكرار المحاولة تلقائيا بعد ${sec} ثانية.`;
}

function fallbackHtml() {
  const errorText = runtimeState.lastFailure || runtimeState.lastError;
  const errorBlock = errorText
    ? `<div class="err">${escapeHtml(errorText)}</div>`
    : '';
  const setupHint = runtimeState.needsSetup
    ? '<div class="hint">يرجى إكمال الربط مرة واحدة فقط. بعد ذلك سيعمل التفعيل والمزامنة تلقائيا.</div>'
    : '';
  const blockedHint = isBlockedNow()
    ? `<div class="hint" style="background:rgba(231,76,60,.14);border-color:rgba(231,76,60,.45);color:#ffb6ae">${escapeHtml(runtimeState.blockedReason)}<br>${escapeHtml(blockedCountdownText())}</div>`
    : '';
  const logTail = runtimeState.logs.slice(-14);
  const logs = logTail.length
    ? `<div class="logs">${logTail.map((line) => `<div>${escapeHtml(line)}</div>`).join('')}</div>`
    : '';

  const retryNote = isBlockedNow()
    ? 'تم إيقاف التكرار السريع مؤقتا لتجنب الـloop.'
    : `المحاولة التلقائية كل ${Math.round(RETRY_MS / 1000)} ثواني.`;
  const cloudButton = runtimeState.remoteFallbackUrl
    ? `<a class="dark" href="${escapeHtml(runtimeState.remoteFallbackUrl)}">فتح النسخة السحابية</a>`
    : '';

  return `<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Arab Eagles Desktop</title>
  <style>
    body{margin:0;background:#090b10;color:#f2f2f2;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Tahoma,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}
    .card{max-width:860px;width:100%;background:#10131b;border:1px solid #2c3a56;border-radius:16px;padding:22px;box-shadow:0 20px 48px rgba(0,0,0,.35)}
    h1{margin:0 0 10px;color:#d4af37;font-size:1.45rem}
    p{margin:0 0 12px;line-height:1.8;color:#d8dee9}
    .err{margin:0 0 12px;padding:10px 12px;border-radius:10px;background:rgba(198,76,76,.14);border:1px solid rgba(198,76,76,.4);color:#ffcccc;word-break:break-word}
    .box{background:#0a0d13;border:1px solid #2a3348;border-radius:10px;padding:10px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr;text-align:left;color:#d4e7ff;margin-bottom:12px}
    .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
    a{display:inline-block;text-decoration:none;border-radius:10px;padding:10px 14px;font-weight:700}
    .gold{background:#d4af37;color:#111}
    .dark{background:#202736;color:#f4f4f4;border:1px solid #3e4b66}
    .mini{margin-top:10px;font-size:.85rem;color:#9ea8be}
    .hint{margin:10px 0;padding:9px 12px;border-radius:10px;background:rgba(212,175,55,.15);border:1px solid rgba(212,175,55,.36);color:#ffe8a8}
    .logs{margin-top:12px;background:#070a11;border:1px solid #283249;border-radius:10px;padding:10px;max-height:220px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.88rem;color:#cedfff}
    .logs > div{margin-bottom:5px;line-height:1.45}
  </style>
</head>
<body>
  <div class="card">
    <h1>جاري تجهيز النظام المحلي تلقائيا</h1>
    <p>التطبيق يفحص المتطلبات ويشغّل الخدمة المحلية تلقائيا، ثم يفتح النظام مباشرة.</p>
    ${errorBlock}
    ${blockedHint}
    ${setupHint}
    <div class="box">${escapeHtml(runtimeState.runtimeRoot || 'Runtime path unavailable')}</div>
    <div class="actions">
      <a class="gold" href="javascript:location.reload()">إعادة المحاولة الآن</a>
      <a class="dark" href="${LOCAL_URL}">فتح النظام المحلي</a>
      ${cloudButton}
      <a class="dark" href="https://www.docker.com/products/docker-desktop/">تحميل Docker Desktop</a>
    </div>
    ${logs}
    <div class="mini">${escapeHtml(retryNote)}</div>
  </div>
</body>
</html>`;
}

function renderFallback(win) {
  runtimeState.lastFallbackRenderAt = Date.now();
  const html = fallbackHtml();
  win.loadURL(`data:text/html;charset=UTF-8,${encodeURIComponent(html)}`);
}

async function bootstrapRuntime() {
  syncRuntimeTemplate();
  spawnRuntimeStart();
}

function setupWindowMonitoring(win) {
  const tick = async () => {
    if (win.isDestroyed()) return;

    const reachable = await canReachLocal();
    if (reachable) {
      refreshSetupFlagFromEnv();
      const targetUrl = runtimeState.needsSetup && !runtimeState.openedSetup ? DESKTOP_SETUP_URL : LOCAL_URL;
      runtimeState.openedSetup = runtimeState.openedSetup || runtimeState.needsSetup;
      runtimeState.lastFailure = '';
      runtimeState.lastError = '';
      runtimeState.restartAttempts = 0;
      clearBlockedState();

      const current = win.webContents.getURL() || '';
      if (!current.startsWith(LOCAL_URL)) {
        win.loadURL(targetUrl).catch(() => {});
      }
    } else {
      const current = win.webContents.getURL() || '';
      runtimeState.lastFailure = `Cannot reach ${LOCAL_URL}`;
      if (runtimeState.remoteFallbackUrl && !current.startsWith(runtimeState.remoteFallbackUrl)) {
        win.loadURL(runtimeState.remoteFallbackUrl).catch(() => {});
      } else if (!current.startsWith('data:text/html') && !runtimeState.remoteFallbackUrl) {
        renderFallback(win);
      } else if (!runtimeState.remoteFallbackUrl && Date.now() - runtimeState.lastFallbackRenderAt > FALLBACK_REFRESH_MS) {
        renderFallback(win);
      }

      const enoughTimePassed = Date.now() - runtimeState.lastStartAttemptAt > RUNTIME_RESTART_MS;
      if (!runtimeState.starting && enoughTimePassed && !isBlockedNow()) {
        spawnRuntimeStart();
      }
    }

    setTimeout(tick, RETRY_MS);
  };

  setTimeout(tick, RETRY_MS);
}

function createWindow() {
  const win = new BrowserWindow({
    width: 1440,
    height: 920,
    minWidth: 1024,
    minHeight: 700,
    autoHideMenuBar: true,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });

  win.webContents.on('did-fail-load', (_event, errorCode, errorDescription, validatedURL, isMainFrame) => {
    if (!isMainFrame) return;
    const triedUrl = String(validatedURL || '');
    if (triedUrl.startsWith(LOCAL_URL) || (runtimeState.remoteFallbackUrl && triedUrl.startsWith(runtimeState.remoteFallbackUrl))) {
      runtimeState.lastFailure = `Cannot reach ${LOCAL_URL} (${errorCode}): ${errorDescription || 'load_failed'}`;
      renderFallback(win);
    }
  });

  win.webContents.on('did-finish-load', () => {
    if (win.getTitle() === '') {
      win.setTitle('Arab Eagles ERP Desktop');
    }
  });

  renderFallback(win);
  bootstrapRuntime().catch((error) => {
    runtimeState.lastError = error.message || 'bootstrap_failed';
    addRuntimeLog(`Bootstrap error: ${runtimeState.lastError}`);
    markBlocked('فشل تجهيز نسخة التشغيل المحلية.', BLOCK_COOLDOWN_MS);
    renderFallback(win);
  });
  setupWindowMonitoring(win);
}

app.whenReady().then(() => {
  createWindow();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

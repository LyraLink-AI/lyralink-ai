const { app, BrowserWindow, shell, session, dialog, ipcMain } = require('electron');
const path = require('path');
const fs = require('fs');
const http = require('http');
const https = require('https');
const { spawn } = require('child_process');
const log = require('electron-log');

const REMOTE_APP_URL = process.env.LYRALINK_APP_URL || 'http://lyralinkai.com/chat';
const LOCAL_MODE = process.argv.includes('--local') || process.env.LYRALINK_MODE === 'local';
const ALLOW_PACKAGED_LOCAL_MODE = process.env.LYRALINK_ALLOW_PACKAGED_LOCAL_MODE === '1';
const LOCAL_HOST = process.env.LYRALINK_LOCAL_HOST || '127.0.0.1';
const LOCAL_PORT = Number(process.env.LYRALINK_LOCAL_PORT || 37991);

let mainWindow = null;
let localServerProc = null;
let activeAppUrl = REMOTE_APP_URL;
let activeAppOrigin = new URL(REMOTE_APP_URL).origin;

const UPDATE_MANIFEST_URL = process.env.LYRALINK_UPDATE_MANIFEST || 'http://lyralinkai.com/desktop-updates/latest.json';

function resolveInstallerUrlForPlatform(manifest) {
  const byPlatform = manifest && typeof manifest === 'object' ? manifest.platformInstallerUrls : null;
  if (byPlatform && typeof byPlatform === 'object') {
    const platformUrl = byPlatform[process.platform];
    if (typeof platformUrl === 'string' && platformUrl.trim() !== '') {
      return platformUrl.trim();
    }
  }

  const generic = manifest && typeof manifest.installerUrl === 'string' ? manifest.installerUrl.trim() : '';
  return generic;
}

function parseVersion(v) {
  return String(v || '0.0.0')
    .split('.')
    .map((n) => Number(n.replace(/[^0-9]/g, '')) || 0)
    .slice(0, 3);
}

function isVersionGreater(a, b) {
  const av = parseVersion(a);
  const bv = parseVersion(b);
  for (let i = 0; i < 3; i += 1) {
    if ((av[i] || 0) > (bv[i] || 0)) return true;
    if ((av[i] || 0) < (bv[i] || 0)) return false;
  }
  return false;
}

function fetchJson(url) {
  return new Promise((resolve, reject) => {
    const client = url.startsWith('https://') ? https : http;
    const req = client.get(url, (res) => {
      if (res.statusCode !== 200) {
        res.resume();
        reject(new Error(`Update check failed (${res.statusCode})`));
        return;
      }
      let raw = '';
      res.setEncoding('utf8');
      res.on('data', (chunk) => { raw += chunk; });
      res.on('end', () => {
        try {
          resolve(JSON.parse(raw));
        } catch (e) {
          reject(new Error('Invalid update manifest JSON'));
        }
      });
    });
    req.on('error', reject);
    req.setTimeout(12000, () => req.destroy(new Error('Update check timeout')));
  });
}

async function setupAutoUpdater() {
  if (!app.isPackaged) {
    return;
  }
  if (process.env.LYRALINK_DISABLE_AUTO_UPDATE === '1') {
    return;
  }

  try {
    const manifest = await fetchJson(UPDATE_MANIFEST_URL);
    const latestVersion = manifest.version;
    const installerUrl = resolveInstallerUrlForPlatform(manifest);
    const currentVersion = app.getVersion();

    if (!latestVersion || !installerUrl) {
      log.warn('[auto-update] manifest missing version or installerUrl');
      return;
    }

    if (!isVersionGreater(latestVersion, currentVersion)) {
      log.info('[auto-update] no updates available');
      return;
    }

    const result = await dialog.showMessageBox({
      type: 'info',
      buttons: ['Download Update', 'Later'],
      defaultId: 0,
      cancelId: 1,
      title: 'Update Available',
      message: `Lyralink ${latestVersion} is available (you have ${currentVersion}).`,
      detail: manifest.notes || 'Download and run the new installer to update.'
    });

    if (result.response === 0) {
      shell.openExternal(installerUrl);
    }
  } catch (err) {
    log.error('[auto-update] check failed:', err?.message || err);
  }
}

function runtimePath(...parts) {
  if (app.isPackaged) {
    return path.join(process.resourcesPath, ...parts);
  }
  return path.join(__dirname, ...parts);
}

function commandExists(bin) {
  try {
    const child = spawn(bin, ['-v'], { stdio: 'ignore' });
    return !!child;
  } catch (_) {
    return false;
  }
}

function resolvePhpBinary() {
  const candidates = [
    process.env.PHP_BIN || '',
    runtimePath('php', 'php.exe'),
    runtimePath('php', 'php'),
    'php',
  ].filter(Boolean);

  for (const c of candidates) {
    const looksLikePath = c.includes('\\') || c.includes('/');
    if (looksLikePath) {
      if (fs.existsSync(c)) return c;
    } else if (commandExists(c)) {
      return c;
    }
  }
  return null;
}

function probeLocalServer(url, timeoutMs = 10000) {
  return new Promise((resolve) => {
    const started = Date.now();
    const attempt = () => {
      const req = http.get(url, (res) => {
        res.resume();
        if (res.statusCode === 200) {
          resolve(true);
        } else if (Date.now() - started > timeoutMs) {
          resolve(false);
        } else {
          setTimeout(attempt, 250);
        }
      });
      req.on('error', () => {
        if (Date.now() - started > timeoutMs) {
          resolve(false);
        } else {
          setTimeout(attempt, 250);
        }
      });
      req.setTimeout(2000, () => req.destroy());
    };
    attempt();
  });
}

async function startLocalPhpServer() {
  const phpBin = resolvePhpBinary();
  const webRoot = runtimePath('web');
  const routerPath = runtimePath('local', 'router.php');

  if (!phpBin || !fs.existsSync(webRoot) || !fs.existsSync(routerPath)) {
    return false;
  }

  const bind = `${LOCAL_HOST}:${LOCAL_PORT}`;
  localServerProc = spawn(phpBin, ['-S', bind, '-t', webRoot, routerPath], {
    cwd: webRoot,
    stdio: 'ignore',
    windowsHide: true,
  });

  localServerProc.on('exit', () => {
    localServerProc = null;
  });

  const healthUrl = `http://${LOCAL_HOST}:${LOCAL_PORT}/health`;
  const ok = await probeLocalServer(healthUrl, 12000);
  if (!ok) {
    if (localServerProc) {
      localServerProc.kill();
      localServerProc = null;
    }
    return false;
  }

  activeAppUrl = `http://${LOCAL_HOST}:${LOCAL_PORT}/chat`;
  activeAppOrigin = `http://${LOCAL_HOST}:${LOCAL_PORT}`;
  return true;
}

function allowKnownPermission(permission) {
  return permission === 'media' || permission === 'mediaKeySystem' || permission === 'clipboard-sanitized-write';
}

function attachWindowStateBridge(win) {
  if (!win) return;
  const emit = () => {
    try {
      win.webContents.send('window-state', {
        isMaximized: win.isMaximized(),
      });
    } catch (_) {}
  };
  win.on('maximize', emit);
  win.on('unmaximize', emit);
  win.on('enter-full-screen', emit);
  win.on('leave-full-screen', emit);
  win.webContents.on('did-finish-load', emit);
}

function injectCustomTitlebar(win) {
  if (!win || win.isDestroyed()) return;
  const css = [
    'html, body { margin-top: 0 !important; }',
    'body { padding-top: 36px !important; box-sizing: border-box !important; }',
    '#lyra-desktop-chrome {',
    '  position: fixed;',
    '  top: 0;',
    '  left: 0;',
    '  right: 0;',
    '  height: 36px;',
    '  background: #0a0a0f;',
    '  border-bottom: 1px solid rgba(148,163,184,0.12);',
    '  display: flex;',
    '  align-items: center;',
    '  justify-content: space-between;',
    '  z-index: 2147483647;',
    '  -webkit-app-region: drag;',
    '  user-select: none;',
    '}',
    '#lyra-desktop-chrome .lyra-title {',
    '  margin-left: 10px;',
    '  color: #cbd5e1;',
    '  font-size: 11px;',
    '  letter-spacing: 0.4px;',
    "  font-family: 'DM Mono', monospace;",
    '  opacity: 0.92;',
    '  pointer-events: none;',
    '}',
    '#lyra-desktop-chrome .lyra-controls {',
    '  height: 100%;',
    '  display: flex;',
    '  -webkit-app-region: no-drag;',
    '}',
    '#lyra-desktop-chrome .lyra-btn {',
    '  width: 46px;',
    '  height: 100%;',
    '  border: 0;',
    '  background: transparent;',
    '  color: #e2e8f0;',
    '  font-size: 12px;',
    '  cursor: pointer;',
    '  display: inline-flex;',
    '  align-items: center;',
    '  justify-content: center;',
    '}',
    '#lyra-desktop-chrome .lyra-btn:hover { background: rgba(148,163,184,0.18); }',
    '#lyra-desktop-chrome .lyra-btn.close:hover { background: #b91c1c; color: #fff; }',
    '#lyra-desktop-chrome .lyra-btn svg { width: 11px; height: 11px; stroke: currentColor; fill: none; stroke-width: 1.5; }',
    '#lyra-desktop-chrome .lyra-btn .max-icon-alt { display: none; }',
    '#lyra-desktop-chrome.maximized .lyra-btn .max-icon { display: none; }',
    '#lyra-desktop-chrome.maximized .lyra-btn .max-icon-alt { display: inline; }'
  ].join('\n');

  const script = `(() => {
    if (window.__lyraChromeInjected) return;
    window.__lyraChromeInjected = true;

    const style = document.createElement('style');
    style.id = 'lyra-desktop-chrome-style';
    style.textContent = ${JSON.stringify(css)};
    document.head.appendChild(style);

    const bar = document.createElement('div');
    bar.id = 'lyra-desktop-chrome';

    const title = document.createElement('div');
    title.className = 'lyra-title';
    title.textContent = 'Lyralink AI';

    const controls = document.createElement('div');
    controls.className = 'lyra-controls';

    const makeBtn = (id, titleText, className, svgMarkup) => {
      const btn = document.createElement('button');
      btn.className = className;
      btn.id = id;
      btn.setAttribute('aria-label', titleText);
      btn.title = titleText;
      btn.innerHTML = svgMarkup;
      return btn;
    };

    const minBtn = makeBtn(
      'lyra-min-btn',
      'Minimize',
      'lyra-btn',
      '<svg viewBox="0 0 10 10"><path d="M1 5.5h8"></path></svg>'
    );

    const maxBtn = makeBtn(
      'lyra-max-btn',
      'Maximize',
      'lyra-btn',
      '<svg class="max-icon" viewBox="0 0 10 10"><rect x="1.5" y="1.5" width="7" height="7"></rect></svg>' +
      '<svg class="max-icon-alt" viewBox="0 0 10 10"><path d="M2.5 1.5h6v6"></path><path d="M1.5 2.5h6v6h-6z"></path></svg>'
    );

    const closeBtn = makeBtn(
      'lyra-close-btn',
      'Close',
      'lyra-btn close',
      '<svg viewBox="0 0 10 10"><path d="M2 2l6 6M8 2L2 8"></path></svg>'
    );

    controls.appendChild(minBtn);
    controls.appendChild(maxBtn);
    controls.appendChild(closeBtn);
    bar.appendChild(title);
    bar.appendChild(controls);

    document.body.appendChild(bar);

    bar.addEventListener('dblclick', (e) => {
      if (e.target.closest('.lyra-controls')) return;
      window.lyraDesktop?.toggleMaximize?.();
    });

    document.getElementById('lyra-min-btn')?.addEventListener('click', () => window.lyraDesktop?.minimize?.());
    document.getElementById('lyra-max-btn')?.addEventListener('click', () => window.lyraDesktop?.toggleMaximize?.());
    document.getElementById('lyra-close-btn')?.addEventListener('click', () => window.lyraDesktop?.close?.());

    window.lyraDesktop?.onWindowState?.((state) => {
      if (state?.isMaximized) bar.classList.add('maximized');
      else bar.classList.remove('maximized');
    });
  })();`;
  win.webContents.executeJavaScript(script).catch(() => {});
}

function createWindow() {
  const isWindows = process.platform === 'win32';
  const useCustomTitlebar = isWindows && process.env.LYRALINK_NATIVE_TITLEBAR !== '1';

  mainWindow = new BrowserWindow({
    width: 1480,
    height: 920,
    minWidth: 1100,
    minHeight: 700,
    backgroundColor: '#0a0a0f',
    title: 'Lyralink AI',
    autoHideMenuBar: true,
    frame: !useCustomTitlebar,
    icon: path.join(__dirname, 'assets', isWindows ? 'app.ico' : 'app.png'),
    // Use custom draggable titlebar by default; set LYRALINK_NATIVE_TITLEBAR=1 to force native.
    titleBarStyle: useCustomTitlebar ? 'hidden' : 'default',
    titleBarOverlay: false,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      spellcheck: false,
      preload: path.join(__dirname, 'preload.js')
    }
  });

  if (useCustomTitlebar) {
    attachWindowStateBridge(mainWindow);
    mainWindow.webContents.on('did-finish-load', () => injectCustomTitlebar(mainWindow));
    mainWindow.webContents.on('did-navigate-in-page', () => injectCustomTitlebar(mainWindow));
  }

  mainWindow.loadURL(activeAppUrl);

  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith(activeAppOrigin)) {
      return { action: 'allow' };
    }
    shell.openExternal(url);
    return { action: 'deny' };
  });

  mainWindow.webContents.on('will-navigate', (event, url) => {
    if (!url.startsWith(activeAppOrigin)) {
      event.preventDefault();
      shell.openExternal(url);
    }
  });
}

app.commandLine.appendSwitch('enable-features', 'MediaFoundationVideoCapture,WebRtcAllowInputVolumeAdjustment');
app.commandLine.appendSwitch('autoplay-policy', 'no-user-gesture-required');

ipcMain.handle('window-minimize', (event) => {
  const win = BrowserWindow.fromWebContents(event.sender);
  if (win) win.minimize();
});

ipcMain.handle('window-toggle-maximize', (event) => {
  const win = BrowserWindow.fromWebContents(event.sender);
  if (!win) return false;
  if (win.isMaximized()) win.unmaximize();
  else win.maximize();
  return win.isMaximized();
});

ipcMain.handle('window-close', (event) => {
  const win = BrowserWindow.fromWebContents(event.sender);
  if (win) win.close();
});

const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  app.on('second-instance', () => {
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore();
      mainWindow.focus();
    }
  });

  app.whenReady().then(() => {
    (async () => {
      if (LOCAL_MODE && (!app.isPackaged || ALLOW_PACKAGED_LOCAL_MODE)) {
        const localStarted = await startLocalPhpServer();
        if (!localStarted) {
          dialog.showMessageBox({
            type: 'warning',
            title: 'Local Mode Unavailable',
            message: 'Could not start bundled local server. Falling back to hosted mode.',
            detail: 'Expected resources: ./web, ./local/router.php and PHP runtime (resources/php or system php).'
          });
        }
      } else if (LOCAL_MODE && app.isPackaged) {
        log.warn('[local-mode] disabled for packaged builds (set LYRALINK_ALLOW_PACKAGED_LOCAL_MODE=1 to override)');
      }

    session.defaultSession.setPermissionRequestHandler((webContents, permission, callback, details) => {
      try {
        const requestingOrigin = new URL(details.requestingUrl).origin;
        callback(requestingOrigin === activeAppOrigin && allowKnownPermission(permission));
      } catch (_) {
        callback(false);
      }
    });

    session.defaultSession.setPermissionCheckHandler((webContents, permission, requestingOrigin) => {
      return requestingOrigin === activeAppOrigin && allowKnownPermission(permission);
    });

    createWindow();

    app.on('activate', () => {
      if (BrowserWindow.getAllWindows().length === 0) createWindow();
    });

    setTimeout(() => { setupAutoUpdater(); }, 6000);
    })();
  });

  app.on('window-all-closed', () => {
    if (localServerProc) {
      localServerProc.kill();
      localServerProc = null;
    }
    if (process.platform !== 'darwin') app.quit();
  });
}

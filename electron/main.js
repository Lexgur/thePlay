const { app, BrowserWindow, dialog, ipcMain, screen } = require('electron');
const { spawn } = require('child_process');
const http = require('http');
const net = require('net');
const os = require('os');
const path = require('path');

app.setName('theplay');
app.commandLine.appendSwitch('no-sandbox');

const root = app.isPackaged ? process.resourcesPath : path.resolve(__dirname, '..');
let php = null;
let phpLog = '';
let scriptWindow = null;
let displayWindow = null;

function webPreferences() {
  return {
    preload: path.join(__dirname, 'preload.js'),
    contextIsolation: true,
    nodeIntegration: false,
    sandbox: true,
  };
}

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      const port = address && typeof address === 'object' ? address.port : 0;
      server.close(() => resolve(port));
    });
  });
}

function waitForServer(port) {
  return new Promise((resolve, reject) => {
    let attempts = 0;
    const tick = () => {
      const request = http.get({ host: '127.0.0.1', port, path: '/' }, (response) => {
        response.on('data', () => {});
        response.on('end', () => resolve());
      });
      request.on('error', () => {
        attempts += 1;
        if (attempts > 50) {
          reject(new Error(startupError('PHP did not start')));
          return;
        }
        setTimeout(tick, 100);
      });
    };
    tick();
  });
}

function startupError(message) {
  const detail = phpLog.trim().split('\n').slice(-8).join('\n');
  return detail ? message + '\n' + detail : message;
}

function loadPage(window, url) {
  return new Promise((resolve, reject) => {
    let attempts = 0;
    const tick = () => {
      window.loadURL(url).then(resolve).catch(() => {
        attempts += 1;
        if (attempts > 25) {
          reject(new Error(startupError('Could not open http://127.0.0.1')));
          return;
        }
        setTimeout(tick, 200);
      });
    };
    tick();
  });
}

async function createWindow() {
  const port = await freePort();
  const home = os.homedir();
  php = spawn('php', [
    '-d', 'display_errors=0',
    '-d', 'html_errors=0',
    '-d', 'log_errors=1',
    '-d', 'xdebug.mode=off',
    '-d', 'max_execution_time=0',
    '-S', `127.0.0.1:${port}`,
    path.join(root, 'php', 'public', 'index.php'),
  ], {
    cwd: root,
    env: {
      ...process.env,
      THEPLAY_DATA: app.getPath('userData'),
      PATH: `${home}/.local/bin:${home}/.local/node/bin:${process.env.PATH || ''}`,
    },
  });
  php.on('error', (error) => {
    phpLog += error.message + '\n';
  });
  php.stdout.on('data', (chunk) => process.stdout.write(chunk));
  php.stderr.on('data', (chunk) => {
    phpLog += chunk.toString();
    process.stderr.write(chunk);
  });

  try {
    await waitForServer(port);
  } catch (error) {
    dialog.showErrorBox('thePlay', 'PHP did not start. Install php and run from the project folder.');
    app.quit();
    return;
  }

  scriptWindow = new BrowserWindow({
    width: 1100,
    height: 800,
    show: false,
    backgroundColor: '#faf7f2',
    title: 'thePlay',
    webPreferences: webPreferences(),
  });
  displayWindow = new BrowserWindow({
    width: 960,
    height: 540,
    backgroundColor: '#000000',
    title: 'thePlay display',
    webPreferences: webPreferences(),
  });

  ipcMain.handle('open-file', async () => {
    const chosen = await dialog.showOpenDialog(scriptWindow, {
      title: 'Open a script',
      defaultPath: path.join(home, 'Downloads'),
      filters: [{ name: 'Script', extensions: ['docx', 'pdf'] }],
      properties: ['openFile'],
    });
    if (chosen.canceled || chosen.filePaths.length === 0) {
      return null;
    }
    return chosen.filePaths[0];
  });
  ipcMain.handle('open-image', async () => {
    const chosen = await dialog.showOpenDialog(scriptWindow, {
      title: 'Choose an image',
      filters: [{ name: 'Image', extensions: ['jpg', 'jpeg', 'png', 'gif', 'webp'] }],
      properties: ['openFile'],
    });
    if (chosen.canceled || chosen.filePaths.length === 0) {
      return null;
    }
    return chosen.filePaths[0];
  });
  ipcMain.handle('focus-display', () => {
    if (!displayWindow || displayWindow.isDestroyed()) {
      return;
    }
    displayWindow.show();
    displayWindow.focus();
  });
  ipcMain.handle('fullscreen-display', () => {
    if (!displayWindow || displayWindow.isDestroyed()) {
      return false;
    }
    const next = !displayWindow.isFullScreen();
    displayWindow.setFullScreen(next);
    return next;
  });
  ipcMain.handle('list-screens', () => {
    return screen.getAllDisplays().map((display, index) => ({
      id: display.id,
      label: 'Screen ' + (index + 1) + ' (' + display.size.width + '×' + display.size.height + ')',
    }));
  });
  ipcMain.handle('send-to-screen', (_event, id) => {
    const display = screen.getAllDisplays().find((item) => item.id === Number(id));
    if (!display || !displayWindow || displayWindow.isDestroyed()) {
      return;
    }
    if (displayWindow.isFullScreen()) {
      displayWindow.setFullScreen(false);
    }
    displayWindow.setBounds(display.bounds);
    displayWindow.setFullScreen(true);
  });

  displayWindow.on('enter-full-screen', () => {
    displayWindow.webContents.send('display-fullscreen', true);
  });
  displayWindow.on('leave-full-screen', () => {
    displayWindow.webContents.send('display-fullscreen', false);
  });
  scriptWindow.on('closed', () => {
    if (displayWindow && !displayWindow.isDestroyed()) {
      displayWindow.close();
    }
  });
  scriptWindow.once('ready-to-show', () => scriptWindow.show());
  await loadPage(scriptWindow, `http://127.0.0.1:${port}/`);
  await loadPage(displayWindow, `http://127.0.0.1:${port}/display`);
}

app.whenReady().then(createWindow).catch((error) => {
  dialog.showErrorBox('thePlay', error instanceof Error ? error.message : 'Could not start');
  app.quit();
});

app.on('window-all-closed', () => {
  app.quit();
});

app.on('before-quit', () => {
  if (php && !php.killed) {
    php.kill();
  }
});

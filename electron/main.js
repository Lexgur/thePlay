const { app, BrowserWindow, dialog, ipcMain } = require('electron');
const { spawn } = require('child_process');
const http = require('http');
const net = require('net');
const os = require('os');
const path = require('path');

app.setName('theplay');

const root = path.resolve(__dirname, '..');
let php = null;

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
        response.resume();
        resolve();
      });
      request.on('error', () => {
        attempts += 1;
        if (attempts > 50) {
          reject(new Error('PHP did not start'));
          return;
        }
        setTimeout(tick, 100);
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
  php.stdout.on('data', (chunk) => process.stdout.write(chunk));
  php.stderr.on('data', (chunk) => process.stderr.write(chunk));

  try {
    await waitForServer(port);
  } catch (error) {
    dialog.showErrorBox('thePlay', 'PHP did not start. Install php and run from the project folder.');
    app.quit();
    return;
  }

  const window = new BrowserWindow({
    width: 1100,
    height: 800,
    show: false,
    backgroundColor: '#faf7f2',
    title: 'thePlay',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });

  ipcMain.handle('open-file', async () => {
    const chosen = await dialog.showOpenDialog(window, {
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

  window.once('ready-to-show', () => window.show());
  await window.loadURL(`http://127.0.0.1:${port}/`);
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

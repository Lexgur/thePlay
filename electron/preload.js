const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('thePlay', {
  openFile: () => ipcRenderer.invoke('open-file'),
  openImage: () => ipcRenderer.invoke('open-image'),
  focusDisplay: () => ipcRenderer.invoke('focus-display'),
  fullscreen: () => ipcRenderer.invoke('fullscreen-display'),
  onFullscreen: (callback) => ipcRenderer.on('display-fullscreen', (_event, on) => callback(on)),
  listScreens: () => ipcRenderer.invoke('list-screens'),
  sendToScreen: (id) => ipcRenderer.invoke('send-to-screen', id),
});

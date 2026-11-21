const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('thePlay', {
  openFile: () => ipcRenderer.invoke('open-file'),
});

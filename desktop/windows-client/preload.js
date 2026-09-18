const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('lyraDesktop', {
  minimize: () => ipcRenderer.invoke('window-minimize'),
  toggleMaximize: () => ipcRenderer.invoke('window-toggle-maximize'),
  close: () => ipcRenderer.invoke('window-close'),
  onWindowState: (cb) => {
    if (typeof cb !== 'function') return;
    const handler = (_event, payload) => cb(payload || {});
    ipcRenderer.on('window-state', handler);
  },
});

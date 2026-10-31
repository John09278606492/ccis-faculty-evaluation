const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('classroom', {
    status: () => ipcRenderer.invoke('status'), setup: input => ipcRenderer.invoke('setup', input),
    start: port => ipcRenderer.invoke('start', port), stop: () => ipcRenderer.invoke('stop'),
    openSystem: () => ipcRenderer.invoke('open-system'), openDatabase: () => ipcRenderer.invoke('open-database'),
    openData: () => ipcRenderer.invoke('open-data'), backup: () => ipcRenderer.invoke('backup'), restore: () => ipcRenderer.invoke('restore'),
});

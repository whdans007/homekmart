const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('api', {
  // DB 설정
  getConfig: () => ipcRenderer.invoke('config:get'),
  setConfig: (cfg) => ipcRenderer.invoke('config:set', cfg),
  testConnection: (cfg) => ipcRenderer.invoke('db:testConnection', cfg),

  // 점포
  getStores: () => ipcRenderer.invoke('db:getStores'),

  // 검색
  search: (barcode, storeId) => ipcRenderer.invoke('db:search', barcode, storeId),
  suggest: (q, storeId) => ipcRenderer.invoke('db:suggest', q, storeId),

  // 출력용 상품 조회
  printItems: (skus, storeId) => ipcRenderer.invoke('db:printItems', skus, storeId),

  // 가격 / 이름 수정
  updatePrice: (productId, storeId, price) => ipcRenderer.invoke('db:updatePrice', productId, storeId, price),
  updateName: (productId, nameEn, nameKo) => ipcRenderer.invoke('db:updateName', productId, nameEn, nameKo),

  // 마스터 파일 업로드
  importMaster: (filePath, storeId) => ipcRenderer.invoke('db:importMaster', filePath, storeId),

  // 파일 열기 다이얼로그
  openFile: () => ipcRenderer.invoke('dialog:openFile'),

  // 라벨 출력 창 열기
  openPrint: (params) => ipcRenderer.invoke('print:open', params),

  // 앱 정보
  getVersion: () => ipcRenderer.invoke('app:version'),
});

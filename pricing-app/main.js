const { app, BrowserWindow, ipcMain, dialog } = require('electron');
const path = require('path');
const mysql = require('mysql2/promise');
const ExcelJS = require('exceljs');
const Store = require('electron-store');

// ── 설정 저장소 ──────────────────────────────────────────────
const store = new Store({
  name: 'db-config',
  encryptionKey: 'hkm-pricing-secret-key-2024',
  defaults: {
    host: 'localhost',
    port: 3306,
    user: '',
    password: '',
    database: '',
    charset: 'utf8mb4',
  }
});

// ── DB 연결 풀 ───────────────────────────────────────────────
let pool = null;

async function createPool(cfg) {
  if (pool) { try { await pool.end(); } catch (_) {} }
  pool = mysql.createPool({
    host:     cfg.host     || 'localhost',
    port:     +(cfg.port   || 3306),
    user:     cfg.user,
    password: cfg.password,
    database: cfg.database,
    charset:  cfg.charset  || 'utf8mb4',
    waitForConnections: true,
    connectionLimit: 5,
    queueLimit: 0,
  });
}

async function getPool() {
  if (!pool) {
    const cfg = store.get();
    if (!cfg.user || !cfg.database) return null;
    await createPool(cfg);
  }
  return pool;
}

// ── EAN 체크 디지트 검증 ──────────────────────────────────────
function validateEanCheckDigit(code) {
  const digits = code.split('').map(Number);
  const last = digits.pop();
  const len = digits.length;
  const sum = digits.reduce((acc, d, i) => {
    const weight = (i % 2 === (len % 2 === 0 ? 1 : 0)) ? 3 : 1;
    return acc + d * weight;
  }, 0);
  return (10 - (sum % 10)) % 10 === last;
}

function detectBarcodeFormat(sku) {
  if (/^\d+$/.test(sku)) {
    const len = sku.length;
    if (len === 8  && validateEanCheckDigit(sku)) return 'EAN8';
    if (len === 12 && validateEanCheckDigit(sku)) return 'UPC';
    if (len === 13 && validateEanCheckDigit(sku)) return 'EAN13';
    if (len === 14 && validateEanCheckDigit(sku)) return 'ITF14';
  } else if (/^[A-Z0-9 \-\.\/\+\%\*]+$/.test(sku)) {
    return 'CODE39';
  }
  return 'CODE128';
}

// ── 가격 포맷 ─────────────────────────────────────────────────
function fmtPrice(v) {
  if (v === null || v === undefined || v === '') return '';
  const n = parseFloat(v);
  return isNaN(n) ? '' : n.toLocaleString('ko-KR');
}

// ── 동적 price 표현식 (스키마 캐시) ──────────────────────────
let _priceExprCache = {};
async function getPriceExpr(conn) {
  if (_priceExprCache.expr) return _priceExprCache.expr;
  const [[rProd]] = await conn.query(
    "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='selling_price'"
  );
  const [[rInv]] = await conn.query(
    "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory' AND column_name='selling_price'"
  );
  const hasProd = rProd.c > 0;
  const hasInv  = rInv.c  > 0;
  if (hasProd && hasInv) _priceExprCache.expr = 'COALESCE(i.selling_price, p.selling_price)';
  else if (hasInv)       _priceExprCache.expr = 'i.selling_price';
  else if (hasProd)      _priceExprCache.expr = 'p.selling_price';
  else                   _priceExprCache.expr = 'NULL';
  return _priceExprCache.expr;
}

// ── 앱 윈도우 ─────────────────────────────────────────────────
let mainWindow = null;

function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 900,
    minHeight: 600,
    title: 'HOME K MART Pricing',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
    icon: path.join(__dirname, 'assets', 'icon.png'),
    backgroundColor: '#0f172a',
  });

  mainWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));
  mainWindow.setMenuBarVisibility(false);
}

app.whenReady().then(async () => {
  // 저장된 설정으로 풀 초기화 시도 (실패해도 앱 시작)
  const cfg = store.get();
  if (cfg.user && cfg.database) {
    try { await createPool(cfg); } catch (_) {}
  }
  createMainWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createMainWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

// ══════════════════════════════════════════════════════════════
//  IPC 핸들러
// ══════════════════════════════════════════════════════════════

// ── 앱 버전 ──────────────────────────────────────────────────
ipcMain.handle('app:version', () => app.getVersion());

// ── 설정 저장/불러오기 ────────────────────────────────────────
ipcMain.handle('config:get', () => {
  const cfg = store.get();
  return { ...cfg, password: cfg.password ? '●'.repeat(8) : '' }; // 비밀번호 마스킹
});

ipcMain.handle('config:set', async (_, cfg) => {
  // 비밀번호 마스킹 값이면 기존 값 유지
  if (cfg.password && cfg.password.startsWith('●')) {
    cfg.password = store.get('password');
  }
  store.set(cfg);
  _priceExprCache = {}; // 스키마 캐시 초기화
  try {
    await createPool(cfg);
    return { success: true };
  } catch (e) {
    return { success: false, message: e.message };
  }
});

// ── DB 연결 테스트 ────────────────────────────────────────────
ipcMain.handle('db:testConnection', async (_, cfg) => {
  try {
    const conn = await mysql.createConnection({
      host: cfg.host, port: +(cfg.port || 3306),
      user: cfg.user, password: cfg.password, database: cfg.database,
    });
    await conn.ping();
    await conn.end();
    return { success: true };
  } catch (e) {
    return { success: false, message: e.message };
  }
});

// ── 점포 목록 ─────────────────────────────────────────────────
ipcMain.handle('db:getStores', async () => {
  try {
    const p = await getPool();
    if (!p) return { success: false, stores: [], message: 'DB 미설정' };
    const [rows] = await p.query("SELECT id, name FROM stores ORDER BY name ASC");
    return { success: true, stores: rows };
  } catch (e) {
    return { success: false, stores: [], message: e.message };
  }
});

// ── 상품 검색 (바코드/SKU) ────────────────────────────────────
ipcMain.handle('db:search', async (_, barcode, storeId) => {
  if (!barcode) return { success: false, message: '바코드를 입력해주세요.' };
  try {
    const p = await getPool();
    if (!p) return { success: false, message: 'DB가 연결되지 않았습니다.' };
    const conn = await p.getConnection();
    try {
      const priceExpr = await getPriceExpr(conn);
      const [rows] = await conn.query(
        `SELECT p.id AS product_id, p.name_ko, p.name_en, p.sku,
                ${priceExpr} AS selling_price
         FROM products p
         LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
         WHERE p.sku = ? LIMIT 1`,
        [storeId, barcode]
      );
      if (!rows.length) return { success: false, message: `상품을 찾을 수 없습니다: ${barcode}` };
      const r = rows[0];
      return {
        success: true,
        product: {
          product_id: r.product_id,
          name_ko: r.name_ko || '',
          name_en: r.name_en || '',
          sku: r.sku,
          selling_price: fmtPrice(r.selling_price),
          selling_price_raw: parseFloat(r.selling_price || 0),
        }
      };
    } finally { conn.release(); }
  } catch (e) {
    return { success: false, message: `DB 오류: ${e.message}` };
  }
});

// ── 상품 자동완성 검색 ────────────────────────────────────────
ipcMain.handle('db:suggest', async (_, q, storeId) => {
  if (!q || q.length < 1) return { success: false, products: [] };
  try {
    const p = await getPool();
    if (!p) return { success: false, products: [] };
    const conn = await p.getConnection();
    try {
      const priceExpr = await getPriceExpr(conn);
      const [[rActive]] = await conn.query(
        "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='is_active'"
      );
      const activeClause = rActive.c > 0 ? 'AND p.is_active = 1' : '';
      const like = `%${q}%`;
      const [rows] = await conn.query(
        `SELECT p.id AS product_id, p.sku, p.name_ko, p.name_en,
                ${priceExpr} AS selling_price
         FROM products p
         LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
         WHERE (p.sku LIKE ? OR p.name_en LIKE ? OR p.name_ko LIKE ?)
           ${activeClause}
         ORDER BY
           CASE WHEN p.sku = ?     THEN 0
                WHEN p.sku LIKE ?  THEN 1
                WHEN p.name_en LIKE ? THEN 2
                ELSE 3 END,
           p.name_en ASC
         LIMIT 15`,
        [storeId, like, like, like, q, like, like]
      );
      const products = rows.map(r => ({
        product_id: r.product_id,
        sku: r.sku,
        name_en: r.name_en || '',
        name_ko: r.name_ko || '',
        selling_price: fmtPrice(r.selling_price),
        selling_price_raw: parseFloat(r.selling_price || 0),
      }));
      return { success: true, products };
    } finally { conn.release(); }
  } catch (e) {
    return { success: false, products: [], message: e.message };
  }
});

// ── 라벨 출력용 상품 조회 ─────────────────────────────────────
ipcMain.handle('db:printItems', async (_, skus, storeId) => {
  if (!skus || !skus.length) return { success: true, items: [] };
  try {
    const p = await getPool();
    if (!p) return { success: false, items: [], message: 'DB 미연결' };
    const conn = await p.getConnection();
    try {
      const [[rProd]] = await conn.query(
        "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='selling_price'"
      );
      const [[rInv]] = await conn.query(
        "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory' AND column_name='selling_price'"
      );
      let priceExpr;
      if (rProd.c && rInv.c) priceExpr = 'COALESCE(inv.selling_price, p.selling_price)';
      else if (rInv.c)        priceExpr = 'inv.selling_price';
      else if (rProd.c)       priceExpr = 'p.selling_price';
      else                    priceExpr = 'NULL';

      const ph = skus.map(() => '?').join(',');
      const [rows] = await conn.query(
        `SELECT p.sku, p.name_en, p.name_ko, ${priceExpr} AS selling_price
         FROM products p
         LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.store_id = ?
         WHERE p.sku IN (${ph})`,
        [storeId, ...skus]
      );
      const rowMap = {};
      rows.forEach(r => { rowMap[r.sku] = r; });
      const items = skus.map(s => rowMap[s]
        ? { ...rowMap[s], barcodeFormat: detectBarcodeFormat(s) }
        : { sku: s, name_en: '', name_ko: '', selling_price: null, barcodeFormat: detectBarcodeFormat(s) }
      );
      return { success: true, items };
    } finally { conn.release(); }
  } catch (e) {
    return { success: false, items: [], message: e.message };
  }
});

// ── 가격 수정 ─────────────────────────────────────────────────
ipcMain.handle('db:updatePrice', async (_, productId, storeId, price) => {
  try {
    const p = await getPool();
    if (!p) return { success: false, message: 'DB 미연결' };
    await p.query(
      `INSERT INTO inventory (product_id, store_id, selling_price, quantity)
       VALUES (?, ?, ?, 0)
       ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price)`,
      [productId, storeId, price]
    );
    return { success: true };
  } catch (e) {
    return { success: false, message: e.message };
  }
});

// ── 상품명 수정 ───────────────────────────────────────────────
ipcMain.handle('db:updateName', async (_, productId, nameEn, nameKo) => {
  try {
    const p = await getPool();
    if (!p) return { success: false, message: 'DB 미연결' };
    await p.query(
      `UPDATE products SET name_en = ?, name_ko = ?, updated_at = NOW() WHERE id = ?`,
      [nameEn, nameKo, productId]
    );
    return { success: true };
  } catch (e) {
    return { success: false, message: e.message };
  }
});

// ── 마스터 파일 업로드 (Excel/CSV) ───────────────────────────
ipcMain.handle('db:importMaster', async (_, filePath, storeId) => {
  if (!filePath) return { success: false, message: '파일 경로가 없습니다.' };
  if (!storeId)  return { success: false, message: '점포가 선택되지 않았습니다.' };
  try {
    const wb = new ExcelJS.Workbook();
    const ext = path.extname(filePath).toLowerCase();
    if (ext === '.csv') {
      await wb.csv.readFile(filePath);
    } else {
      await wb.xlsx.readFile(filePath);
    }
    const ws = wb.worksheets[0];
    if (!ws) return { success: false, message: '시트를 읽을 수 없습니다.' };

    const excelData = [];
    ws.eachRow({ includeEmpty: false }, (row, rowNum) => {
      if (rowNum === 1) return; // 헤더 스킵
      const sku     = String(row.getCell(1).value || '').trim();  // A열
      const nameEn  = String(row.getCell(3).value || '').trim();  // C열
      const sellP   = parseFloat(row.getCell(5).value || 0);      // E열
      const costP   = parseFloat(row.getCell(10).value || 0);     // J열
      if (sku) excelData.push({ sku, name_en: nameEn, selling_price: sellP, cost_price: costP });
    });

    if (!excelData.length) return { success: false, message: '유효한 데이터가 없습니다.' };

    const p = await getPool();
    if (!p) return { success: false, message: 'DB 미연결' };
    const conn = await p.getConnection();
    try {
      await conn.beginTransaction();
      const [[catRow]] = await conn.query("SELECT id FROM categories ORDER BY id LIMIT 1");
      const defaultCat = catRow ? catRow.id : 1;

      let success = 0, errors = 0, newProducts = 0, updated = 0;
      const log = [];

      for (const row of excelData) {
        if (!row.name_en || row.selling_price < 0 || row.cost_price < 0) { errors++; continue; }
        try {
          const [[existing]] = await conn.query("SELECT id FROM products WHERE sku = ?", [row.sku]);
          let productId = existing ? existing.id : null;
          if (!productId) {
            const [ins] = await conn.query(
              "INSERT INTO products (sku, name_ko, name_en, category_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())",
              [row.sku, row.name_en, row.name_en, defaultCat]
            );
            productId = ins.insertId;
            newProducts++;
          }
          await conn.query(
            `INSERT INTO inventory (product_id, store_id, selling_price, cost_price, quantity)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price), cost_price = VALUES(cost_price)`,
            [productId, storeId, row.selling_price, row.cost_price]
          );
          updated++; success++;
        } catch (e) {
          errors++;
          if (log.length < 5) log.push(`SKU ${row.sku}: ${e.message}`);
        }
      }

      if (success > 0) {
        await conn.commit();
        let msg = `✅ 업로드 완료!\n• 전체 행: ${excelData.length}개\n• 신규 상품: ${newProducts}개\n• 가격 업데이트: ${updated}개\n• 성공: ${success}개`;
        if (errors > 0) msg += `\n• 실패: ${errors}개`;
        if (log.length) msg += '\n\n오류 내역:\n' + log.join('\n');
        return { success: true, message: msg };
      } else {
        await conn.rollback();
        return { success: false, message: `❌ 저장 실패 (실패: ${errors}개)\nA열(SKU), C열(상품명)이 있는지 확인하세요.` };
      }
    } catch (e) {
      await conn.rollback();
      throw e;
    } finally { conn.release(); }
  } catch (e) {
    return { success: false, message: `파일 처리 오류: ${e.message}` };
  }
});

// ── 파일 열기 다이얼로그 ──────────────────────────────────────
ipcMain.handle('dialog:openFile', async () => {
  const result = await dialog.showOpenDialog(mainWindow, {
    title: '마스터 파일 선택',
    filters: [
      { name: 'Excel / CSV', extensions: ['xlsx', 'xls', 'csv'] },
      { name: '모든 파일', extensions: ['*'] },
    ],
    properties: ['openFile'],
  });
  return result.canceled ? null : result.filePaths[0];
});

// ── 라벨 출력 창 ──────────────────────────────────────────────
ipcMain.handle('print:open', async (_, params) => {
  // params: { skus: [], mode: 'pricing'|'2p', storeId: number, autoprint: bool }
  const printWin = new BrowserWindow({
    width: 900, height: 650,
    title: '라벨 출력',
    parent: mainWindow,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });
  printWin.setMenuBarVisibility(false);
  // URL 파라미터 전달
  const q = new URLSearchParams({
    skus: (params.skus || []).join(','),
    mode: params.mode || '2p',
    store_id: params.storeId || 1,
    autoprint: params.autoprint ? '1' : '0',
  });
  printWin.loadFile(
    path.join(__dirname, 'renderer', 'print.html'),
    { search: q.toString() }
  );
  return { success: true };
});

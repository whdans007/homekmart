<?php
$page_title = '상품명 변경 이력';
require_once __DIR__ . '/partials/header.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo '<div class="p-8 text-red-600">접근 권한이 없습니다.</div>';
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$filterLang     = $_GET['lang']      ?? '';
$filterSku      = trim($_GET['sku']  ?? '');
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to']   ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$rows = [];
$total = 0;
$tableExists = false;
$dbError = '';

try {
    $conn = get_db_connection();

    $tableExists = (bool)$conn->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='product_name_history'"
    )->fetch_row()[0];

    if ($tableExists) {
        $where  = ['1=1'];
        $types  = '';
        $params = [];

        if ($filterLang && in_array($filterLang, ['en', 'ko'])) {
            $where[] = 'h.language = ?'; $types .= 's'; $params[] = $filterLang;
        }
        if ($filterSku !== '') {
            $where[] = 'p.sku LIKE ?'; $types .= 's'; $params[] = '%' . $filterSku . '%';
        }
        if ($filterDateFrom) {
            $where[] = 'DATE(h.changed_at) >= ?'; $types .= 's'; $params[] = $filterDateFrom;
        }
        if ($filterDateTo) {
            $where[] = 'DATE(h.changed_at) <= ?'; $types .= 's'; $params[] = $filterDateTo;
        }

        $whereStr = implode(' AND ', $where);

        $cntStmt = $conn->prepare("SELECT COUNT(*) FROM product_name_history h LEFT JOIN products p ON h.product_id = p.id WHERE $whereStr");
        if ($types) $cntStmt->bind_param($types, ...$params);
        $cntStmt->execute();
        $total = $cntStmt->get_result()->fetch_row()[0];
        $cntStmt->close();

        $stmt = $conn->prepare(
            "SELECT h.id, h.language, h.old_name, h.new_name, h.changed_at,
                    p.sku, p.name_ko, p.id AS product_id
             FROM product_name_history h
             LEFT JOIN products p ON h.product_id = p.id
             WHERE $whereStr ORDER BY h.changed_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $conn->close();
} catch (Exception $e) {
    $dbError = $e->getMessage();
    error_log('product_name_history.php error: ' . $e->getMessage());
}

$totalPages = $total > 0 ? ceil($total / $perPage) : 1;

function qStr(array $overrides = []): string {
    $p = array_merge([
        'lang' => $_GET['lang'] ?? '', 'sku' => $_GET['sku'] ?? '',
        'date_from' => $_GET['date_from'] ?? '', 'date_to' => $_GET['date_to'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ], $overrides);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}
?>

<div class="py-6 px-6 max-w-7xl mx-auto">

    <!-- 헤더 -->
    <div class="flex items-center justify-between mb-6 no-print">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-history text-blue-500"></i>
                상품명 변경 이력
            </h1>
            <p class="text-sm text-gray-500 mt-1">pricing 페이지에서 수정된 영문/한글 상품명 변경 기록</p>
        </div>
        <div class="flex items-center gap-2">
            <span id="selectedCount" class="text-sm text-gray-500 hidden">
                <span id="selectedNum" class="font-bold text-blue-600">0</span>건 선택됨
            </span>
            <button onclick="printSelected()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
                <i class="fas fa-print"></i> 선택 항목 인쇄
            </button>
            <button onclick="printAll()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-gray-600 text-white text-sm font-medium rounded-lg hover:bg-gray-700 transition-colors">
                <i class="fas fa-list"></i> 전체 인쇄
            </button>
        </div>
    </div>

    <!-- 인쇄용 제목 (화면에서는 숨김) -->
    <div class="print-only" style="display:none">
        <h1 style="font-size:18px;font-weight:bold;margin-bottom:4px">상품명 변경 이력</h1>
        <p id="printSubtitle" style="font-size:12px;color:#666;margin-bottom:16px"></p>
    </div>

    <?php if ($dbError): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 no-print">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($dbError) ?>
    </div>
    <?php endif; ?>

    <?php if (!$tableExists): ?>
    <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-6 py-8 rounded-xl text-center no-print">
        <i class="fas fa-info-circle text-3xl mb-3 block text-yellow-500"></i>
        <p class="font-semibold">아직 변경 이력이 없습니다.</p>
        <p class="text-sm mt-1">pricing 페이지에서 상품명을 처음 수정하면 자동으로 테이블이 생성됩니다.</p>
    </div>
    <?php else: ?>

    <!-- 필터 -->
    <form method="get" class="bg-white border border-gray-200 rounded-xl p-4 mb-4 shadow-sm no-print">
        <div class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">언어</label>
                <select name="lang" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                    <option value="">전체</option>
                    <option value="en" <?= $filterLang === 'en' ? 'selected' : '' ?>>ENG (영문)</option>
                    <option value="ko" <?= $filterLang === 'ko' ? 'selected' : '' ?>>KOR (한글)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">SKU</label>
                <input type="text" name="sku" value="<?= htmlspecialchars($filterSku) ?>" placeholder="SKU 검색"
                    class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none w-40">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">시작일</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>"
                    class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">종료일</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>"
                    class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fas fa-search mr-1"></i> 검색
                </button>
                <a href="product_name_history.php" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                    초기화
                </a>
            </div>
        </div>
    </form>

    <!-- 건수 + 전체선택 바 -->
    <div class="flex items-center justify-between mb-3 no-print">
        <div class="text-sm text-gray-600">
            총 <strong class="text-gray-900"><?= number_format($total) ?></strong>건
            <?php if ($totalPages > 1): ?>
            &nbsp;·&nbsp; <?= $page ?> / <?= $totalPages ?> 페이지
            <?php endif; ?>
        </div>
        <?php if (!empty($rows)): ?>
        <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
            <input type="checkbox" id="checkAll" onchange="toggleAll(this)" class="w-4 h-4 accent-blue-600">
            현재 페이지 전체 선택
        </label>
        <?php endif; ?>
    </div>

    <!-- 테이블 -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        <table class="w-full text-sm" id="historyTable">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-3 py-3 w-10 no-print"></th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700 w-36">변경 일시</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700 w-28">SKU</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700 w-48">이전 상품명</th>
                    <th class="text-center px-3 py-3 text-gray-400 w-6">→</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">변경 후 상품명</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="6" class="text-center py-12 text-gray-400">
                        <i class="fas fa-inbox text-3xl mb-2 block"></i>
                        검색 결과가 없습니다.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($rows as $r): ?>
                <tr class="hover:bg-gray-50 transition-colors data-row"
                    data-id="<?= (int)$r['id'] ?>"
                    data-date="<?= htmlspecialchars(substr($r['changed_at'], 0, 16)) ?>"
                    data-sku="<?= htmlspecialchars($r['sku'] ?? '') ?>"
                    data-nameko="<?= htmlspecialchars($r['name_ko'] ?? '') ?>"
                    data-lang="<?= htmlspecialchars($r['language']) ?>"
                    data-old="<?= htmlspecialchars($r['old_name'] ?: '') ?>"
                    data-new="<?= htmlspecialchars($r['new_name']) ?>">
                    <td class="px-3 py-3 text-center no-print">
                        <input type="checkbox" class="row-check w-4 h-4 accent-blue-600" onchange="onRowCheck()">
                    </td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap text-xs"><?= htmlspecialchars(substr($r['changed_at'], 0, 16)) ?></td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600"><?= htmlspecialchars($r['sku'] ?? '-') ?></td>
                    <td class="px-4 py-3">
                        <div class="text-xs text-gray-500"><?= htmlspecialchars($r['name_ko'] ?? '-') ?></div>
                        <div class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($r['old_name'] ?: '(없음)') ?></div>
                    </td>
                    <td class="px-3 py-3 text-center text-gray-400">→</td>
                    <td class="px-4 py-3">
                        <div class="text-xs text-gray-500"><?= htmlspecialchars($r['name_ko'] ?? '-') ?></div>
                        <div class="text-sm font-semibold text-gray-900 mt-0.5"><?= htmlspecialchars($r['new_name']) ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 페이지네이션 -->
    <?php if ($totalPages > 1): ?>
    <div class="flex justify-center gap-1 mt-6 no-print">
        <?php if ($page > 1): ?>
        <a href="<?= qStr(['page' => $page - 1]) ?>" class="px-3 py-2 rounded-lg border border-gray-300 text-sm hover:bg-gray-50">이전</a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
        <a href="<?= qStr(['page' => $i]) ?>"
            class="px-3 py-2 rounded-lg border text-sm <?= $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= qStr(['page' => $page + 1]) ?>" class="px-3 py-2 rounded-lg border border-gray-300 text-sm hover:bg-gray-50">다음</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- 인쇄 미리보기 모달 -->
<div id="previewOverlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.6);align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;width:860px;max-width:96vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.4)">
        <!-- 모달 헤더 -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;flex-shrink:0">
            <div>
                <h2 style="font-size:16px;font-weight:700;color:#111827;margin:0">인쇄 미리보기</h2>
                <p id="previewSubtitle" style="font-size:12px;color:#6b7280;margin:2px 0 0"></p>
            </div>
            <div style="display:flex;gap:8px">
                <button onclick="doPrint()" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">
                    <i class="fas fa-print"></i> 인쇄
                </button>
                <button onclick="closePreview()" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:#f3f4f6;color:#374151;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">
                    닫기
                </button>
            </div>
        </div>
        <!-- 모달 본문 (미리보기) -->
        <div style="overflow-y:auto;flex:1;padding:20px">
            <div id="previewContent"></div>
        </div>
    </div>
</div>

<style>
@media print {
    body * { visibility: hidden; }
    #printFrame, #printFrame * { visibility: visible; }
    #printFrame { position: fixed; inset: 0; width: 100%; padding: 20px; box-sizing: border-box; }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<!-- 실제 인쇄 전용 숨김 iframe -->
<div id="printFrame" style="display:none"></div>

<script>
function toggleAll(cb) {
    document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
    onRowCheck();
}

function onRowCheck() {
    const checks  = document.querySelectorAll('.row-check');
    const allCb   = document.getElementById('checkAll');
    const checked = [...checks].filter(c => c.checked);
    const countEl = document.getElementById('selectedCount');
    const numEl   = document.getElementById('selectedNum');
    if (allCb) allCb.checked = checks.length > 0 && checked.length === checks.length;
    if (countEl) {
        numEl.textContent = checked.length;
        countEl.classList.toggle('hidden', checked.length === 0);
    }
}

function getSelectedRows() {
    return [...document.querySelectorAll('.data-row')].filter(tr =>
        tr.querySelector('.row-check')?.checked
    );
}

// SVG 바코드 생성 (Code128, 외부 라이브러리 없이 순수 JS)
function makeBarcodesSVG(container) {
    if (typeof JsBarcode === 'undefined') return;
    container.querySelectorAll('svg.barcode-svg').forEach(svg => {
        const sku = svg.dataset.sku;
        if (!sku) return;
        try {
            JsBarcode(svg, sku, {
                format: 'CODE128', width: 1.2, height: 30,
                displayValue: false, margin: 0
            });
        } catch(e) { svg.style.display = 'none'; }
    });
}

function barcodeCell(sku) {
    if (!sku) return '-';
    return `<div style="text-align:center;width:100%">
        <svg class="barcode-svg" data-sku="${sku}" style="width:100%;max-width:120px;height:auto;display:block;margin:0 auto"></svg>
        <div style="font-family:monospace;font-size:8px;margin-top:1px;color:#374151">${sku}</div>
    </div>`;
}

function buildTableHTML(rows) {
    const thStyle = 'text-align:left;padding:6px 8px;border:1px solid #d1d5db;background:#f3f4f6;font-size:11px;font-weight:700;color:#374151';
    const thead = `<thead><tr>
        <th style="${thStyle};width:110px">변경 일시</th>
        <th style="${thStyle};width:130px;text-align:center">SKU / 바코드</th>
        <th style="${thStyle};width:160px">이전 상품명</th>
        <th style="${thStyle};width:16px;text-align:center">→</th>
        <th style="${thStyle}">변경 후 상품명</th>
    </tr></thead>`;

    const tbody = rows.map((tr, idx) => {
        const d   = tr.dataset;
        const bg  = idx % 2 === 0 ? '#fff' : '#f9fafb';
        const tdS = `padding:5px 8px;border:1px solid #e5e7eb;font-size:11px;background:${bg}`;
        return `<tr>
            <td style="${tdS};white-space:nowrap;color:#6b7280">${d.date}</td>
            <td style="${tdS};text-align:center">${barcodeCell(d.sku)}</td>
            <td style="${tdS}">
                <div style="color:#374151;font-size:10px">${d.nameko || '-'}</div>
                <div style="color:#9ca3af;font-size:10px;margin-top:2px">${d.old || '(없음)'}</div>
            </td>
            <td style="${tdS};text-align:center;color:#9ca3af">→</td>
            <td style="${tdS}">
                <div style="color:#374151;font-size:10px">${d.nameko || '-'}</div>
                <div style="font-weight:700;color:#111827;font-size:12px;margin-top:2px">${d.new}</div>
            </td>
        </tr>`;
    }).join('');

    return `<table style="width:100%;border-collapse:collapse">${thead}<tbody>${tbody}</tbody></table>`;
}

function openPreview(rows, label) {
    const subtitle = `출력일: ${new Date().toLocaleString('ko-KR')} · ${label}`;
    document.getElementById('previewSubtitle').textContent = subtitle;

    const html = `
        <div style="margin-bottom:12px">
            <div style="font-size:16px;font-weight:700;color:#111827">상품명 변경 이력</div>
            <div style="font-size:11px;color:#6b7280;margin-top:2px">${subtitle}</div>
        </div>
        ${buildTableHTML(rows)}`;

    const previewContent = document.getElementById('previewContent');
    previewContent.innerHTML = html;
    document.getElementById('previewOverlay').style.display = 'flex';
    // 모달이 렌더된 후 바코드 생성
    requestAnimationFrame(() => makeBarcodesSVG(previewContent));
}

function closePreview() {
    document.getElementById('previewOverlay').style.display = 'none';
}

function doPrint() {
    const content = document.getElementById('previewContent').innerHTML;
    const win = window.open('', '_blank', 'width=960,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
        <meta charset="utf-8"><title>상품명 변경 이력</title>
        <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script>
        <style>
            body { font-family: 'Malgun Gothic', Arial, sans-serif; margin: 20px; }
            table { width:100%; border-collapse:collapse; }
            @media print { body { margin: 10px; } }
        </style>
    </head><body>${content}<script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('svg.barcode-svg').forEach(function(svg) {
                var sku = svg.dataset.sku;
                if (!sku) return;
                try { JsBarcode(svg, sku, {format:'CODE128',width:1.2,height:30,displayValue:false,margin:2}); }
                catch(e) { svg.style.display='none'; }
            });
            setTimeout(function(){ window.print(); window.close(); }, 600);
        });
    <\/script></body></html>`);
    win.document.close();
    win.focus();
}

function printSelected() {
    const sel = getSelectedRows();
    if (!sel.length) { alert('인쇄할 항목을 선택해주세요.'); return; }
    openPreview(sel, `선택 ${sel.length}건`);
}

function printAll() {
    const all = [...document.querySelectorAll('.data-row')];
    if (!all.length) { alert('인쇄할 데이터가 없습니다.'); return; }
    openPreview(all, `전체 ${all.length}건 (현재 페이지)`);
}

// 모달 바깥 클릭 시 닫기
document.getElementById('previewOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

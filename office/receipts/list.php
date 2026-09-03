<?php
// Design Ref: §6.1 — 영수증 목록 (Date별 관리, Status 필터 포함)
$page_title      = 'Receipt Management';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();

// Date 필터 파라미터
$year    = (int)($_GET['year']  ?? date('Y'));
$month   = (int)($_GET['month'] ?? date('n'));
$status  = $_GET['status'] ?? 'all';   // all | unused | used
$search  = trim($_GET['search'] ?? '');

// 월 선택 옵션 (최근 12개월)
$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $ts = mktime(0, 0, 0, date('n') - $i, 1, date('Y'));
    $month_options[] = ['y' => (int)date('Y', $ts), 'm' => (int)date('n', $ts), 'label' => date('M Y', $ts)];
}

// 쿼리 조건 구성
// 검색어가 있으면 월 필터 없이 전체 기간 검색, 없으면 선택 월만 표시
if ($search !== '') {
    $where  = "store_id=?";
    $params = [$store_id];
    $types  = 'i';
} else {
    $where  = "store_id=? AND YEAR(receipt_date)=? AND MONTH(receipt_date)=?";
    $params = [$store_id, $year, $month];
    $types  = 'iii';
}

if ($status === 'unused') {
    $where .= " AND linked_purchase_id IS NULL AND er_section IS NULL AND COALESCE(is_cer_placed,0)=0 AND COALESCE(is_cd_paid,0)=0";
} elseif ($status === 'used') {
    $where .= " AND (linked_purchase_id IS NOT NULL OR er_section IS NOT NULL OR COALESCE(is_cer_placed,0)=1 OR COALESCE(is_cd_paid,0)=1)";
}

if ($search !== '') {
    $like    = '%' . $search . '%';
    $where  .= " AND (supplier_name LIKE ? OR description LIKE ? OR cv_no LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like]);
    $types  .= 'sss';
}

$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT id, supplier_name, description, amount, receipt_date,
            file_path, file_mime, file_original_name, linked_purchase_type, linked_purchase_id,
            cv_no, created_at,
            er_section,
            COALESCE(is_cer_placed,0) AS is_cer_placed,
            COALESCE(is_cd_paid,0) AS is_cd_paid,
            COALESCE(payment_type,'cash') AS payment_type
     FROM office_receipts
     WHERE $where
     ORDER BY receipt_date DESC, id DESC"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Pending: All 건수 (월 무관하게 표시)
$cnt_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM office_receipts WHERE store_id=? AND linked_purchase_id IS NULL AND er_section IS NULL AND COALESCE(is_cer_placed,0)=0 AND COALESCE(is_cd_paid,0)=0");
$cnt_stmt->bind_param('i', $store_id);
$cnt_stmt->execute();
$total_unused = (int)$cnt_stmt->get_result()->fetch_assoc()['cnt'];
$cnt_stmt->close();

// 공급처 인라인 수정용 목록 (Plan: 목록에서 바로 Supplier 교정)
$sup_res   = $conn->query("SELECT name FROM suppliers ORDER BY name");
$suppliers = $sup_res ? $sup_res->fetch_all(MYSQLI_ASSOC) : [];

$conn->close();

$month_total = array_sum(array_column($rows, 'amount'));
$unused_this_month = count(array_filter($rows, fn($r) =>
    $r['linked_purchase_id'] === null && $r['er_section'] === null && !$r['is_cer_placed'] && !$r['is_cd_paid']
));
?>

<!-- 헤더 -->
<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-receipt mr-2 text-indigo-600"></i>Receipt Management
  </h2>
  <a href="add.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-plus mr-1"></i>Add Receipt
  </a>
</div>

<!-- Pending: 알림 배너 -->
<?php if ($total_unused > 0): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-4 flex items-center gap-2 text-sm text-amber-800">
  <i class="fa-solid fa-triangle-exclamation text-amber-500"></i>
  <span>Pending: 영수증 <strong><?php echo $total_unused; ?></strong>이 있습니다.
    <a href="../product_purchase/add.php" class="underline hover:text-amber-900">Product Purchase</a> 또는
    <a href="../equipment_purchase/add.php" class="underline hover:text-amber-900">Equipment Purchase</a>to process payment.
  </span>
</div>
<?php endif; ?>

<!-- 필터 -->
<form method="GET" class="flex flex-wrap gap-3 mb-5 bg-white p-4 rounded-xl shadow-sm border border-gray-100 items-center">
  <!-- 검색 -->
  <div class="flex items-center gap-1 flex-1 min-w-48">
    <div class="relative flex-1">
      <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
      <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
             placeholder="공급처, 내용, CV No. 검색..."
             class="w-full border border-gray-300 rounded-lg pl-8 pr-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400">
    </div>
    <?php if ($search !== ''): ?>
    <a href="list.php" class="text-xs text-gray-400 hover:text-red-500 px-2 py-2 whitespace-nowrap">
      <i class="fa-solid fa-xmark"></i> 초기화
    </a>
    <?php endif; ?>
  </div>

  <!-- 이전 달로 이동 -->
  <?php
    $prev_month = $month - 1;
    $prev_year  = $year;
    if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
  ?>
  <a href="list.php?year=<?php echo $prev_year; ?>&month=<?php echo $prev_month; ?>&status=<?php echo urlencode($status); ?>"
     class="border border-gray-300 rounded-lg px-2.5 py-2 text-sm text-gray-500 hover:bg-gray-50 hover:text-indigo-600 <?php echo $search ? 'opacity-40 pointer-events-none' : ''; ?>"
     title="전월로 이동">
    <i class="fa-solid fa-angles-left"></i>
  </a>

  <!-- 월 선택 (검색 중이면 흐리게) -->
  <select name="month" id="month_sel"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm <?php echo $search ? 'opacity-40' : ''; ?>"
          onchange="syncYear()" <?php echo $search ? 'disabled' : ''; ?>>
    <?php foreach ($month_options as $opt): ?>
    <option value="<?php echo $opt['m']; ?>"
            data-year="<?php echo $opt['y']; ?>"
            <?php echo ($opt['y']===$year && $opt['m']===$month) ? 'selected' : ''; ?>>
      <?php echo htmlspecialchars($opt['label']); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="hidden" name="year" id="year_hidden" value="<?php echo $year; ?>">

  <!-- Status 필터 -->
  <select name="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
    <option value="all"    <?php echo $status==='all'    ? 'selected':'' ?>>All</option>
    <option value="unused" <?php echo $status==='unused' ? 'selected':'' ?>>Pending</option>
    <option value="used"   <?php echo $status==='used'   ? 'selected':'' ?>>Processed</option>
  </select>

  <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm">
    <i class="fa-solid fa-magnifying-glass mr-1"></i>Search
  </button>

  <!-- 요약 -->
  <div class="ml-auto flex gap-4 text-sm text-gray-500">
    <span>Total: <strong class="text-gray-800"><?php echo count($rows); ?></strong></span>
    <span>Amount: <strong class="text-indigo-700"><?php echo format_amount($month_total); ?></strong></span>
    <span>Pending: <strong class="text-amber-600"><?php echo $unused_this_month; ?></strong></span>
  </div>
</form>

<?php if ($search !== ''): ?>
<div class="mb-3 text-sm text-indigo-600">
  <i class="fa-solid fa-filter mr-1"></i>
  "<strong><?php echo htmlspecialchars($search); ?></strong>" 검색 결과 — 전체 기간
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
<div class="text-center py-16 text-gray-400">
  <i class="fa-solid fa-receipt text-4xl mb-3"></i>
  <p>No receipts found for this period.</p>
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <table class="min-w-full divide-y divide-gray-100">
    <thead class="bg-gray-50">
      <tr>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Description</th>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase w-32 min-w-[8rem] max-w-[8rem]">CV No.</th>
        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">금액</th>
        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">File</th>
        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
        <th class="px-4 py-3"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
    <?php foreach ($rows as $r):
        $linked     = $r['linked_purchase_id'] !== null;
        $er_placed  = !$linked && ($r['er_section'] !== null);
        $is_used    = $linked || ($r['er_section'] !== null) || $r['is_cer_placed'] || $r['is_cd_paid'];
        $type       = $r['linked_purchase_type'];

        // Design Ref: §8.3 — 6가지 Status 결정
        if ($linked) {
            $status_label = $type === 'product' ? '상품구매' : '비품구매';
            $status_color = 'blue';
        } elseif ($r['is_cd_paid']) {
            $status_label = 'CD 완료';
            $status_color = 'green';
        } elseif ($r['is_cer_placed']) {
            $status_label = 'CER 완료';
            $status_color = 'green';
        } elseif ($er_placed) {
            $sec_labels = [
                'selling'         => 'Cash',
                'not_selling'     => 'Cash',
                'other_exp_cash'  => 'Cash(기타)',
                'check_sup'       => 'Cheque',
                'other_exp_check' => 'Cheque(기타)',
            ];
            $sec_label    = $sec_labels[$r['er_section'] ?? ''] ?? ($r['er_section'] ?? '');
            $status_label = 'ER · ' . $sec_label;
            $status_color = $r['payment_type'] === 'check' ? 'purple' : 'teal';
        } else {
            $status_label = 'Pending';
            $status_color = 'amber';
        }
    ?>
      <tr class="hover:bg-gray-50 transition-colors <?php echo !$is_used ? 'bg-amber-50/30' : ''; ?>">
        <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap"><?php echo htmlspecialchars($r['receipt_date']); ?></td>
        <td class="px-4 py-3 text-sm font-medium text-gray-800 pos-supplier-cell" data-id="<?php echo $r['id']; ?>">
          <div class="flex items-center gap-1.5">
            <span class="pos-supplier-text"><?php echo htmlspecialchars($r['supplier_name']); ?></span>
            <!-- ER/CER/CD 처리 여부와 무관하게 항상 수정 가능 — 세 흐름 모두 supplier_name을
                 office_receipts/office_product_purchases/office_equipment_purchases에서 매번
                 새로 조회(live)하며 별도 스냅샷을 저장하지 않으므로, 원본 테이블만 갱신하면 됨.
                 opacity-0/group-hover:opacity-100 조합은 이 프로젝트의 컴파일된 style.css에
                 포함되어 있지 않아 동작하지 않으므로(항상 숨김/무효과), hover 의존 없이 항상 노출 -->
            <button type="button" class="supplier-edit-btn text-gray-400 hover:text-indigo-600" title="공급처 수정">
              <i class="fa-solid fa-pen text-xs"></i>
            </button>
          </div>
        </td>
        <td class="px-4 py-3 text-sm text-gray-500 max-w-xs break-words whitespace-normal"><?php echo htmlspecialchars($r['description'] ?? ''); ?></td>
        <td class="px-4 py-3 text-sm text-gray-600 font-mono break-all w-32 min-w-[8rem] max-w-[8rem] whitespace-normal line-clamp-2" title="<?php echo $r['cv_no'] ? htmlspecialchars($r['cv_no']) : ''; ?>"><?php echo $r['cv_no'] ? htmlspecialchars($r['cv_no']) : '<span class="text-gray-300">—</span>'; ?></td>
        <td class="px-4 py-3 text-sm font-medium text-gray-800 text-right whitespace-nowrap"><?php echo format_amount((float)$r['amount']); ?></td>
        <td class="px-4 py-3 text-center">
          <?php if ($r['file_path']): ?>
          <button onclick="openPreview(<?php echo $r['id']; ?>,'<?php echo addslashes($r['file_mime'] ?? ''); ?>','<?php echo addslashes(htmlspecialchars($r['supplier_name'] ?? '', ENT_QUOTES)); ?>')"
                  class="text-indigo-500 hover:text-indigo-700" title="파일 미리보기">
            <i class="fa-solid fa-paperclip"></i>
          </button>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-center">
          <?php
            $color_map = [
                'amber'  => 'bg-amber-100 text-amber-700',
                'teal'   => 'bg-teal-100 text-teal-700',
                'purple' => 'bg-purple-100 text-purple-700',
                'blue'   => 'bg-blue-100 text-blue-700',
                'green'  => 'bg-green-100 text-green-700',
                'orange' => 'bg-orange-100 text-orange-700',
            ];
            $css = $color_map[$status_color] ?? 'bg-gray-100 text-gray-700';
          ?>
          <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $css; ?>">
            <?php echo htmlspecialchars($status_label); ?>
          </span>
          <?php if ($r['payment_type'] === 'check' && !$linked): ?>
          <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-violet-50 text-violet-600 border border-violet-200">CHQ</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-right whitespace-nowrap">
          <?php if (!$is_used): ?>
          <a href="edit.php?id=<?php echo $r['id']; ?>" class="text-xs text-gray-400 hover:text-indigo-600 mr-2">Edit</a>
          <form method="POST" action="delete.php" class="inline"
                onsubmit="return confirm('Delete this receipt?')">
            <input type="hidden" name="id"     value="<?php echo $r['id']; ?>">
            <input type="hidden" name="year"   value="<?php echo $year; ?>">
            <input type="hidden" name="month"  value="<?php echo $month; ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="text-xs text-gray-400 hover:text-red-600">Delete</button>
          </form>
          <?php elseif ($er_placed && in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])): ?>
          <span class="text-xs text-teal-500 mr-1">Expense Report</span>
          <?php elseif (!$er_placed && in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])): ?>
          <form method="POST" action="unlink.php" class="inline"
                onsubmit="return confirm('이 영수증의 연결을 해제하여 미사용 상태로 변경하시겠습니까?')">
            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
            <input type="hidden" name="year" value="<?php echo $year; ?>">
            <input type="hidden" name="month" value="<?php echo $month; ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="text-xs bg-orange-500 hover:bg-orange-600 text-white px-2 py-1 rounded font-medium">Unlink</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- 첨부파일 미리보기 모달 -->
<!-- 첨부파일 미리보기 모달 -->
<div id="previewModal" style="display:none;position:fixed;inset:0;z-index:9999">
  <div style="position:absolute;inset:0;background:rgba(0,0,0,.5)" onclick="closePreview()"></div>
  <div style="position:absolute;left:0;top:0;bottom:0;width:min(720px,65vw);background:#fff;box-shadow:4px 0 30px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden">
    <!-- 헤더 -->
    <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #e5e7eb;background:#f9fafb;flex-shrink:0">
      <i class="fa-solid fa-paperclip" style="color:#6366f1"></i>
      <span id="previewTitle" style="flex:1;font-size:13px;font-weight:600;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
      <a id="previewDownload" href="#"
         style="display:flex;align-items:center;gap:6px;padding:6px 12px;background:#16a34a;color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none">
        <i class="fa-solid fa-download"></i>다운로드
      </a>
      <button onclick="closePreview()"
              style="width:32px;height:32px;border:none;background:none;cursor:pointer;border-radius:6px;font-size:16px;color:#6b7280;display:flex;align-items:center;justify-content:center"
              onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='none'">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <!-- 콘텐츠 — 나머지 세로 공간 전부 사용 -->
    <div id="previewBody" style="flex:1;position:relative;overflow:hidden;background:#e5e7eb">
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:13px">로딩 중...</div>
    </div>
  </div>
</div>

<script>
function syncYear() {
    const sel = document.getElementById('month_sel');
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('year_hidden').value = opt.dataset.year;
    sel.form.submit();
}

let previewBlobUrl = null;

async function openPreview(id, mime, title) {
    const modal   = document.getElementById('previewModal');
    const body    = document.getElementById('previewBody');
    const dlLink  = document.getElementById('previewDownload');

    document.getElementById('previewTitle').textContent = title || '첨부파일';
    dlLink.href = 'view.php?id=' + id + '&dl';
    body.innerHTML = '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:13px">로딩 중...</div>';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';

    // 이전 blob 정리
    if (previewBlobUrl) { URL.revokeObjectURL(previewBlobUrl); previewBlobUrl = null; }

    // 파일을 blob으로 받아 blob URL로 표시 → X-Frame-Options 프레이밍 차단을 우회
    try {
        const res = await fetch('view.php?id=' + id, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const blob = await res.blob();
        previewBlobUrl = URL.createObjectURL(blob);
        const realMime = (mime && mime !== '') ? mime : (blob.type || '');

        if (realMime.startsWith('image/')) {
            const wrap = document.createElement('div');
            wrap.style.cssText = 'position:absolute;inset:0;overflow:auto;display:flex;align-items:center;justify-content:center;padding:16px;background:#e5e7eb';
            const img = document.createElement('img');
            img.src = previewBlobUrl;
            img.alt = title;
            img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,.2)';
            body.innerHTML = '';
            wrap.appendChild(img);
            body.appendChild(wrap);
        } else {
            // PDF 등 — blob URL은 페이지가 직접 로드하므로 XFO 영향을 받지 않음
            const iframe = document.createElement('iframe');
            iframe.src = previewBlobUrl;
            iframe.title = title;
            iframe.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:none;display:block';
            body.innerHTML = '';
            body.appendChild(iframe);
        }
    } catch (e) {
        body.innerHTML = '<div style="position:absolute;inset:0;display:flex;flex-direction:column;gap:8px;align-items:center;justify-content:center;color:#ef4444;font-size:13px;text-align:center;padding:20px">'
            + '<i class="fa-solid fa-triangle-exclamation" style="font-size:24px"></i>'
            + '<div>파일을 불러올 수 없습니다. (' + (e.message || 'error') + ')</div>'
            + '<a href="view.php?id=' + id + '&dl" style="color:#16a34a;text-decoration:underline">다운로드로 열기</a>'
            + '</div>';
    }
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewBody').innerHTML = '';
    document.body.style.overflow = '';
    if (previewBlobUrl) { URL.revokeObjectURL(previewBlobUrl); previewBlobUrl = null; }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePreview();
});

// ── 공급처(Supplier) 인라인 수정 ──
const SUPPLIERS = <?php echo json_encode(array_column($suppliers, 'name'), JSON_UNESCAPED_UNICODE); ?>;

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function supplierDisplayHtml(name) {
    return '<div class="flex items-center gap-1.5">' +
        '<span class="pos-supplier-text">' + escapeHtml(name) + '</span>' +
        '<button type="button" class="supplier-edit-btn text-gray-400 hover:text-indigo-600" title="공급처 수정">' +
        '<i class="fa-solid fa-pen text-xs"></i></button></div>';
}

function bindSupplierEditBtn(cell) {
    const btn = cell.querySelector('.supplier-edit-btn');
    if (btn) btn.addEventListener('click', () => startSupplierEdit(cell));
}

function renderSupplierDisplay(cell, name) {
    cell.innerHTML = supplierDisplayHtml(name);
    bindSupplierEditBtn(cell);
}

function startSupplierEdit(cell) {
    if (cell.querySelector('.supplier-edit-input')) return;
    const id      = cell.dataset.id;
    const current = cell.querySelector('.pos-supplier-text').textContent;
    const list    = SUPPLIERS.includes(current) ? SUPPLIERS : [current, ...SUPPLIERS];

    cell.innerHTML =
        '<div class="relative" style="min-width:160px">' +
        '<input type="text" class="supplier-edit-input w-full border border-indigo-300 rounded px-2 py-1 text-sm focus:ring-2 focus:ring-indigo-400" autocomplete="off">' +
        '<div class="supplier-edit-dropdown hidden absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-40 overflow-y-auto"></div>' +
        '</div>';
    const input    = cell.querySelector('.supplier-edit-input');
    const dropdown = cell.querySelector('.supplier-edit-dropdown');
    input.value = current;

    function renderDropdown(filter) {
        const q = filter.trim().toLowerCase();
        const matches = list.filter(s => s.toLowerCase().includes(q));
        if (!matches.length) { dropdown.classList.add('hidden'); dropdown.innerHTML = ''; return; }
        dropdown.innerHTML = matches.map(s =>
            '<div class="px-2 py-1.5 text-sm hover:bg-indigo-50 cursor-pointer" data-name="' + escapeHtml(s) + '">' + escapeHtml(s) + '</div>'
        ).join('');
        dropdown.classList.remove('hidden');
    }

    async function save(name) {
        if (!name || name === current) { renderSupplierDisplay(cell, current); return; }
        input.disabled = true;
        try {
            const res = await fetch('ajax_update_supplier.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(id) + '&supplier_name=' + encodeURIComponent(name)
            });
            const data = await res.json();
            if (data.success) {
                renderSupplierDisplay(cell, data.supplier_name);
            } else {
                alert(data.error || '수정에 실패했습니다.');
                renderSupplierDisplay(cell, current);
            }
        } catch (e) {
            alert('수정 중 오류가 발생했습니다.');
            renderSupplierDisplay(cell, current);
        }
    }

    input.addEventListener('focus', () => renderDropdown(input.value));
    input.addEventListener('input', () => renderDropdown(input.value));
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { renderSupplierDisplay(cell, current); }
        if (e.key === 'Enter') {
            e.preventDefault();
            const exact = list.find(s => s.toLowerCase() === input.value.trim().toLowerCase());
            if (exact) save(exact);
        }
    });
    dropdown.addEventListener('mousedown', (e) => {
        e.preventDefault();
        const opt = e.target.closest('[data-name]');
        if (opt) save(opt.dataset.name);
    });
    input.addEventListener('blur', () => {
        setTimeout(() => { if (cell.querySelector('.supplier-edit-input')) renderSupplierDisplay(cell, current); }, 150);
    });

    input.focus();
    input.select();
    renderDropdown(current);
}

document.querySelectorAll('.pos-supplier-cell').forEach(bindSupplierEditBtn);
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

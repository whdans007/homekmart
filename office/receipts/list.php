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
        <td class="px-4 py-3 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($r['supplier_name']); ?></td>
        <td class="px-4 py-3 text-sm text-gray-500 max-w-xs break-words whitespace-normal"><?php echo htmlspecialchars($r['description'] ?? ''); ?></td>
        <td class="px-4 py-3 text-sm text-gray-600 font-mono break-all w-32 min-w-[8rem] max-w-[8rem] whitespace-normal"><?php echo $r['cv_no'] ? htmlspecialchars($r['cv_no']) : '<span class="text-gray-300">—</span>'; ?></td>
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
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

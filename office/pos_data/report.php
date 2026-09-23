<?php
// Design Ref: 화면(필터/골격)은 즉시 렌더링하고, 무거운 집계 쿼리는
// ajax_report_meta.php / ajax_report_daily.php / ajax_report_dept.php / ajax_report_dow.php 가
// office/lib/pos_report_helper.php 를 통해 비동기로 채운다 (대용량 pos_sales_data 로딩 체감속도 개선).
$page_title      = 'POS Daily Sales Report';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

// 기간 필터 (기본: 이번 달) — DB 조회 없이 GET 파라미터만으로 계산
$today        = date('Y-m-d');
$default_from = date('Y-m-01');
$default_to   = date('Y-m-d');

$date_from = trim($_GET['from'] ?? $default_from);
$date_to   = trim($_GET['to']   ?? $default_to);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $default_to;
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$dept_filter = trim($_GET['dept'] ?? '');
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-chart-line mr-2 text-blue-600"></i>POS Daily Sales Report
  </h2>
</div>

<!-- 필터 -->
<form method="GET" class="flex flex-wrap gap-2 mb-5 items-end">
  <div class="flex flex-col gap-1">
    <label class="text-xs text-gray-500 font-medium">From</label>
    <input type="date" name="from" value="<?php echo htmlspecialchars($date_from); ?>"
           class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
  </div>
  <div class="flex flex-col gap-1">
    <label class="text-xs text-gray-500 font-medium">To</label>
    <input type="date" name="to" value="<?php echo htmlspecialchars($date_to); ?>"
           class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
  </div>
  <div class="flex flex-col gap-1" id="pos-dept-filter-wrap">
    <label class="text-xs text-gray-500 font-medium">Department</label>
    <select name="dept" id="pos-dept-select" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
      <option value="">All Departments</option>
      <?php if ($dept_filter !== ''): ?>
      <option value="<?php echo htmlspecialchars($dept_filter); ?>" selected><?php echo htmlspecialchars($dept_filter); ?></option>
      <?php endif; ?>
    </select>
  </div>

  <!-- 빠른 기간 선택 버튼 -->
  <div class="flex flex-col gap-1">
    <label class="text-xs text-gray-500 font-medium invisible">Quick</label>
    <div class="flex gap-1">
      <?php
      $quick = [
          'This Month' => [date('Y-m-01'), date('Y-m-d')],
          'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
          'This Year'  => [date('Y-01-01'), date('Y-m-d')],
      ];
      foreach ($quick as $label => [$f, $t]):
          $active = ($date_from === $f && $date_to === $t && $dept_filter === '');
          $cls = $active ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-600';
      ?>
      <a href="?from=<?php echo $f; ?>&to=<?php echo $t; ?>"
         class="px-3 py-2 rounded-lg text-xs font-medium <?php echo $cls; ?>"><?php echo $label; ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm self-end">
    <i class="fa-solid fa-magnifying-glass mr-1"></i>조회
  </button>
</form>

<!-- 데이터 없음 안내 (AJAX 결과에 따라 표시) -->
<div id="pos-empty-state" class="hidden"></div>

<!-- 본문 (AJAX로 순차 채워짐) -->
<div id="pos-content">

  <!-- 요약 카드 -->
  <div class="grid gap-3 mb-5" style="grid-template-columns:repeat(8,minmax(0,1fr));">
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-3 py-[18px]" style="grid-column:span 3;">
      <p class="text-xs text-gray-400 mb-1">요일별 평균 매출 (최근 3개월)</p>
      <div style="height:96px;" class="relative">
        <canvas id="dowChart"></canvas>
        <div id="dowChart-loading" class="absolute inset-0 flex items-center justify-center text-xs text-gray-300">
          <i class="fa-solid fa-spinner fa-spin"></i>
        </div>
      </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-3 py-[18px]">
      <p class="text-xs text-gray-400 mb-1">Total NET SALES</p>
      <p class="text-lg font-bold text-blue-700" id="card-sum-net"><i class="fa-solid fa-spinner fa-spin text-gray-300"></i></p>
      <p class="text-xs text-gray-400" id="card-sum-net-sub">&nbsp;</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-3 py-[18px]">
      <p class="text-xs text-gray-400 mb-1">일평균 매출</p>
      <p class="text-lg font-bold text-blue-700" id="card-avg-net"><i class="fa-solid fa-spinner fa-spin text-gray-300"></i></p>
      <p class="text-xs text-gray-400">avg / day</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-3 py-[18px]">
      <p class="text-xs text-gray-400 mb-1">Gross Profit</p>
      <p class="text-lg font-bold text-green-600" id="card-sum-gp"><i class="fa-solid fa-spinner fa-spin text-gray-300"></i></p>
      <p class="text-xs text-gray-400" id="card-sum-gp-sub">&nbsp;</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-3 py-[18px]">
      <p class="text-xs text-gray-400 mb-1">Transactions</p>
      <p class="text-lg font-bold text-gray-700" id="card-sum-tx"><i class="fa-solid fa-spinner fa-spin text-gray-300"></i></p>
      <p class="text-xs text-gray-400" id="card-sum-tx-sub">&nbsp;</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-3 py-[18px]">
      <p class="text-xs text-gray-400 mb-1">Total PCS</p>
      <p class="text-lg font-bold text-gray-700" id="card-sum-pcs"><i class="fa-solid fa-spinner fa-spin text-gray-300"></i></p>
      <p class="text-xs text-gray-400" id="card-sum-pcs-sub">&nbsp;</p>
    </div>
  </div>

  <!-- 차트 -->
  <div class="flex gap-4 mb-5">
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 flex-1 min-w-0">
      <p class="text-sm font-semibold text-gray-600 mb-3">Daily NET SALES</p>
      <div class="relative" style="height:240px;">
        <canvas id="salesChart" style="height:240px;"></canvas>
        <div id="salesChart-loading" class="absolute inset-0 flex items-center justify-center text-xs text-gray-300" style="min-height:120px;">
          <i class="fa-solid fa-spinner fa-spin mr-1"></i>불러오는 중...
        </div>
      </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 flex-1 min-w-0">
      <p class="text-sm font-semibold text-gray-600 mb-3">Daily PCS(판매 수량)</p>
      <div class="relative" style="height:240px;">
        <canvas id="pcsChart" style="height:240px;"></canvas>
        <div id="pcsChart-loading" class="absolute inset-0 flex items-center justify-center text-xs text-gray-300" style="min-height:120px;">
          <i class="fa-solid fa-spinner fa-spin mr-1"></i>불러오는 중...
        </div>
      </div>
    </div>
  </div>

  <!-- 일별 테이블 + 부서별 테이블 -->
  <div class="flex gap-4 flex-wrap">

    <!-- 일별 상세 -->
    <div class="flex-1 min-w-0 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <table class="min-w-full text-xs divide-y divide-gray-100">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left text-gray-500 font-semibold">DATE</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">TX</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">PCS</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">DISCOUNT</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">NET SALES</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">GROSS PROFIT</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">MARGIN</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">vs Avg</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50" id="pos-daily-tbody">
          <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400"><i class="fa-solid fa-spinner fa-spin mr-1"></i>불러오는 중...</td></tr>
        </tbody>
        <tfoot class="bg-gray-50 border-t-2 border-gray-200" id="pos-daily-tfoot"></tfoot>
      </table>
    </div>

    <!-- 부서별 합계 -->
    <div class="w-96 flex-shrink-0 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden self-start hidden" id="pos-dept-panel">
      <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
        <p class="text-xs font-semibold text-gray-600">Department Breakdown</p>
        <p class="text-xs text-gray-400"><?php echo htmlspecialchars($date_from); ?> ~ <?php echo htmlspecialchars($date_to); ?></p>
      </div>
      <table class="min-w-full text-xs divide-y divide-gray-100">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left text-gray-500 font-semibold">DEPT</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">NET SALES</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">%</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50" id="pos-dept-tbody"></tbody>
        <tfoot class="bg-gray-50 border-t-2 border-gray-200" id="pos-dept-tfoot"></tfoot>
      </table>
    </div>

  </div>

  <!-- SKU별 판매 분석 -->
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mt-4">
    <div class="px-4 py-3 border-b border-gray-100 bg-gray-50 flex flex-wrap items-center justify-between gap-2">
      <div>
        <p class="text-sm font-semibold text-gray-600">SKU별 판매 분석</p>
        <p class="text-xs text-gray-400" id="pos-sku-summary">&nbsp;</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <input type="text" id="pos-sku-search" placeholder="품목명/코드 검색..."
               class="border border-gray-300 rounded-lg px-3 py-1.5 text-xs w-48 focus:ring-2 focus:ring-blue-400">
        <select id="pos-sku-limit" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-blue-400">
          <option value="50">Top 50</option>
          <option value="100" selected>Top 100</option>
          <option value="300">Top 300</option>
          <option value="20000">전체</option>
        </select>
        <a href="#" id="pos-sku-export-btn" target="_blank"
           class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-medium whitespace-nowrap">
          <i class="fa-solid fa-file-excel mr-1"></i>Excel 다운로드
        </a>
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-xs divide-y divide-gray-100">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left text-gray-500 font-semibold">ITEMCODE</th>
            <th class="px-3 py-2 text-left text-gray-500 font-semibold">ITEMNAME</th>
            <th class="px-3 py-2 text-left text-gray-500 font-semibold">DEPT</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold cursor-pointer select-none pos-sku-sortable" data-sort="tx">TX</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold cursor-pointer select-none pos-sku-sortable" data-sort="pcs">PCS</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">원가</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">판매가</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold cursor-pointer select-none pos-sku-sortable" data-sort="net">NET SALES</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold">%</th>
            <th class="px-3 py-2 text-right text-gray-500 font-semibold cursor-pointer select-none pos-sku-sortable" data-sort="gp">GROSS PROFIT</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50" id="pos-sku-tbody">
          <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400"><i class="fa-solid fa-spinner fa-spin mr-1"></i>불러오는 중...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>
(function() {
    const dateFrom = <?php echo json_encode($date_from); ?>;
    const dateTo   = <?php echo json_encode($date_to); ?>;
    const deptFilter = <?php echo json_encode($dept_filter); ?>;
    const qs = 'from=' + encodeURIComponent(dateFrom) + '&to=' + encodeURIComponent(dateTo) + '&dept=' + encodeURIComponent(deptFilter);
    const fromDateObj = new Date(dateFrom + 'T00:00:00');
    const toDateObj = new Date(dateTo + 'T00:00:00');
    const isFullMonth = fromDateObj.getDate() === 1 &&
        fromDateObj.getFullYear() === toDateObj.getFullYear() &&
        fromDateObj.getMonth() === toDateObj.getMonth();

    let prevLabel = '';
    let prevFetchPromise = Promise.resolve(null);
    if (isFullMonth) {
        let py = fromDateObj.getFullYear(), pm = fromDateObj.getMonth() - 1;
        if (pm < 0) { pm = 11; py -= 1; }
        prevLabel = (pm + 1) + '월';
        const prevFrom = formatYmd(new Date(py, pm, 1));
        const prevLastDay = new Date(py, pm + 1, 0).getDate();
        const prevTo = formatYmd(new Date(py, pm, prevLastDay));
        const prevQs = 'from=' + encodeURIComponent(prevFrom) + '&to=' + encodeURIComponent(prevTo) + '&dept=' + encodeURIComponent(deptFilter);
        prevFetchPromise = fetch('ajax_report_daily.php?' + prevQs).then(r => r.json()).catch(() => null);
    }

    let prev2Label = '';
    let prev2FetchPromise = Promise.resolve(null);
    if (isFullMonth) {
        let py = fromDateObj.getFullYear(), pm = fromDateObj.getMonth() - 2;
        while (pm < 0) { pm += 12; py -= 1; }
        prev2Label = (pm + 1) + '월';
        const prev2From = formatYmd(new Date(py, pm, 1));
        const prev2LastDay = new Date(py, pm + 1, 0).getDate();
        const prev2To = formatYmd(new Date(py, pm, prev2LastDay));
        const prev2Qs = 'from=' + encodeURIComponent(prev2From) + '&to=' + encodeURIComponent(prev2To) + '&dept=' + encodeURIComponent(deptFilter);
        prev2FetchPromise = fetch('ajax_report_daily.php?' + prev2Qs).then(r => r.json()).catch(() => null);
    }

    Chart.register(ChartDataLabels);

    function nf(n) { return Math.round(Number(n) || 0).toLocaleString('en-US'); }
    function dowOf(ymd) {
        const labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        return labels[new Date(ymd + 'T00:00:00').getDay()];
    }
    function pad2(n) { return String(n).padStart(2, '0'); }
    function formatYmd(date) {
        return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
    }
    function formatSaleDate(date) {
        return pad2(date.getMonth() + 1) + '-' + pad2(date.getDate()) + '-' + date.getFullYear();
    }
    function buildDayList(dateFrom, dateTo, rows, dateKeyField) {
        const from = new Date(dateFrom + 'T00:00:00');
        const to = new Date(dateTo + 'T00:00:00');
        const isFullMonth = from.getDate() === 1 &&
            from.getFullYear() === to.getFullYear() &&
            from.getMonth() === to.getMonth();

        if (!isFullMonth) {
            return rows.map(row => ({ ymd: row[dateKeyField], label: row.sale_date, row: row }));
        }

        const rowsByDate = new Map(rows.map(row => [row[dateKeyField], row]));
        const lastDay = new Date(from.getFullYear(), from.getMonth() + 1, 0).getDate();
        const days = [];
        for (let day = 1; day <= lastDay; day++) {
            const date = new Date(from.getFullYear(), from.getMonth(), day);
            const ymd = formatYmd(date);
            days.push({ ymd: ymd, label: formatSaleDate(date), row: rowsByDate.get(ymd) || null });
        }
        return days;
    }

    // ── 요일별 평균 매출 미니 차트 ──
    fetch('ajax_report_dow.php')
        .then(r => r.json())
        .then(res => {
            const el = document.getElementById('dowChart');
            const loading = document.getElementById('dowChart-loading');
            if (loading) loading.remove();
            if (!res.success) return;
            new Chart(el, {
                type: 'bar',
                data: {
                    labels: res.labels,
                    datasets: [{
                        data: res.data,
                        backgroundColor: res.labels.map(d => (d === 'Sat' || d === 'Sun') ? 'rgba(239,68,68,0.7)' : 'rgba(59,130,246,0.6)'),
                        borderRadius: 3,
                        datalabels: {
                            anchor: 'end', align: 'end', offset: 2, color: '#374151',
                            font: { size: 8, weight: '600' },
                            formatter: v => v >= 1000
                                ? (v / 1000).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K'
                                : v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
                        }
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    layout: { padding: { top: 12 } },
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: ctx => ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) } }
                    },
                    scales: { x: { ticks: { font: { size: 9 } }, grid: { display: false } }, y: { display: false } }
                }
            });
        })
        .catch(() => { const loading = document.getElementById('dowChart-loading'); if (loading) loading.remove(); });

    // ── 부서 목록 + 진단정보 (드롭다운 채우기 / 빈 데이터 안내용) ──
    const metaPromise = fetch('ajax_report_meta.php').then(r => r.json());
    metaPromise.then(res => {
        if (!res.success) return;
        const wrap = document.getElementById('pos-dept-filter-wrap');
        if (!res.dept_list.length) { wrap.classList.add('hidden'); return; }
        const sel = document.getElementById('pos-dept-select');
        sel.innerHTML = '<option value="">All Departments</option>' +
            res.dept_list.map(d => {
                const v = d.replace(/"/g, '&quot;');
                return '<option value="' + v + '"' + (d === deptFilter ? ' selected' : '') + '>' + v + '</option>';
            }).join('');
    }).catch(() => {});

    function renderEmptyState(diag) {
        document.getElementById('pos-content').classList.add('hidden');
        const box = document.getElementById('pos-empty-state');
        box.classList.remove('hidden');
        let html = '<div class="text-center py-12 text-gray-400">' +
            '<i class="fa-solid fa-chart-line text-4xl mb-3"></i><p>해당 기간에 데이터가 없습니다.</p></div>';
        if (diag && diag.cnt > 0) {
            html += '<div class="mt-4 bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-sm text-yellow-800 max-w-lg mx-auto">' +
                '<p class="font-semibold mb-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>데이터 범위 안내</p>' +
                '<p>전체 <strong>' + nf(diag.cnt) + '</strong>건의 데이터가 있습니다.</p>' +
                '<p>날짜 범위: <strong>' + diag.min_date + '</strong> ~ <strong>' + diag.max_date + '</strong></p>' +
                '<p>감지된 날짜 포맷: <code class="bg-yellow-100 px-1 rounded">' + diag.date_fmt + '</code>' +
                '&nbsp;(샘플: <code class="bg-yellow-100 px-1 rounded">' + (diag.sample || '') + '</code>)</p>' +
                '<p class="mt-2 text-xs text-yellow-600">위 날짜 범위로 조회 기간을 맞춰주세요.</p>' +
                '<div class="mt-3"><a href="?from=' + encodeURIComponent(diag.min_date) + '&to=' + encodeURIComponent(diag.max_date) + '" ' +
                'class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg text-xs font-medium">전체 기간 조회</a></div></div>';
        } else {
            html += '<div class="mt-4 text-center text-sm text-gray-400"><p>POS Sales Data가 아직 업로드되지 않았습니다.</p>' +
                '<a href="index.php" class="mt-2 inline-block text-blue-600 hover:underline">업로드 바로가기</a></div>';
        }
        box.innerHTML = html;
    }

    // ── 일별 집계(요약카드/차트/일별테이블) ──
    Promise.all([
        fetch('ajax_report_daily.php?' + qs).then(r => r.json()),
        prevFetchPromise,
        prev2FetchPromise
    ]).then(([res, prevRes, prev2Res]) => {
            if (!res.success) return;
            const rows = res.rows, sums = res.sums;
            if (!rows.length) {
                metaPromise.then(m => renderEmptyState(m.diag)).catch(() => renderEmptyState(null));
                return;
            }

            const days = rows.length;
            const avgNet = days > 0 ? sums.net / days : 0;

            document.getElementById('card-sum-net').textContent = nf(sums.net);
            document.getElementById('card-sum-net-sub').textContent = days + ' days';
            document.getElementById('card-avg-net').textContent = nf(avgNet);
            document.getElementById('card-sum-gp').textContent = nf(sums.gp);
            document.getElementById('card-sum-gp-sub').textContent = sums.net > 0 ? nf(sums.gp / sums.net * 100) + '% margin' : '-';
            document.getElementById('card-sum-tx').textContent = nf(sums.tx);
            document.getElementById('card-sum-tx-sub').textContent = 'avg ' + (days > 0 ? nf(sums.tx / days) : 0) + '/day';
            document.getElementById('card-sum-pcs').textContent = nf(sums.pcs);
            document.getElementById('card-sum-pcs-sub').textContent = 'avg ' + (sums.tx > 0 ? nf(sums.pcs / sums.tx) : 0) + '/tx';

            const dayList = buildDayList(dateFrom, dateTo, rows, 'parsed_date');
            let prevNetData = null, prevPcsData = null;
            if (isFullMonth && prevRes && prevRes.success && prevRes.rows && prevRes.rows.length > 0) {
                const prevByDay = {};
                prevRes.rows.forEach(r => {
                    const day = parseInt(r.parsed_date.slice(8, 10), 10);
                    prevByDay[day] = r;
                });
                prevNetData = dayList.map((d, idx) => {
                    const r = prevByDay[idx + 1];
                    return r ? Math.round((parseFloat(r.net_sales) || 0) * 100) / 100 : null;
                });
                prevPcsData = dayList.map((d, idx) => {
                    const r = prevByDay[idx + 1];
                    return r ? (parseFloat(r.total_pcs) || 0) : null;
                });
            }
            let prev2NetData = null, prev2PcsData = null;
            if (isFullMonth && prev2Res && prev2Res.success && prev2Res.rows && prev2Res.rows.length > 0) {
                const prev2ByDay = {};
                prev2Res.rows.forEach(r => {
                    const day = parseInt(r.parsed_date.slice(8, 10), 10);
                    prev2ByDay[day] = r;
                });
                prev2NetData = dayList.map((d, idx) => {
                    const r = prev2ByDay[idx + 1];
                    return r ? Math.round((parseFloat(r.net_sales) || 0) * 100) / 100 : null;
                });
                prev2PcsData = dayList.map((d, idx) => {
                    const r = prev2ByDay[idx + 1];
                    return r ? (parseFloat(r.total_pcs) || 0) : null;
                });
            }
            const yesterdayDate = new Date();
            yesterdayDate.setDate(yesterdayDate.getDate() - 1);
            const yesterdayYmd = formatYmd(yesterdayDate);
            const salesBackgroundColors = dayList.map(d => d.ymd === yesterdayYmd ? 'rgba(245,158,11,0.85)' : 'rgba(59,130,246,0.7)');
            const salesBorderColors = dayList.map(d => d.ymd === yesterdayYmd ? 'rgba(217,119,6,1)' : 'rgba(59,130,246,1)');
            const pcsBackgroundColors = dayList.map(d => d.ymd === yesterdayYmd ? 'rgba(245,158,11,0.85)' : 'rgba(139,92,246,0.7)');
            const pcsBorderColors = dayList.map(d => d.ymd === yesterdayYmd ? 'rgba(217,119,6,1)' : 'rgba(139,92,246,1)');

            // 메인 차트
            const loading = document.getElementById('salesChart-loading');
            if (loading) loading.remove();
            new Chart(document.getElementById('salesChart'), {
                type: 'bar',
                data: {
                    labels: dayList.map(d => d.row ? d.row.sale_date : d.label),
                    datasets: [
                        {
                            label: 'NET SALES',
                            data: dayList.map(d => d.row ? Math.round((parseFloat(d.row.net_sales) || 0) * 100) / 100 : null),
                            backgroundColor: salesBackgroundColors, borderColor: salesBorderColors, borderWidth: 1, order: 2,
                            datalabels: {
                                anchor: 'end', align: 'end', offset: 2, color: '#1d4ed8', font: { size: 9, weight: '600' },
                                formatter: v => v === null || v === undefined ? '' : v >= 1000
                                    ? (v / 1000).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K'
                                    : v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
                            }
                        },
                        {
                            label: 'Gross Profit',
                            data: dayList.map(d => d.row ? Math.round((parseFloat(d.row.gross_profit) || 0) * 100) / 100 : null),
                            type: 'line', borderColor: 'rgba(34,197,94,0.9)', backgroundColor: 'rgba(34,197,94,0.15)',
                            borderWidth: 2, pointRadius: 3, fill: false, tension: 0.3, order: 1, yAxisID: 'y',
                            datalabels: { display: false }
                        },
                        ...(prevNetData ? [{
                            label: prevLabel,
                            data: prevNetData,
                            type: 'line',
                            borderColor: 'rgba(107,114,128,0.7)',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            pointRadius: 0,
                            fill: false,
                            tension: 0.3,
                            order: 3,
                            yAxisID: 'y',
                            datalabels: { display: false }
                        }] : []),
                        ...(prev2NetData ? [{
                            label: prev2Label,
                            data: prev2NetData,
                            type: 'line',
                            borderColor: 'rgba(20,184,166,0.7)',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            borderDash: [2, 3],
                            pointRadius: 0,
                            fill: false,
                            tension: 0.3,
                            order: 4,
                            yAxisID: 'y',
                            datalabels: { display: false }
                        }] : [])
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    layout: { padding: { top: 20 } },
                    plugins: {
                        legend: { position: 'top', labels: { font: { size: 11 } } },
                        tooltip: { callbacks: { label: ctx => ctx.parsed.y == null ? '' : ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) } }
                    },
                    scales: {
                        x: { ticks: { font: { size: 10 }, maxRotation: 45 } },
                        y: { ticks: { font: { size: 10 }, callback: v => v.toLocaleString() } }
                    }
                }
            });

            const pcsLoading = document.getElementById('pcsChart-loading');
            if (pcsLoading) pcsLoading.remove();
            new Chart(document.getElementById('pcsChart'), {
                type: 'bar',
                data: {
                    labels: dayList.map(d => d.row ? d.row.sale_date : d.label),
                    datasets: [
                        {
                            label: 'PCS',
                            data: dayList.map(d => d.row ? (parseFloat(d.row.total_pcs) || 0) : null),
                            backgroundColor: pcsBackgroundColors,
                            borderColor: pcsBorderColors,
                            borderWidth: 1,
                            datalabels: {
                                anchor: 'end', align: 'end', offset: 2, color: '#6d28d9', font: { size: 9, weight: '600' },
                                formatter: v => v === null || v === undefined ? '' : v >= 1000
                                    ? (v / 1000).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K'
                                    : v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
                            }
                        },
                        ...(prevPcsData ? [{
                            label: prevLabel,
                            data: prevPcsData,
                            type: 'line',
                            borderColor: 'rgba(107,114,128,0.7)',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            pointRadius: 0,
                            fill: false,
                            tension: 0.3,
                            order: 3,
                            yAxisID: 'y',
                            datalabels: { display: false }
                        }] : []),
                        ...(prev2PcsData ? [{
                            label: prev2Label,
                            data: prev2PcsData,
                            type: 'line',
                            borderColor: 'rgba(20,184,166,0.7)',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            borderDash: [2, 3],
                            pointRadius: 0,
                            fill: false,
                            tension: 0.3,
                            order: 4,
                            yAxisID: 'y',
                            datalabels: { display: false }
                        }] : [])
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    layout: { padding: { top: 20 } },
                    plugins: {
                        legend: { display: !!(prevPcsData || prev2PcsData), position: 'top', labels: { font: { size: 11 } } },
                        tooltip: { callbacks: { label: ctx => ctx.parsed.y == null ? '' : ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) } }
                    },
                    scales: {
                        x: { ticks: { font: { size: 10 }, maxRotation: 45 } },
                        y: { ticks: { font: { size: 10 }, callback: v => v.toLocaleString() } }
                    }
                }
            });

            // 일별 테이블
            const tbody = document.getElementById('pos-daily-tbody');
            tbody.innerHTML = rows.map(r => {
                const net = parseFloat(r.net_sales) || 0;
                const gp  = parseFloat(r.gross_profit) || 0;
                const margin = net > 0 ? gp / net * 100 : 0;
                const diff = avgNet > 0 ? (net - avgNet) / avgNet * 100 : 0;
                const diffCls = diff >= 0 ? 'text-green-600' : 'text-red-500';
                const dow = dowOf(r.parsed_date);
                const isSun = dow === 'Sun';
                return '<tr class="hover:bg-gray-50 ' + (isSun ? 'bg-red-50' : '') + '">' +
                    '<td class="px-3 py-2 font-medium text-gray-700 whitespace-nowrap">' + r.sale_date + ' <span class="text-gray-400 ml-1">' + dow + '</span></td>' +
                    '<td class="px-3 py-2 text-right font-mono text-gray-600">' + nf(r.tx_count) + '</td>' +
                    '<td class="px-3 py-2 text-right font-mono text-gray-600">' + nf(r.total_pcs) + '</td>' +
                    '<td class="px-3 py-2 text-right font-mono text-gray-600">' + nf(r.discount) + '</td>' +
                    '<td class="px-3 py-2 text-right font-bold font-mono text-blue-700">' + nf(net) + '</td>' +
                    '<td class="px-3 py-2 text-right font-mono text-green-600">' + nf(gp) + '</td>' +
                    '<td class="px-3 py-2 text-right font-mono text-gray-500">' + nf(margin) + '%</td>' +
                    '<td class="px-3 py-2 text-right font-mono ' + diffCls + '">' + (diff >= 0 ? '+' : '') + nf(diff) + '%</td>' +
                    '</tr>';
            }).join('');

            document.getElementById('pos-daily-tfoot').innerHTML =
                '<tr>' +
                '<td class="px-3 py-2 font-bold text-gray-700 text-xs">TOTAL (' + days + '일)</td>' +
                '<td class="px-3 py-2 text-right font-bold font-mono text-gray-700">' + nf(sums.tx) + '</td>' +
                '<td class="px-3 py-2 text-right font-bold font-mono text-gray-700">' + nf(sums.pcs) + '</td>' +
                '<td class="px-3 py-2 text-right font-bold font-mono text-gray-700">' + nf(sums.disc) + '</td>' +
                '<td class="px-3 py-2 text-right font-bold font-mono text-blue-700">' + nf(sums.net) + '</td>' +
                '<td class="px-3 py-2 text-right font-bold font-mono text-green-600">' + nf(sums.gp) + '</td>' +
                '<td class="px-3 py-2 text-right font-bold font-mono text-gray-500">' + (sums.net > 0 ? nf(sums.gp / sums.net * 100) + '%' : '-') + '</td>' +
                '<td class="px-3 py-2"></td></tr>';
        })
        .catch(() => {});

    // ── 부서별 매출 breakdown ──
    fetch('ajax_report_dept.php?' + qs)
        .then(r => r.json())
        .then(res => {
            if (!res.success || !res.dept_rows.length) return;
            const rows = res.dept_rows;
            const total = rows.reduce((s, r) => s + (parseFloat(r.net_sales) || 0), 0);
            document.getElementById('pos-dept-panel').classList.remove('hidden');
            document.getElementById('pos-dept-tbody').innerHTML = rows.map(r => {
                const net = parseFloat(r.net_sales) || 0;
                const pct = total > 0 ? net / total * 100 : 0;
                const dept = String(r.department);
                const href = '?from=' + encodeURIComponent(dateFrom) + '&to=' + encodeURIComponent(dateTo) + '&dept=' + encodeURIComponent(dept);
                const escDept = dept.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                return '<tr class="hover:bg-gray-50">' +
                    '<td class="px-3 py-1.5 text-gray-700 truncate max-w-28" title="' + escDept + '">' +
                    '<a href="' + href + '" class="hover:text-blue-600">' + escDept + '</a></td>' +
                    '<td class="px-3 py-1.5 text-right font-mono text-blue-700">' + nf(net) + '</td>' +
                    '<td class="px-3 py-1.5 text-right font-mono text-gray-400">' + nf(pct) + '%</td></tr>';
            }).join('');
            document.getElementById('pos-dept-tfoot').innerHTML =
                '<tr><td class="px-3 py-2 font-bold text-gray-700 text-xs">TOTAL</td>' +
                '<td class="px-3 py-2 text-right font-bold font-mono text-blue-700">' + nf(total) + '</td>' +
                '<td class="px-3 py-2 text-right font-mono text-gray-500">100%</td></tr>';
        })
        .catch(() => {});

    // ── SKU별 판매 분석 (정렬/검색/limit) ──
    (function() {
        const tbody     = document.getElementById('pos-sku-tbody');
        const summaryEl = document.getElementById('pos-sku-summary');
        const searchEl  = document.getElementById('pos-sku-search');
        const limitEl   = document.getElementById('pos-sku-limit');
        const exportBtn = document.getElementById('pos-sku-export-btn');
        const heads     = document.querySelectorAll('.pos-sku-sortable');

        let state = { sort: 'net', dir: 'desc' };
        let lastRows = [];

        function esc(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function renderArrows() {
            heads.forEach(h => {
                const base = h.dataset.label || h.textContent.replace(/\s*[▲▼]$/, '');
                h.dataset.label = base;
                h.textContent = base + (h.dataset.sort === state.sort ? (state.dir === 'asc' ? ' ▲' : ' ▼') : '');
            });
        }

        function nf2(n) { return (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

        function renderRows(rows, totalNet) {
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">데이터가 없습니다.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const pcs   = parseFloat(r.total_pcs) || 0;
                const cost  = parseFloat(r.cur_unit_cost) || 0;
                const price = parseFloat(r.cur_selling_price) || 0;
                const net   = parseFloat(r.net_sales) || 0;
                const gp    = parseFloat(r.gross_profit) || 0;
                const pct   = totalNet > 0 ? net / totalNet * 100 : 0;
                return '<tr class="hover:bg-gray-50">' +
                    '<td class="px-3 py-1.5 font-mono text-gray-500 whitespace-nowrap">' + esc(r.item_code || '') + '</td>' +
                    '<td class="px-3 py-1.5 text-gray-700">' + esc(r.item_name || '') + '</td>' +
                    '<td class="px-3 py-1.5 text-gray-500 whitespace-nowrap">' + esc(r.department || '') + '</td>' +
                    '<td class="px-3 py-1.5 text-right font-mono text-gray-600">' + nf(r.tx_count) + '</td>' +
                    '<td class="px-3 py-1.5 text-right font-mono text-gray-600">' + nf(pcs) + '</td>' +
                    '<td class="px-3 py-1.5 text-right font-mono text-gray-500">' + nf2(cost) + '</td>' +
                    '<td class="px-3 py-1.5 text-right font-mono text-gray-500">' + nf2(price) + '</td>' +
                    '<td class="px-3 py-1.5 text-right font-bold font-mono text-blue-700">' + nf(net) + '</td>' +
                    '<td class="px-3 py-1.5 text-right font-mono text-gray-400">' + nf(pct) + '%</td>' +
                    '<td class="px-3 py-1.5 text-right font-mono text-green-600">' + nf(gp) + '</td>' +
                    '</tr>';
            }).join('');
        }

        function applySearch() {
            const kw = (searchEl.value || '').trim().toLowerCase();
            if (!kw) { renderRows(lastRows, window.__posSkuTotalNet || 0); return; }
            const filtered = lastRows.filter(r =>
                String(r.item_code || '').toLowerCase().includes(kw) ||
                String(r.item_name || '').toLowerCase().includes(kw) ||
                String(r.department || '').toLowerCase().includes(kw)
            );
            renderRows(filtered, window.__posSkuTotalNet || 0);
        }

        function updateExportLink() {
            const limit = limitEl.value;
            exportBtn.href = 'export_report_sku.php?' + qs + '&sort=' + state.sort + '&dir=' + state.dir + '&limit=' + encodeURIComponent(limit);
        }

        function load() {
            tbody.innerHTML = '<tr><td colspan="10" class="px-3 py-6 text-center text-gray-400"><i class="fa-solid fa-spinner fa-spin mr-1"></i>불러오는 중...</td></tr>';
            renderArrows();
            updateExportLink();
            const limit = limitEl.value;
            fetch('ajax_report_sku.php?' + qs + '&sort=' + state.sort + '&dir=' + state.dir + '&limit=' + encodeURIComponent(limit))
                .then(r => r.json())
                .then(res => {
                    if (!res.success) return;
                    lastRows = res.rows;
                    window.__posSkuTotalNet = res.totals.net;
                    summaryEl.textContent = 'SKU ' + nf(res.totals.sku_count) + '종 · 총 수량 ' + nf(res.totals.pcs) +
                        ' · 총 매출 ' + nf(res.totals.net) + (lastRows.length < res.totals.sku_count ? ' (상위 ' + lastRows.length + '건 표시)' : '');
                    applySearch();
                })
                .catch(() => {
                    tbody.innerHTML = '<tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">불러오기 실패</td></tr>';
                });
        }

        heads.forEach(h => {
            h.addEventListener('click', () => {
                const col = h.dataset.sort;
                if (state.sort === col) {
                    state.dir = state.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sort = col;
                    state.dir = 'desc';
                }
                load();
            });
        });
        limitEl.addEventListener('change', load);
        searchEl.addEventListener('input', applySearch);

        load();
    })();
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

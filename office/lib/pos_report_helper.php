<?php
// Design Ref: office/pos_data/report.php 성능 개선 — 화면(shell)은 즉시 렌더링하고
// 무거운 집계 쿼리는 ajax_report_*.php 가 이 파일의 함수를 통해 비동기로 채운다.
// report.php / ajax_report_meta.php / ajax_report_daily.php / ajax_report_dept.php /
// ajax_report_dow.php 는 이 파일의 함수를 통해서만 데이터에 접근한다.

// sale_date 포맷 감지 (Y-m-d, m/d/Y, m-d-Y 등 업로드 원본 포맷이 제각각이라 샘플로 판별)
function pos_report_detect_date_format(string $sample): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sample)) return 'Y-m-d';
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $sample)) return 'm/d/Y';
    if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $sample)) return 'm-d-Y';
    return 'Y-m-d';
}

// 점포의 sale_date 저장 포맷을 감지하고, 실제 DATE로 파싱하는 SQL 표현식을 만든다.
function pos_report_get_date_expr(mysqli $conn, int $store_id): array {
    $sample_row = $conn->query(
        "SELECT sale_date FROM pos_sales_data WHERE upload_id IN
         (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id}) LIMIT 1"
    )->fetch_assoc();
    $sample = $sample_row['sale_date'] ?? '';
    $fmt = pos_report_detect_date_format($sample);
    if ($fmt === 'Y-m-d') {
        $parsed_expr = 'd.sale_date';
    } else {
        $mysql_fmt = ($fmt === 'm/d/Y') ? '%c/%e/%Y' : '%c-%e-%Y';
        $parsed_expr = "STR_TO_DATE(d.sale_date, '{$mysql_fmt}')";
    }
    return ['fmt' => $fmt, 'parsed_expr' => $parsed_expr, 'sample' => $sample];
}

// 필터 기간을 SQL WHERE 조건으로 변환 (parsed_expr 은 항상 실제 DATE 값이므로 Y-m-d 문자열로 비교 가능)
function pos_report_date_cond(string $parsed_expr, string $date_from, string $date_to): string {
    return "{$parsed_expr} BETWEEN '{$date_from}' AND '{$date_to}'";
}

// 진단 정보(전체 건수/날짜범위/샘플) — 조회 기간과 무관하게 점포 전체 기준
function pos_report_get_diag(mysqli $conn, int $store_id): array {
    $diag = $conn->query(
        "SELECT COUNT(*) AS cnt, MIN(d.sale_date) AS min_date, MAX(d.sale_date) AS max_date,
                (SELECT sale_date FROM pos_sales_data WHERE upload_id IN
                 (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id}) LIMIT 1) AS sample
         FROM pos_sales_data d
         WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})"
    )->fetch_assoc();
    $diag['date_fmt'] = !empty($diag['sample']) ? pos_report_detect_date_format($diag['sample']) : 'Y-m-d';
    return $diag;
}

// 부서 목록 (필터 드롭다운용) — 점포 전체 기준
function pos_report_get_dept_list(mysqli $conn, int $store_id): array {
    $dept_list = [];
    $dr = $conn->query(
        "SELECT DISTINCT d.department FROM pos_sales_data d
         WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})
           AND d.department IS NOT NULL AND d.department != ''
         ORDER BY d.department ASC"
    );
    while ($row = $dr->fetch_row()) $dept_list[] = $row[0];
    return $dept_list;
}

// 일별 집계 + 합계 + 차트용 데이터
function pos_report_get_daily(mysqli $conn, int $store_id, string $date_from, string $date_to, string $dept_filter): array {
    $expr      = pos_report_get_date_expr($conn, $store_id);
    $date_cond = pos_report_date_cond($expr['parsed_expr'], $date_from, $date_to);

    $dept_cond = '';
    if ($dept_filter !== '') {
        $dept_cond = " AND d.department = '" . $conn->real_escape_string($dept_filter) . "'";
    }

    $rows = $conn->query(
        "SELECT
            d.sale_date,
            {$expr['parsed_expr']}           AS parsed_date,
            COUNT(DISTINCT d.si_no)          AS tx_count,
            SUM(d.pcs)                       AS total_pcs,
            SUM(d.net_sales)                 AS net_sales,
            SUM(d.gross_profit)              AS gross_profit,
            SUM(d.total_cost)                AS total_cost,
            SUM(d.discount)                  AS discount,
            SUM(d.sc_discount)               AS sc_discount,
            SUM(d.pwd_discount)              AS pwd_discount
         FROM pos_sales_data d
         WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})
           AND {$date_cond}
           {$dept_cond}
         GROUP BY d.sale_date
         ORDER BY d.sale_date ASC"
    )->fetch_all(MYSQLI_ASSOC);

    $sum_net  = array_sum(array_column($rows, 'net_sales'));
    $sum_gp   = array_sum(array_column($rows, 'gross_profit'));
    $sum_cost = array_sum(array_column($rows, 'total_cost'));
    $sum_disc = array_sum(array_column($rows, 'discount'));
    $sum_tx   = array_sum(array_column($rows, 'tx_count'));
    $sum_pcs  = array_sum(array_column($rows, 'total_pcs'));

    return [
        'rows'  => $rows,
        'sums'  => [
            'net' => $sum_net, 'gp' => $sum_gp, 'cost' => $sum_cost,
            'disc' => $sum_disc, 'tx' => $sum_tx, 'pcs' => $sum_pcs,
        ],
    ];
}

// 부서별 집계 (선택된 기간, department 필터와 무관하게 전체 부서 breakdown)
function pos_report_get_dept_breakdown(mysqli $conn, int $store_id, string $date_from, string $date_to): array {
    $expr      = pos_report_get_date_expr($conn, $store_id);
    $date_cond = pos_report_date_cond($expr['parsed_expr'], $date_from, $date_to);

    return $conn->query(
        "SELECT
            d.department,
            SUM(d.net_sales) AS net_sales
         FROM pos_sales_data d
         WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})
           AND {$date_cond}
         GROUP BY d.department
         ORDER BY net_sales DESC"
    )->fetch_all(MYSQLI_ASSOC);
}

// 요일별 3개월 평균 매출 (선택된 기간 필터와 무관 — 최근 3개월 고정 기준, 영업 참고용)
function pos_report_get_dow_averages(mysqli $conn, int $store_id): array {
    $expr = pos_report_get_date_expr($conn, $store_id);

    $dow_rows = $conn->query(
        "SELECT DAYOFWEEK(t.d_parsed) AS dow, AVG(t.day_total) AS avg_net
         FROM (
            SELECT {$expr['parsed_expr']} AS d_parsed, SUM(d.net_sales) AS day_total
            FROM pos_sales_data d
            WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})
              AND {$expr['parsed_expr']} >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
            GROUP BY {$expr['parsed_expr']}
         ) t
         GROUP BY DAYOFWEEK(t.d_parsed)"
    )->fetch_all(MYSQLI_ASSOC);

    // MySQL DAYOFWEEK(): 1=Sun,2=Mon,...,7=Sat → 월~일 순으로 재정렬
    $dow_map = [];
    foreach ($dow_rows as $r) { $dow_map[(int)$r['dow']] = round((float)$r['avg_net'], 2); }
    $dow_order  = [2, 3, 4, 5, 6, 7, 1];
    $dow_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    return [
        'labels' => $dow_labels,
        'data'   => array_map(fn($d) => $dow_map[$d] ?? 0, $dow_order),
    ];
}

<?php
/**
 * barcode_helper.php — 상품 바코드 중복 검증 공용 라이브러리
 *
 * 상품 마스터(kw_products) 입력 루틴이 여러 곳(product_add.php, ajax/add_product.php,
 * product_edit.php, 입고 화면 빠른 등록 모달)에 분산되어 있어 동일 바코드가 서로 다른
 * 상품에 중복 등록되는 문제가 있었다. 세 개의 바코드 컬럼(barcode_unit / barcode_box /
 * barcode_logistics)에는 UNIQUE 제약이 없으므로 애플리케이션 레벨에서 교차 검증한다.
 *
 * 교차 검증: 입력된 값이 다른 상품의 3개 컬럼 중 '어느 하나'와 일치하면 중복으로 판정한다.
 * (바코드 검색이 3개 컬럼을 모두 대상으로 하므로, 검색 의미론과 일치시킨다.)
 */

/**
 * 입력 바코드 값들이 이미 다른 상품에 등록되어 있는지 검사한다.
 *
 * @param mysqli   $conn                DB 커넥션
 * @param array    $barcodes            검사할 바코드 값 배열(null/빈문자열은 무시)
 * @param int      $exclude_product_id  수정 시 자기 자신 제외(신규 등록은 0)
 * @return array   충돌 목록:
 *                 [['barcode'=>값, 'product_id'=>id, 'name_en'=>..., 'name_ko'=>..., 'column'=>컬럼], ...]
 *                 빈 배열이면 중복 없음.
 */
function kw_find_barcode_conflicts(mysqli $conn, array $barcodes, int $exclude_product_id = 0): array
{
    // 빈값 제거 + 중복 값 정규화
    $vals = [];
    foreach ($barcodes as $b) {
        $b = trim((string)$b);
        if ($b !== '') {
            $vals[$b] = true;
        }
    }
    if (empty($vals)) {
        return [];
    }
    $vals = array_keys($vals);

    // 3개 컬럼 각각에 대해 IN(...) 조회
    $ph     = implode(',', array_fill(0, count($vals), '?'));
    $sql    = "SELECT id, name_en, name_ko, barcode_unit, barcode_box, barcode_logistics
               FROM kw_products
               WHERE (barcode_unit IN ($ph)
                   OR barcode_box IN ($ph)
                   OR barcode_logistics IN ($ph))";
    $types  = str_repeat('s', count($vals) * 3);
    $params = array_merge($vals, $vals, $vals);

    if ($exclude_product_id > 0) {
        $sql   .= " AND id <> ?";
        $types .= 'i';
        $params[] = $exclude_product_id;
    }

    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    // 어느 컬럼에서 일치했는지 표기
    $valset    = array_flip($vals);
    $conflicts = [];
    foreach ($rows as $r) {
        foreach (['barcode_unit', 'barcode_box', 'barcode_logistics'] as $col) {
            $cv = trim((string)($r[$col] ?? ''));
            if ($cv !== '' && isset($valset[$cv])) {
                $conflicts[] = [
                    'barcode'    => $cv,
                    'product_id' => (int)$r['id'],
                    'name_en'    => $r['name_en'],
                    'name_ko'    => $r['name_ko'],
                    'column'     => $col,
                ];
            }
        }
    }
    return $conflicts;
}

/**
 * 충돌 목록을 사용자용 안내 메시지 한 줄로 변환한다.
 *
 * @param array $conflicts kw_find_barcode_conflicts() 반환값
 * @return string
 */
function kw_format_barcode_conflict_msg(array $conflicts): string
{
    $parts = [];
    foreach ($conflicts as $c) {
        $label = $c['name_en'];
        if (!empty($c['name_ko'])) {
            $label .= ' (' . $c['name_ko'] . ')';
        }
        $parts[] = "Barcode '{$c['barcode']}' is already registered to product [#{$c['product_id']}] {$label}.";
    }
    // 동일 메시지 중복 제거
    return implode(' ', array_values(array_unique($parts)));
}

<?php
// Design Ref: box-pcs-unit.design.md §4.3 — 단위(BOX/PCS) 로직 일원화 헬퍼
// 단위 분기·환산·표기·단위별 FEFO를 이 파일에만 둔다. 페이지에 인라인 작성 금지.
//
// 유효 단가 규칙 (Design §3.2):
//   lot unit=BOX                          → kw_inbound.cost_price (BOX 단가)
//   lot unit=PCS, inbound_unit=PCS        → kw_inbound.cost_price
//   lot unit=PCS, inbound_unit=BOX (개봉 파생 lot) → kw_inbound.cost_price_pcs

const LC_UNIT_BOX  = 'BOX';
const LC_UNIT_PACK = 'PACK'; // Design Ref: pack-unit §4.1 — BOX와 동급 묶음 단위
const LC_UNIT_PCS  = 'PCS';

// 개봉 가능한 묶음 단위(ppb개 PCS로 분해). PCS는 최소 단위(개봉 불가).
const LC_BUNDLE_UNITS = [LC_UNIT_BOX, LC_UNIT_PACK];
// 전체 단위 (집계/표기 루프용)
const LC_ALL_UNITS    = [LC_UNIT_BOX, LC_UNIT_PACK, LC_UNIT_PCS];

// FEFO/평균원가 쿼리 공용 유효단가 CASE식 (i=kw_inventory, b=kw_inbound 별칭 가정)
// Design Ref: pack-unit §4.1 — 개봉 파생 PCS lot 판정에 PACK 포함
const LC_EFFECTIVE_COST_SQL =
    "CASE WHEN i.unit = 'PCS' AND b.inbound_unit IN ('BOX','PACK') THEN b.cost_price_pcs ELSE b.cost_price END";

/**
 * 묶음 단위(BOX/PACK) 여부. 개봉 가능 판정에 사용.
 * Design Ref: pack-unit §4.2
 */
function kw_is_bundle_unit(?string $unit): bool {
    return in_array(strtoupper(trim((string)$unit)), LC_BUNDLE_UNITS, true);
}

/**
 * 상품 unit 값 → 'BOX' | 'PACK' | 'PCS' 정규화.
 * 'BOX'/'박스' → BOX, 'PACK'/'팩' → PACK, 그 외(EA, 개, kg, NULL 등) → PCS
 * Design Ref: pack-unit §4.2
 */
function kw_normalize_unit(?string $product_unit): string {
    $u = strtoupper(trim((string)$product_unit));
    if ($u === 'BOX'  || $u === '박스') return LC_UNIT_BOX;
    if ($u === 'PACK' || $u === '팩')   return LC_UNIT_PACK;
    return LC_UNIT_PCS;
}

/**
 * 입력 단위 검증: 'BOX'/'PACK'/'PCS'만 허용, 그 외는 기본값 반환.
 */
function kw_valid_unit(?string $unit, string $default = LC_UNIT_PCS): string {
    $u = strtoupper(trim((string)$unit));
    return in_array($u, LC_ALL_UNITS, true) ? $u : $default;
}

/**
 * PCS 환산 원가. ppb < 1 은 1로 보정 (Plan FR-11).
 */
function kw_pcs_cost(float $box_cost, int $ppb): float {
    return round($box_cost / max(1, $ppb), 4);
}

/**
 * 입고 행의 ppb(박스당 PCS 수량)를 결정한다.
 * Design Ref: inbound-ppb-override §3.1 — 행별 ppb 오버라이드, 마스터값은 폴백.
 * 클라이언트가 전송한 값을 우선 사용하고, 비정상 값(빈 값/1 미만)이면 상품 마스터 값으로 폴백한다.
 * 두 경우 모두 최소 1로 보정한다.
 */
function kw_resolve_row_ppb($posted, $fallback): int {
    $fallback = max(1, (int)($fallback ?? 1));
    if ($posted === null || $posted === '') {
        return $fallback;
    }
    $val = (int)$posted;
    return $val >= 1 ? $val : $fallback;
}

/**
 * 단위별 재고 집계 → ['BOX' => int, 'PCS' => int]
 */
function kw_get_stock_by_unit(mysqli $conn, int $product_id): array {
    $stock = array_fill_keys(LC_ALL_UNITS, 0); // BOX/PACK/PCS 3키 초기화
    $st = $conn->prepare(
        "SELECT unit, COALESCE(SUM(quantity_remain), 0) AS remain
         FROM kw_inventory
         WHERE product_id = ?
         GROUP BY unit"
    );
    $st->bind_param('i', $product_id);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $stock[$row['unit']] = (int)$row['remain'];
    }
    $st->close();
    return $stock;
}

/**
 * 단위별 유효단가 가중평균 → ['BOX' => float, 'PCS' => float]
 * (재고 잔량 > 0 lot 기준)
 */
function kw_get_avg_cost_by_unit(mysqli $conn, int $product_id): array {
    $cost = array_fill_keys(LC_ALL_UNITS, 0.0); // BOX/PACK/PCS 3키 초기화
    $st = $conn->prepare(
        "SELECT i.unit,
                COALESCE(SUM(i.quantity_remain * " . LC_EFFECTIVE_COST_SQL . ")
                         / NULLIF(SUM(i.quantity_remain), 0), 0) AS avg_cost
         FROM kw_inventory i
         JOIN kw_inbound b ON i.inbound_id = b.id
         WHERE i.product_id = ? AND i.quantity_remain > 0
         GROUP BY i.unit"
    );
    $st->bind_param('i', $product_id);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $cost[$row['unit']] = (float)$row['avg_cost'];
    }
    $st->close();
    return $cost;
}

/**
 * 재고 표기: "5 BOX + 3 PACK + 12 PCS" / "3 PACK" / "0"
 * Design Ref: pack-unit §4.3 — LC_ALL_UNITS 루프로 단위 확장 대응
 */
function kw_format_stock(array $stock_by_unit): string {
    $parts = [];
    foreach (LC_ALL_UNITS as $u) {
        if (!empty($stock_by_unit[$u])) $parts[] = number_format($stock_by_unit[$u]) . ' ' . $u;
    }
    return $parts ? implode(' + ', $parts) : '0';
}

/**
 * Design Ref: §4.3 — 단위 필터 FEFO 출고 (음수 허용).
 * kw_fifo_ship_allow_negative()의 단위 버전: WHERE i.unit = $unit 인 lot에서만 차감하고
 * 유효단가(LC_EFFECTIVE_COST_SQL)를 cost_price로 반환한다. 트랜잭션 내에서만 호출할 것.
 * Plan SC-5: BOX 주문 → BOX lot만, PCS 주문 → PCS lot만 차감
 */
function kw_unit_fifo_ship_allow_negative(mysqli $conn, int $product_id, int $qty_needed, string $unit): array {
    $unit = kw_valid_unit($unit);

    $stmt = $conn->prepare(
        "SELECT i.id AS inventory_id, i.quantity_remain, i.storage_location,
                i.lot_number, i.expiry_date, b.id AS inbound_id,
                " . LC_EFFECTIVE_COST_SQL . " AS cost_price
         FROM kw_inventory i
         JOIN kw_inbound b ON i.inbound_id = b.id
         WHERE i.product_id = ? AND i.unit = ? AND i.quantity_remain > 0
         ORDER BY (i.expiry_date IS NULL), i.expiry_date ASC, i.id ASC
         FOR UPDATE"
    );
    $stmt->bind_param('is', $product_id, $unit);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $remaining  = $qty_needed;
    $deductions = [];
    foreach ($lots as $lot) {
        if ($remaining <= 0) break;
        $deduct = min($remaining, (int)$lot['quantity_remain']);
        $upd = $conn->prepare("UPDATE kw_inventory SET quantity_out = quantity_out + ? WHERE id = ?");
        $upd->bind_param('ii', $deduct, $lot['inventory_id']);
        $upd->execute();
        $upd->close();
        $deductions[] = [
            'inventory_id'     => (int)$lot['inventory_id'],
            'inbound_id'       => (int)$lot['inbound_id'],
            'quantity'         => $deduct,
            'cost_price'       => (float)$lot['cost_price'],
            'storage_location' => $lot['storage_location'] ?? null,
            'lot_number'       => $lot['lot_number'] ?? null,
            'expiry_date'      => $lot['expiry_date'] ?? null,
        ];
        $remaining -= $deduct;
    }

    if ($remaining > 0) {
        // 부족분: 동일 단위의 가장 최근 lot에서 음수 차감 (v16 음수재고 정책의 단위 버전)
        $stmt = $conn->prepare(
            "SELECT i.id AS inventory_id, i.storage_location, i.lot_number, i.expiry_date,
                    b.id AS inbound_id,
                    " . LC_EFFECTIVE_COST_SQL . " AS cost_price
             FROM kw_inventory i
             JOIN kw_inbound b ON i.inbound_id = b.id
             WHERE i.product_id = ? AND i.unit = ?
             ORDER BY i.id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->bind_param('is', $product_id, $unit);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($lot) {
            $upd = $conn->prepare("UPDATE kw_inventory SET quantity_out = quantity_out + ? WHERE id = ?");
            $upd->bind_param('ii', $remaining, $lot['inventory_id']);
            $upd->execute();
            $upd->close();
            $deductions[] = [
                'inventory_id'     => (int)$lot['inventory_id'],
                'inbound_id'       => (int)$lot['inbound_id'],
                'quantity'         => $remaining,
                'cost_price'       => (float)$lot['cost_price'],
                'storage_location' => $lot['storage_location'] ?? null,
                'lot_number'       => $lot['lot_number'] ?? null,
                'expiry_date'      => $lot['expiry_date'] ?? null,
            ];
        } else {
            // 해당 단위 lot이 전혀 없는 상품: 조정용 lot 생성 후 음수 차감 (단위 지정)
            $cost_price = 0.0;
            $today = date('Y-m-d');
            $ins = $conn->prepare(
                "INSERT INTO kw_inbound (inbound_date, product_id, lot_number, quantity, inbound_unit, pieces_per_box, cost_price, cost_price_pcs)
                 VALUES (?, ?, 'ADJUST', 0, ?, 1, ?, ?)"
            );
            $ins->bind_param('sisdd', $today, $product_id, $unit, $cost_price, $cost_price);
            $ins->execute();
            $inbound_id = $conn->insert_id;
            $ins->close();

            $insInv = $conn->prepare(
                "INSERT INTO kw_inventory (inbound_id, product_id, unit, lot_number, quantity_in, quantity_out)
                 VALUES (?, ?, ?, 'ADJUST', 0, ?)"
            );
            $insInv->bind_param('iisi', $inbound_id, $product_id, $unit, $remaining);
            $insInv->execute();
            $inventory_id = $conn->insert_id;
            $insInv->close();

            $deductions[] = [
                'inventory_id'     => $inventory_id,
                'inbound_id'       => (int)$inbound_id,
                'quantity'         => $remaining,
                'cost_price'       => $cost_price,
                'storage_location' => null,
                'lot_number'       => 'ADJUST',
                'expiry_date'      => null,
            ];
        }
    }

    return $deductions;
}

/**
 * Design Ref: §4.3 — 단위 필터 FEFO 피킹 미리보기 (읽기 전용, 음수재고 허용).
 * 반환: ['picks'=>[], 'shortfall'=>int, 'suggest_break'=>bool]
 * Plan FR-06: PCS 부족 + BOX 재고 보유 시 suggest_break=true → "박스 개봉 필요" 안내
 */
function kw_unit_fefo_preview_allow_negative(mysqli $conn, int $product_id, int $qty_needed, string $unit): array {
    $unit = kw_valid_unit($unit);

    $stmt = $conn->prepare(
        "SELECT i.id AS inventory_id, i.quantity_remain, i.lot_number, i.expiry_date, i.storage_location
         FROM kw_inventory i
         JOIN kw_inbound ib ON i.inbound_id = ib.id
         WHERE i.product_id = ? AND i.unit = ? AND i.quantity_remain > 0
         ORDER BY (i.expiry_date IS NULL), i.expiry_date ASC, i.id ASC"
    );
    $stmt->bind_param('is', $product_id, $unit);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $remaining = $qty_needed;
    $picks = [];
    foreach ($lots as $lot) {
        if ($remaining <= 0) break;
        $take = min($remaining, (int)$lot['quantity_remain']);
        $picks[] = [
            'inventory_id'     => (int)$lot['inventory_id'],
            'lot_number'       => $lot['lot_number'],
            'expiry_date'      => $lot['expiry_date'],
            'storage_location' => $lot['storage_location'],
            'quantity'         => $take,
        ];
        $remaining -= $take;
    }

    // PCS 부족 + 묶음(BOX/PACK) 재고 보유 → 개봉 제안 (자동 개봉 없음 — Plan 결정)
    // Design Ref: pack-unit §4.2 — suggest_break 판정에 PACK 포함
    $suggest_break = false;
    if ($remaining > 0 && $unit === LC_UNIT_PCS) {
        $stock = kw_get_stock_by_unit($conn, $product_id);
        $suggest_break = ($stock[LC_UNIT_BOX] > 0 || $stock[LC_UNIT_PACK] > 0);
    }

    return ['picks' => $picks, 'shortfall' => $remaining, 'suggest_break' => $suggest_break];
}

/**
 * Design Ref: §4.2 — 박스 개봉 트랜잭션 (검증 + BOX 차감 + PCS lot 생성 + 이력 기록).
 * Plan SC-4: pcs_created = boxes×ppb − damaged. 전량 파손 시 PCS lot 미생성(new_inventory_id NULL).
 *
 * 호출자가 트랜잭션을 관리한다 (autocommit=false 상태에서 호출, 성공 시 commit / 실패 시 rollback).
 * 모든 검증은 쓰기 이전에 수행되므로 실패 반환 시 DB 변경 없음.
 *
 * 반환: ['success'=>bool, 'message'=>string?, 'break_id'=>int?, 'pcs_created'=>int?,
 *        'damage_cost'=>float?, 'new_inventory_id'=>int|null]
 */
function kw_execute_box_break(mysqli $conn, int $inventory_id, int $boxes, int $damaged, string $notes = '', ?int $user_id = null): array {
    if (!$inventory_id || $boxes <= 0) {
        return ['success' => false, 'message' => '잘못된 요청입니다.'];
    }
    if ($damaged < 0) {
        return ['success' => false, 'message' => '파손 수량이 올바르지 않습니다.'];
    }

    // 1) BOX lot 잠금 + 검증 (Design §7 동시성: SELECT ... FOR UPDATE)
    $st = $conn->prepare(
        "SELECT i.id, i.product_id, i.unit, i.lot_number, i.expiry_date, i.storage_location,
                i.quantity_remain, i.inbound_id,
                GREATEST(1, b.pieces_per_box) AS ppb,
                IF(b.cost_price_pcs > 0, b.cost_price_pcs, b.cost_price / GREATEST(1, b.pieces_per_box)) AS pcs_cost
         FROM kw_inventory i
         JOIN kw_inbound b ON i.inbound_id = b.id
         WHERE i.id = ?
         FOR UPDATE"
    );
    $st->bind_param('i', $inventory_id);
    $st->execute();
    $lot = $st->get_result()->fetch_assoc();
    $st->close();

    // Design Ref: pack-unit §4.4 — 묶음 단위(BOX/PACK) lot만 개봉 가능. 산출물은 항상 PCS.
    if (!$lot || !kw_is_bundle_unit($lot['unit'])) {
        return ['success' => false, 'message' => '개봉 가능한 묶음(BOX/PACK) 재고가 아닙니다.'];
    }
    if ((int)$lot['quantity_remain'] < $boxes) {
        return ['success' => false, 'message' => '잔여 박스(' . $lot['quantity_remain'] . ')보다 많이 개봉할 수 없습니다.'];
    }

    $ppb = (int)$lot['ppb'];
    $max_pcs = $boxes * $ppb;
    if ($damaged > $max_pcs) {
        return ['success' => false, 'message' => '파손 수량이 개봉 낱개 수(' . $max_pcs . ')를 초과합니다.'];
    }

    // 2) BOX lot 차감
    $st = $conn->prepare("UPDATE kw_inventory SET quantity_out = quantity_out + ? WHERE id = ?");
    $st->bind_param('ii', $boxes, $inventory_id);
    $st->execute();
    $st->close();

    // 3) PCS lot 생성 — inbound_id·lot번호·유통기한·위치 승계 (Design §3.3)
    $pcs_created = $max_pcs - $damaged;
    $new_inventory_id = null;
    if ($pcs_created > 0) {
        $st = $conn->prepare(
            "INSERT INTO kw_inventory (inbound_id, product_id, unit, lot_number, expiry_date, storage_location, quantity_in, quantity_out)
             VALUES (?, ?, 'PCS', ?, ?, ?, ?, 0)"
        );
        $st->bind_param('iisssi',
            $lot['inbound_id'], $lot['product_id'], $lot['lot_number'],
            $lot['expiry_date'], $lot['storage_location'], $pcs_created
        );
        $st->execute();
        $new_inventory_id = $conn->insert_id;
        $st->close();
    }

    // 4) 개봉/파손 이력 기록 (FR-08: damage_cost = damaged × PCS단가)
    $damage_cost = round($damaged * (float)$lot['pcs_cost'], 4);
    $st = $conn->prepare(
        "INSERT INTO kw_box_breaks
         (product_id, source_inventory_id, new_inventory_id, boxes_opened, pieces_per_box, pcs_created, damaged_qty, damage_cost, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $st->bind_param('iiiiiiidsi',
        $lot['product_id'], $inventory_id, $new_inventory_id,
        $boxes, $ppb, $pcs_created, $damaged, $damage_cost, $notes, $user_id
    );
    $st->execute();
    $break_id = $conn->insert_id;
    $st->close();

    return [
        'success'          => true,
        'break_id'         => $break_id,
        'pcs_created'      => $pcs_created,
        'damage_cost'      => $damage_cost,
        'new_inventory_id' => $new_inventory_id,
    ];
}

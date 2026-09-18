<?php
// Design Ref: §4.3 — FEFO 출고 핵심 로직. 트랜잭션 내에서만 호출할 것.

/**
 * FIFO 방식으로 재고 차감.
 */
function lc_fifo_deduct(mysqli $conn, int $product_id, int $qty_needed): bool {
    $stmt = $conn->prepare(
        "SELECT i.id, i.quantity_remain
         FROM lc_inventory i
         JOIN lc_inbound ib ON i.inbound_id = ib.id
         WHERE i.product_id = ? AND i.quantity_remain > 0
         ORDER BY i.expiry_date ASC, i.id ASC
         FOR UPDATE"
    );
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $total = array_sum(array_column($lots, 'quantity_remain'));
    if ($total < $qty_needed) return false;

    $remaining = $qty_needed;
    foreach ($lots as $lot) {
        if ($remaining <= 0) break;
        $deduct = min($remaining, (int)$lot['quantity_remain']);
        $upd = $conn->prepare("UPDATE lc_inventory SET quantity_out = quantity_out + ? WHERE id = ?");
        $upd->bind_param('ii', $deduct, $lot['id']);
        $upd->execute();
        $upd->close();
        $remaining -= $deduct;
    }
    return true;
}

/**
 * FEFO 출고 + lot 원가 기록.
 */
function lc_fifo_ship(mysqli $conn, int $product_id, int $qty_needed): array|false {
    $stmt = $conn->prepare(
        "SELECT i.id AS inventory_id, i.quantity_remain, i.storage_location,
                i.lot_number, i.expiry_date, b.cost_price, b.id AS inbound_id
         FROM lc_inventory i
         JOIN lc_inbound b ON i.inbound_id = b.id
         WHERE i.product_id = ? AND i.quantity_remain > 0
         ORDER BY i.expiry_date ASC, i.id ASC
         FOR UPDATE"
    );
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (array_sum(array_column($lots, 'quantity_remain')) < $qty_needed) return false;

    $remaining  = $qty_needed;
    $deductions = [];
    foreach ($lots as $lot) {
        if ($remaining <= 0) break;
        $deduct = min($remaining, (int)$lot['quantity_remain']);
        $upd = $conn->prepare("UPDATE lc_inventory SET quantity_out = quantity_out + ? WHERE id = ?");
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
    return $deductions;
}

/**
 * Design Ref: §3.3 — 음수 재고를 허용하는 FEFO 출고.
 * lc_fifo_ship()과 동일하게 만료일 ASC로 정상 차감하되, 재고가 부족해도 false를
 * 반환하지 않고 부족분(shortfall)을 마지막 lot(없으면 신규 조정용 lot)에서
 * 음수로 차감하여 항상 quantity 합계 == $qty_needed 인 배열을 반환한다.
 * Plan SC: 재고 부족 상품도 정상 등록되고 quantity_remain이 음수가 됨
 */
function lc_fifo_ship_allow_negative(mysqli $conn, int $product_id, int $qty_needed): array {
    $stmt = $conn->prepare(
        "SELECT i.id AS inventory_id, i.quantity_remain, i.storage_location,
                i.lot_number, i.expiry_date, b.cost_price, b.id AS inbound_id
         FROM lc_inventory i
         JOIN lc_inbound b ON i.inbound_id = b.id
         WHERE i.product_id = ? AND i.quantity_remain > 0
           AND i.id NOT IN (SELECT inventory_id FROM lc_lot_promotions WHERE status = 'active')
         ORDER BY i.expiry_date ASC, i.id ASC
         FOR UPDATE"
    );
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $remaining  = $qty_needed;
    $deductions = [];
    foreach ($lots as $lot) {
        if ($remaining <= 0) break;
        $deduct = min($remaining, (int)$lot['quantity_remain']);
        $upd = $conn->prepare("UPDATE lc_inventory SET quantity_out = quantity_out + ? WHERE id = ?");
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
        // 재고 초과분: 해당 상품의 가장 최근 lot(quantity_remain 무관)에서 음수 차감
        $stmt = $conn->prepare(
            "SELECT i.id AS inventory_id, i.storage_location, i.lot_number, i.expiry_date,
                    b.cost_price, b.id AS inbound_id
             FROM lc_inventory i
             JOIN lc_inbound b ON i.inbound_id = b.id
             WHERE i.product_id = ?
             ORDER BY i.id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $lot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($lot) {
            $upd = $conn->prepare("UPDATE lc_inventory SET quantity_out = quantity_out + ? WHERE id = ?");
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
            // 입고 이력이 전혀 없는 상품: 조정용 lot(quantity_in=0, cost_price=0)을 생성해 음수로 차감
            $cost_price = 0.0;

            $today = date('Y-m-d');
            $ins = $conn->prepare(
                "INSERT INTO lc_inbound (inbound_date, product_id, lot_number, quantity, cost_price)
                 VALUES (?, ?, 'ADJUST', 0, ?)"
            );
            $ins->bind_param('sid', $today, $product_id, $cost_price);
            $ins->execute();
            $inbound_id = $conn->insert_id;
            $ins->close();

            $insInv = $conn->prepare(
                "INSERT INTO lc_inventory (inbound_id, product_id, lot_number, quantity_in, quantity_out)
                 VALUES (?, ?, 'ADJUST', 0, ?)"
            );
            $insInv->bind_param('iii', $inbound_id, $product_id, $remaining);
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
 * 프로모션 지정 주문 항목의 LOT 차감. 프로모션 LOT 잔량까지는 할인가로,
 * 초과분은 같은 상품의 일반 FEFO 재고에서 정상가로 자동 분할 차감한다(FR-12).
 * 프로모션 LOT 자체는 음수 차감을 허용하지 않는다(잔량 한도까지만).
 * lc_fifo_ship_allow_negative()와 동일한 shape의 배열을 반환한다.
 */
function lc_ship_promo_lot(mysqli $conn, int $product_id, int $promotion_id, int $qty_needed): array {
    $stmt = $conn->prepare("SELECT * FROM lc_lot_promotions WHERE id = ? AND status = 'active' FOR UPDATE");
    $stmt->bind_param('i', $promotion_id);
    $stmt->execute();
    $promo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$promo) {
        throw new Exception("프로모션(#{$promotion_id})이 더 이상 유효하지 않습니다.");
    }
    if ((int)$promo['product_id'] !== $product_id) {
        // 클라이언트가 보낸 product_id[]가 promotion_id가 실제로 가리키는 상품과 다름 — 조작/불일치 방지
        throw new Exception("프로모션(#{$promotion_id})이 요청한 상품과 일치하지 않습니다.");
    }

    $stmt = $conn->prepare(
        "SELECT i.id AS inventory_id, i.quantity_remain, i.storage_location, i.lot_number, i.expiry_date, b.id AS inbound_id
         FROM lc_inventory i JOIN lc_inbound b ON i.inbound_id = b.id
         WHERE i.id = ? FOR UPDATE"
    );
    $stmt->bind_param('i', $promo['inventory_id']);
    $stmt->execute();
    $lot = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $deductions = [];
    $promo_available = (int)($lot['quantity_remain'] ?? 0);
    $promo_ship_qty = max(0, min($promo_available, $qty_needed));

    if ($promo_ship_qty > 0) {
        $upd = $conn->prepare("UPDATE lc_inventory SET quantity_out = quantity_out + ? WHERE id = ?");
        $upd->bind_param('ii', $promo_ship_qty, $lot['inventory_id']);
        $upd->execute();
        $upd->close();

        $deductions[] = [
            'inventory_id'     => (int)$lot['inventory_id'],
            'inbound_id'       => (int)$lot['inbound_id'],
            'quantity'         => $promo_ship_qty,
            'cost_price'       => (float)$promo['discounted_price'],
            'storage_location' => $lot['storage_location'] ?? null,
            'lot_number'       => $lot['lot_number'] ?? null,
            'expiry_date'      => $lot['expiry_date'] ?? null,
        ];
    }

    $remaining = $qty_needed - $promo_ship_qty;
    if ($remaining > 0) {
        // FR-12: 초과분은 같은 상품의 일반 FEFO 재고에서 정상가로 (프로모션 LOT은 이미 소진되어 자동 제외됨)
        $fefo = lc_fifo_ship_allow_negative($conn, $product_id, $remaining);
        $deductions = array_merge($deductions, $fefo);
    }

    return $deductions;
}

/**
 * 대책 C — 주문 접수(store/order.php) 시점 재고 차감(예약).
 * 주문의 모든 항목을 FEFO로 차감하고 lc_order_item_lots에 lot 내역을 기록한다.
 * $set_cost=true 이면 차감된 lot의 가중평균 원가로 unit_price를 갱신(접수/승인 시).
 * $set_cost=false 이면 unit_price는 그대로 두고 재고/lot만 재배정(수량 수정 시).
 * 반드시 트랜잭션 내에서 호출. 총액(total_amount) 재계산은 호출측에서 수행.
 */
function lc_allocate_order_stock(mysqli $conn, int $order_id, bool $set_cost = true): void {
    $order_id = (int)$order_id;
    $res = $conn->query(
        "SELECT id, product_id, quantity, promotion_id FROM lc_order_items WHERE order_id = $order_id"
    );
    if ($res === false) {
        throw new Exception('lc_allocate_order_stock: order_items 조회 실패 - ' . $conn->error);
    }
    $items = $res->fetch_all(MYSQLI_ASSOC);

    $ins_lot   = $conn->prepare(
        "INSERT INTO lc_order_item_lots (order_item_id, inventory_id, inbound_id, quantity, cost_price)
         VALUES (?,?,?,?,?)"
    );
    $upd_price = $set_cost ? $conn->prepare("UPDATE lc_order_items SET unit_price = ? WHERE id = ?") : null;

    foreach ($items as $item) {
        $item_id = (int)$item['id'];
        // 재고 부족 시에도 음수 재고로 차감 (기존 출고 로직과 동일)
        $promotion_id = isset($item['promotion_id']) && $item['promotion_id'] !== null ? (int)$item['promotion_id'] : 0;
        $lots = $promotion_id > 0
            ? lc_ship_promo_lot($conn, (int)$item['product_id'], $promotion_id, (int)$item['quantity'])
            : lc_fifo_ship_allow_negative($conn, (int)$item['product_id'], (int)$item['quantity']);

        if ($set_cost) {
            $total_cost = array_sum(array_map(fn($l) => $l['quantity'] * $l['cost_price'], $lots));
            $total_qty  = array_sum(array_column($lots, 'quantity'));
            $avg_cost   = $total_qty > 0 ? round($total_cost / $total_qty, 4) : 0.0;
            $upd_price->bind_param('di', $avg_cost, $item_id);
            $upd_price->execute();
        }

        foreach ($lots as $lot) {
            $ins_lot->bind_param('iiiid',
                $item_id, $lot['inventory_id'], $lot['inbound_id'],
                $lot['quantity'], $lot['cost_price']
            );
            $ins_lot->execute();
        }
    }
    if ($upd_price) { $upd_price->close(); }
    $ins_lot->close();
}

/**
 * 대책 C — 차감(예약) 복원.
 * 주문에 기록된 lot 기준으로 quantity_out을 되돌리고 lot 내역을 삭제한다.
 * 차감 이력(lot)이 없으면(예: 전환 이전 생성된 레거시 pending 주문) 아무 일도 하지 않는다.
 * 반드시 트랜잭션 내에서 호출.
 */
function lc_restore_order_stock(mysqli $conn, int $order_id): void {
    $order_id = (int)$order_id;
    $item_ids = array_column(
        $conn->query("SELECT id FROM lc_order_items WHERE order_id = $order_id")->fetch_all(MYSQLI_ASSOC),
        'id'
    );
    if (!$item_ids) { return; }
    $ids_csv = implode(',', array_map('intval', $item_ids));

    $lots = $conn->query(
        "SELECT inventory_id, SUM(quantity) AS qty FROM lc_order_item_lots
         WHERE order_item_id IN ($ids_csv) GROUP BY inventory_id"
    )->fetch_all(MYSQLI_ASSOC);

    if ($lots) {
        $upd = $conn->prepare("UPDATE lc_inventory SET quantity_out = GREATEST(0, quantity_out - ?) WHERE id = ?");
        foreach ($lots as $lot) {
            $qty = (int)$lot['qty']; $inv = (int)$lot['inventory_id'];
            $upd->bind_param('ii', $qty, $inv);
            $upd->execute();
        }
        $upd->close();
        $conn->query("DELETE FROM lc_order_item_lots WHERE order_item_id IN ($ids_csv)");
    }
}

/**
 * 주문 항목에 이미 재고 차감(lot) 기록이 있는지 확인.
 * 대책 B → 대책 C(주문 접수 즉시 차감) 전환 시, 전환 이전에 생성된 레거시 pending
 * 주문(아직 차감 이력이 없는 주문)을 승인 단계에서도 안전하게 처리하기 위한 판별용.
 */
function lc_order_stock_allocated(mysqli $conn, int $order_id): bool {
    $order_id = (int)$order_id;
    $res = $conn->query(
        "SELECT COUNT(*) AS c FROM lc_order_item_lots
         WHERE order_item_id IN (SELECT id FROM lc_order_items WHERE order_id = $order_id)"
    );
    if ($res === false) {
        throw new Exception('lc_order_stock_allocated: 조회 실패 - ' . $conn->error);
    }
    $row = $res->fetch_assoc();
    return ((int)($row['c'] ?? 0)) > 0;
}

/**
 * 피킹 미리보기.
 */
function lc_fefo_preview(mysqli $conn, int $product_id, int $qty_needed): array|false {
    $stmt = $conn->prepare(
        "SELECT i.id AS inventory_id, i.quantity_remain, i.lot_number, i.expiry_date, i.storage_location
         FROM lc_inventory i
         JOIN lc_inbound ib ON i.inbound_id = ib.id
         WHERE i.product_id = ? AND i.quantity_remain > 0
         ORDER BY i.expiry_date ASC, i.id ASC"
    );
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (array_sum(array_column($lots, 'quantity_remain')) < $qty_needed) return false;

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
    return $picks;
}

/**
 * Design Ref: §5 — 지점출고 장바구니 미리보기용 FEFO 피킹 미리보기(읽기 전용, 음수재고 허용).
 * lc_fefo_preview()와 동일하게 유통기한 ASC로 lot을 나열하되, 재고가 부족해도 false를
 * 반환하지 않고 부족분(shortfall)을 별도로 반환한다. FOR UPDATE/쓰기 없음 — 출고 전 미리보기 전용.
 */
function lc_fefo_preview_allow_negative(mysqli $conn, int $product_id, int $qty_needed): array {
    $stmt = $conn->prepare(
        "SELECT i.id AS inventory_id, i.quantity_remain, i.lot_number, i.expiry_date, i.storage_location
         FROM lc_inventory i
         JOIN lc_inbound ib ON i.inbound_id = ib.id
         WHERE i.product_id = ? AND i.quantity_remain > 0
         ORDER BY i.expiry_date ASC, i.id ASC"
    );
    $stmt->bind_param('i', $product_id);
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

    return ['picks' => $picks, 'shortfall' => $remaining];
}

/**
 * Design Ref: 음수 LOT 자동 재조정 — 신규 입고 등록 직후 호출.
 * lc_fifo_ship_allow_negative()는 재고 부족분을 당시 존재하던 LOT에 음수로 기록하는데,
 * 그 뒤 새 입고가 들어와도 과거 음수는 저절로 정리되지 않아 LOT별 화면에 마이너스 재고가
 * 영구히 남는다. 새 입고가 등록될 때마다 상품의 음수 LOT들을 찾아 quantity_out을
 * FEFO 순서(유통기한 ASC, id ASC)로 다른 양수 LOT에 이전한다.
 * quantity_in은 건드리지 않고 LOT 간 quantity_out만 옮기므로 상품 전체 재고 합계는 불변.
 * 반드시 트랜잭션 내에서, 신규 lc_inventory 행 삽입 직후 호출할 것.
 */
function lc_rebalance_negative_lots(mysqli $conn, int $product_id): void {
    $stmt = $conn->prepare(
        "SELECT id, quantity_remain FROM lc_inventory
         WHERE product_id = ? AND quantity_remain < 0
         ORDER BY expiry_date ASC, id ASC
         FOR UPDATE"
    );
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $negatives = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$negatives) return;

    foreach ($negatives as $neg) {
        $shortfall = -(int)$neg['quantity_remain'];
        if ($shortfall <= 0) continue;
        $neg_id = (int)$neg['id'];

        $stmt = $conn->prepare(
            "SELECT id, quantity_remain FROM lc_inventory
             WHERE product_id = ? AND id <> ? AND quantity_remain > 0
             ORDER BY expiry_date ASC, id ASC
             FOR UPDATE"
        );
        $stmt->bind_param('ii', $product_id, $neg_id);
        $stmt->execute();
        $positives = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $remaining = $shortfall;
        foreach ($positives as $pos) {
            if ($remaining <= 0) break;
            $move = min($remaining, (int)$pos['quantity_remain']);
            $pos_id = (int)$pos['id'];

            $u1 = $conn->prepare("UPDATE lc_inventory SET quantity_out = quantity_out - ? WHERE id = ?");
            $u1->bind_param('ii', $move, $neg_id);
            $u1->execute();
            $u1->close();

            $u2 = $conn->prepare("UPDATE lc_inventory SET quantity_out = quantity_out + ? WHERE id = ?");
            $u2->bind_param('ii', $move, $pos_id);
            $u2->execute();
            $u2->close();

            $remaining -= $move;
        }
    }
}

/**
 * 상품별 재고 합계.
 */
function lc_get_stock(mysqli $conn, int $product_id): int {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(i.quantity_remain), 0)
         FROM lc_inventory i
         JOIN lc_inbound ib ON i.inbound_id = ib.id
         WHERE i.product_id = ? AND i.quantity_remain > 0"
    );
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $val = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $val;
}

/**
 * 유통기한 D-days 기준 CSS 클래스 반환.
 */
function lc_expiry_class(?string $expiry_date): string {
    if (!$expiry_date) return '';
    $days = (int)((strtotime($expiry_date) - time()) / 86400);
    if ($days < 0)   return 'bg-red-50 text-red-700';
    if ($days <= 30) return 'bg-orange-50 text-orange-700';
    if ($days <= 90) return 'bg-yellow-50 text-yellow-700';
    return '';
}

/**
 * 주문 상태 배지 CSS 클래스.
 */
function lc_status_class(string $status): string {
    return match($status) {
        'pending'          => 'bg-gray-100 text-gray-700',
        'approved'         => 'bg-blue-100 text-blue-700',
        'shipped'          => 'bg-purple-100 text-purple-700',
        'delivered'        => 'bg-green-100 text-green-700',
        'cancelled'        => 'bg-red-100 text-red-700',
        'cancel_requested' => 'bg-orange-100 text-orange-700',
        default            => 'bg-gray-100 text-gray-600',
    };
}

/**
 * 주문 상태 레이블.
 */
function lc_status_label(string $status): string {
    return match($status) {
        'pending'          => 'Pending',
        'approved'         => 'Approved',
        'shipped'          => 'Shipped',
        'delivered'        => 'Delivered',
        'cancelled'        => 'Cancelled',
        'cancel_requested' => 'Cancel Request',
        default            => $status,
    };
}

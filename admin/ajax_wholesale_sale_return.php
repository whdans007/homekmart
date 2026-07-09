<?php
// 도매판매 반품 처리 API
// Design Ref: docs/02-design/features/wholesale-sales-return.design.md §4
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();

header('Content-Type: application/json');

if (!has_permission('wholesale_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'PERMISSION_DENIED', 'message' => t('messages.permission_denied')]);
    exit;
}

$is_super_admin = ($_SESSION['role'] === 'super_admin');
$session_store_id = $_SESSION['store_id'] ?? null;

function json_error($http_code, $error_code, $message, $extra = []) {
    http_response_code($http_code);
    echo json_encode(array_merge(['success' => false, 'error' => $error_code, 'message' => $message], $extra));
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    json_error(500, 'SERVER_ERROR', '데이터베이스 연결에 실패했습니다.');
}

// ── GET: 반품 이력 조회 ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sale_id = (int)($_GET['sale_id'] ?? 0);

    if ($sale_id <= 0) {
        json_error(400, 'INVALID_REQUEST', 'sale_id가 필요합니다.');
    }

    $sale_check_sql = "SELECT id, store_id FROM wholesale_sales WHERE id = ?";
    $sale_check_stmt = $pdo->prepare($sale_check_sql);
    $sale_check_stmt->execute([$sale_id]);
    $sale_row = $sale_check_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale_row) {
        json_error(404, 'SALE_NOT_FOUND', '판매 건을 찾을 수 없습니다.');
    }
    if (!$is_super_admin && (int)$sale_row['store_id'] !== (int)$session_store_id) {
        json_error(403, 'PERMISSION_DENIED', '조회 권한이 없습니다.');
    }

    $returns_stmt = $pdo->prepare("
        SELECT wsr.id, wsr.reason, wsr.total_amount, wsr.created_at, u.full_name as processed_by_name
        FROM wholesale_sale_returns wsr
        LEFT JOIN users u ON wsr.processed_by = u.id
        WHERE wsr.sale_id = ?
        ORDER BY wsr.created_at DESC
    ");
    $returns_stmt->execute([$sale_id]);
    $returns = $returns_stmt->fetchAll(PDO::FETCH_ASSOC);

    $items_stmt = $pdo->prepare("
        SELECT wsri.return_id, wsri.sale_item_id, wsri.quantity, wsri.unit_price, wsri.amount,
               COALESCE(wsi.custom_product_name, p.name_ko, p.name_en) as product_name
        FROM wholesale_sale_return_items wsri
        LEFT JOIN wholesale_sale_items wsi ON wsri.sale_item_id = wsi.id
        LEFT JOIN products p ON wsi.product_id = p.id
        WHERE wsri.return_id IN (SELECT id FROM wholesale_sale_returns WHERE sale_id = ?)
        ORDER BY wsri.id ASC
    ");
    $items_stmt->execute([$sale_id]);
    $all_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    $items_by_return = [];
    foreach ($all_items as $item) {
        $items_by_return[$item['return_id']][] = $item;
    }
    foreach ($returns as &$r) {
        $r['items'] = $items_by_return[$r['id']] ?? [];
    }
    unset($r);

    echo json_encode(['success' => true, 'returns' => $returns]);
    exit;
}

// ── POST: 반품 처리 ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'METHOD_NOT_ALLOWED', '허용되지 않은 요청 방식입니다.');
}

$sale_id = (int)($_POST['sale_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$return_type = ($_POST['return_type'] ?? 'partial') === 'full' ? 'full' : 'partial';
$requested_items = json_decode($_POST['items'] ?? '[]', true);
if (!is_array($requested_items)) {
    $requested_items = [];
}

if ($sale_id <= 0) {
    json_error(400, 'INVALID_REQUEST', 'sale_id가 필요합니다.');
}

try {
    $pdo->beginTransaction();

    // 판매 건 잠금 + 점포 스코프 확인
    $sale_stmt = $pdo->prepare("SELECT * FROM wholesale_sales WHERE id = ? FOR UPDATE");
    $sale_stmt->execute([$sale_id]);
    $sale = $sale_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        $pdo->rollback();
        json_error(404, 'SALE_NOT_FOUND', '판매 건을 찾을 수 없습니다.');
    }
    if (!$is_super_admin && (int)$sale['store_id'] !== (int)$session_store_id) {
        $pdo->rollback();
        json_error(403, 'PERMISSION_DENIED', '반품 권한이 없습니다.');
    }
    if ($sale['return_status'] === 'full') {
        $pdo->rollback();
        json_error(409, 'ALREADY_FULLY_RETURNED', '이미 전체 반품 처리된 판매 건입니다.');
    }

    // 판매 품목 잠금 (등록상품인 경우 pieces_per_box도 함께 조회)
    $items_stmt = $pdo->prepare("
        SELECT wsi.id, wsi.product_id, wsi.quantity, wsi.returned_quantity, wsi.unit_price, wsi.sale_unit,
               p.pieces_per_box
        FROM wholesale_sale_items wsi
        LEFT JOIN products p ON wsi.product_id = p.id
        WHERE wsi.sale_id = ?
        FOR UPDATE
    ");
    $items_stmt->execute([$sale_id]);
    $sale_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    $sale_items_by_id = [];
    foreach ($sale_items as $si) {
        $sale_items_by_id[(int)$si['id']] = $si;
    }

    // 반품 대상 품목 + 수량 결정
    $targets = []; // sale_item_id => quantity

    if ($return_type === 'full') {
        foreach ($sale_items as $si) {
            $remaining = round((float)$si['quantity'] - (float)$si['returned_quantity'], 2);
            if ($remaining > 0) {
                $targets[(int)$si['id']] = $remaining;
            }
        }
    } else {
        foreach ($requested_items as $req) {
            $sale_item_id = (int)($req['sale_item_id'] ?? 0);
            $qty = round((float)($req['quantity'] ?? 0), 2);

            if ($sale_item_id <= 0 || $qty <= 0) {
                continue;
            }
            if (!isset($sale_items_by_id[$sale_item_id])) {
                $pdo->rollback();
                json_error(400, 'INVALID_ITEM', '판매 건에 속하지 않은 품목입니다.', ['sale_item_id' => $sale_item_id]);
            }

            $si = $sale_items_by_id[$sale_item_id];
            $remaining = round((float)$si['quantity'] - (float)$si['returned_quantity'], 2);

            if ($qty > $remaining) {
                $pdo->rollback();
                json_error(400, 'INVALID_QUANTITY', '반품 수량이 반품 가능 수량을 초과했습니다.', ['sale_item_id' => $sale_item_id]);
            }

            $targets[$sale_item_id] = $qty;
        }
    }

    if (empty($targets)) {
        $pdo->rollback();
        json_error(400, 'NOTHING_TO_RETURN', '반품할 품목이 없습니다.');
    }

    // 반품 헤더 생성
    $total_return_amount = 0.0;
    foreach ($targets as $sale_item_id => $qty) {
        $unit_price = (float)$sale_items_by_id[$sale_item_id]['unit_price'];
        $total_return_amount += round($qty * $unit_price, 2);
    }
    $total_return_amount = round($total_return_amount, 2);

    $return_header_stmt = $pdo->prepare("
        INSERT INTO wholesale_sale_returns (sale_id, reason, total_amount, processed_by, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $return_header_stmt->execute([$sale_id, $reason !== '' ? $reason : null, $total_return_amount, $_SESSION['user_id']]);
    $return_id = $pdo->lastInsertId();

    $return_item_stmt = $pdo->prepare("
        INSERT INTO wholesale_sale_return_items (return_id, sale_item_id, quantity, unit_price, amount, restocked, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $update_returned_qty_stmt = $pdo->prepare("
        UPDATE wholesale_sale_items SET returned_quantity = returned_quantity + ? WHERE id = ?
    ");
    $upsert_inventory_stmt = $pdo->prepare("
        INSERT INTO inventory (product_id, store_id, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ");
    $log_transaction_stmt = $pdo->prepare("
        INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks, transaction_date)
        SELECT id, ?, '반품', ?, ?, NOW() FROM inventory WHERE product_id = ? AND store_id = ?
    ");

    foreach ($targets as $sale_item_id => $qty) {
        $si = $sale_items_by_id[$sale_item_id];
        $unit_price = (float)$si['unit_price'];
        $amount = round($qty * $unit_price, 2);
        $is_registered_product = !empty($si['product_id']);

        $return_item_stmt->execute([
            $return_id, $sale_item_id, $qty, $unit_price, $amount, $is_registered_product ? 1 : 0
        ]);

        $update_returned_qty_stmt->execute([$qty, $sale_item_id]);

        if ($is_registered_product) {
            $pieces_per_box = (int)($si['pieces_per_box'] ?? 1);
            if ($pieces_per_box <= 0) {
                $pieces_per_box = 1;
            }
            $restock_qty = ($si['sale_unit'] === 'box') ? round($qty * $pieces_per_box) : round($qty);

            $upsert_inventory_stmt->execute([$si['product_id'], $sale['store_id'], $restock_qty]);
            $log_transaction_stmt->execute([
                $_SESSION['user_id'], $restock_qty,
                "도매판매 반품 (Return ID: {$return_id}, Sale ID: {$sale_id})",
                $si['product_id'], $sale['store_id']
            ]);
        }
    }

    // 판매 건 금액/상태 갱신
    $new_returned_amount = round((float)$sale['returned_amount'] + $total_return_amount, 2);
    $new_total_amount = round((float)$sale['total_amount'] - $total_return_amount, 2);
    $new_final_amount = round((float)$sale['final_amount'] - $total_return_amount, 2);

    // 전체 반품 여부 재확인 (모든 품목이 잔여수량 0인지)
    $recheck_stmt = $pdo->prepare("SELECT quantity, returned_quantity FROM wholesale_sale_items WHERE sale_id = ?");
    $recheck_stmt->execute([$sale_id]);
    $all_returned = true;
    foreach ($recheck_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (round((float)$row['quantity'] - (float)$row['returned_quantity'], 2) > 0) {
            $all_returned = false;
            break;
        }
    }
    $new_return_status = $all_returned ? 'full' : ($new_returned_amount > 0 ? 'partial' : 'none');

    $update_sale_stmt = $pdo->prepare("
        UPDATE wholesale_sales
        SET total_amount = ?, final_amount = ?, returned_amount = ?, return_status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $update_sale_stmt->execute([$new_total_amount, $new_final_amount, $new_returned_amount, $new_return_status, $sale_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'return_id' => (int)$return_id,
        'total_amount' => $total_return_amount,
        'sale' => [
            'id' => $sale_id,
            'total_amount' => $new_total_amount,
            'final_amount' => $new_final_amount,
            'returned_amount' => $new_returned_amount,
            'return_status' => $new_return_status,
        ],
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    error_log("Wholesale sale return error: " . $e->getMessage());
    json_error(500, 'SERVER_ERROR', '반품 처리 중 오류가 발생했습니다.');
}

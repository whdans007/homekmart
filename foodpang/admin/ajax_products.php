<?php
/**
 * Foodpang 상품 큐레이션 AJAX 엔드포인트 (search/add/update/delete/reorder/move_category).
 * foodpang_products 테이블만 다루며 mall_products에는 절대 접근하지 않는다 —
 * Mall 큐레이션과 완전히 분리되어 있음을 보장한다.
 */
ob_start();

try {
    require_once __DIR__ . '/../../config/db_config.php';
    require_once __DIR__ . '/../../lib/session_helper.php';
    require_once __DIR__ . '/../../lib/permission_helper.php';
    require_once __DIR__ . '/../../mall/lib/csrf.php';
    require_once __DIR__ . '/../lib/store_context.php';
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '필요한 라이브러리를 불러올 수 없습니다: ' . $e->getMessage()]]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');

function foodpang_json_error($code, $message, $http = 400, $details = null) {
    http_response_code($http);
    $error = ['code' => $code, 'message' => $message];
    if ($details !== null) {
        $error['details'] = $details;
    }
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

/**
 * prepare()가 실패(false)하면 원인을 로그에 남기고 명확한 JSON 오류로 종료한다.
 * mysqli prepare/execute 오류를 조용히 무시하지 않기 위한 공통 가드.
 */
function foodpang_stmt_or_fail(mysqli $conn, $sql) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log('foodpang ajax_products prepare failed: ' . $conn->error . ' | sql=' . $sql);
        $conn->close();
        foodpang_json_error('SERVER_ERROR', '요청을 처리할 수 없습니다 (DB 오류)', 500);
    }
    return $stmt;
}

/**
 * execute()가 실패하면 원인을 로그에 남기고 명확한 JSON 오류로 종료한다.
 * 중복 이름(UNIQUE 제약) 위반은 별도 코드로 구분해 사용자에게 이해 가능한 메시지를 준다.
 */
function foodpang_execute_or_fail(mysqli_stmt $stmt, mysqli $conn) {
    if ($stmt->execute()) {
        return;
    }
    $err = $stmt->error;
    error_log('foodpang ajax_products execute failed: ' . $err);
    $stmt->close();
    $conn->close();
    if (strpos($err, 'Duplicate entry') !== false) {
        foodpang_json_error('DUPLICATE', '이미 같은 이름의 카테고리가 있습니다.', 409);
    }
    foodpang_json_error('SERVER_ERROR', '저장 중 오류가 발생했습니다', 500);
}

if (!is_logged_in()) {
    foodpang_json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_permission('foodpang_management')) {
    foodpang_json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $conn = get_db_connection();
    // products.php(페이지 렌더링)와 항상 같은 store_id를 쓰도록 단일 판정 로직을 공유한다.
    $store_id = foodpang_resolve_store_id($conn);

    if (in_array($action, ['category_add', 'category_update', 'category_delete', 'category_reorder', 'category_bulk_edit'], true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '잘못된 요청 방식입니다.');
        }
        if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
            $conn->close();
            foodpang_json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요.', 403);
        }

        if ($action === 'category_add') {
            $name = trim($_POST['name'] ?? '');
            $name_en = trim($_POST['name_en'] ?? '');
            if ($name === '') foodpang_json_error('VALIDATION_ERROR', '카테고리명을 입력해주세요.');

            // foodpang_categories의 UNIQUE KEY(store_id, parent_id, name)는 parent_id가 항상 NULL이라
            // MySQL이 NULL을 서로 다른 값으로 취급해 실제로는 중복을 막지 못한다. 애플리케이션에서 직접 확인한다.
            $dup_check = foodpang_stmt_or_fail($conn, 'SELECT id FROM foodpang_categories WHERE store_id = ? AND parent_id IS NULL AND name = ?');
            $dup_check->bind_param('is', $store_id, $name);
            foodpang_execute_or_fail($dup_check, $conn);
            $is_duplicate = (bool)$dup_check->get_result()->fetch_assoc();
            $dup_check->close();
            if ($is_duplicate) {
                $conn->close();
                foodpang_json_error('DUPLICATE', '이미 같은 이름의 카테고리가 있습니다.', 409);
            }

            $order = foodpang_stmt_or_fail($conn, 'SELECT COALESCE(MAX(sort_order), -1) + 1 next_order FROM foodpang_categories WHERE store_id = ? AND parent_id IS NULL');
            $order->bind_param('i', $store_id);
            foodpang_execute_or_fail($order, $conn);
            $sort_order = (int)$order->get_result()->fetch_assoc()['next_order'];
            $order->close();

            $stmt = foodpang_stmt_or_fail($conn, 'INSERT INTO foodpang_categories (store_id, name, name_en, sort_order) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('issi', $store_id, $name, $name_en, $sort_order);
            foodpang_execute_or_fail($stmt, $conn);
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                $conn->close();
                foodpang_json_error('SERVER_ERROR', '카테고리가 저장되지 않았습니다.', 500);
            }
            $stmt->close();
        } elseif ($action === 'category_update') {
            $id = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $name_en = trim($_POST['name_en'] ?? '');
            if ($id <= 0 || $name === '') foodpang_json_error('VALIDATION_ERROR', '카테고리명을 확인해주세요.');

            // 현재 점포 소속이 아닌 카테고리는 애초에 수정 대상에서 제외한다(다른 점포 데이터 오염 방지 +
            // affected_rows=0이 "값 미변경"인지 "대상 없음"인지 구분하기 위함).
            $owner_check = foodpang_stmt_or_fail($conn, 'SELECT id FROM foodpang_categories WHERE id = ? AND store_id = ?');
            $owner_check->bind_param('ii', $id, $store_id);
            foodpang_execute_or_fail($owner_check, $conn);
            $owner_found = (bool)$owner_check->get_result()->fetch_assoc();
            $owner_check->close();
            if (!$owner_found) {
                $conn->close();
                foodpang_json_error('NOT_FOUND', '카테고리를 찾을 수 없습니다. 점포가 변경되었을 수 있으니 새로고침 후 다시 시도해주세요.', 404);
            }

            // category_add와 동일하게, parent_id가 항상 NULL이라 UNIQUE 제약이 실제로 걸리지 않으므로 직접 확인한다.
            $dup_check = foodpang_stmt_or_fail($conn, 'SELECT id FROM foodpang_categories WHERE store_id = ? AND parent_id IS NULL AND name = ? AND id != ?');
            $dup_check->bind_param('isi', $store_id, $name, $id);
            foodpang_execute_or_fail($dup_check, $conn);
            $is_duplicate = (bool)$dup_check->get_result()->fetch_assoc();
            $dup_check->close();
            if ($is_duplicate) {
                $conn->close();
                foodpang_json_error('DUPLICATE', '이미 같은 이름의 카테고리가 있습니다.', 409);
            }

            $stmt = foodpang_stmt_or_fail($conn, 'UPDATE foodpang_categories SET name = ?, name_en = ? WHERE id = ? AND store_id = ?');
            $stmt->bind_param('ssii', $name, $name_en, $id, $store_id);
            foodpang_execute_or_fail($stmt, $conn);
            $stmt->close();
            // 존재는 위에서 이미 확인했으므로, affected_rows=0은 "값이 기존과 동일"한 정상 케이스일 뿐 오류가 아니다.
        } elseif ($action === 'category_delete') {
            $id = (int)($_POST['category_id'] ?? 0);

            // mall/admin(save_category.php)과 동일하게, 걸려있는 상품 목록까지 함께 보여준다
            // ("상품이 등록되어 삭제 불가"라고만 하면 관리자가 어떤 상품을 먼저 빼야 하는지 알 수 없었다).
            $blocking_stmt = foodpang_stmt_or_fail(
                $conn,
                'SELECT p.id, p.name_ko, p.sku
                 FROM foodpang_products fp
                 INNER JOIN products p ON p.id = fp.product_id
                 WHERE fp.category_id = ? AND fp.store_id = ?
                 ORDER BY p.name_ko LIMIT 21'
            );
            $blocking_stmt->bind_param('ii', $id, $store_id);
            foodpang_execute_or_fail($blocking_stmt, $conn);
            $blocking_products = $blocking_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $blocking_stmt->close();

            $count_stmt = foodpang_stmt_or_fail($conn, 'SELECT COUNT(*) cnt FROM foodpang_products WHERE category_id = ? AND store_id = ?');
            $count_stmt->bind_param('ii', $id, $store_id);
            foodpang_execute_or_fail($count_stmt, $conn);
            $product_count = (int)$count_stmt->get_result()->fetch_assoc()['cnt'];
            $count_stmt->close();

            if ($product_count > 0) {
                $conn->close();
                $shown = array_slice($blocking_products, 0, 20);
                $details = [
                    'product_count' => $product_count,
                    'products' => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name_ko'], 'sku' => $p['sku']], $shown),
                    'truncated' => $product_count > 20,
                ];
                foodpang_json_error('VALIDATION_ERROR', "이 카테고리에 상품이 {$product_count}개 등록되어 있어 삭제할 수 없습니다. 먼저 상품을 다른 카테고리로 옮기거나 제거해주세요.", 400, $details);
            }

            $stmt = foodpang_stmt_or_fail($conn, 'DELETE FROM foodpang_categories WHERE id = ? AND store_id = ?');
            $stmt->bind_param('ii', $id, $store_id);
            foodpang_execute_or_fail($stmt, $conn);
            $deleted = $stmt->affected_rows;
            $stmt->close();
            if ($deleted < 1) {
                $conn->close();
                foodpang_json_error('NOT_FOUND', '카테고리를 찾을 수 없습니다. 점포가 변경되었을 수 있으니 새로고침 후 다시 시도해주세요.', 404);
            }
        } elseif ($action === 'category_reorder') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $_POST['order'] ?? ''))));
            if (empty($ids)) {
                $conn->close();
                foodpang_json_error('VALIDATION_ERROR', '입력값을 확인해주세요.');
            }

            // 실행 전, 전달된 id가 모두 현재 점포 소속인지 확인한다. UPDATE의 affected_rows는
            // "값이 그대로여서 0"인 경우와 "대상이 없어서 0"인 경우를 구분할 수 없어 신뢰할 수 없기 때문이다.
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $verify = foodpang_stmt_or_fail($conn, "SELECT COUNT(*) cnt FROM foodpang_categories WHERE store_id = ? AND id IN ({$placeholders})");
            $verify->bind_param(str_repeat('i', count($ids) + 1), $store_id, ...$ids);
            foodpang_execute_or_fail($verify, $conn);
            $matched = (int)$verify->get_result()->fetch_assoc()['cnt'];
            $verify->close();
            if ($matched !== count($ids)) {
                $conn->close();
                foodpang_json_error('VALIDATION_ERROR', '카테고리 목록이 최신 상태가 아닙니다. 새로고침 후 다시 시도해주세요.', 409);
            }

            $stmt = foodpang_stmt_or_fail($conn, 'UPDATE foodpang_categories SET sort_order = ? WHERE id = ? AND store_id = ?');
            foreach ($ids as $sort_order => $id) {
                $stmt->bind_param('iii', $sort_order, $id, $store_id);
                foodpang_execute_or_fail($stmt, $conn);
            }
            $stmt->close();
        } elseif ($action === 'category_bulk_edit') {
            // 카테고리 관리 모달의 "한번에 적용" — 기존 카테고리 이름 수정(category_id[]/name[]/name_en[])과
            // "추가"로 화면에 쌓아둔 신규 카테고리(new_name[]/new_name_en[])를 한 트랜잭션으로 함께 반영한다.
            // (mall/admin/ajax/save_category.php의 bulk_edit와 동일한 계약, Foodpang은 소분류가 없어 parent_id 없음)
            $ids = $_POST['category_id'] ?? [];
            $names = $_POST['name'] ?? [];
            $names_en = $_POST['name_en'] ?? [];
            $new_names = $_POST['new_name'] ?? [];
            $new_names_en = $_POST['new_name_en'] ?? [];

            if (!is_array($ids) || !is_array($names) || !is_array($names_en)
                || count($ids) !== count($names) || count($ids) !== count($names_en)) {
                $conn->close();
                foodpang_json_error('VALIDATION_ERROR', '입력값을 확인해주세요.');
            }
            if (!is_array($new_names) || !is_array($new_names_en) || count($new_names) !== count($new_names_en)) {
                $conn->close();
                foodpang_json_error('VALIDATION_ERROR', '입력값을 확인해주세요.');
            }

            $updates = [];
            foreach ($ids as $i => $raw_id) {
                $cid = (int)$raw_id;
                $u_name = trim($names[$i] ?? '');
                $u_name_en = trim($names_en[$i] ?? '');
                if ($cid <= 0 || $u_name === '') {
                    $conn->close();
                    foodpang_json_error('VALIDATION_ERROR', '카테고리명을 입력해주세요.');
                }
                $updates[$cid] = ['name' => $u_name, 'name_en' => $u_name_en];
            }

            $inserts = [];
            foreach ($new_names as $i => $raw_name) {
                $n_name = trim($raw_name);
                $n_name_en = trim($new_names_en[$i] ?? '');
                if ($n_name === '') {
                    $conn->close();
                    foodpang_json_error('VALIDATION_ERROR', '추가하려는 카테고리명이 비어있습니다.');
                }
                $inserts[] = ['name' => $n_name, 'name_en' => $n_name_en];
            }

            if (empty($updates) && empty($inserts)) {
                $conn->close();
                foodpang_json_error('VALIDATION_ERROR', '적용할 변경사항이 없습니다.');
            }

            // 배치 내부 중복 이름 검사(수정 대상 + 신규 추가 전부 합쳐서)
            $all_names = array_merge(array_column($updates, 'name'), array_column($inserts, 'name'));
            $name_counts = array_count_values($all_names);
            foreach ($name_counts as $dup_name => $cnt) {
                if ($cnt > 1) {
                    $conn->close();
                    foodpang_json_error('DUPLICATE', "카테고리명이 중복됩니다: {$dup_name}", 409);
                }
            }

            // 이 점포 소속인지 확인 + 배치 밖 기존 카테고리와의 중복 검사
            foreach ($updates as $cid => $u) {
                $owner_check = foodpang_stmt_or_fail($conn, 'SELECT id FROM foodpang_categories WHERE id = ? AND store_id = ?');
                $owner_check->bind_param('ii', $cid, $store_id);
                foodpang_execute_or_fail($owner_check, $conn);
                $owner_found = (bool)$owner_check->get_result()->fetch_assoc();
                $owner_check->close();
                if (!$owner_found) {
                    $conn->close();
                    foodpang_json_error('NOT_FOUND', '카테고리 목록이 최신 상태가 아닙니다. 새로고침 후 다시 시도해주세요.', 409);
                }

                $dup_check = foodpang_stmt_or_fail($conn, 'SELECT id FROM foodpang_categories WHERE store_id = ? AND parent_id IS NULL AND name = ? AND id != ?');
                $dup_check->bind_param('isi', $store_id, $u['name'], $cid);
                foodpang_execute_or_fail($dup_check, $conn);
                $is_dup = (bool)$dup_check->get_result()->fetch_assoc();
                $dup_check->close();
                if ($is_dup) {
                    $conn->close();
                    foodpang_json_error('DUPLICATE', "이미 같은 이름의 카테고리가 있습니다: {$u['name']}", 409);
                }
            }
            foreach ($inserts as $ins) {
                $dup_check = foodpang_stmt_or_fail($conn, 'SELECT id FROM foodpang_categories WHERE store_id = ? AND parent_id IS NULL AND name = ?');
                $dup_check->bind_param('is', $store_id, $ins['name']);
                foodpang_execute_or_fail($dup_check, $conn);
                $is_dup = (bool)$dup_check->get_result()->fetch_assoc();
                $dup_check->close();
                if ($is_dup) {
                    $conn->close();
                    foodpang_json_error('DUPLICATE', "이미 같은 이름의 카테고리가 있습니다: {$ins['name']}", 409);
                }
            }

            // 검증이 모두 끝난 뒤에만 실제로 쓴다 - 트랜잭션 안에서는 foodpang_execute_or_fail(성공 시 conn을 닫고
            // exit)을 쓰지 않고 직접 execute()/rollback으로 처리해 부분 반영을 방지한다.
            $conn->begin_transaction();
            try {
                if (!empty($updates)) {
                    $stmt = $conn->prepare('UPDATE foodpang_categories SET name = ?, name_en = ? WHERE id = ? AND store_id = ?');
                    if ($stmt === false) throw new Exception($conn->error);
                    foreach ($updates as $cid => $u) {
                        $stmt->bind_param('ssii', $u['name'], $u['name_en'], $cid, $store_id);
                        if (!$stmt->execute()) throw new Exception($stmt->error);
                    }
                    $stmt->close();
                }
                if (!empty($inserts)) {
                    $order_stmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 next_order FROM foodpang_categories WHERE store_id = ? AND parent_id IS NULL');
                    if ($order_stmt === false) throw new Exception($conn->error);
                    $order_stmt->bind_param('i', $store_id);
                    if (!$order_stmt->execute()) throw new Exception($order_stmt->error);
                    $next_order = (int)$order_stmt->get_result()->fetch_assoc()['next_order'];
                    $order_stmt->close();

                    $ins_stmt = $conn->prepare('INSERT INTO foodpang_categories (store_id, name, name_en, sort_order) VALUES (?, ?, ?, ?)');
                    if ($ins_stmt === false) throw new Exception($conn->error);
                    foreach ($inserts as $ins) {
                        $ins_stmt->bind_param('issi', $store_id, $ins['name'], $ins['name_en'], $next_order);
                        if (!$ins_stmt->execute()) throw new Exception($ins_stmt->error);
                        if ($ins_stmt->affected_rows < 1) throw new Exception('insert affected 0 rows');
                        $next_order++;
                    }
                    $ins_stmt->close();
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('foodpang ajax_products category_bulk_edit failed: ' . $e->getMessage());
                $conn->close();
                $is_dup_err = strpos($e->getMessage(), 'Duplicate entry') !== false;
                foodpang_json_error(
                    $is_dup_err ? 'DUPLICATE' : 'SERVER_ERROR',
                    $is_dup_err ? '이미 같은 이름의 카테고리가 있습니다.' : '저장 중 오류가 발생했습니다',
                    $is_dup_err ? 409 : 500
                );
            }
            $conn->close();
            echo json_encode(['success' => true, 'data' => ['updated' => count($updates), 'inserted' => count($inserts)]]);
            exit;
        }
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'search') {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }
        $like = '%' . $q . '%';
        $stmt = $conn->prepare(
            'SELECT p.id AS product_id, p.name_ko, p.sku,
                    (SELECT fp.id FROM foodpang_products fp WHERE fp.product_id = p.id AND fp.store_id = ?) AS foodpang_product_id
             FROM products p
             WHERE (p.name_ko LIKE ? OR p.name_en LIKE ? OR p.sku LIKE ?) AND p.is_active = 1
             ORDER BY p.name_ko LIMIT 20'
        );
        $stmt->bind_param('isss', $store_id, $like, $like, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $conn->close();

        foreach ($rows as &$row) {
            $row['already_added'] = $row['foodpang_product_id'] !== null;
            unset($row['foodpang_product_id']);
        }
        unset($row);

        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $conn->close();
        foodpang_json_error('VALIDATION_ERROR', '잘못된 요청 방식입니다');
    }
    if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
        $conn->close();
        foodpang_json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요.', 403);
    }

    if ($action === 'add') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($product_id <= 0 || $category_id <= 0 || $store_id <= 0) {
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $store_check = $conn->prepare('SELECT id FROM stores WHERE id = ?');
        $store_check->bind_param('i', $store_id);
        $store_check->execute();
        if (!$store_check->get_result()->fetch_assoc()) {
            $store_check->close();
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '유효하지 않은 점포입니다');
        }
        $store_check->close();

        $cat_check = $conn->prepare('SELECT id FROM foodpang_categories WHERE id = ? AND store_id = ?');
        $cat_check->bind_param('ii', $category_id, $store_id);
        $cat_check->execute();
        if (!$cat_check->get_result()->fetch_assoc()) {
            $cat_check->close();
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '유효하지 않은 카테고리입니다');
        }
        $cat_check->close();

        $check = $conn->prepare('SELECT id FROM foodpang_products WHERE product_id = ? AND store_id = ?');
        $check->bind_param('ii', $product_id, $store_id);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $check->close();
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '이미 Foodpang에 등록된 상품입니다');
        }
        $check->close();

        $order_stmt = $conn->prepare('SELECT COALESCE(MAX(display_order), -1) + 1 AS next_order FROM foodpang_products WHERE store_id = ?');
        $order_stmt->bind_param('i', $store_id);
        $order_stmt->execute();
        $next_order = (int)($order_stmt->get_result()->fetch_assoc()['next_order'] ?? 0);
        $order_stmt->close();

        $stmt = $conn->prepare(
            'INSERT INTO foodpang_products (product_id, store_id, category_id, is_active, display_order) VALUES (?, ?, ?, 1, ?)'
        );
        $stmt->bind_param('iiii', $product_id, $store_id, $category_id, $next_order);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update') {
        $foodpang_product_id = (int)($_POST['foodpang_product_id'] ?? 0);
        if ($foodpang_product_id <= 0) {
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $exists = $conn->prepare('SELECT id FROM foodpang_products WHERE id = ? AND store_id = ?');
        $exists->bind_param('ii', $foodpang_product_id, $store_id);
        $exists->execute();
        if (!$exists->get_result()->fetch_assoc()) {
            $exists->close();
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '큐레이션 상품을 찾을 수 없습니다');
        }
        $exists->close();

        $display_name = trim($_POST['display_name'] ?? '');
        $display_name_en = trim($_POST['display_name_en'] ?? '');
        $is_active = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
        $is_sold_out = ($_POST['is_sold_out'] ?? '0') === '1' ? 1 : 0;

        $price_raw = trim($_POST['selling_price'] ?? '');
        if ($price_raw !== '' && (!is_numeric($price_raw) || (float)$price_raw < 0)) {
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '가격은 0 이상의 숫자여야 합니다');
        }
        $selling_price_override = $price_raw === '' ? null : (float)$price_raw;

        $stmt = $conn->prepare(
            'UPDATE foodpang_products
             SET display_name = ?, display_name_en = ?, selling_price_override = ?, is_active = ?, is_sold_out = ?
             WHERE id = ? AND store_id = ?'
        );
        $stmt->bind_param('ssdiiii', $display_name, $display_name_en, $selling_price_override, $is_active, $is_sold_out, $foodpang_product_id, $store_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'move_category') {
        $foodpang_product_id = (int)($_POST['foodpang_product_id'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($foodpang_product_id <= 0 || $category_id <= 0) {
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '상품과 카테고리를 확인해주세요');
        }

        $category_check = $conn->prepare('SELECT id FROM foodpang_categories WHERE id = ? AND store_id = ?');
        $category_check->bind_param('ii', $category_id, $store_id);
        $category_check->execute();
        $valid_category = (bool)$category_check->get_result()->fetch_assoc();
        $category_check->close();
        if (!$valid_category) {
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '유효하지 않은 카테고리입니다');
        }

        $stmt = $conn->prepare('UPDATE foodpang_products SET category_id = ? WHERE id = ? AND store_id = ?');
        $stmt->bind_param('iii', $category_id, $foodpang_product_id, $store_id);
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            $verify = $conn->prepare('SELECT id FROM foodpang_products WHERE id = ? AND store_id = ? AND category_id = ?');
            $verify->bind_param('iii', $foodpang_product_id, $store_id, $category_id);
            $verify->execute();
            $already_moved = (bool)$verify->get_result()->fetch_assoc();
            $verify->close();
            if (!$already_moved) {
                $stmt->close();
                $conn->close();
                foodpang_json_error('NOT_FOUND', '큐레이션 상품을 찾을 수 없습니다', 404);
            }
        }
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $foodpang_product_id = (int)($_POST['foodpang_product_id'] ?? 0);
        if ($foodpang_product_id <= 0) {
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $stmt = $conn->prepare('DELETE FROM foodpang_products WHERE id = ? AND store_id = ?');
        $stmt->bind_param('ii', $foodpang_product_id, $store_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reorder') {
        $ids = array_filter(array_map('intval', explode(',', $_POST['order'] ?? '')));
        if (empty($ids)) {
            $conn->close();
            foodpang_json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $conn->begin_transaction();
        $stmt = $conn->prepare('UPDATE foodpang_products SET display_order = ? WHERE id = ? AND store_id = ?');
        foreach (array_values($ids) as $index => $foodpang_product_id) {
            $stmt->bind_param('iii', $index, $foodpang_product_id, $store_id);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    $conn->close();
    foodpang_json_error('VALIDATION_ERROR', '알 수 없는 작업입니다');
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    error_log('ajax_foodpang_products.php error: ' . $e->getMessage());
    foodpang_json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}

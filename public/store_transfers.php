<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '점간이동' . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 점간이동 권한 확인
if (!has_permission('store_transfer_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$errors = [];
$success_message = '';
$stores = [];
$edit_mode = false;
$edit_transfer_id = 0;
$edit_data = null;

// 수정 모드 확인
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_transfer_id = (int)$_GET['edit'];
    $edit_mode = true;
}

// 점포 목록 가져오기 및 수정 데이터 로드
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 점포 목록 조회 (현재 사용자가 super_admin이 아닌 경우 자신의 점포 제외하고 다른 점포들만)
    if ($_SESSION['role'] === 'super_admin') {
        $store_stmt = $pdo->prepare("SELECT id, name FROM stores ORDER BY name");
        $store_stmt->execute();
    } else {
        $store_stmt = $pdo->prepare("SELECT id, name FROM stores WHERE id != ? ORDER BY name");
        $store_stmt->execute([$current_store_id]);
    }
    $stores = $store_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 수정 모드인 경우 기존 데이터 로드
    if ($edit_mode && $edit_transfer_id > 0) {
        // 이동 정보 조회
        $edit_sql = "
            SELECT 
                st.*,
                fs.name as from_store_name,
                ts.name as to_store_name
            FROM store_transfers st
            LEFT JOIN stores fs ON st.from_store_id = fs.id
            LEFT JOIN stores ts ON st.to_store_id = ts.id
            WHERE st.id = ?
        ";
        
        // 권한 확인 (super_admin이 아닌 경우 본인 점포 관련 데이터만)
        if ($_SESSION['role'] !== 'super_admin') {
            $edit_sql .= " AND (st.from_store_id = " . (int)$current_store_id . " OR st.to_store_id = " . (int)$current_store_id . ")";
        }
        
        $edit_stmt = $pdo->prepare($edit_sql);
        $edit_stmt->execute([$edit_transfer_id]);
        $edit_data = $edit_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_data) {
            $errors[] = '수정할 점간이동 내역을 찾을 수 없거나 접근 권한이 없습니다.';
            $edit_mode = false;
        } else {
            // 이동 항목들 가져오기
            $items_sql = "
                SELECT 
                    sti.product_id,
                    sti.quantity,
                    sti.unit_cost_price,
                    sti.total_price,
                    sti.remarks,
                    p.sku,
                    p.name_ko,
                    p.name_en,
                    COALESCE(p.pieces_per_box, 1) as pieces_per_box
                FROM store_transfer_items sti
                LEFT JOIN products p ON sti.product_id = p.id
                WHERE sti.transfer_id = ?
                ORDER BY p.name_en, p.name_ko
            ";
            
            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([$edit_transfer_id]);
            $edit_data['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
} catch (PDOException $e) {
    $errors[] = '데이터베이스 연결 오류: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST 요청 수신: " . json_encode($_POST));
    
    $action = $_POST['action'] ?? 'save';
    $from_store_id = (int)($_POST['from_store_id'] ?? 0);
    $to_store_id = (int)($_POST['to_store_id'] ?? 0);
    $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d');
    $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
    $is_edit = isset($_POST['edit_transfer_id']) && is_numeric($_POST['edit_transfer_id']);
    $edit_transfer_id_post = $is_edit ? (int)$_POST['edit_transfer_id'] : 0;
    
    error_log("처리할 액션: {$action}, 수정 모드: " . ($is_edit ? 'Yes' : 'No') . ", Transfer ID: {$edit_transfer_id_post}");
    
    if (empty($from_store_id)) {
        $errors[] = '출발 점포를 선택해주세요.';
    }
    
    if (empty($to_store_id)) {
        $errors[] = '목적지 점포를 선택해주세요.';
    }
    
    if ($from_store_id === $to_store_id) {
        $errors[] = '출발 점포와 목적지 점포가 동일할 수 없습니다.';
    }
    
    if (empty($cart_items)) {
        $errors[] = '이동할 상품을 추가해주세요.';
    }
    
    // 삭제 액션 처리
    if ($action === 'delete' && $is_edit && $edit_transfer_id_post > 0) {
        error_log("삭제 액션 시작 - Transfer ID: {$edit_transfer_id_post}, User: {$_SESSION['user_id']}, Role: {$_SESSION['role']}");
        
        try {
            $pdo->beginTransaction();
            
            // 권한 확인
            $check_sql = "SELECT from_store_id, to_store_id, status FROM store_transfers WHERE id = ?";
            if ($_SESSION['role'] !== 'super_admin') {
                $check_sql .= " AND from_store_id = " . (int)$current_store_id;
            }
            error_log("권한 확인 SQL: {$check_sql}");
            
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$edit_transfer_id_post]);
            $transfer_info = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            error_log("Transfer 정보: " . json_encode($transfer_info));
            
            if (!$transfer_info) {
                throw new Exception('삭제 권한이 없거나 해당 이동 내역을 찾을 수 없습니다.');
            }
            
            $delete_from_store_id = $transfer_info['from_store_id'];
            $delete_to_store_id = $transfer_info['to_store_id'];
            $current_status = $transfer_info['status'];
            
            error_log("삭제 대상 - From Store: {$delete_from_store_id}, To Store: {$delete_to_store_id}, Status: {$current_status}");
            
            // confirmed 상태인 경우 재고 원복
            if ($current_status === 'confirmed') {
                error_log("확정된 이동이므로 재고 복원 시작");
                
                $old_items_stmt = $pdo->prepare("SELECT product_id, quantity FROM store_transfer_items WHERE transfer_id = ?");
                $old_items_stmt->execute([$edit_transfer_id_post]);
                $old_items = $old_items_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                error_log("복원할 항목 수: " . count($old_items));
                
                foreach ($old_items as $old_item) {
                    error_log("재고 복원 - Product: {$old_item['product_id']}, Quantity: {$old_item['quantity']}");
                    
                    // 출발지에 재고 복원
                    $restore_from = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND store_id = ?");
                    $restore_from->execute([$old_item['quantity'], $old_item['product_id'], $delete_from_store_id]);
                    error_log("출발지 재고 복원 완료");
                    
                    // 목적지에서 재고 차감
                    $reduce_to = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND store_id = ?");
                    $reduce_to->execute([$old_item['quantity'], $old_item['product_id'], $delete_to_store_id]);
                    error_log("목적지 재고 차감 완료");
                }
            }
            
            // 관련 데이터 삭제
            error_log("Transfer items 삭제 시작");
            $delete_items_stmt = $pdo->prepare("DELETE FROM store_transfer_items WHERE transfer_id = ?");
            $delete_items_stmt->execute([$edit_transfer_id_post]);
            
            error_log("Transfer 레코드 삭제 시작");
            $delete_transfer_stmt = $pdo->prepare("DELETE FROM store_transfers WHERE id = ?");
            $delete_transfer_stmt->execute([$edit_transfer_id_post]);
            
            $pdo->commit();
            error_log("삭제 트랜잭션 커밋 완료");
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => '점간이동 내역이 성공적으로 삭제되었습니다.'
            ];
            
            error_log("삭제 완료 - 리다이렉트 실행");
            header('Location: store_transfers_list.php');
            exit;
            
        } catch (Exception $e) {
            $pdo->rollback();
            error_log("삭제 오류 발생: " . $e->getMessage());
            error_log("삭제 오류 스택: " . $e->getTraceAsString());
            $errors[] = '삭제 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
    
    // 권한 확인 (super_admin이 아닌 경우 자신의 점포가 출발지여야 함)
    if ($action !== 'delete' && $_SESSION['role'] !== 'super_admin' && $from_store_id != $current_store_id) {
        $errors[] = '자신의 점포에서 출발하는 이동만 등록할 수 있습니다.';
    }
    
    // 저장/수정 액션 처리
    if ($action === 'save' && empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $total_amount = array_sum(array_column($cart_items, 'total_price'));
            
            if ($is_edit && $edit_transfer_id_post > 0) {
                // 수정 모드 - 기존 데이터 업데이트
                
                // 권한 확인
                $check_sql = "SELECT id FROM store_transfers WHERE id = ?";
                if ($_SESSION['role'] !== 'super_admin') {
                    $check_sql .= " AND from_store_id = " . (int)$current_store_id;
                }
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([$edit_transfer_id_post]);
                
                if (!$check_stmt->fetch()) {
                    throw new Exception('수정 권한이 없습니다.');
                }
                
                // 기존 이동으로 인한 재고 변경사항 원복 (confirmed 상태인 경우에만)
                $status_check = $pdo->prepare("SELECT status FROM store_transfers WHERE id = ?");
                $status_check->execute([$edit_transfer_id_post]);
                $current_status = $status_check->fetchColumn();
                
                if ($current_status === 'confirmed') {
                    // 기존 항목들로 재고 원복
                    $old_items_stmt = $pdo->prepare("SELECT product_id, quantity FROM store_transfer_items WHERE transfer_id = ?");
                    $old_items_stmt->execute([$edit_transfer_id_post]);
                    $old_items = $old_items_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($old_items as $old_item) {
                        // 출발지에 재고 복원
                        $restore_from = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND store_id = ?");
                        $restore_from->execute([$old_item['quantity'], $old_item['product_id'], $from_store_id]);
                        
                        // 목적지에서 재고 차감
                        $reduce_to = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND store_id = ?");
                        $reduce_to->execute([$old_item['quantity'], $old_item['product_id'], $to_store_id]);
                    }
                }
                
                // 이동 기록 업데이트
                $update_stmt = $pdo->prepare("
                    UPDATE store_transfers 
                    SET from_store_id = ?, to_store_id = ?, transfer_date = ?, total_amount = ?, final_amount = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $update_stmt->execute([$from_store_id, $to_store_id, $transfer_date, $total_amount, $total_amount, $edit_transfer_id_post]);
                
                // 기존 이동 항목들 삭제
                $delete_stmt = $pdo->prepare("DELETE FROM store_transfer_items WHERE transfer_id = ?");
                $delete_stmt->execute([$edit_transfer_id_post]);
                
                $transfer_id = $edit_transfer_id_post;
                $success_msg = '점간이동 내역이 성공적으로 수정되었습니다.';
                
            } else {
                // 새로운 이동 등록
                $transfer_stmt = $pdo->prepare("
                    INSERT INTO store_transfers (from_store_id, to_store_id, user_id, transfer_date, total_amount, final_amount, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 'confirmed', NOW())
                ");
                $transfer_stmt->execute([$from_store_id, $to_store_id, $_SESSION['user_id'], $transfer_date, $total_amount, $total_amount]);
                $transfer_id = $pdo->lastInsertId();
                
                $success_msg = '점간이동이 성공적으로 등록되었습니다.';
            }
            
            // 새로운 이동 항목들 추가 및 재고 이동 처리
            foreach ($cart_items as $item) {
                $product_id = $item['product_id'];
                $quantity = $item['quantity'];
                $unit_cost_price = $item['unit_cost_price'];
                $total_price = $item['total_price'];
                $remarks = $item['remarks'] ?? '';
                
                // 이동 항목 추가
                $item_stmt = $pdo->prepare("
                    INSERT INTO store_transfer_items (transfer_id, product_id, quantity, unit_cost_price, total_price, remarks, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $item_stmt->execute([$transfer_id, $product_id, $quantity, $unit_cost_price, $total_price, $remarks]);
                
                // 출발지 재고 차감
                $from_inventory_stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND store_id = ?");
                $from_inventory_stmt->execute([$quantity, $product_id, $from_store_id]);
                
                // 목적지 재고 추가 (없으면 원가 정보와 함께 생성)
                $to_inventory_check = $pdo->prepare("SELECT quantity, cost_price FROM inventory WHERE product_id = ? AND store_id = ?");
                $to_inventory_check->execute([$product_id, $to_store_id]);
                $to_inventory = $to_inventory_check->fetch(PDO::FETCH_ASSOC);
                
                if ($to_inventory) {
                    // 기존 재고에 추가
                    $to_inventory_update = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND store_id = ?");
                    $to_inventory_update->execute([$quantity, $product_id, $to_store_id]);
                } else {
                    // 새로운 재고 레코드 생성 (이동하는 원가로 설정)
                    $to_inventory_insert = $pdo->prepare("
                        INSERT INTO inventory (product_id, store_id, quantity, cost_price, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ");
                    $to_inventory_insert->execute([$product_id, $to_store_id, $quantity, $unit_cost_price]);
                }
            }
            
            $pdo->commit();
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $success_msg
            ];
            
            // 미리보기 페이지로 리다이렉트
            header("Location: store_transfer_preview.php?id=$transfer_id");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollback();
            $errors[] = '처리 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-6xl mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="store_transfers_list.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-exchange-alt mr-1"></i>
                            점간이동
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo $edit_mode ? '점간이동 수정' : '점간이동 등록'; ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-exchange-alt mr-2 text-primary-500"></i>
                    <?php echo $edit_mode ? '점간이동 수정' : '점간이동 등록'; ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    <?php echo $edit_mode ? '기존 점간이동 내역을 수정합니다.' : '출발 점포와 목적지 점포, 상품을 선택하여 점간이동을 등록하세요.'; ?>
                </p>
            </div>

            <div class="px-6 py-4">
                <?php if (isset($flash)): ?>
                    <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                                    <?php echo htmlspecialchars($flash['message']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">다음 오류를 해결해주세요:</h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" id="transfer-form">
                    <!-- 점포 및 기본 정보 -->
                    <div class="space-y-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">이동 정보</h3>
                            
                            <!-- 출발점포, 목적지점포, 이동날짜 -->
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
                                <!-- 출발 점포 -->
                                <div>
                                    <label for="from_store_id" class="block text-sm font-medium text-gray-700 mb-2">
                                        출발 점포 <span class="text-red-500">*</span>
                                    </label>
                                    <?php if ($_SESSION['role'] === 'super_admin'): ?>
                                        <select name="from_store_id" id="from_store_id" required onchange="updateProductSearch(); updateToStoreOptions();"
                                                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                            <option value="">출발 점포를 선택하세요</option>
                                            <?php foreach ($stores as $store): ?>
                                                <?php 
                                                $selected = false;
                                                if ($edit_data && $store['id'] == $edit_data['from_store_id']) {
                                                    $selected = true;
                                                } elseif (!$edit_data && $store['id'] == $current_store_id) {
                                                    // 신규 등록시 현재 점포를 기본값으로 설정
                                                    $selected = true;
                                                }
                                                ?>
                                                <option value="<?php echo $store['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($store['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <div class="p-3 bg-gray-50 border border-gray-300 rounded-md">
                                            <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($current_store_name); ?></span>
                                        </div>
                                        <input type="hidden" name="from_store_id" id="from_store_id" value="<?php echo $current_store_id; ?>">
                                    <?php endif; ?>
                                </div>

                                <!-- 목적지 점포 -->
                                <div>
                                    <label for="to_store_id" class="block text-sm font-medium text-gray-700 mb-2">
                                        목적지 점포 <span class="text-red-500">*</span>
                                    </label>
                                    <select name="to_store_id" id="to_store_id" required
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                        <option value="">목적지 점포를 선택하세요</option>
                                        <?php foreach ($stores as $store): ?>
                                            <option value="<?php echo $store['id']; ?>" 
                                                    data-store-id="<?php echo $store['id']; ?>"
                                                    <?php echo ($edit_data && $store['id'] == $edit_data['to_store_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($store['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- 이동 날짜 -->
                                <div>
                                    <label for="transfer_date" class="block text-sm font-medium text-gray-700 mb-2">
                                        이동 날짜
                                    </label>
                                    <input type="date" name="transfer_date" id="transfer_date" 
                                           value="<?php echo $edit_data ? $edit_data['transfer_date'] : date('Y-m-d'); ?>"
                                           class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                </div>
                            </div>
                        </div>

                        <!-- 상품 검색 및 추가 -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">상품 추가</h3>
                            
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <input type="text" id="product_search" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                           placeholder="상품명이나 SKU를 입력해서 검색하세요..."
                                           autocomplete="off">
                                    <div id="product_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                                        <!-- 검색 결과가 여기에 표시됩니다 -->
                                    </div>
                                </div>
                                <button type="button" id="product_search_btn" 
                                        class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 whitespace-nowrap">
                                    <i class="fas fa-search mr-1"></i>
                                    검색
                                </button>
                            </div>
                            
                            <div id="search_status" class="mt-2 text-sm text-gray-600"></div>
                        </div>
                    </div>

                    <!-- 장바구니 섹션 -->
                    <div class="mt-8">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                <i class="fas fa-exchange-alt mr-2 text-primary-500"></i>
                                이동할 상품 목록
                            </h3>
                            
                            <div id="cart_empty" class="text-center text-gray-500 py-8">
                                <i class="fas fa-exchange-alt text-4xl mb-4"></i>
                                <p>이동할 상품이 없습니다.</p>
                                <p class="text-sm">상품을 검색해서 추가해주세요.</p>
                            </div>
                            
                            <div id="cart_items" class="hidden">
                                <!-- 테이블 형태 장바구니 -->
                                <div class="border rounded-md overflow-hidden cart-table-wrapper">
                                    <table class="w-full cart-table">
                                        <thead class="bg-gray-100">
                                            <tr>
                                                <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700">SKU</th>
                                                <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700">상품명</th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700">수량</th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700">박스포장수량</th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700">박스원가</th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700">매입가</th>
                                                <th class="px-2 py-3 text-right text-xs font-semibold text-gray-700">합계</th>
                                                <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700">비고</th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700">삭제</th>
                                            </tr>
                                        </thead>
                                        <tbody id="cart_list">
                                            <!-- 장바구니 항목들이 여기에 추가됩니다 -->
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- 총 금액 -->
                                <div class="mt-4 pt-3 border-t border-gray-300">
                                    <div class="flex justify-between text-lg font-medium">
                                        <span>총 이동 금액:</span>
                                        <span id="cart_total">0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 저장 버튼 -->
                        <div class="mt-8 flex justify-center gap-4">
                            <button type="submit" id="complete_transfer_btn" 
                                    class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:hover:bg-gray-400 whitespace-nowrap"
                                    disabled>
                                <i class="fas fa-save mr-1"></i>
                                <?php echo $edit_mode ? '수정 완료' : '이동 등록'; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                            <button type="button" id="delete_transfer_btn" 
                                    class="px-4 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 whitespace-nowrap"
                                    onclick="deleteTransfer()">
                                <i class="fas fa-trash mr-1"></i>
                                삭제
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <input type="hidden" name="cart_items" id="cart_items_input" value="">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="edit_transfer_id" value="<?php echo $edit_transfer_id; ?>">
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 상품 목록 모달 -->
<div id="product-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[70vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900">
                    <i class="fas fa-box mr-2 text-green-500"></i>
                    이동 가능한 상품 목록
                </h3>
                <button type="button" id="close-product-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="p-3 border-b border-gray-200">
                    <input type="text" id="modal-product-search" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           placeholder="상품명이나 SKU로 필터링...">
                </div>
                <div id="product-list" class="flex-1 overflow-y-auto p-3 space-y-2 max-h-80">
                    <!-- 상품 목록이 여기에 표시됩니다 -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 매입 이력 선택 모달 -->
<div id="purchase-history-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[70vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900" id="purchase-modal-title">
                    <i class="fas fa-history mr-2 text-blue-500"></i>
                    매입 이력 선택
                </h3>
                <button type="button" id="close-purchase-history-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="p-4">
                    <div id="selected-product-info" class="mb-4 p-3 bg-gray-50 rounded-lg">
                        <div class="font-medium text-gray-900" id="purchase-product-name">상품명</div>
                        <div class="text-sm text-gray-600" id="purchase-product-sku">SKU: -</div>
                    </div>
                    
                    <div class="mb-4">
                        <h4 class="text-sm font-medium text-gray-900 mb-3">최근 매입 이력 (최대 3건)</h4>
                        <div id="purchase-history-list" class="space-y-2">
                            <!-- 매입 이력이 여기에 표시됩니다 -->
                        </div>
                        <div id="purchase-history-loading" class="text-center py-4 hidden">
                            <i class="fas fa-spinner fa-spin text-gray-400"></i>
                            <span class="ml-2 text-sm text-gray-600">매입 이력 조회 중...</span>
                        </div>
                        <div id="purchase-history-empty" class="text-center py-4 hidden">
                            <i class="fas fa-inbox text-gray-400 text-2xl mb-2"></i>
                            <p class="text-sm text-gray-600 mb-3">해당 점포의 매입 이력이 없습니다.</p>
                            <button type="button" id="use-default-price-btn" 
                                    class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                <i class="fas fa-plus mr-2"></i>기본 원가로 추가
                            </button>
                        </div>
                    </div>
                    
                    <div class="border-t pt-4">
                        <button type="button" id="manual-price-input-btn" 
                                class="w-full px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                            <i class="fas fa-edit mr-2"></i>
                            수동으로 가격 입력
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// PHP 데이터를 JavaScript로 전달
const editMode = <?php echo json_encode($edit_mode); ?>;
const editData = <?php echo json_encode($edit_data); ?>;
const currentStoreId = <?php echo json_encode($current_store_id); ?>;
const userRole = <?php echo json_encode($_SESSION['role']); ?>;

document.addEventListener('DOMContentLoaded', function() {
    let cart = [];
    
    const productSearch = document.getElementById('product_search');
    const productSearchResults = document.getElementById('product_search_results');
    
    const cartEmpty = document.getElementById('cart_empty');
    const cartItems = document.getElementById('cart_items');
    const cartList = document.getElementById('cart_list');
    const cartTotal = document.getElementById('cart_total');
    const cartItemsInput = document.getElementById('cart_items_input');
    const completeTransferBtn = document.getElementById('complete_transfer_btn');
    const searchStatus = document.getElementById('search_status');
    
    let searchTimeout;
    
    // 모달 관련 요소들
    const productModal = document.getElementById('product-modal');
    const closeProductModal = document.getElementById('close-product-modal');
    const productSearchBtn = document.getElementById('product_search_btn');
    const productList = document.getElementById('product-list');
    const modalProductSearch = document.getElementById('modal-product-search');
    
    // 매입 이력 모달 관련 요소들
    const purchaseHistoryModal = document.getElementById('purchase-history-modal');
    const closePurchaseHistoryModal = document.getElementById('close-purchase-history-modal');
    const purchaseHistoryList = document.getElementById('purchase-history-list');
    const purchaseHistoryLoading = document.getElementById('purchase-history-loading');
    const purchaseHistoryEmpty = document.getElementById('purchase-history-empty');
    const purchaseProductName = document.getElementById('purchase-product-name');
    const purchaseProductSku = document.getElementById('purchase-product-sku');
    const manualPriceInputBtn = document.getElementById('manual-price-input-btn');
    const useDefaultPriceBtn = document.getElementById('use-default-price-btn');
    const purchaseModalTitle = document.getElementById('purchase-modal-title');
    
    // 현재 선택 중인 상품 정보
    let currentSelectedProduct = null;
    
    // 상품 검색
    productSearch.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            productSearchResults.classList.add('hidden');
            return;
        }
        
        searchTimeout = setTimeout(function() {
            searchProducts(query);
        }, 300);
    });
    
    // 상품 검색 버튼 클릭 - 모달 표시
    productSearchBtn.addEventListener('click', function() {
        const fromStoreId = getFromStoreId();
        
        if (!fromStoreId) {
            alert('출발 점포를 먼저 선택해주세요.');
            return;
        }
        
        const query = productSearch.value.trim();
        if (query.length === 0) {
            // 빈 검색어일 때 전체 목록을 모달로 표시
            showProductModal();
        } else {
            // 검색어가 있으면 검색 실행
            searchProducts(query);
        }
    });
    
    // 모달 닫기 이벤트
    closeProductModal.addEventListener('click', function() {
        productModal.classList.add('hidden');
    });
    
    // 모달 외부 클릭시 닫기
    productModal.addEventListener('click', function(e) {
        if (e.target === productModal) {
            productModal.classList.add('hidden');
        }
    });
    
    // ESC 키로 모달 닫기
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            productModal.classList.add('hidden');
        }
    });
    
    // 모달 내 검색 기능
    modalProductSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const items = productList.querySelectorAll('.modal-product-item');
        
        items.forEach(function(item) {
            const name = (item.dataset.nameEn || '').toLowerCase() + ' ' + (item.dataset.nameKo || '').toLowerCase();
            const sku = item.dataset.sku.toLowerCase();
            
            if (name.includes(query) || sku.includes(query)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });
    
    // 검색 결과 외부 클릭시 닫기
    document.addEventListener('click', function(e) {
        if (!productSearch.contains(e.target) && !productSearchResults.contains(e.target)) {
            productSearchResults.classList.add('hidden');
        }
    });
    
    function getFromStoreId() {
        const fromStoreSelect = document.getElementById('from_store_id');
        return fromStoreSelect ? fromStoreSelect.value : '';
    }
    
    function updateProductSearch() {
        // 출발 점포가 변경되면 장바구니 초기화
        if (cart.length > 0 && !confirm('출발 점포를 변경하면 현재 장바구니의 상품들이 모두 삭제됩니다. 계속하시겠습니까?')) {
            return false;
        }
        cart = [];
        updateCart();
        productSearch.value = '';
        productSearchResults.classList.add('hidden');
        searchStatus.textContent = '';
    }
    
    // 전역 함수로 등록
    window.updateProductSearch = updateProductSearch;
    
    function searchProducts(query) {
        const fromStoreId = getFromStoreId();
        
        if (!fromStoreId) {
            searchStatus.textContent = '출발 점포를 먼저 선택해주세요.';
            searchStatus.className = 'mt-2 text-sm text-red-600';
            return;
        }
        
        searchStatus.textContent = '검색 중...';
        searchStatus.className = 'mt-2 text-sm text-gray-600';
        
        fetch('ajax_search_transfer_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&from_store_id=' + encodeURIComponent(fromStoreId) + '&limit=10'
        })
        .then(response => response.json())
        .then(data => {
            console.log('Search API Response:', data);
            
            if (data.success && data.products) {
                displayProductResults(data.products);
                searchStatus.textContent = data.products.length + '개 상품을 찾았습니다.';
                searchStatus.className = 'mt-2 text-sm text-green-600';
            } else {
                let errorMessage = data.message || '검색 결과가 없습니다.';
                
                // 권한 오류인 경우 특별 처리
                if (data.error_type === 'permission_denied') {
                    errorMessage = '로그인이 필요하거나 권한이 없습니다. 페이지를 새로고침해주세요.';
                    searchStatus.className = 'mt-2 text-sm text-red-600';
                } else if (data.error_type === 'database_error') {
                    errorMessage = '데이터베이스 오류가 발생했습니다.';
                    searchStatus.className = 'mt-2 text-sm text-red-600';
                    if (data.error_detail) {
                        console.error('Database Error Detail:', data.error_detail);
                    }
                } else {
                    searchStatus.className = 'mt-2 text-sm text-orange-600';
                }
                
                productSearchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">' + errorMessage + '</div>';
                productSearchResults.classList.remove('hidden');
                searchStatus.textContent = errorMessage;
            }
        })
        .catch(error => {
            console.error('Network Error:', error);
            searchStatus.textContent = '네트워크 오류가 발생했습니다. 인터넷 연결을 확인해주세요.';
            searchStatus.className = 'mt-2 text-sm text-red-600';
        });
    }
    
    function displayProductResults(products) {
        let html = '';
        products.forEach(function(product) {
            html += `
                <div class="p-3 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0 product-item" 
                     data-id="${product.id}" 
                     data-sku="${product.sku}"
                     data-name-ko="${product.name_ko || ''}"
                     data-name-en="${product.name_en || ''}"
                     data-cost-price="${product.cost_price}"
                     data-available-quantity="${product.available_quantity}"
                     data-min-quantity="${product.min_quantity}"
                     data-pieces-per-box="${product.pieces_per_box || 1}">
                    <div class="font-medium text-gray-900">
                        ${product.name_en || product.name_ko || 'N/A'}
                    </div>
                    <div class="text-sm text-gray-600">${product.name_ko && product.name_en && product.name_ko !== product.name_en ? product.name_ko : ''}</div>
                    <div class="text-xs text-gray-500 mt-1">
                        SKU: ${product.sku} | 원가: ${parseFloat(product.cost_price).toFixed(2)} | 재고: ${product.available_quantity}개 | 박스포장: ${product.pieces_per_box || 1}개
                    </div>
                </div>
            `;
        });
        
        productSearchResults.innerHTML = html;
        productSearchResults.classList.remove('hidden');
        
        // 상품 선택 이벤트 - 매입 이력 조회 후 추가
        document.querySelectorAll('.product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                console.log('상품 클릭됨:', this);
                try {
                    window.showPurchaseHistoryBeforeAdd(this);
                } catch (error) {
                    console.error('상품 선택 중 오류:', error);
                    alert('상품 선택 중 오류가 발생했습니다: ' + error.message);
                }
            });
        });
    }
    
    function addToCart(item) {
        const productId = item.dataset.id;
        const sku = item.dataset.sku;
        const nameKo = item.dataset.nameKo;
        const nameEn = item.dataset.nameEn;
        const costPrice = parseFloat(item.dataset.costPrice);
        const availableQuantity = parseInt(item.dataset.availableQuantity);
        const minQuantity = parseInt(item.dataset.minQuantity);
        const piecesPerBox = parseInt(item.dataset.piecesPerBox) || 1; // 실제 상품정보에서 가져오기
        
        // 이미 장바구니에 있는지 확인
        const existingIndex = cart.findIndex(item => item.product_id == productId);
        
        if (existingIndex >= 0) {
            const currentQuantity = cart[existingIndex].quantity;
            const newQuantity = currentQuantity + minQuantity;
            
            cart[existingIndex].quantity = newQuantity;
            cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_cost_price;
        } else {
            cart.push({
                product_id: productId,
                sku: sku,
                name_ko: nameKo,
                name_en: nameEn,
                unit_cost_price: costPrice,
                quantity: minQuantity,
                pieces_per_box: piecesPerBox, // 상품정보의 박스포장수량 사용
                total_price: costPrice * minQuantity,
                remarks: ''
            });
        }
        
        updateCart();
        productSearchResults.classList.add('hidden');
        productSearch.value = '';
    }
    
    function updateCart() {
        if (cart.length === 0) {
            cartEmpty.classList.remove('hidden');
            cartItems.classList.add('hidden');
        } else {
            cartEmpty.classList.add('hidden');
            cartItems.classList.remove('hidden');
            
            let html = '';
            cart.forEach(function(item, index) {
                html += `
                    <tr class="border-b hover:bg-gray-50">
                        <!-- SKU -->
                        <td class="px-2 py-3 text-xs font-mono text-gray-700 font-medium sku-column">${item.sku}</td>
                        
                        <!-- 상품명 (영문 위, 한글 아래) -->
                        <td class="px-2 py-3 product-name">
                            <div class="text-sm font-medium text-gray-900" title="${item.name_en || '-'}">${item.name_en || '-'}</div>
                            <div class="text-sm text-gray-600 mt-1" title="${item.name_ko || '-'}">${item.name_ko || '-'}</div>
                        </td>
                        
                        <!-- 수량 -->
                        <td class="px-2 py-3 text-center">
                            <input type="number" 
                                   class="w-20 px-2 py-1 text-sm text-center border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500" 
                                   value="${item.quantity}"
                                   min="1"
                                   onchange="updateQuantityDirect(${index}, this.value)"
                                   onblur="updateQuantityDirect(${index}, this.value)">
                        </td>
                        
                        <!-- 박스포장수량 -->
                        <td class="px-2 py-3 text-center">
                            <input type="number" 
                                   class="w-20 px-2 py-1 text-sm text-center border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500" 
                                   value="${item.pieces_per_box || 1}"
                                   min="1"
                                   onchange="updatePiecesPerBox(${index}, this.value)"
                                   onblur="updatePiecesPerBox(${index}, this.value)">
                        </td>
                        
                        <!-- 박스원가 -->
                        <td class="px-2 py-3 text-center">
                            <input type="number" 
                                   class="w-24 px-2 py-1 text-sm text-center border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500" 
                                   value="${parseFloat(item.unit_cost_price).toFixed(2)}"
                                   min="0"
                                   step="0.01"
                                   onchange="updateBoxPrice(${index}, this.value)"
                                   onblur="updateBoxPrice(${index}, this.value)">
                        </td>
                        
                        <!-- 매입가 선택 -->
                        <td class="px-2 py-3 text-center">
                            <button type="button" 
                                    onclick="showPurchaseHistoryModal('${item.product_id}', '${(item.name_ko || item.name_en || 'N/A').replace(/'/g, '&apos;')}', '${item.sku}', getFromStoreId())"
                                    class="px-2 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:ring-offset-1 whitespace-nowrap"
                                    title="매입 이력에서 가격 선택">
                                <i class="fas fa-history mr-1"></i>선택
                            </button>
                        </td>
                        
                        <!-- 합계 -->
                        <td class="px-2 py-3 text-right">
                            <div class="text-sm font-semibold text-primary-600">
                                ${parseFloat(item.total_price).toFixed(2)}
                            </div>
                        </td>
                        
                        <!-- 비고 -->
                        <td class="px-2 py-3">
                            <input type="text" 
                                   class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500" 
                                   placeholder="이동 사유, 비고 등"
                                   value="${item.remarks || ''}"
                                   onchange="updateRemarks(${index}, this.value)"
                                   maxlength="100">
                        </td>
                        
                        <!-- 삭제버튼 -->
                        <td class="px-2 py-3 text-center">
                            <button type="button" onclick="removeFromCart(${index})" 
                                    class="text-red-400 hover:text-red-600 w-6 h-6 rounded hover:bg-red-50 flex items-center justify-center">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            cartList.innerHTML = html;
            
            const total = cart.reduce((sum, item) => sum + item.total_price, 0);
            cartTotal.textContent = parseFloat(total).toFixed(2);
        }
        
        cartItemsInput.value = JSON.stringify(cart);
        updateTransferButton();
    }
    
    window.removeFromCart = function(index) {
        cart.splice(index, 1);
        updateCart();
    };
    
    // 수량 직접 입력
    window.updateQuantityDirect = function(index, value) {
        const newQuantity = parseInt(value) || 1;
        
        if (newQuantity <= 0) {
            cart.splice(index, 1);
        } else {
            cart[index].quantity = newQuantity;
            cart[index].total_price = cart[index].quantity * cart[index].unit_cost_price;
        }
        updateCart();
    };
    
    // 박스포장수량 수정
    window.updatePiecesPerBox = function(index, value) {
        const newPiecesPerBox = parseInt(value) || 1;
        cart[index].pieces_per_box = newPiecesPerBox;
        cartItemsInput.value = JSON.stringify(cart);
    };
    
    // 박스원가 수정
    window.updateBoxPrice = function(index, value) {
        const newPrice = parseFloat(value) || 0;
        cart[index].unit_cost_price = newPrice;
        cart[index].total_price = cart[index].quantity * cart[index].unit_cost_price;
        updateCart();
    };
    
    window.updateRemarks = function(index, value) {
        cart[index].remarks = value;
        cartItemsInput.value = JSON.stringify(cart);
    };
    
    function updateTransferButton() {
        const fromStoreId = getFromStoreId();
        const toStoreId = document.getElementById('to_store_id').value;
        const hasItems = cart.length > 0;
        completeTransferBtn.disabled = !fromStoreId || !toStoreId || !hasItems || fromStoreId === toStoreId;
    }
    
    // 점포 선택 변경 시 버튼 상태 업데이트
    document.getElementById('to_store_id').addEventListener('change', updateTransferButton);
    if (userRole === 'super_admin') {
        document.getElementById('from_store_id').addEventListener('change', updateTransferButton);
    }
    
    // 상품 모달 표시 및 전체 목록 로드
    function showProductModal() {
        const fromStoreId = getFromStoreId();
        
        if (!fromStoreId) {
            alert('출발 점포를 먼저 선택해주세요.');
            return;
        }
        
        productModal.classList.remove('hidden');
        modalProductSearch.value = '';
        loadAllProducts();
    }
    
    // 전체 상품 목록 로드
    function loadAllProducts() {
        const fromStoreId = getFromStoreId();
        
        if (!fromStoreId) {
            return;
        }
        
        productList.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin mr-2"></i>로딩 중...</div>';
        
        fetch('ajax_search_transfer_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=&from_store_id=' + encodeURIComponent(fromStoreId) + '&limit=100&show_all=1'
        })
        .then(response => response.json())
        .then(data => {
            console.log('Modal Product List API Response:', data);
            
            if (data.success && data.products) {
                displayModalProductList(data.products);
            } else {
                let errorMessage = '이동 가능한 상품이 없습니다.';
                
                if (data.error_type === 'permission_denied') {
                    errorMessage = '로그인이 필요하거나 권한이 없습니다. 페이지를 새로고침해주세요.';
                } else if (data.error_type === 'database_error') {
                    errorMessage = '데이터베이스 오류가 발생했습니다.';
                    if (data.error_detail) {
                        console.error('Database Error Detail:', data.error_detail);
                    }
                } else if (data.message) {
                    errorMessage = data.message;
                }
                
                productList.innerHTML = '<div class="text-center py-4 text-gray-500">' + errorMessage + '</div>';
            }
        })
        .catch(error => {
            console.error('Network Error loading products:', error);
            productList.innerHTML = '<div class="text-center py-4 text-red-500">네트워크 오류가 발생했습니다. 인터넷 연결을 확인해주세요.</div>';
        });
    }
    
    // 모달 상품 목록 표시
    function displayModalProductList(products) {
        let html = '';
        products.forEach(function(product) {
            html += `
                <div class="modal-product-item p-3 border border-gray-200 rounded-md hover:bg-green-50 cursor-pointer transition-colors duration-200" 
                     data-id="${product.id}" 
                     data-sku="${product.sku}"
                     data-name-ko="${product.name_ko || ''}"
                     data-name-en="${product.name_en || ''}"
                     data-cost-price="${product.cost_price}"
                     data-available-quantity="${product.available_quantity}"
                     data-min-quantity="${product.min_quantity}"
                     data-pieces-per-box="${product.pieces_per_box || 1}">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-sm truncate">
                                ${product.name_en || product.name_ko || 'N/A'}
                            </div>
                            ${product.name_ko && product.name_en && product.name_ko !== product.name_en ? 
                                `<div class="text-xs text-gray-600 mt-1 truncate">${product.name_ko}</div>` : ''}
                            <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-2">
                                <span><i class="fas fa-barcode mr-1"></i>${product.sku}</span>
                                <span class="text-blue-600 font-medium"><i class="fas fa-coins mr-1"></i>${parseFloat(product.cost_price).toFixed(2)}</span>
                                <span><i class="fas fa-box mr-1"></i>재고 ${product.available_quantity}개</span>
                                <span><i class="fas fa-cubes mr-1"></i>박스포장 ${product.pieces_per_box || 1}개</span>
                            </div>
                        </div>
                        <div class="text-green-500 ml-2">
                            <i class="fas fa-plus-circle text-lg"></i>
                        </div>
                    </div>
                </div>
            `;
        });
        
        productList.innerHTML = html;
        
        // 클릭 이벤트 추가
        productList.querySelectorAll('.modal-product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                showPurchaseHistoryBeforeAdd(this);
            });
        });
    }
    
    
    // 수정 모드인 경우 기존 데이터로 폼 초기화
    function initializeEditMode() {
        if (!editMode || !editData) return;
        
        // 점포 선택 설정
        if (editData.from_store_id && document.getElementById('from_store_id')) {
            document.getElementById('from_store_id').value = editData.from_store_id;
            // 출발 점포 설정 후 목적지 점포 옵션 업데이트
            updateToStoreOptions();
        }
        
        if (editData.to_store_id) {
            document.getElementById('to_store_id').value = editData.to_store_id;
        }
        
        // 이동 날짜 설정
        if (editData.transfer_date) {
            document.getElementById('transfer_date').value = editData.transfer_date;
        }
        
        // 장바구니 항목들 복원
        if (editData.items && editData.items.length > 0) {
            cart = [];
            editData.items.forEach(function(item) {
                cart.push({
                    product_id: item.product_id,
                    sku: item.sku,
                    name_ko: item.name_ko,
                    name_en: item.name_en,
                    unit_cost_price: parseFloat(item.unit_cost_price),
                    quantity: parseInt(item.quantity),
                    pieces_per_box: parseInt(item.pieces_per_box) || 1, // 상품정보의 실제 박스포장수량 사용
                    total_price: parseFloat(item.total_price),
                    remarks: item.remarks || ''
                });
            });
            
            updateCart();
        }
    }
    
    // 페이지 로드 시 수정 모드 초기화
    if (editMode) {
        initializeEditMode();
    }
    
    // 초기 버튼 상태 설정
    updateTransferButton();
    
    // 초기 목적지 점포 필터링 실행
    updateToStoreOptions();
});

// 점간이동 삭제 함수
window.deleteTransfer = function() {
    console.log('deleteTransfer 함수 호출됨');
    
    if (!confirm('정말로 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.')) {
        console.log('사용자가 삭제를 취소함');
        return;
    }
    
    // 삭제 버튼 비활성화
    const deleteBtn = document.querySelector('button[onclick="deleteTransfer()"]');
    if (deleteBtn) {
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>삭제 중...';
        console.log('삭제 버튼 비활성화됨');
    }
    
    // 수정 중인 transfer_id 가져오기
    const editTransferIdInput = document.querySelector('input[name="edit_transfer_id"]');
    const transferId = editTransferIdInput ? editTransferIdInput.value : '';
    
    console.log('Transfer ID:', transferId);
    
    if (!transferId) {
        alert('삭제할 점간이동 ID를 찾을 수 없습니다.');
        if (deleteBtn) {
            deleteBtn.disabled = false;
            deleteBtn.innerHTML = '<i class="fas fa-trash mr-1"></i>삭제';
        }
        return;
    }
    
    // 폼 생성 및 제출
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    // action 필드 추가
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'delete';
    form.appendChild(actionInput);
    
    // edit_transfer_id 필드 추가
    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'edit_transfer_id';
    idInput.value = transferId;
    form.appendChild(idInput);
    
    console.log('폼 데이터:', {
        action: 'delete',
        edit_transfer_id: transferId
    });
    
    document.body.appendChild(form);
    form.submit();
};

// 목적지 점포 옵션 업데이트 함수
function updateToStoreOptions() {
    const fromStoreSelect = document.getElementById('from_store_id');
    const toStoreSelect = document.getElementById('to_store_id');
    
    if (!fromStoreSelect || !toStoreSelect) {
        console.log('점포 선택 요소를 찾을 수 없습니다');
        return;
    }
    
    const selectedFromStoreId = fromStoreSelect.value;
    const currentToStoreValue = toStoreSelect.value; // 현재 선택된 목적지 점포 값 저장
    
    console.log('출발 점포 ID:', selectedFromStoreId);
    
    // 모든 목적지 점포 옵션을 순회하며 출발 점포와 같은 것은 숨김
    Array.from(toStoreSelect.options).forEach(function(option) {
        if (option.value === '') {
            // 기본 옵션("목적지 점포를 선택하세요")은 항상 표시
            option.style.display = '';
            option.disabled = false;
        } else if (option.value === selectedFromStoreId) {
            // 출발 점포와 같은 점포는 숨김 및 비활성화
            option.style.display = 'none';
            option.disabled = true;
            
            // 만약 현재 선택된 목적지가 출발 점포와 같다면 선택 해제
            if (option.selected) {
                toStoreSelect.value = '';
            }
        } else {
            // 다른 점포들은 표시
            option.style.display = '';
            option.disabled = false;
        }
    });
    
    // 현재 선택된 목적지 점포 값이 유효하다면 복원
    if (currentToStoreValue && currentToStoreValue !== selectedFromStoreId) {
        toStoreSelect.value = currentToStoreValue;
    }
    
    console.log('목적지 점포 옵션 업데이트 완료');
}

// 상품 추가 전 매입 이력 조회 함수
// 상품 추가 전 매입 이력 조회 함수 (전역 스코프)
window.showPurchaseHistoryBeforeAdd = function(item) {
    console.log('showPurchaseHistoryBeforeAdd 호출됨', item);
    
    try {
        // 데이터 속성을 일관되게 dataset으로 가져오기 (data-name-ko -> dataset.nameKo 자동변환)
        const productId = item.dataset.id;
        const sku = item.dataset.sku;
        const nameKo = item.dataset.nameKo || '';  // data-name-ko -> nameKo
        const nameEn = item.dataset.nameEn || '';  // data-name-en -> nameEn  
        const costPrice = parseFloat(item.dataset.costPrice || 0);
        const availableQuantity = parseInt(item.dataset.availableQuantity || 0);
        const minQuantity = parseInt(item.dataset.minQuantity || 1);
        const piecesPerBox = parseInt(item.dataset.piecesPerBox || 1);
        
        console.log('추출된 상품 데이터:', {
            productId, sku, nameKo, nameEn, costPrice, 
            availableQuantity, minQuantity, piecesPerBox
        });
        
        const fromStoreId = getFromStoreId();
        console.log('출발 점포 ID:', fromStoreId);
        
        if (!fromStoreId) {
            alert('출발 점포를 먼저 선택해주세요.');
            return;
        }
        
        if (!productId) {
            alert('상품 정보를 찾을 수 없습니다.');
            return;
        }
    } catch (error) {
        console.error('데이터 추출 중 오류:', error);
        alert('상품 데이터를 읽는 중 오류가 발생했습니다.');
        return;
    }
    
    // 상품 정보를 전역 변수에 저장 (매입가 선택 후 사용)
    window.pendingProductToAdd = {
        productId, sku, nameKo, nameEn, costPrice, 
        availableQuantity, minQuantity, piecesPerBox, fromStoreId
    };
    
    const productName = nameKo || nameEn || 'N/A';
    
    // 이미 장바구니에 있는지 확인
    const existingIndex = cart.findIndex(cartItem => cartItem.product_id == productId);
    if (existingIndex >= 0) {
        // 이미 있는 상품은 수량만 증가
        const currentQuantity = cart[existingIndex].quantity;
        const newQuantity = currentQuantity + minQuantity;
        
        cart[existingIndex].quantity = newQuantity;
        cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_cost_price;
        updateCart();
        productSearchResults.classList.add('hidden');
        productSearch.value = '';
        return;
    }
    
    // 새 상품인 경우 매입 이력 조회
    showPurchaseHistoryForNewProduct(productId, productName, sku, fromStoreId);
}

// 매입 이력 조회 모달 관련 함수들
function showPurchaseHistoryForNewProduct(productId, productName, productSku, fromStoreId) {
    console.log('새 상품 매입 이력 조회:', { productId, productName, productSku, fromStoreId });
    
    // 상품 정보 설정
    currentSelectedProduct = { productId, productName, productSku, fromStoreId, isNewProduct: true };
    purchaseProductName.textContent = productName || 'N/A';
    purchaseProductSku.textContent = 'SKU: ' + (productSku || 'N/A');
    
    // 모달 제목 업데이트 (새 상품 추가용)
    purchaseModalTitle.innerHTML = '<i class="fas fa-plus mr-2 text-green-500"></i>매입가 선택 후 추가';
    
    // 모달 표시
    purchaseHistoryModal.classList.remove('hidden');
    
    // 매입 이력 로드
    loadPurchaseHistory(productId, fromStoreId);
}

function showPurchaseHistoryModal(productId, productName, productSku, fromStoreId) {
    console.log('매입 이력 모달 표시:', { productId, productName, productSku, fromStoreId });
    
    // 상품 정보 설정
    currentSelectedProduct = { productId, productName, productSku, fromStoreId };
    purchaseProductName.textContent = productName || 'N/A';
    purchaseProductSku.textContent = 'SKU: ' + (productSku || 'N/A');
    
    // 모달 제목 업데이트 (기존 상품 가격 변경용)
    purchaseModalTitle.innerHTML = '<i class="fas fa-history mr-2 text-blue-500"></i>매입가 변경';
    
    // 모달 표시
    purchaseHistoryModal.classList.remove('hidden');
    
    // 매입 이력 로드
    loadPurchaseHistory(productId, fromStoreId);
}

function loadPurchaseHistory(productId, storeId) {
    // 로딩 상태 표시
    purchaseHistoryLoading.classList.remove('hidden');
    purchaseHistoryList.classList.add('hidden');
    purchaseHistoryEmpty.classList.add('hidden');
    
    const params = new URLSearchParams({
        product_id: productId,
        store_id: storeId
    });
    
    fetch('ajax_get_purchase_history.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            purchaseHistoryLoading.classList.add('hidden');
            
            if (data.success && data.data && data.data.length > 0) {
                // 최근 3건만 표시
                const recentHistory = data.data.slice(0, 3);
                displayPurchaseHistory(recentHistory);
            } else {
                purchaseHistoryEmpty.classList.remove('hidden');
                console.log('매입 이력 없음:', data.message || '데이터 없음');
            }
        })
        .catch(error => {
            purchaseHistoryLoading.classList.add('hidden');
            purchaseHistoryEmpty.classList.remove('hidden');
            console.error('매입 이력 조회 오류:', error);
        });
}

function displayPurchaseHistory(historyData) {
    let html = '';
    
    historyData.forEach((item, index) => {
        html += `
            <div class="border border-gray-200 rounded-lg p-3 hover:bg-gray-50 transition-colors">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex-1">
                        <div class="text-sm font-medium text-gray-900">
                            ${item.purchase_date_formatted} | ${item.supplier_name || 'N/A'}
                        </div>
                        <div class="text-xs text-gray-600 mt-1">
                            박스원가: <strong class="text-gray-900">${item.box_cost_formatted}</strong> | 
                            수량: ${item.quantity}개 | 
                            유형: ${item.purchase_type === 'box' ? '박스' : '개별'}
                        </div>
                    </div>
                    <button type="button" 
                            onclick="selectPurchasePrice('${item.box_cost}')"
                            class="ml-3 px-3 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">
                        선택
                    </button>
                </div>
            </div>
        `;
    });
    
    purchaseHistoryList.innerHTML = html;
    purchaseHistoryList.classList.remove('hidden');
}

function selectPurchasePrice(boxCost) {
    const price = parseFloat(boxCost);
    if (isNaN(price) || price <= 0) {
        alert('올바르지 않은 가격입니다.');
        return;
    }
    
    if (currentSelectedProduct) {
        if (currentSelectedProduct.isNewProduct) {
            // 새 상품 추가하기
            addToCartWithSelectedPrice(price);
        } else {
            // 기존 상품 가격 변경
            applyBoxCostToProduct(currentSelectedProduct.productId, price);
        }
        purchaseHistoryModal.classList.add('hidden');
    }
}

// 선택된 매입가로 새 상품을 장바구니에 추가
function addToCartWithSelectedPrice(selectedPrice) {
    if (!window.pendingProductToAdd) {
        console.error('추가할 상품 정보가 없습니다.');
        return;
    }
    
    const product = window.pendingProductToAdd;
    
    cart.push({
        product_id: product.productId,
        sku: product.sku,
        name_ko: product.nameKo,
        name_en: product.nameEn,
        unit_cost_price: selectedPrice, // 선택된 매입가 사용
        quantity: product.minQuantity,
        pieces_per_box: product.piecesPerBox,
        total_price: selectedPrice * product.minQuantity,
        remarks: ''
    });
    
    updateCart();
    productSearchResults.classList.add('hidden');
    productSearch.value = '';
    
    // 임시 상품 정보 정리
    window.pendingProductToAdd = null;
    
    console.log('선택된 매입가로 상품 추가됨:', selectedPrice);
}

function applyBoxCostToProduct(productId, boxCost) {
    // 장바구니에서 해당 상품을 찾아서 박스원가 업데이트
    const cartIndex = cart.findIndex(item => item.product_id == productId);
    if (cartIndex !== -1) {
        cart[cartIndex].unit_cost_price = boxCost;
        cart[cartIndex].total_price = cart[cartIndex].quantity * boxCost;
        updateCart();
        console.log('박스원가 업데이트됨:', boxCost);
    }
}


// 모달 이벤트 리스너들
closePurchaseHistoryModal.addEventListener('click', function() {
    purchaseHistoryModal.classList.add('hidden');
    currentSelectedProduct = null;
    // 임시 상품 정보도 정리
    if (window.pendingProductToAdd) {
        window.pendingProductToAdd = null;
    }
});

manualPriceInputBtn.addEventListener('click', function() {
    const price = prompt('박스원가를 입력해주세요:', '');
    if (price !== null) {
        const numericPrice = parseFloat(price);
        if (!isNaN(numericPrice) && numericPrice > 0) {
            if (currentSelectedProduct) {
                if (currentSelectedProduct.isNewProduct) {
                    // 새 상품 추가하기
                    addToCartWithSelectedPrice(numericPrice);
                } else {
                    // 기존 상품 가격 변경
                    applyBoxCostToProduct(currentSelectedProduct.productId, numericPrice);
                }
                purchaseHistoryModal.classList.add('hidden');
            }
        } else {
            alert('올바른 숫자를 입력해주세요.');
        }
    }
});

// 기본 원가로 추가 버튼 이벤트
useDefaultPriceBtn.addEventListener('click', function() {
    if (currentSelectedProduct && currentSelectedProduct.isNewProduct && window.pendingProductToAdd) {
        // 기본 원가(inventory cost_price) 사용
        const defaultPrice = window.pendingProductToAdd.costPrice;
        addToCartWithSelectedPrice(defaultPrice);
        purchaseHistoryModal.classList.add('hidden');
        console.log('기본 원가로 상품 추가됨:', defaultPrice);
    }
});

// 모달 바깥 클릭 시 닫기
purchaseHistoryModal.addEventListener('click', function(e) {
    if (e.target === this) {
        this.classList.add('hidden');
        currentSelectedProduct = null;
        // 임시 상품 정보도 정리
        if (window.pendingProductToAdd) {
            window.pendingProductToAdd = null;
        }
    }
});

</script>

<style>
/* 모달 스타일링 */
.modal-product-item {
    transition: all 0.2s ease-in-out;
}

.modal-product-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* 모달 애니메이션 */
#product-modal, #purchase-history-modal {
    animation: fadeIn 0.2s ease-in-out;
}

#product-modal > div, #purchase-history-modal > div {
    animation: slideIn 0.2s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* 검색 버튼 호버 효과 */
#product_search_btn {
    transition: all 0.2s ease-in-out;
}

#product_search_btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(34, 197, 94, 0.3);
}

/* 장바구니 테이블 가로 스크롤 */
@media (max-width: 768px) {
    .cart-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .cart-table {
        min-width: 600px;
    }
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
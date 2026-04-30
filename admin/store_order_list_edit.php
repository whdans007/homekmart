<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '점포세팅 주문관리 편집 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 상품 관리 권한 확인
if (!has_permission('product_management') && !has_permission('shop_access')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$errors = [];
$success_message = '';
$order_list = null;
$list_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$list_items = [];
$list_title = '';

// POST 요청 처리 (저장)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_list'])) {
    $list_title = trim($_POST['list_title'] ?? '');
    $items_data = isset($_POST['items_data']) ? json_decode($_POST['items_data'], true) : [];

    error_log("=== Save Order List Debug ===");
    error_log("Title: " . $list_title);
    error_log("Items data: " . print_r($items_data, true));
    error_log("List ID: " . $list_id);

    if (empty($list_title)) {
        $errors[] = '제목을 입력하세요.';
    } elseif (empty($items_data)) {
        $errors[] = '최소 1개 이상의 상품을 추가하세요.';
    } else {
        try {
            $conn = get_db_connection();

            if (!$conn) {
                throw new Exception("데이터베이스 연결 실패");
            }

            $conn->autocommit(false);

            $current_user_id = $_SESSION['user_id'] ?? 0;
            $current_store_id = $_SESSION['store_id'] ?? 0;

            error_log("User ID: " . $current_user_id . ", Store ID: " . $current_store_id);

            if (empty($current_store_id) && !empty($current_user_id)) {
                $user_sql = "SELECT store_id FROM users WHERE id = ?";
                $user_stmt = $conn->prepare($user_sql);
                if (!$user_stmt) {
                    throw new Exception("Prepare 실패: " . $conn->error);
                }
                $user_stmt->bind_param("i", $current_user_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_row = $user_result->fetch_assoc()) {
                    $current_store_id = $user_row['store_id'];
                }
                $user_stmt->close();
            }

            if ($list_id > 0) {
                // 기존 리스트 업데이트
                error_log("Updating existing list ID: " . $list_id);
                $update_sql = "UPDATE store_order_lists SET title = ?, updated_at = NOW() WHERE id = ? AND store_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare 실패: " . $conn->error);
                }
                $update_stmt->bind_param("sii", $list_title, $list_id, $current_store_id);
                if (!$update_stmt->execute()) {
                    throw new Exception("Update 실패: " . $update_stmt->error);
                }

                // 기존 아이템들 삭제
                $delete_sql = "DELETE FROM store_order_list_items WHERE order_list_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                if (!$delete_stmt) {
                    throw new Exception("Prepare 실패: " . $conn->error);
                }
                $delete_stmt->bind_param("i", $list_id);
                if (!$delete_stmt->execute()) {
                    throw new Exception("Delete 실패: " . $delete_stmt->error);
                }
            } else {
                // 새 리스트 생성
                error_log("Creating new list");
                $insert_sql = "INSERT INTO store_order_lists (store_id, title, created_by) VALUES (?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                if (!$insert_stmt) {
                    throw new Exception("Prepare 실패: " . $conn->error);
                }
                $insert_stmt->bind_param("isi", $current_store_id, $list_title, $current_user_id);
                if (!$insert_stmt->execute()) {
                    throw new Exception("Insert 실패: " . $insert_stmt->error);
                }
                $list_id = $conn->insert_id;
                error_log("New list created with ID: " . $list_id);
            }

            // 아이템들 저장
            $item_sql = "INSERT INTO store_order_list_items (order_list_id, product_id, quantity, order_status) VALUES (?, ?, ?, ?)";
            $item_stmt = $conn->prepare($item_sql);
            if (!$item_stmt) {
                throw new Exception("Prepare 실패: " . $conn->error);
            }

            foreach ($items_data as $item) {
                $product_id = (int)$item['product_id'];
                $quantity = (int)($item['quantity'] ?? 1);
                $order_status = $item['order_status'] ?? '주문';
                $name_ko = $item['name_ko'] ?? '';
                $name_en = $item['name_en'] ?? '';
                $pieces_per_box = (int)($item['pieces_per_box'] ?? 1);

                // 상품명이나 박스포장수량이 수정되었으면 products 테이블도 업데이트
                if (!empty($name_ko) || !empty($name_en) || $pieces_per_box > 0) {
                    $updates = [];
                    $params = [];

                    if (!empty($name_ko)) {
                        $updates[] = "name_ko = ?";
                        $params[] = $name_ko;
                    }
                    if (!empty($name_en)) {
                        $updates[] = "name_en = ?";
                        $params[] = $name_en;
                    }
                    if ($pieces_per_box > 0) {
                        $updates[] = "pieces_per_box = ?";
                        $params[] = $pieces_per_box;
                    }
                    $params[] = $product_id;

                    if (!empty($updates)) {
                        $update_product_sql = "UPDATE products SET " . implode(", ", $updates) . " WHERE id = ?";
                        $update_product_stmt = $conn->prepare($update_product_sql);
                        if (!$update_product_stmt) {
                            throw new Exception("Product update prepare 실패: " . $conn->error);
                        }

                        // 동적 bind_param - string과 integer 혼합
                        $types = '';
                        $count = count($params);
                        for ($i = 0; $i < $count - 1; $i++) {
                            $types .= is_numeric($params[$i]) && strpos($params[$i], '.') === false ? 'i' : 's';
                        }
                        $types .= 'i'; // product_id는 항상 integer

                        $update_product_stmt->bind_param($types, ...$params);
                        if (!$update_product_stmt->execute()) {
                            throw new Exception("Product update 실패: " . $update_product_stmt->error);
                        }
                        error_log("Product updated - Product ID: $product_id, name_ko: $name_ko, name_en: $name_en, pieces_per_box: $pieces_per_box");
                    }
                }

                // store_order_list_items에 저장
                $item_stmt->bind_param("iiss", $list_id, $product_id, $quantity, $order_status);
                if (!$item_stmt->execute()) {
                    throw new Exception("Item insert 실패: " . $item_stmt->error);
                }
                error_log("Item added - Product: $product_id, Qty: $quantity, Order Status: $order_status");
            }

            $conn->commit();
            error_log("Transaction committed successfully");
            $conn->close();

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => '주문 리스트가 저장되었습니다.'
            ];

            header('Location: store_order_lists.php');
            exit;

        } catch (Exception $e) {
            error_log("Exception caught: " . $e->getMessage());
            if (isset($conn)) {
                $conn->rollback();
                $conn->close();
            }
            $errors[] = '저장 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

// 기존 리스트 로드
if ($list_id > 0) {
    $conn = get_db_connection();
    $current_store_id = $_SESSION['store_id'] ?? 0;
    $current_user_id = $_SESSION['user_id'] ?? 0;

    // store_id가 없으면 users 테이블에서 조회
    if (empty($current_store_id) && !empty($current_user_id)) {
        $user_sql = "SELECT store_id FROM users WHERE id = ?";
        $user_stmt = $conn->prepare($user_sql);
        if ($user_stmt) {
            $user_stmt->bind_param("i", $current_user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            if ($user_row = $user_result->fetch_assoc()) {
                $current_store_id = $user_row['store_id'];
            }
            $user_stmt->close();
        }
    }

    // store_id가 있으면 with store_id, 없으면 without store_id로 조회
    if (!empty($current_store_id)) {
        $list_sql = "SELECT * FROM store_order_lists WHERE id = ? AND store_id = ?";
        $list_stmt = $conn->prepare($list_sql);
        $list_stmt->bind_param("ii", $list_id, $current_store_id);
    } else {
        // store_id가 없으면 ID로만 조회 (super_admin 등)
        $list_sql = "SELECT * FROM store_order_lists WHERE id = ?";
        $list_stmt = $conn->prepare($list_sql);
        $list_stmt->bind_param("i", $list_id);
    }

    $list_stmt->execute();
    $list_result = $list_stmt->get_result();

    if ($list_result->num_rows === 0) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '리스트를 찾을 수 없습니다.'
        ];
        header('Location: store_order_lists.php');
        exit;
    }

    $order_list = $list_result->fetch_assoc();
    $list_title = $order_list['title'];

    // 리스트 아이템들 로드
    $items_sql = "SELECT
                      soli.product_id,
                      soli.quantity,
                      soli.order_status,
                      p.sku,
                      p.name_en,
                      p.name_ko,
                      COALESCE(p.pieces_per_box, 1) as pieces_per_box
                  FROM store_order_list_items soli
                  JOIN products p ON soli.product_id = p.id
                  WHERE soli.order_list_id = ?
                  ORDER BY soli.id ASC";

    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("i", $list_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();

    while ($item_row = $items_result->fetch_assoc()) {
        $list_items[] = [
            'product_id' => (int)$item_row['product_id'],
            'sku' => $item_row['sku'],
            'name_en' => $item_row['name_en'],
            'name_ko' => $item_row['name_ko'],
            'pieces_per_box' => (int)$item_row['pieces_per_box'],
            'quantity' => (int)$item_row['quantity'],
            'order_status' => $item_row['order_status']
        ];
    }

    $conn->close();
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<style>
main {
    overflow: hidden !important;
}

.store-order-wrapper {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.order-card {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

.order-scroll-area {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    min-height: 0;
}

.order-section-fixed {
    flex-shrink: 0;
}

.responsive-table {
    overflow-x: auto;
    width: 100%;
}

.table-compact td, .table-compact th {
    padding: 0.5rem 0.75rem !important;
    font-size: 0.875rem;
}

#cartTable {
    width: 100% !important;
    table-layout: auto !important;
}

@media (max-width: 767px) {
    .mobile-hidden { display: none !important; }

    th[data-column="quantity"], td[data-column="quantity"],
    th[data-column="remarks"], td[data-column="remarks"] {
        display: none !important;
    }

    th[data-column="sku"] {
        display: none !important;
    }

    td[data-column="sku"] {
        display: none !important;
    }
}
</style>

<div class="w-full px-2 sm:px-3 md:px-4 store-order-wrapper">
    <div class="mb-2 hidden md:block">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li>
                    <a href="index.php" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-home mr-1"></i>
                        대시보드
                    </a>
                </li>
                <li>
                    <div class="flex items-center">
                        <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                        <a href="store_order_lists.php" class="text-gray-400 hover:text-gray-600">점포세팅 주문관리</a>
                    </div>
                </li>
                <li>
                    <div class="flex items-center">
                        <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                        <span class="text-gray-600"><?php echo $order_list ? '수정' : '새로 등록'; ?></span>
                    </div>
                </li>
            </ol>
        </nav>
    </div>

    <?php if (isset($flash)): ?>
        <div class="mb-4 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
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
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <?php foreach ($errors as $error): ?>
                <div class="flex mb-2">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400 order-card">

        <!-- 제목 입력 섹션 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 order-section-fixed">
            <label class="block text-sm font-medium text-gray-700 mb-2">리스트 제목</label>
            <input type="text" id="list_title" value="<?php echo htmlspecialchars($list_title); ?>"
                   class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                   placeholder="예: 라면, 장류 등">
        </div>

        <!-- 상품 검색 섹션 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white order-section-fixed">
            <div class="flex justify-between items-start mb-3">
                <h3 class="text-lg leading-6 font-semibold text-gray-900">
                    <i class="fas fa-list-ul mr-2 text-blue-500"></i>
                    상품 목록
                </h3>
            </div>
            <div class="flex space-x-3">
                <!-- 상품 검색 입력창 -->
                <div class="relative flex-1">
                    <div class="flex">
                        <input type="text" id="product_search"
                               class="flex-1 px-3 py-2 border border-gray-300 rounded-l-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="SKU 또는 상품명으로 검색..."
                               autocomplete="off">
                        <div class="px-2 py-2 bg-blue-50 border border-l-0 border-gray-300 rounded-r-md flex items-center">
                            <i class="fas fa-search text-blue-600 text-sm"></i>
                        </div>
                    </div>
                    <div id="product_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                        <!-- 검색 결과가 여기에 표시됩니다 -->
                    </div>
                </div>
                <button type="button" id="product_search_btn"
                        class="inline-flex items-center px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-search mr-1"></i>
                    검색
                </button>
            </div>
        </div>

        <!-- 스크롤 가능한 영역 -->
        <div class="order-scroll-area">
            <div id="cart_empty" class="px-6 py-12 text-center text-gray-500 border border-gray-100">
                <i class="fas fa-box-open text-4xl mb-4"></i>
                <p>추가된 상품이 없습니다</p>
                <p class="text-sm">위의 검색창에서 상품을 검색하여 추가하세요</p>
            </div>

            <div id="cart_items" class="hidden">
            <table class="min-w-full table-compact" id="cartTable">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100" data-column="sku">SKU</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100" data-column="name">상품명</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100" data-column="quantity">수량</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100" data-column="order-status">주문상태</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100" data-column="actions">삭제</th>
                    </tr>
                </thead>
                <tbody class="bg-white" id="cart_list">
                    <!-- Cart items will be added here -->
                </tbody>
            </table>

                <!-- 총 상품수 (테이블 푸터 스타일) -->
                <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex items-center justify-between w-full">
                    <div class="text-sm font-medium text-gray-700">
                        총 상품수
                    </div>
                    <div class="text-sm font-semibold text-gray-900" id="cart_total">
                        0 개
                    </div>
                </div>
            </div>
        </div>
        </div>

        <!-- 저장 버튼 -->
        <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex justify-center space-x-3 order-section-fixed">
            <button type="button" id="preview_btn"
                    class="px-6 py-3 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:hover:bg-gray-400 font-semibold"
                    disabled>
                <i class="fas fa-eye mr-2"></i>
                미리보기
            </button>

            <form method="POST" id="saveForm" class="inline">
                <input type="hidden" name="list_title" id="list_title_hidden">
                <input type="hidden" name="items_data" id="itemsDataInput">
                <input type="hidden" name="save_list" value="1">
                <button type="submit" id="save_list_btn"
                        class="px-6 py-3 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:hover:bg-gray-400 font-semibold"
                        disabled>
                    <i class="fas fa-save mr-2"></i>
                    저장
                </button>
            </form>

            <a href="store_order_lists.php"
               class="inline-flex items-center px-6 py-3 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                <i class="fas fa-arrow-left mr-2"></i>
                취소
            </a>
        </div>
    </div>

</div>

<!-- 미리보기 모달 (body 최상위에 위치해야 overflow:hidden 영향 없음) -->
<div id="preview_modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div style="width:92%; height:90vh; max-width:1200px; background:white; border-radius:10px; display:flex; flex-direction:column; box-shadow:0 25px 50px rgba(0,0,0,0.4); overflow:hidden;">
        <!-- 모달 헤더 -->
        <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 24px; border-bottom:1px solid #e5e7eb; flex-shrink:0; background:#f9fafb;">
            <h2 style="font-size:18px; font-weight:600; color:#111827; margin:0;">
                <i class="fas fa-print" style="color:#16a34a; margin-right:8px;"></i>
                주문 리스트 미리보기
            </h2>
            <div style="display:flex; align-items:center; gap:10px;">
                <button type="button" id="print_preview_btn"
                        style="padding:8px 18px; background:#2563eb; color:white; border:none; border-radius:6px; font-size:14px; font-weight:500; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-print"></i> 인쇄
                </button>
                <button type="button" id="close_preview_btn"
                        style="padding:8px 14px; background:#ef4444; color:white; border:none; border-radius:6px; font-size:14px; font-weight:500; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-times"></i> 닫기
                </button>
            </div>
        </div>

        <!-- 모달 콘텐츠 -->
        <div id="preview_modal_content" style="flex:1; overflow-y:auto; overflow-x:auto; background:white; padding:0;">
            <!-- 컨텐츠가 여기에 로드됩니다 -->
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let cart = [];
    let searchTimeout;
    const listId = <?php echo $list_id; ?>;

    const productSearch = document.getElementById('product_search');
    const productSearchResults = document.getElementById('product_search_results');
    const cartEmpty = document.getElementById('cart_empty');
    const cartItems = document.getElementById('cart_items');
    const cartList = document.getElementById('cart_list');
    const cartTotal = document.getElementById('cart_total');
    const saveListBtn = document.getElementById('save_list_btn');
    const listTitleInput = document.getElementById('list_title');
    const previewBtn = document.getElementById('preview_btn');
    const previewModal = document.getElementById('preview_modal');
    const previewModalContent = document.getElementById('preview_modal_content');
    const closePreviewBtn = document.getElementById('close_preview_btn');
    const printPreviewBtn = document.getElementById('print_preview_btn');

    // 모달을 body 최상위로 이동 (overflow:hidden 컨테이너 영향 차단)
    if (previewModal && previewModal.parentNode !== document.body) {
        document.body.appendChild(previewModal);
    }

    // 높이 동적 계산 함수
    function adjustHeights() {
        const main = document.querySelector('main');
        const wrapper = document.querySelector('.store-order-wrapper');
        const card = document.querySelector('.order-card');

        if (main && wrapper) {
            const mainHeight = window.innerHeight;
            const headerHeight = 64; // h-16 = 64px

            // main 높이 설정
            main.style.height = mainHeight + 'px';
            main.style.overflow = 'hidden';

            // wrapper 높이 설정 (main의 내부 높이)
            wrapper.style.height = (mainHeight - headerHeight) + 'px';

            console.log('Heights adjusted - main: ' + mainHeight + 'px, wrapper: ' + (mainHeight - headerHeight) + 'px');
        }
    }

    // 초기 높이 설정
    adjustHeights();

    // 윈도우 리사이즈시 높이 재계산
    window.addEventListener('resize', adjustHeights);

    // 기존 리스트 로드
    if (listId > 0) {
        loadExistingList();
    }

    // 저장 버튼 활성화/비활성화 체크
    updateSaveButton();

    // 저장 폼 제출
    document.getElementById('saveForm').addEventListener('submit', function(e) {
        const title = listTitleInput.value.trim();

        if (!title) {
            e.preventDefault();
            showNotification('제목을 입력하세요.', 'error');
            return false;
        }

        if (cart.length === 0) {
            e.preventDefault();
            showNotification('최소 1개 이상의 상품을 추가하세요.', 'error');
            return false;
        }

        // 리스트 제목을 hidden 필드에 설정
        document.getElementById('list_title_hidden').value = title;

        // 장바구니 데이터를 hidden input에 설정
        const itemsData = cart.map(item => ({
            product_id: item.product_id,
            quantity: item.quantity,
            order_status: item.order_status || '주문',
            name_ko: item.name_ko || '',
            name_en: item.name_en || '',
            pieces_per_box: item.pieces_per_box || 1
        }));

        document.getElementById('itemsDataInput').value = JSON.stringify(itemsData);
        console.log('Submitting data:', {
            title: title,
            items: itemsData
        });
        return true;
    });

    // 검색 입력값 정제 함수 (맨 앞 쉼표 제거)
    function cleanBarcodeInput(input) {
        return input.trim().replace(/^,+/, '').trim();
    }

    // 검색 버튼 클릭 (일반 검색)
    document.getElementById('product_search_btn').addEventListener('click', function() {
        let query = cleanBarcodeInput(productSearch.value);
        if (query.length > 0) {
            searchProducts(query, false);
        }
    });

    // 입력시 자동 검색 (Debounce 적용)
    productSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        let query = cleanBarcodeInput(this.value);

        if (query.length > 0) {
            // 300ms 후 검색 실행 (연속 입력시 마지막 입력만 검색)
            searchTimeout = setTimeout(() => {
                searchProducts(query, false);
            }, 300);
        } else {
            // 검색어가 없으면 결과 숨김
            productSearchResults.classList.add('hidden');
        }
    });

    // Enter 키로 검색 (바코드 스캔 감지)
    productSearch.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimeout);
            let query = cleanBarcodeInput(productSearch.value);
            if (query.length > 0) {
                // Enter 키 입력은 바코드 스캔으로 간주 (즉시 검색)
                searchProducts(query, true);
            }
        }
    });

    function searchProducts(query, isBarcodeScan = false) {
        fetch('ajax_search_order_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=20'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.products && data.products.length > 0) {
                // 바코드 스캔이고 결과가 정확히 1개면 자동 추가
                if (isBarcodeScan && data.products.length === 1) {
                    const product = data.products[0];
                    const added = addToCart({
                        product_id: product.id,
                        sku: product.sku,
                        name_en: product.name_en,
                        name_ko: product.name_ko,
                        pieces_per_box: product.pieces_per_box || 1
                    });
                    // 추가 성공 시에만 알림 표시 (중복이면 addToCart에서 경고)
                    if (added) {
                        showNotification(`"${product.name_ko || product.name_en}" 상품이 추가되었습니다.`, 'info');
                    }
                } else {
                    showSearchResults(data.products);
                }
            } else {
                productSearchResults.innerHTML = '<div class="p-3 text-gray-500 text-sm">검색 결과가 없습니다.</div>';
                productSearchResults.classList.remove('hidden');
                if (isBarcodeScan) {
                    showNotification('검색된 상품이 없습니다.', 'warning');
                }
            }
        })
        .catch(error => {
            console.error('Search error:', error);
            showNotification('검색 중 오류가 발생했습니다.', 'error');
        });
    }

    function showSearchResults(products) {
        productSearchResults.innerHTML = products.map(product => {
            const displayName = product.name_ko || product.name_en || '상품명 없음';
            const boxUnit = product.pieces_per_box || 1;
            return `
                <div class="p-2 border-b border-gray-100 hover:bg-blue-50 cursor-pointer"
                     onclick="addToCart({
                         product_id: ${product.id},
                         sku: '${product.sku.replace(/'/g, "\\'")}',
                         name_en: '${product.name_en.replace(/'/g, "\\'")}',
                         name_ko: '${product.name_ko.replace(/'/g, "\\'")}',
                         pieces_per_box: ${boxUnit}
                     })">
                    <div class="text-sm font-medium text-gray-900">${product.sku} - ${displayName}</div>
                    <div class="text-xs text-gray-500">박스포장수량: ${boxUnit}개</div>
                </div>
            `;
        }).join('');
        productSearchResults.classList.remove('hidden');
    }

    window.addToCart = function(product) {
        // 중복 확인
        const exists = cart.find(item => item.product_id === product.product_id);

        // 검색 필드 초기화 (중복이든 아니든 항상 실행)
        productSearch.value = '';
        productSearchResults.classList.add('hidden');
        productSearch.focus();

        if (exists) {
            showNotification('이미 추가된 상품입니다.', 'warning');
            return false;
        }

        cart.push({
            product_id: product.product_id,
            sku: product.sku,
            name_en: product.name_en,
            name_ko: product.name_ko,
            pieces_per_box: product.pieces_per_box || 1,
            quantity: 1,
            order_status: '주문'
        });

        renderCart();
        updateSaveButton();

        // 추가된 상품이 보이도록 스크롤
        setTimeout(() => {
            const scrollArea = document.querySelector('.order-scroll-area');
            if (scrollArea) {
                scrollArea.scrollTop = scrollArea.scrollHeight;
            }
        }, 0);

        return true;
    };

    window.removeFromCart = function(productId) {
        cart = cart.filter(item => item.product_id !== productId);
        renderCart();
        updateSaveButton();
    };

    window.updateQuantity = function(productId, newQuantity) {
        const item = cart.find(item => item.product_id === productId);
        if (item) {
            item.quantity = Math.max(1, parseInt(newQuantity) || 1);
            renderCart();
            updateSaveButton();
        }
    };

    window.toggleOrderStatus = function(productId, currentStatus, listId) {
        console.log('toggleOrderStatus called:', { productId, currentStatus, listId });
        const newStatus = currentStatus === '주문' ? '비주문' : '주문';
        console.log('newStatus:', newStatus);
        const item = cart.find(item => item.product_id === productId);
        console.log('found item:', item);

        if (item) {
            item.order_status = newStatus;
            console.log('updated item:', item);
            renderCart();
            updateSaveButton();
        }

        // 기존 리스트를 편집하는 경우, 즉시 AJAX로 저장
        if (listId > 0) {
            console.log('saving to database for listId:', listId);
            saveOrderStatusImmediately(productId, newStatus, listId);
        }
    };

    window.saveOrderStatusImmediately = function(productId, orderStatus, listId) {
        fetch('ajax_update_order_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'product_id=' + productId + '&list_id=' + listId + '&order_status=' + encodeURIComponent(orderStatus)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('주문 상태가 저장되었습니다.', 'info');
            } else {
                showNotification('저장 실패: ' + data.message, 'error');
                // 상태 복원
                const item = cart.find(item => item.product_id === productId);
                if (item) {
                    item.order_status = orderStatus === '주문' ? '비주문' : '주문';
                    renderCart();
                }
            }
        })
        .catch(error => {
            console.error('Save error:', error);
            showNotification('저장 중 오류가 발생했습니다.', 'error');
            // 상태 복원
            const item = cart.find(item => item.product_id === productId);
            if (item) {
                item.order_status = orderStatus === '주문' ? '비주문' : '주문';
                renderCart();
            }
        });
    };

    window.updateProductName = function(productId, newName) {
        const item = cart.find(item => item.product_id === productId);
        if (item) {
            item.name_ko = newName;
            updateSaveButton();
        }
    };

    window.updateProductNameEn = function(productId, newName) {
        const item = cart.find(item => item.product_id === productId);
        if (item) {
            item.name_en = newName;
            updateSaveButton();
        }
    };

    window.updateBoxPacking = function(productId, newValue) {
        const item = cart.find(item => item.product_id === productId);
        if (item) {
            item.pieces_per_box = Math.max(1, parseInt(newValue) || 1);
            renderCart();
            updateSaveButton();
        }
    };

    function renderCart() {
        if (cart.length === 0) {
            cartEmpty.classList.remove('hidden');
            cartItems.classList.add('hidden');
        } else {
            cartEmpty.classList.add('hidden');
            cartItems.classList.remove('hidden');

            cartList.innerHTML = cart.map(item => {
                const status = item.order_status || '주문';
                const statusLabel = status === '주문' ? '주문' : '비주문';
                const statusBgColor = status === '주문' ? 'bg-blue-100' : 'bg-red-100';
                const statusTextColor = status === '주문' ? 'text-blue-700' : 'text-red-700';

                console.log('Rendering item:', { product_id: item.product_id, order_status: item.order_status, status: status });

                return `
                <tr class="border-b border-gray-200">
                    <td class="px-6 py-4 text-sm text-gray-900 border border-gray-100" data-column="sku">${item.sku}</td>
                    <td class="px-6 py-4 text-sm text-gray-900 border border-gray-100" data-column="name">
                        <input type="text" value="${(item.name_ko || item.name_en).replace(/"/g, '&quot;')}"
                               class="w-full px-2 py-1 text-sm border border-gray-300 rounded mb-1"
                               placeholder="상품명 (한글)"
                               onchange="updateProductName(${item.product_id}, this.value)">
                        <input type="text" value="${item.name_en.replace(/"/g, '&quot;')}"
                               class="w-full px-2 py-1 text-sm border border-gray-300 rounded text-xs text-gray-600"
                               placeholder="Product name (English)"
                               onchange="updateProductNameEn(${item.product_id}, this.value)">
                    </td>
                    <td class="px-6 py-4 text-center border border-gray-100" data-column="quantity">
                        <input type="number" value="${item.quantity}" min="1"
                               class="w-16 px-2 py-1 text-sm border border-gray-300 rounded"
                               onchange="updateQuantity(${item.product_id}, this.value)">
                    </td>
                    <td class="px-6 py-4 text-center border border-gray-100" data-column="order-status">
                        <button type="button" class="px-3 py-1 text-sm font-semibold rounded ${statusBgColor} ${statusTextColor} hover:opacity-80 transition-opacity cursor-pointer"
                                data-product-id="${item.product_id}" data-status="${status}" data-list-id="${listId}"
                                onclick="window.toggleOrderStatus(${item.product_id}, '${status}', ${listId}); return false;">
                            ${statusLabel}
                        </button>
                    </td>
                    <td class="px-6 py-4 text-center border border-gray-100" data-column="actions">
                        <button type="button" onclick="removeFromCart(${item.product_id})"
                                class="text-red-600 hover:text-red-900 text-sm">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            }).join('');

            const totalQuantity = cart.reduce((sum, item) => sum + item.quantity, 0);
            cartTotal.textContent = totalQuantity + ' 개';
        }
    }

    function updateSaveButton() {
        const title = listTitleInput.value.trim();
        const hasItems = cart.length > 0;
        saveListBtn.disabled = !(title && hasItems);

        // 미리보기 버튼은 기존 리스트에서만 활성화 (listId > 0)
        if (previewBtn) {
            previewBtn.disabled = !(hasItems && listId > 0);
        }
    }

    listTitleInput.addEventListener('input', updateSaveButton);

    function loadExistingList() {
        <?php if (!empty($list_items)): ?>
        cart = <?php echo json_encode($list_items); ?>;
        renderCart();
        <?php endif; ?>
    }

    function showNotification(message, type = 'info') {
        const bgColor = type === 'error' ? 'bg-red-100' : type === 'warning' ? 'bg-yellow-100' : 'bg-blue-100';
        const textColor = type === 'error' ? 'text-red-700' : type === 'warning' ? 'text-yellow-700' : 'text-blue-700';

        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 ${bgColor} ${textColor} px-4 py-3 rounded-md shadow-lg z-50 max-w-sm`;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => notification.remove(), 3000);
    }

    // 미리보기 모달 이벤트 연결
    if (previewBtn) {
        previewBtn.addEventListener('click', function() {
            openPrintPreview();
        });
    }

    if (closePreviewBtn) {
        closePreviewBtn.addEventListener('click', function() {
            closeModal();
        });
    }

    if (printPreviewBtn) {
        printPreviewBtn.addEventListener('click', function() {
            const content = previewModalContent.innerHTML;
            const printWindow = window.open('', '_blank', 'width=900,height=700');
            printWindow.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>주문 리스트 인쇄</title><style>body{margin:0;padding:0;}</style></head><body>' + content + '</body></html>');
            printWindow.document.close();
            printWindow.focus();
            setTimeout(function() {
                printWindow.print();
                printWindow.close();
            }, 300);
        });
    }

    // 모달 외부 클릭으로 닫기
    if (previewModal) {
        previewModal.addEventListener('click', function(e) {
            if (e.target === previewModal) {
                closeModal();
            }
        });
    }

    window.openPrintPreview = function() {
        if (listId <= 0) {
            showNotification('먼저 리스트를 저장하세요.', 'warning');
            return;
        }

        previewModalContent.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:200px;"><i class="fas fa-spinner fa-spin" style="font-size:2rem; color:#9ca3af;"></i></div>';
        previewModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        fetch('ajax_get_order_list_preview.php?id=' + listId)
            .then(response => {
                if (!response.ok) {
                    throw new Error('미리보기를 불러올 수 없습니다.');
                }
                return response.text();
            })
            .then(html => {
                previewModalContent.innerHTML = html;
            })
            .catch(error => {
                console.error('Preview error:', error);
                previewModalContent.innerHTML = '<div class="p-8 text-center text-red-600"><i class="fas fa-exclamation-circle text-4xl mb-4"></i><p>' + error.message + '</p></div>';
            });
    };

    window.closeModal = function() {
        previewModal.style.display = 'none';
        previewModalContent.innerHTML = '';
        document.body.style.overflow = '';
    };
});

// 인쇄시 모달 내용만 출력
window.addEventListener('beforeprint', function() {
    const previewModal = document.getElementById('preview_modal');
    if (previewModal && previewModal.style.display === 'flex') {
        document.body.style.overflow = 'hidden';
    }
});

window.addEventListener('afterprint', function() {
    document.body.style.overflow = 'auto';
});
</script>

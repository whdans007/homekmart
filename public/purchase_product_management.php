<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('purchase_product.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();

// 날짜 변수
$selected_date = $_GET['date'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

// 현재 사용자의 점포 정보 가져오기
$current_store_name = t('store.main_store');
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $user_stmt = $conn->prepare("SELECT s.name as store_name, s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $current_store_name = $user_row['store_name'] ?? t('store.main_store');
            $current_store_id = $user_row['store_id'];
        }
        $user_stmt->close();
    } catch (Exception $e) {
        error_log("Store info error: " . $e->getMessage());
    }
}

// 선택된 날짜의 매입 상품 조회
$purchase_products = [];

try {
    // deleted_at 컬럼 존재 여부 확인
    $check_deleted_at = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
    $has_deleted_at = $check_deleted_at->num_rows > 0;
    
    $deleted_condition = $has_deleted_at ? "AND p.deleted_at IS NULL" : "";
    
    $sql = "
        SELECT 
            pr.id as product_id,
            pr.sku,
            pr.name_en,
            pr.name_ko,
            pr.pieces_per_box,
            pi.quantity,
            pi.unit_price,
            pi.purchase_type,
            pi.discount_rate,
            pi.discounted_total,
            COALESCE(pi.discounted_total, pi.quantity * pi.unit_price) as total_amount,
            s.name as supplier_name,
            p.purchase_date,
            pi.item_id as purchase_item_id,
            p.purchase_id
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.purchase_id
        JOIN products pr ON pi.product_id = pr.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE DATE(p.purchase_date) = ? 
        $deleted_condition
        ORDER BY p.purchase_date DESC, pr.name_ko ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $selected_date);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // 낱개단가 계산
        $piece_price = 0;
        if ($row['purchase_type'] === 'box' && $row['pieces_per_box'] > 0) {
            $piece_price = $row['unit_price'] / $row['pieces_per_box'];
        } elseif ($row['purchase_type'] === 'piece') {
            $piece_price = $row['unit_price'];
        }
        
        $row['piece_price'] = $piece_price;
        $purchase_products[] = $row;
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("Purchase products query error: " . $e->getMessage());
}

$conn->close();
?>

<div class="container mx-auto px-2 py-4">
    <div class="flex justify-between items-center mb-3">
        <div>
            <h1 class="text-lg font-bold text-gray-900"><?php echo t('purchase_product.management'); ?></h1>
            <p class="text-xs text-gray-600"><?php echo t('purchase_product.description'); ?></p>
        </div>
    </div>

    <!-- 날짜 네비게이션 -->
    <div class="bg-white rounded shadow mb-3">
        <div class="px-3 py-2">
            <div class="flex items-center justify-center space-x-2">
                <a href="?date=<?php echo $prev_date; ?>" class="inline-flex items-center px-2 py-1 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                    <i class="fas fa-chevron-left mr-1"></i>
                    <?php echo t('common.previous'); ?>
                </a>
                
                <div class="flex items-center space-x-2">
                    <input type="date" id="date-picker" value="<?php echo $selected_date; ?>" 
                           class="border border-gray-300 rounded px-2 py-1 text-xs"
                           onchange="window.location.href='?date=' + this.value;">
                    <span class="text-sm font-semibold text-gray-900">
                        <?php echo date('Y년 m월 d일 (l)', strtotime($selected_date)); ?>
                    </span>
                </div>
                
                <a href="?date=<?php echo $next_date; ?>" class="inline-flex items-center px-2 py-1 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                    <?php echo t('common.next'); ?>
                    <i class="fas fa-chevron-right ml-1"></i>
                </a>
            </div>
        </div>
    </div>


    <!-- 상품 목록 테이블 -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg leading-6 font-medium text-gray-900">
                <?php echo t('purchase_product.product_list'); ?>
                <span class="text-sm text-gray-500 ml-2">(<?php echo count($purchase_products); ?><?php echo t('common.items'); ?>)</span>
            </h3>
        </div>
        
        <?php if (empty($purchase_products)): ?>
        <div class="px-6 py-12 text-center">
            <i class="fas fa-inbox text-gray-400 text-4xl mb-4"></i>
            <p class="text-gray-500"><?php echo t('purchase_product.no_data'); ?></p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('product.sku'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-500 uppercase">
                            상품명
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase.quantity'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase.unit_price'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase_product.piece_price'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase_product.stock_quantity'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase.total_amount'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($purchase_products as $product): ?>
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="showProductDetails(<?php echo $product['product_id']; ?>)">
                        <td class="px-2 py-1 whitespace-nowrap text-xs font-medium text-gray-900">
                            <?php echo htmlspecialchars($product['sku']); ?>
                        </td>
                        <td class="px-2 py-1 text-xs text-gray-900">
                            <div class="text-gray-800"><?php echo htmlspecialchars($product['name_en']); ?></div>
                            <div class="text-gray-600"><?php echo htmlspecialchars($product['name_ko']); ?></div>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['quantity']); ?>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['unit_price'], 2); ?>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['piece_price'], 2); ?>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php
                            $total_pieces = $product['purchase_type'] === 'box' ? 
                                $product['quantity'] * $product['pieces_per_box'] : 
                                $product['quantity'];
                            echo number_format($total_pieces);
                            ?>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right font-medium">
                            <?php echo number_format($product['total_amount'], 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="6" class="px-2 py-1 text-right text-xs font-medium text-gray-900">
                            <?php echo t('common.total'); ?>:
                        </td>
                        <td class="px-2 py-1 text-right text-xs font-bold text-gray-900">
                            <?php echo number_format(array_sum(array_column($purchase_products, 'total_amount')), 2); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 상품 상세 정보 모달 -->
<div id="productModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
        
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                        <?php echo t('product.details'); ?>
                    </h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" onclick="closeProductModal()">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div id="productDetails">
                    <!-- 상품 상세 정보가 여기에 로드됩니다 -->
                    <div class="text-center py-8">
                        <i class="fas fa-spinner fa-spin text-gray-400 text-2xl mb-2"></i>
                        <p class="text-gray-500"><?php echo t('common.loading'); ?>...</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primary-600 text-base font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:ml-3 sm:w-auto sm:text-sm" onclick="saveProductDetails()">
                    <?php echo t('common.save'); ?>
                </button>
                <button type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm" onclick="closeProductModal()">
                    <?php echo t('common.cancel'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function showProductDetails(productId) {
    const modal = document.getElementById('productModal');
    const detailsContainer = document.getElementById('productDetails');
    
    // 모달 표시
    modal.classList.remove('hidden');
    
    // 로딩 상태 표시
    detailsContainer.innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-gray-400 text-2xl mb-2"></i>
            <p class="text-gray-500"><?php echo t('common.loading'); ?>...</p>
        </div>
    `;
    
    // AJAX로 상품 상세 정보 로드
    fetch('ajax_get_purchase_product_details.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'product_id=' + encodeURIComponent(productId)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            detailsContainer.innerHTML = data.html;
        } else {
            detailsContainer.innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-exclamation-triangle text-red-400 text-2xl mb-2"></i>
                    <p class="text-red-500">${data.message}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        detailsContainer.innerHTML = `
            <div class="text-center py-8">
                <i class="fas fa-exclamation-triangle text-red-400 text-2xl mb-2"></i>
                <p class="text-red-500"><?php echo t('common.error_occurred'); ?></p>
            </div>
        `;
    });
}

function closeProductModal() {
    document.getElementById('productModal').classList.add('hidden');
}

function saveProductDetails() {
    const form = document.getElementById('productEditForm');
    if (!form) return;
    
    const formData = new FormData(form);
    
    fetch('ajax_update_purchase_product.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('<?php echo t('common.save_success'); ?>');
            closeProductModal();
            // 페이지 새로고침하여 변경사항 반영
            window.location.reload();
        } else {
            alert('<?php echo t('common.save_error'); ?>: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('<?php echo t('common.error_occurred'); ?>');
    });
}

// ESC 키로 모달 닫기
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeProductModal();
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
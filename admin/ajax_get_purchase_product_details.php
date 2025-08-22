<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !has_permission('purchase_management')) {
    echo json_encode([
        'success' => false,
        'message' => t('messages.permission_denied')
    ]);
    exit;
}

if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
    echo json_encode([
        'success' => false,
        'message' => t('product.invalid_product_id')
    ]);
    exit;
}

$product_id = (int)$_POST['product_id'];
$conn = get_db_connection();

try {
    // 상품 기본 정보 조회
    $product_sql = "
        SELECT 
            p.id, p.sku, p.name_en, p.name_ko, p.description, p.barcode,
            p.pieces_per_box, p.is_active, p.image_url, p.created_at, p.updated_at,
            b.name_ko as brand_name_ko, b.name_en as brand_name_en,
            c.name as category_name, c.id as category_id,
            b.id as brand_id
        FROM products p
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.id = ?
    ";
    
    $stmt = $conn->prepare($product_sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$product = $result->fetch_assoc()) {
        echo json_encode([
            'success' => false,
            'message' => t('product.not_found')
        ]);
        exit;
    }
    
    // 현재 점포의 재고 정보 조회
    $inventory_sql = "
        SELECT 
            inv.cost_price, inv.selling_price, inv.quantity,
            s.name as store_name, s.id as store_id
        FROM inventory inv
        JOIN stores s ON inv.store_id = s.id
        WHERE inv.product_id = ?
        ORDER BY s.name
    ";
    
    $inv_stmt = $conn->prepare($inventory_sql);
    $inv_stmt->bind_param("i", $product_id);
    $inv_stmt->execute();
    $inv_result = $inv_stmt->get_result();
    
    $inventory = [];
    while ($inv_row = $inv_result->fetch_assoc()) {
        $inventory[] = $inv_row;
    }
    
    // 카테고리 목록 조회 (편집용)
    $categories_sql = "SELECT id, name FROM categories ORDER BY name";
    $cat_result = $conn->query($categories_sql);
    $categories = [];
    while ($cat = $cat_result->fetch_assoc()) {
        $categories[] = $cat;
    }
    
    // 브랜드 목록 조회 (편집용)
    $brands_sql = "SELECT id, name_ko, name_en FROM brands ORDER BY name_ko";
    $brand_result = $conn->query($brands_sql);
    $brands = [];
    while ($brand = $brand_result->fetch_assoc()) {
        $brands[] = $brand;
    }
    
    // HTML 폼 생성
    $html = '
    <form id="productEditForm">
        <input type="hidden" name="product_id" value="' . htmlspecialchars($product['id']) . '">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- 기본 정보 -->
            <div class="space-y-4">
                <h4 class="text-lg font-medium text-gray-900">' . t('product.basic_info') . '</h4>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">' . t('product.sku') . '</label>
                    <input type="text" name="sku" value="' . htmlspecialchars($product['sku']) . '" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">' . t('product.name_en') . '</label>
                    <input type="text" name="name_en" value="' . htmlspecialchars($product['name_en']) . '" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">' . t('product.name_ko') . '</label>
                    <input type="text" name="name_ko" value="' . htmlspecialchars($product['name_ko']) . '" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">' . t('product.barcode') . '</label>
                    <input type="text" name="barcode" value="' . htmlspecialchars($product['barcode']) . '" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">' . t('product.pieces_per_box') . '</label>
                    <input type="number" name="pieces_per_box" value="' . htmlspecialchars($product['pieces_per_box']) . '" min="1"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>
            </div>
            
            <!-- 분류 정보 -->
            <div class="space-y-4">
                <h4 class="text-lg font-medium text-gray-900">' . t('product.classification_info') . '</h4>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">' . t('product.category') . '</label>
                    <select name="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <option value="">' . t('common.select') . '</option>';
    
    foreach ($categories as $category) {
        $selected = ($category['id'] == $product['category_id']) ? 'selected' : '';
        $html .= '<option value="' . $category['id'] . '" ' . $selected . '>' . htmlspecialchars($category['name']) . '</option>';
    }
    
    $html .= '
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">' . t('product.brand') . '</label>
                    <select name="brand_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <option value="">' . t('common.select') . '</option>';
    
    foreach ($brands as $brand) {
        $selected = ($brand['id'] == $product['brand_id']) ? 'selected' : '';
        $html .= '<option value="' . $brand['id'] . '" ' . $selected . '>' . htmlspecialchars($brand['name_ko']) . '</option>';
    }
    
    $html .= '
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">' . t('product.description') . '</label>
                    <textarea name="description" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500">' . htmlspecialchars($product['description']) . '</textarea>
                </div>
                
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" name="is_active" value="1" ' . ($product['is_active'] ? 'checked' : '') . ' 
                               class="rounded border-gray-300 text-primary-600 shadow-sm focus:border-primary-300 focus:ring focus:ring-primary-200 focus:ring-opacity-50">
                        <span class="ml-2 text-sm text-gray-700">' . t('product.is_active') . '</span>
                    </label>
                </div>
            </div>
        </div>
        
        <!-- 재고 정보 -->
        <div class="mt-6">
            <h4 class="text-lg font-medium text-gray-900 mb-4">' . t('product.inventory_info') . '</h4>';
            
    if (!empty($inventory)) {
        $html .= '
            <div class="bg-gray-50 rounded-lg p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-300">
                            <th class="text-left py-2">' . t('store.name') . '</th>
                            <th class="text-right py-2">' . t('product.cost_price') . '</th>
                            <th class="text-right py-2">' . t('product.selling_price') . '</th>
                            <th class="text-right py-2">' . t('product.quantity') . '</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($inventory as $inv) {
            $margin_rate = '';
            if ($inv['cost_price'] && $inv['selling_price'] && $inv['cost_price'] > 0) {
                $margin = (($inv['selling_price'] - $inv['cost_price']) / $inv['cost_price']) * 100;
                $margin_rate = ' (' . number_format($margin, 1) . '%)';
            }
            
            $html .= '
                        <tr class="border-b border-gray-200">
                            <td class="py-2">' . htmlspecialchars($inv['store_name']) . '</td>
                            <td class="py-2 text-right">' . number_format($inv['cost_price'], 2) . '</td>
                            <td class="py-2 text-right">' . number_format($inv['selling_price'], 2) . $margin_rate . '</td>
                            <td class="py-2 text-right">' . number_format($inv['quantity']) . '</td>
                        </tr>';
        }
        
        $html .= '
                    </tbody>
                </table>
            </div>';
    } else {
        $html .= '<p class="text-gray-500 text-center py-4">' . t('product.no_inventory_data') . '</p>';
    }
    
    $html .= '
        </div>
        
        <!-- 상품 이미지 -->
        <div class="mt-6">';
        
    if ($product['image_url']) {
        $html .= '
            <div class="flex items-center space-x-3">
                <img src="' . htmlspecialchars($product['image_url']) . '" alt="' . htmlspecialchars($product['name_ko']) . '" 
                     class="w-16 h-16 object-cover rounded-lg border">
                <div>
                    <p class="text-sm font-medium text-gray-900">' . t('product.current_image') . '</p>
                    <p class="text-xs text-gray-500">' . htmlspecialchars($product['image_url']) . '</p>
                </div>
            </div>';
    } else {
        $html .= '<p class="text-gray-500 text-center py-4">' . t('product.no_image') . '</p>';
    }
    
    $html .= '
        </div>
        
        <!-- 정보 -->
        <div class="mt-6 text-xs text-gray-500">
            <p>' . t('product.created_at') . ': ' . date('Y-m-d H:i:s', strtotime($product['created_at'])) . '</p>
            <p>' . t('product.updated_at') . ': ' . date('Y-m-d H:i:s', strtotime($product['updated_at'])) . '</p>
        </div>
    </form>';
    
    echo json_encode([
        'success' => true,
        'html' => $html
    ]);
    
} catch (Exception $e) {
    error_log("Purchase product details error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => t('product.load_error')
    ]);
} finally {
    if (isset($stmt)) $stmt->close();
    if (isset($inv_stmt)) $inv_stmt->close();
    $conn->close();
}
?>
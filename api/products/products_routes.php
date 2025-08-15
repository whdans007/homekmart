<?php
/**
 * 상품 API 라우트
 * 배달 가능 상품 관리
 */

$product_path = $path_parts[1] ?? '';

switch ($method) {
    case 'GET':
        switch ($product_path) {
            case '':
                // GET /api/products - 상품 목록
                require_once __DIR__ . '/list_products.php';
                break;
                
            case 'search':
                // GET /api/products/search - 상품 검색
                require_once __DIR__ . '/search_products.php';
                break;
                
            case 'categories':
                // GET /api/products/categories - 카테고리 목록
                require_once __DIR__ . '/categories.php';
                break;
                
            case 'brands':
                // GET /api/products/brands - 브랜드 목록
                require_once __DIR__ . '/brands.php';
                break;
                
            default:
                // GET /api/products/{id} - 상품 상세
                if (is_numeric($product_path)) {
                    $_GET['product_id'] = $product_path;
                    require_once __DIR__ . '/product_detail.php';
                } else {
                    api_not_found('Product endpoint not found');
                }
        }
        break;
        
    default:
        api_error('Method not allowed', 405);
}
?>
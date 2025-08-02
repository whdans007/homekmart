<?php
/**
 * 마진 관리 헬퍼 함수들
 * 카테고리별 마진율 조회 및 관리 기능 제공
 */

/**
 * 상품 카테고리에 따른 마진율을 조회합니다.
 * @param int $product_id 상품 ID
 * @param PDO|null $pdo PDO 연결 객체 (null이면 새로 생성)
 * @return float 마진율 (기본값: 30%)
 */
function get_margin_rate_by_product($product_id, $pdo = null) {
    $default_margin = 30.0; // 기본 마진율 30%
    
    try {
        // PDO 연결이 없으면 새로 생성
        if (!$pdo) {
            require_once __DIR__ . '/../config/db_config.php';
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        // 상품의 카테고리 ID 조회
        $stmt = $pdo->prepare("SELECT category_id FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product || !$product['category_id']) {
            return $default_margin;
        }
        
        // 카테고리별 마진율 조회
        $stmt = $pdo->prepare("SELECT margin_percentage FROM margin_rules WHERE category_id = ?");
        $stmt->execute([$product['category_id']]);
        $margin_rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($margin_rule) {
            return (float)$margin_rule['margin_percentage'];
        }
        
        return $default_margin;
        
    } catch (PDOException $e) {
        error_log("Margin rate lookup error: " . $e->getMessage());
        return $default_margin;
    } catch (Exception $e) {
        error_log("General margin error: " . $e->getMessage());
        return $default_margin;
    }
}

/**
 * 카테고리 ID에 따른 마진율을 조회합니다.
 * @param int $category_id 카테고리 ID
 * @param PDO|null $pdo PDO 연결 객체 (null이면 새로 생성)
 * @return float 마진율 (기본값: 30%)
 */
function get_margin_rate_by_category($category_id, $pdo = null) {
    $default_margin = 30.0; // 기본 마진율 30%
    
    try {
        // PDO 연결이 없으면 새로 생성
        if (!$pdo) {
            require_once __DIR__ . '/../config/db_config.php';
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        // 카테고리별 마진율 조회
        $stmt = $pdo->prepare("SELECT margin_percentage FROM margin_rules WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $margin_rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($margin_rule) {
            return (float)$margin_rule['margin_percentage'];
        }
        
        return $default_margin;
        
    } catch (PDOException $e) {
        error_log("Margin rate lookup error: " . $e->getMessage());
        return $default_margin;
    } catch (Exception $e) {
        error_log("General margin error: " . $e->getMessage());
        return $default_margin;
    }
}

/**
 * 마진율을 기반으로 제안 판매가를 계산합니다.
 * @param float $cost_price 원가
 * @param float $margin_rate 마진율 (퍼센트)
 * @return int 제안 판매가
 */
function calculate_suggested_price($cost_price, $margin_rate) {
    $multiplier = 1 + ($margin_rate / 100);
    return round($cost_price * $multiplier);
}

/**
 * 판매가와 원가를 기반으로 마진율을 계산합니다.
 * @param float $selling_price 판매가
 * @param float $cost_price 원가
 * @return float 마진율 (퍼센트)
 */
function calculate_margin_rate($selling_price, $cost_price) {
    if ($cost_price <= 0) {
        return 0;
    }
    
    return (($selling_price - $cost_price) / $cost_price) * 100;
}

/**
 * 모든 마진 규칙을 조회합니다.
 * @param PDO|null $pdo PDO 연결 객체 (null이면 새로 생성)
 * @return array 마진 규칙 배열
 */
function get_all_margin_rules($pdo = null) {
    try {
        // PDO 연결이 없으면 새로 생성
        if (!$pdo) {
            require_once __DIR__ . '/../config/db_config.php';
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        $stmt = $pdo->query("
            SELECT mr.id, mr.category_id, mr.margin_percentage, c.name as category_name
            FROM margin_rules mr
            LEFT JOIN categories c ON mr.category_id = c.id
            ORDER BY c.name ASC
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Get margin rules error: " . $e->getMessage());
        return [];
    } catch (Exception $e) {
        error_log("General margin rules error: " . $e->getMessage());
        return [];
    }
}

/**
 * 마진 규칙 테이블이 존재하는지 확인합니다.
 * @param PDO|null $pdo PDO 연결 객체 (null이면 새로 생성)
 * @return bool 테이블 존재 여부
 */
function margin_table_exists($pdo = null) {
    try {
        // PDO 연결이 없으면 새로 생성
        if (!$pdo) {
            require_once __DIR__ . '/../config/db_config.php';
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'margin_rules'");
        $stmt->execute();
        
        return $stmt->fetch() !== false;
        
    } catch (PDOException $e) {
        error_log("Check margin table error: " . $e->getMessage());
        return false;
    }
}
?>
<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 세션 시작
session_start();

echo "<h3>세션 정보:</h3>";
echo "로그인 상태: " . (is_logged_in() ? '로그인됨' : '로그인 안됨') . "<br>";
echo "사용자 ID: " . ($_SESSION['user_id'] ?? 'N/A') . "<br>";
echo "역할: " . ($_SESSION['role'] ?? 'N/A') . "<br>";
echo "권한 확인: " . (has_permission('wholesale_management') ? '있음' : '없음') . "<br><br>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search_term = $_POST['search_term'] ?? '';
    
    echo "<h3>검색 결과 (검색어: '$search_term'):</h3>";
    
    if (strlen($search_term) >= 2) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $search_query = "%{$search_term}%";
            
            $sql = "
                SELECT 
                    p.id,
                    p.sku,
                    p.name_ko,
                    p.name_en,
                    p.selling_price
                FROM products p
                WHERE p.is_active = 1 
                AND (p.sku LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?)
                LIMIT 10
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$search_query, $search_query, $search_query]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "찾은 상품 수: " . count($products) . "<br><br>";
            
            foreach ($products as $product) {
                echo "ID: {$product['id']}, SKU: {$product['sku']}, 한글명: {$product['name_ko']}, 영문명: {$product['name_en']}, 가격: {$product['selling_price']}<br>";
            }
            
        } catch (Exception $e) {
            echo "오류: " . $e->getMessage();
        }
    } else {
        echo "검색어가 너무 짧습니다.";
    }
}
?>

<form method="POST">
    <h3>상품 검색 테스트:</h3>
    <input type="text" name="search_term" placeholder="상품명 또는 SKU 입력" value="<?php echo htmlspecialchars($_POST['search_term'] ?? ''); ?>">
    <button type="submit">검색</button>
</form>
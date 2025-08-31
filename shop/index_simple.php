<?php
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

// 점포 목록 조회
$stores_result = $conn->query("SELECT * FROM stores WHERE is_active = 1 ORDER BY name");
$stores = $stores_result->fetch_all(MYSQLI_ASSOC);

// 선택된 점포 ID
$selected_store_id = $_GET['store_id'] ?? ($stores[0]['id'] ?? 1);

// 진열 섹션 존재 여부 확인
$sections_exist = false;
try {
    $sections_result = $conn->query("SHOW TABLES LIKE 'display_sections'");
    if ($sections_result->num_rows > 0) {
        $sections_exist = true;
    }
} catch (Exception $e) {
    $sections_exist = false;
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOME K MART - 쇼핑몰 (테스트 버전)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <!-- 헤더 -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-shopping-bag me-2"></i>HOME K MART
            </a>
            <div class="d-flex align-items-center text-white">
                <i class="fas fa-store me-2"></i>
                <span>쇼핑몰 테스트 버전</span>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <!-- 점포 선택 -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title"><i class="fas fa-store me-2"></i>점포 선택</h5>
                <div class="row">
                    <?php foreach ($stores as $store): ?>
                        <div class="col-md-4 mb-3">
                            <div class="card <?php echo $store['id'] == $selected_store_id ? 'border-primary' : ''; ?>">
                                <div class="card-body text-center">
                                    <h6><?php echo htmlspecialchars($store['name']); ?></h6>
                                    <?php if ($store['address']): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($store['address']); ?></small>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <a href="?store_id=<?php echo $store['id']; ?>" 
                                           class="btn <?php echo $store['id'] == $selected_store_id ? 'btn-primary' : 'btn-outline-primary'; ?> btn-sm">
                                            선택
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 시스템 상태 -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">시스템 상태</h5>
                
                <?php if ($sections_exist): ?>
                    <div class="alert alert-success">✅ 쇼핑몰 시스템이 준비되었습니다!</div>
                    
                    <?php
                    // 진열 섹션 조회
                    $sections_query = "SELECT * FROM display_sections WHERE is_active = 1 ORDER BY display_order ASC";
                    $sections_result = $conn->query($sections_query);
                    ?>
                    
                    <h6>진열 섹션 목록:</h6>
                    <div class="row">
                        <?php while ($section = $sections_result->fetch_assoc()): ?>
                            <div class="col-md-6 mb-2">
                                <div class="card">
                                    <div class="card-body py-2">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($section['name']); ?></h6>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($section['description']); ?>
                                            (<?php echo htmlspecialchars($section['layout_type']); ?>)
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    
                    <div class="mt-3">
                        <a href="index.php?store_id=<?php echo $selected_store_id; ?>" class="btn btn-success">
                            <i class="fas fa-shopping-cart me-2"></i>실제 쇼핑몰로 이동
                        </a>
                        <a href="order.php" class="btn btn-primary">
                            <i class="fas fa-receipt me-2"></i>주문 테스트
                        </a>
                    </div>
                    
                <?php else: ?>
                    <div class="alert alert-warning">
                        ⚠️ 쇼핑몰 시스템 설정이 필요합니다.
                        <br>관리자에게 문의하여 진열 섹션을 설정해주세요.
                    </div>
                    
                    <div class="mt-3">
                        <a href="../admin/display_sections.php" class="btn btn-primary">
                            <i class="fas fa-cog me-2"></i>관리자 페이지로 이동
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 테스트 주문 데이터 -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">테스트 주문</h5>
                <p>실제 주문 기능을 테스트하려면 다음 단계를 진행하세요:</p>
                <ol>
                    <li>관리자 페이지에서 진열 섹션 설정</li>
                    <li>상품을 진열 섹션에 배치</li>
                    <li>고객 쇼핑몰에서 주문 테스트</li>
                    <li>관리자에서 주문 관리</li>
                </ol>
                
                <div class="mt-3">
                    <button class="btn btn-outline-success" onclick="testOrder()">
                        <i class="fas fa-flask me-2"></i>간단한 주문 테스트
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function testOrder() {
            alert('주문 테스트 기능입니다.\n\n실제 주문을 하려면:\n1. 관리자에서 상품을 진열하고\n2. 실제 쇼핑몰 페이지를 이용하세요.');
        }
    </script>
</body>
</html>

<?php $conn->close(); ?>
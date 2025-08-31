<?php
/**
 * 쇼핑몰 통합 관리 대시보드
 * 모든 점포의 상품 현황을 한눈에 확인하고 관리할 수 있는 종합 대시보드
 */

require_once '../config/db_config.php';
require_once '../lib/permission_helper.php';

// 권한 확인
require_permission('admin_access', 'index.php');

$conn = get_db_connection();

// 통계 데이터 수집
$stats = [];

// 1. 전체 통계 - 실제 테이블 구조에 맞게 수정
$total_stats_sql = "SELECT 
    COUNT(DISTINCT p.id) as total_products,
    COUNT(DISTINCT s.id) as active_stores,
    COUNT(DISTINCT c.id) as categories_count,
    AVG(p.selling_price) as avg_price
FROM products p
CROSS JOIN stores s
LEFT JOIN categories c ON p.category_id = c.id";

$total_result = $conn->query($total_stats_sql);
$stats['total'] = $total_result->fetch_assoc();

// store_products 테이블 존재 여부 확인
$tables_sql = "SHOW TABLES LIKE 'store_products'";
$table_exists = $conn->query($tables_sql)->num_rows > 0;

if ($table_exists) {
    // store_products 테이블이 존재하는 경우
    $store_products_sql = "SELECT 
        COUNT(DISTINCT sp.product_id) as store_products,
        COUNT(DISTINCT CASE WHEN sp.is_featured = 1 THEN sp.product_id END) as featured_products
    FROM store_products sp WHERE sp.is_available = 1";
    $sp_result = $conn->query($store_products_sql);
    $sp_data = $sp_result->fetch_assoc();
    $stats['total']['store_products'] = $sp_data['store_products'];
    $stats['total']['featured_products'] = $sp_data['featured_products'];
} else {
    // store_products 테이블이 없는 경우
    $stats['total']['store_products'] = $stats['total']['total_products'];
    $stats['total']['featured_products'] = 0;
}

// 2. 점포별 상품 통계 - 실제 테이블 구조에 맞게 수정
if ($table_exists) {
    // store_products 테이블이 있는 경우
    $store_stats_sql = "SELECT 
        s.id,
        s.name,
        s.created_at,
        COUNT(sp.product_id) as product_count,
        COUNT(CASE WHEN sp.is_featured = 1 THEN 1 END) as featured_count,
        COUNT(CASE WHEN sp.is_available = 0 THEN 1 END) as hidden_count,
        MAX(sp.updated_at) as last_updated
    FROM stores s
    LEFT JOIN store_products sp ON s.id = sp.store_id
    GROUP BY s.id, s.name, s.created_at
    ORDER BY s.name";
} else {
    // store_products 테이블이 없는 경우 - 기본 점포 정보만
    $store_stats_sql = "SELECT 
        s.id,
        s.name,
        s.created_at,
        0 as product_count,
        0 as featured_count,
        0 as hidden_count,
        NULL as last_updated
    FROM stores s
    ORDER BY s.name";
}

$store_result = $conn->query($store_stats_sql);
$stats['stores'] = [];
while ($row = $store_result->fetch_assoc()) {
    $stats['stores'][] = $row;
}

// 3. 카테고리별 분포 - 실제 테이블 구조 확인
$cat_desc_sql = "DESCRIBE categories";
$cat_desc_result = $conn->query($cat_desc_sql);
$cat_columns = [];
while ($col = $cat_desc_result->fetch_assoc()) {
    $cat_columns[] = $col['Field'];
}

// 카테고리 이름 컬럼 찾기
$name_column = 'name';
if (in_array('name_kr', $cat_columns)) {
    $name_column = 'name_kr';
} elseif (in_array('category_name', $cat_columns)) {
    $name_column = 'category_name';
}

$icon_column = in_array('icon_class', $cat_columns) ? 'c.icon_class' : "'fas fa-folder' as icon_class";

if ($table_exists) {
    // store_products 테이블이 있는 경우
    $category_stats_sql = "SELECT 
        c.id,
        c.$name_column as category_name,
        $icon_column,
        COUNT(DISTINCT p.id) as total_products,
        COUNT(DISTINCT sp.product_id) as store_products,
        COUNT(DISTINCT sp.store_id) as stores_count
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    LEFT JOIN store_products sp ON p.id = sp.product_id AND sp.is_available = 1
    GROUP BY c.id, c.$name_column" . (in_array('icon_class', $cat_columns) ? ', c.icon_class' : '') . "
    HAVING total_products > 0
    ORDER BY store_products DESC, c.$name_column";
} else {
    // store_products 테이블이 없는 경우
    $category_stats_sql = "SELECT 
        c.id,
        c.$name_column as category_name,
        $icon_column,
        COUNT(p.id) as total_products,
        COUNT(p.id) as store_products,
        1 as stores_count
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    GROUP BY c.id, c.$name_column" . (in_array('icon_class', $cat_columns) ? ', c.icon_class' : '') . "
    HAVING total_products > 0
    ORDER BY total_products DESC, c.$name_column";
}

$category_result = $conn->query($category_stats_sql);
$stats['categories'] = [];
while ($row = $category_result->fetch_assoc()) {
    $stats['categories'][] = $row;
}

// 4. 최근 활동 - 실제 테이블 구조에 맞게 수정
if ($table_exists) {
    // store_products 테이블이 있는 경우
    $product_name_column = in_array('name_kr', $cat_columns) ? 'name_kr' : 'name';
    $recent_activity_sql = "SELECT 
        'product_added' as activity_type,
        sp.created_at as activity_time,
        COALESCE(p.$product_name_column, p.name, 'Unknown Product') as product_name,
        s.name as store_name
    FROM store_products sp
    JOIN products p ON sp.product_id = p.id
    JOIN stores s ON sp.store_id = s.id
    WHERE sp.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY sp.created_at DESC
    LIMIT 10";
    
    $activity_result = $conn->query($recent_activity_sql);
    $stats['recent_activities'] = [];
    if ($activity_result) {
        while ($row = $activity_result->fetch_assoc()) {
            $stats['recent_activities'][] = $row;
        }
    }
} else {
    // store_products 테이블이 없는 경우
    $stats['recent_activities'] = [];
}

include 'partials/header.php';
?>

<div class="container-fluid">
    <!-- 페이지 헤더 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-store-alt text-pink-600 me-2"></i>
                        쇼핑몰 통합 관리 대시보드
                    </h1>
                    <p class="text-muted">모든 점포의 상품 현황을 한눈에 확인하고 관리합니다.</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary" onclick="refreshDashboard()">
                        <i class="fas fa-sync-alt me-1"></i> 새로고침
                    </button>
                    <a href="store_product_planner.php" class="btn btn-success">
                        <i class="fas fa-tools me-1"></i> 상품 배치 관리
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 전체 통계 카드 -->
    <div class="stats-cards-container mb-4">
        <div class="stats-card">
            <div class="card bg-gradient-primary text-white h-100 shadow-sm">
                <div class="card-body text-center p-3">
                    <div class="mb-2">
                        <i class="fas fa-box-open fa-2x opacity-75 mb-2"></i>
                        <h6 class="card-title mb-0 fw-bold">전체 상품</h6>
                    </div>
                    <h2 class="mb-1 fw-bold"><?= number_format($stats['total']['total_products']) ?></h2>
                    <p class="mb-0 text-light opacity-90">
                        <small class="fw-medium">등록된 모든 상품</small>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="stats-card">
            <div class="card bg-gradient-success text-white h-100 shadow-sm">
                <div class="card-body text-center p-3">
                    <div class="mb-2">
                        <i class="fas fa-shopping-cart fa-2x opacity-75 mb-2"></i>
                        <h6 class="card-title mb-0 fw-bold">점포 노출 상품</h6>
                    </div>
                    <h2 class="mb-1 fw-bold"><?= number_format($stats['total']['store_products']) ?></h2>
                    <p class="mb-0 text-light opacity-90">
                        <small class="fw-medium">점포에서 판매중인 상품</small>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="stats-card">
            <div class="card bg-gradient-warning text-white h-100 shadow-sm">
                <div class="card-body text-center p-3">
                    <div class="mb-2">
                        <i class="fas fa-star fa-2x opacity-75 mb-2"></i>
                        <h6 class="card-title mb-0 fw-bold">추천 상품</h6>
                    </div>
                    <h2 class="mb-1 fw-bold"><?= number_format($stats['total']['featured_products']) ?></h2>
                    <p class="mb-0 text-light opacity-90">
                        <small class="fw-medium">메인에 표시되는 상품</small>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="stats-card">
            <div class="card bg-gradient-info text-white h-100 shadow-sm">
                <div class="card-body text-center p-3">
                    <div class="mb-2">
                        <i class="fas fa-store fa-2x opacity-75 mb-2"></i>
                        <h6 class="card-title mb-0 fw-bold">활성 점포</h6>
                    </div>
                    <h2 class="mb-1 fw-bold"><?= number_format($stats['total']['active_stores']) ?></h2>
                    <p class="mb-0 text-light opacity-90">
                        <small class="fw-medium">운영중인 점포 수</small>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- 점포별 현황 및 카테고리 분포 -->
    <div class="row mb-4">
        <!-- 점포별 현황 -->
        <div class="col-lg-8 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-store text-primary me-2"></i>점포별 상품 현황
                    </h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary active" data-view="card" onclick="toggleStoreView('card')">
                            <i class="fas fa-th"></i>
                        </button>
                        <button class="btn btn-outline-primary" data-view="table" onclick="toggleStoreView('table')">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- 카드 뷰 -->
                    <div id="storeCardView" class="row g-3">
                        <?php foreach ($stats['stores'] as $store): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card store-card h-100 border-left-primary">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="card-title mb-0 text-primary"><?= htmlspecialchars($store['name']) ?></h6>
                                        <span class="badge bg-success">운영중</span>
                                    </div>
                                    <p class="text-muted small mb-2">개설일: <?= date('Y-m-d', strtotime($store['created_at'])) ?></p>
                                    
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div class="border-end">
                                                <h5 class="text-primary mb-0"><?= $store['product_count'] ?></h5>
                                                <small class="text-muted">전체 상품</small>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="border-end">
                                                <h5 class="text-warning mb-0"><?= $store['featured_count'] ?></h5>
                                                <small class="text-muted">추천 상품</small>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <h5 class="text-secondary mb-0"><?= $store['hidden_count'] ?></h5>
                                            <small class="text-muted">숨김 상품</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3 d-flex gap-1">
                                        <a href="store_product_management.php?store_id=<?= $store['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary flex-fill">
                                            <i class="fas fa-edit me-1"></i>관리
                                        </a>
                                        <a href="../shop/?store_id=<?= $store['id'] ?>" 
                                           class="btn btn-sm btn-outline-success flex-fill" target="_blank">
                                            <i class="fas fa-external-link-alt me-1"></i>보기
                                        </a>
                                    </div>
                                    
                                    <?php if ($store['last_updated']): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i>
                                            최근 수정: <?= date('m/d H:i', strtotime($store['last_updated'])) ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- 테이블 뷰 -->
                    <div id="storeTableView" style="display: none;">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>점포명</th>
                                        <th>개설일</th>
                                        <th class="text-center">전체 상품</th>
                                        <th class="text-center">추천 상품</th>
                                        <th class="text-center">숨김 상품</th>
                                        <th class="text-center">최근 수정</th>
                                        <th class="text-center">관리</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['stores'] as $store): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary"><?= htmlspecialchars($store['name']) ?></strong>
                                            <span class="badge bg-success ms-2">운영중</span>
                                        </td>
                                        <td><small><?= date('Y-m-d', strtotime($store['created_at'])) ?></small></td>
                                        <td class="text-center"><strong><?= $store['product_count'] ?></strong></td>
                                        <td class="text-center"><span class="text-warning"><?= $store['featured_count'] ?></span></td>
                                        <td class="text-center"><span class="text-muted"><?= $store['hidden_count'] ?></span></td>
                                        <td class="text-center">
                                            <small><?= $store['last_updated'] ? date('m/d H:i', strtotime($store['last_updated'])) : '-' ?></small>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="store_product_management.php?store_id=<?= $store['id'] ?>" 
                                                   class="btn btn-outline-primary" title="상품 관리">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="../shop/?store_id=<?= $store['id'] ?>" 
                                                   class="btn btn-outline-success" title="쇼핑몰 보기" target="_blank">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 카테고리 분포 -->
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-sitemap text-success me-2"></i>카테고리별 분포
                    </h5>
                </div>
                <div class="card-body">
                    <div class="category-stats">
                        <?php foreach ($stats['categories'] as $category): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <div class="d-flex align-items-center">
                                <i class="<?= $category['icon_class'] ?: 'fas fa-folder' ?> text-muted me-2"></i>
                                <span><?= htmlspecialchars($category['category_name']) ?></span>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-primary"><?= $category['store_products'] ?></span>
                                <small class="text-muted">/ <?= $category['total_products'] ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-3">
                        <canvas id="categoryChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 최근 활동 -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-history text-info me-2"></i>최근 활동 내역
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($stats['recent_activities'])): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                        <h6 class="text-muted">최근 활동 내역이 없습니다</h6>
                        <p class="text-muted">상품을 추가하거나 수정하면 여기에 표시됩니다.</p>
                    </div>
                    <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($stats['recent_activities'] as $activity): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker bg-primary"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1"><?= htmlspecialchars($activity['product_name']) ?></h6>
                                <p class="text-muted mb-1">
                                    <strong><?= htmlspecialchars($activity['store_name']) ?></strong>점에 상품이 추가되었습니다.
                                </p>
                                <small class="text-muted"><?= date('Y-m-d H:i', strtotime($activity['activity_time'])) ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// 페이지 로드시 차트 초기화
document.addEventListener('DOMContentLoaded', function() {
    initializeCategoryChart();
});

// 카테고리 차트 초기화
function initializeCategoryChart() {
    const ctx = document.getElementById('categoryChart').getContext('2d');
    const categoryData = <?= json_encode($stats['categories']) ?>;
    
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: categoryData.map(cat => cat.category_name),
            datasets: [{
                data: categoryData.map(cat => cat.store_products),
                backgroundColor: [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
                    '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

// 점포 뷰 토글
function toggleStoreView(view) {
    const cardView = document.getElementById('storeCardView');
    const tableView = document.getElementById('storeTableView');
    const buttons = document.querySelectorAll('[data-view]');
    
    // 버튼 상태 업데이트
    buttons.forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[data-view="${view}"]`).classList.add('active');
    
    // 뷰 전환
    if (view === 'card') {
        cardView.style.display = 'block';
        tableView.style.display = 'none';
    } else {
        cardView.style.display = 'none';
        tableView.style.display = 'block';
    }
}

// 대시보드 새로고침
function refreshDashboard() {
    location.reload();
}
</script>

<style>
/* 통계 카드 flexbox 레이아웃 */
.stats-cards-container {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}

.stats-card {
    flex: 1;
    min-width: 0;
    width: 25%;
}

.stats-card .card {
    transition: all 0.3s ease;
    border: none;
    border-radius: 12px;
    overflow: hidden;
    height: 100%;
}

.stats-card .card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
}

.stats-card .card .card-body {
    padding: 1.5rem 1rem;
    min-height: 140px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.stats-card .card h2 {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1.2;
}

.stats-card .card h6 {
    font-size: 0.8rem;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.stats-card .card i {
    font-size: 2rem;
}

.stats-card .card .card-body p small {
    font-size: 0.7rem;
}

/* 반응형 디자인 */
@media (max-width: 768px) {
    .stats-cards-container {
        flex-direction: column;
        gap: 1rem;
    }
    
    .stats-card {
        width: 100%;
    }
}

@media (max-width: 1200px) and (min-width: 769px) {
    .stats-cards-container {
        flex-wrap: wrap;
    }
    
    .stats-card {
        width: 48%;
        flex: 0 0 48%;
    }
}

/* 그라데이션 개선 */
.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.bg-gradient-success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    box-shadow: 0 4px 15px rgba(17, 153, 142, 0.3);
}

.bg-gradient-warning {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    box-shadow: 0 4px 15px rgba(240, 147, 251, 0.3);
}

.bg-gradient-info {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
}

/* 기존 스타일 유지 */
.border-left-primary {
    border-left: 4px solid #007bff !important;
}

.store-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.store-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.category-stats {
    max-height: 300px;
    overflow-y: auto;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-marker {
    position: absolute;
    left: -35px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.timeline-item:not(:last-child)::before {
    content: '';
    position: absolute;
    left: -31px;
    top: 17px;
    width: 2px;
    height: calc(100% + 5px);
    background-color: #dee2e6;
}

#categoryChart {
    max-height: 200px;
}
</style>

<?php include 'partials/footer.php'; ?>
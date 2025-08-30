<?php
/**
 * 쇼핑몰 통합 관리 대시보드
 * 모든 점포의 상품 현황을 한눈에 확인하고 관리할 수 있는 종합 대시보드
 */

session_start();
require_once '../config/db_config.php';
require_once '../lib/permission_helper.php';

// 권한 확인
require_permission('admin_access', '../login.php');

$conn = get_db_connection();

// 통계 데이터 수집
$stats = [];

// 1. 전체 통계
$total_stats_sql = "SELECT 
    COUNT(DISTINCT p.id) as total_products,
    COUNT(DISTINCT sp.product_id) as store_products,
    COUNT(DISTINCT s.id) as active_stores,
    COUNT(DISTINCT CASE WHEN sp.is_featured = 1 THEN sp.product_id END) as featured_products
FROM products p
LEFT JOIN store_products sp ON p.id = sp.product_id AND sp.is_available = 1
LEFT JOIN stores s ON sp.store_id = s.id AND s.is_active = 1
WHERE p.status = 'active' OR p.status IS NULL";

$total_result = $conn->query($total_stats_sql);
$stats['total'] = $total_result->fetch_assoc();

// 2. 점포별 상품 통계
$store_stats_sql = "SELECT 
    s.id,
    s.name,
    s.address,
    s.manager,
    s.is_active,
    COUNT(sp.product_id) as product_count,
    COUNT(CASE WHEN sp.is_featured = 1 THEN 1 END) as featured_count,
    COUNT(CASE WHEN sp.is_available = 0 THEN 1 END) as hidden_count,
    MAX(sp.updated_at) as last_updated
FROM stores s
LEFT JOIN store_products sp ON s.id = sp.store_id
WHERE s.is_active = 1
GROUP BY s.id, s.name, s.address, s.manager, s.is_active
ORDER BY s.name";

$store_result = $conn->query($store_stats_sql);
$stats['stores'] = [];
while ($row = $store_result->fetch_assoc()) {
    $stats['stores'][] = $row;
}

// 3. 카테고리별 분포
$category_stats_sql = "SELECT 
    c.id,
    c.name as category_name,
    c.icon_class,
    COUNT(DISTINCT p.id) as total_products,
    COUNT(DISTINCT sp.product_id) as store_products,
    COUNT(DISTINCT sp.store_id) as stores_count
FROM categories c
LEFT JOIN products p ON c.id = p.category_id AND (p.status = 'active' OR p.status IS NULL)
LEFT JOIN store_products sp ON p.id = sp.product_id AND sp.is_available = 1
GROUP BY c.id, c.name, c.icon_class
HAVING total_products > 0
ORDER BY store_products DESC, c.name";

$category_result = $conn->query($category_stats_sql);
$stats['categories'] = [];
while ($row = $category_result->fetch_assoc()) {
    $stats['categories'][] = $row;
}

// 4. 최근 활동
$recent_activity_sql = "SELECT 
    'product_added' as activity_type,
    sp.created_at as activity_time,
    p.name_kr as product_name,
    s.name as store_name,
    u.full_name as user_name
FROM store_products sp
JOIN products p ON sp.product_id = p.id
JOIN stores s ON sp.store_id = s.id
LEFT JOIN users u ON sp.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
WHERE sp.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY sp.created_at DESC
LIMIT 10";

$activity_result = $conn->query($recent_activity_sql);
$stats['recent_activities'] = [];
while ($row = $activity_result->fetch_assoc()) {
    $stats['recent_activities'][] = $row;
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
    <div class="row mb-4">
        <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-3">
            <div class="card bg-gradient-primary text-white">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="card-title mb-1">전체 상품</h5>
                            <h2 class="mb-0"><?= number_format($stats['total']['total_products']) ?></h2>
                            <p class="mb-0 text-light"><small>등록된 모든 상품</small></p>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-box-open fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-3">
            <div class="card bg-gradient-success text-white">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="card-title mb-1">점포 노출 상품</h5>
                            <h2 class="mb-0"><?= number_format($stats['total']['store_products']) ?></h2>
                            <p class="mb-0 text-light"><small>점포에서 판매중인 상품</small></p>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-shopping-cart fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-3">
            <div class="card bg-gradient-warning text-white">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="card-title mb-1">추천 상품</h5>
                            <h2 class="mb-0"><?= number_format($stats['total']['featured_products']) ?></h2>
                            <p class="mb-0 text-light"><small>메인에 표시되는 상품</small></p>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-star fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 mb-3">
            <div class="card bg-gradient-info text-white">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="card-title mb-1">활성 점포</h5>
                            <h2 class="mb-0"><?= number_format($stats['total']['active_stores']) ?></h2>
                            <p class="mb-0 text-light"><small>운영중인 점포 수</small></p>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-store fa-2x opacity-75"></i>
                        </div>
                    </div>
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
                                    <p class="text-muted small mb-2"><?= htmlspecialchars($store['address'] ?: '주소 없음') ?></p>
                                    
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
                                        <th>주소</th>
                                        <th>담당자</th>
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
                                        <td><small><?= htmlspecialchars($store['address'] ?: '-') ?></small></td>
                                        <td><small><?= htmlspecialchars($store['manager'] ?: '-') ?></small></td>
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

.bg-gradient-primary {
    background: linear-gradient(45deg, #007bff, #0056b3);
}

.bg-gradient-success {
    background: linear-gradient(45deg, #28a745, #1e7e34);
}

.bg-gradient-warning {
    background: linear-gradient(45deg, #ffc107, #e0a800);
}

.bg-gradient-info {
    background: linear-gradient(45deg, #17a2b8, #117a8b);
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
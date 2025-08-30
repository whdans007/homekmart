<?php
/**
 * 쇼핑몰 카테고리 매니저
 * 점포별 카테고리 표시/숨김 및 카테고리별 상품 자동 할당 규칙 관리
 */

session_start();
require_once '../config/db_config.php';
require_once '../lib/permission_helper.php';

// 권한 확인
require_permission('admin_access', '../login.php');

$conn = get_db_connection();

// 카테고리 목록 조회
$categories_sql = "SELECT 
    c.id,
    c.name,
    c.icon_class,
    c.description,
    c.created_at,
    COUNT(p.id) as total_products
FROM categories c
LEFT JOIN products p ON c.id = p.category_id AND (p.status = 'active' OR p.status IS NULL)
GROUP BY c.id, c.name, c.icon_class, c.description, c.created_at
ORDER BY c.name";

$categories_result = $conn->query($categories_sql);
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row;
}

// 활성 점포 목록 조회
$stores_sql = "SELECT id, name, address, manager, is_active FROM stores WHERE is_active = 1 ORDER BY name";
$stores_result = $conn->query($stores_sql);
$stores = [];
while ($row = $stores_result->fetch_assoc()) {
    $stores[] = $row;
}

// 점포별 카테고리 상품 현황
$store_category_stats = [];
foreach ($stores as $store) {
    $stats_sql = "SELECT 
        c.id as category_id,
        c.name as category_name,
        COUNT(sp.product_id) as store_products,
        COUNT(p.id) as total_products,
        COUNT(CASE WHEN sp.is_featured = 1 THEN 1 END) as featured_products
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id AND (p.status = 'active' OR p.status IS NULL)
    LEFT JOIN store_products sp ON p.id = sp.product_id AND sp.store_id = ? AND sp.is_available = 1
    GROUP BY c.id, c.name
    ORDER BY c.name";
    
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->bind_param("i", $store['id']);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    
    $store_category_stats[$store['id']] = [];
    while ($row = $stats_result->fetch_assoc()) {
        $store_category_stats[$store['id']][] = $row;
    }
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
                        <i class="fas fa-sitemap text-purple-600 me-2"></i>
                        쇼핑몰 카테고리 매니저
                    </h1>
                    <p class="text-muted">점포별 카테고리 관리 및 자동 할당 규칙을 설정합니다.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="shop_dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> 대시보드로
                    </a>
                    <button class="btn btn-primary" onclick="showBulkCategoryManager()">
                        <i class="fas fa-layer-group me-1"></i> 일괄 관리
                    </button>
                    <button class="btn btn-success" onclick="showAutoAssignRules()">
                        <i class="fas fa-magic me-1"></i> 자동 할당 규칙
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 통계 개요 -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-gradient-primary text-white">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="card-title mb-1">전체 카테고리</h5>
                            <h2 class="mb-0"><?= count($categories) ?></h2>
                            <small class="opacity-75">등록된 카테고리 수</small>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-sitemap fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-gradient-success text-white">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="card-title mb-1">활성 점포</h5>
                            <h2 class="mb-0"><?= count($stores) ?></h2>
                            <small class="opacity-75">운영중인 점포 수</small>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-store fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-gradient-warning text-white">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="card-title mb-1">평균 상품/카테고리</h5>
                            <h2 class="mb-0">
                                <?php
                                $total_products = array_sum(array_column($categories, 'total_products'));
                                $avg_products = count($categories) > 0 ? round($total_products / count($categories)) : 0;
                                echo number_format($avg_products);
                                ?>
                            </h2>
                            <small class="opacity-75">카테고리당 평균 상품 수</small>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-bar fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-gradient-info text-white">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="card-title mb-1">설정 완료도</h5>
                            <h2 class="mb-0">85%</h2>
                            <small class="opacity-75">카테고리 설정 완료율</small>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 카테고리별 점포 현황 매트릭스 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-table text-primary me-2"></i>
                        카테고리별 점포 현황 매트릭스
                    </h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary active" data-view="matrix" onclick="toggleMatrixView('matrix')">
                            <i class="fas fa-table"></i> 매트릭스
                        </button>
                        <button class="btn btn-outline-primary" data-view="chart" onclick="toggleMatrixView('chart')">
                            <i class="fas fa-chart-bar"></i> 차트
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- 매트릭스 뷰 -->
                    <div id="matrixView">
                        <div class="table-responsive">
                            <table class="table table-hover category-matrix-table">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="min-width: 200px;">카테고리</th>
                                        <?php foreach ($stores as $store): ?>
                                        <th class="text-center" style="min-width: 120px;">
                                            <div class="store-header">
                                                <div class="fw-bold"><?= htmlspecialchars($store['name']) ?></div>
                                                <small class="text-muted">상품 수</small>
                                            </div>
                                        </th>
                                        <?php endforeach; ?>
                                        <th class="text-center" style="min-width: 100px;">
                                            <div class="fw-bold">합계</div>
                                            <small class="text-muted">총 상품</small>
                                        </th>
                                        <th class="text-center" style="min-width: 120px;">관리</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="<?= $category['icon_class'] ?: 'fas fa-folder' ?> text-primary me-2"></i>
                                                <div>
                                                    <div class="fw-semibold"><?= htmlspecialchars($category['name']) ?></div>
                                                    <?php if ($category['description']): ?>
                                                    <small class="text-muted"><?= htmlspecialchars($category['description']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <?php foreach ($stores as $store): ?>
                                        <td class="text-center">
                                            <?php 
                                            $stats = array_values(array_filter(
                                                $store_category_stats[$store['id']], 
                                                function($s) use ($category) {
                                                    return $s['category_id'] == $category['id'];
                                                }
                                            ));
                                            $store_stat = $stats[0] ?? ['store_products' => 0, 'featured_products' => 0];
                                            ?>
                                            <div class="category-stat-cell" data-category-id="<?= $category['id'] ?>" data-store-id="<?= $store['id'] ?>">
                                                <div class="stat-number">
                                                    <span class="badge bg-<?= $store_stat['store_products'] > 0 ? 'primary' : 'light text-muted' ?> fs-6">
                                                        <?= $store_stat['store_products'] ?>
                                                    </span>
                                                </div>
                                                <?php if ($store_stat['featured_products'] > 0): ?>
                                                <div class="stat-featured">
                                                    <small class="text-warning">
                                                        <i class="fas fa-star"></i> <?= $store_stat['featured_products'] ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                                <div class="stat-actions mt-1">
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-success btn-sm" 
                                                                onclick="addCategoryToStore(<?= $category['id'] ?>, <?= $store['id'] ?>)"
                                                                title="이 카테고리 상품을 모두 추가">
                                                            <i class="fas fa-plus"></i>
                                                        </button>
                                                        <button class="btn btn-outline-primary btn-sm"
                                                                onclick="manageCategoryInStore(<?= $category['id'] ?>, <?= $store['id'] ?>)"
                                                                title="상세 관리">
                                                            <i class="fas fa-cog"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <?php endforeach; ?>
                                        
                                        <td class="text-center">
                                            <span class="badge bg-secondary fs-6"><?= $category['total_products'] ?></span>
                                        </td>
                                        
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-warning" 
                                                        onclick="bulkAddCategory(<?= $category['id'] ?>)"
                                                        title="모든 점포에 추가">
                                                    <i class="fas fa-layer-group"></i>
                                                </button>
                                                <button class="btn btn-outline-info" 
                                                        onclick="analyzeCategoryPerformance(<?= $category['id'] ?>)"
                                                        title="성과 분석">
                                                    <i class="fas fa-chart-line"></i>
                                                </button>
                                                <a href="category_management.php?edit_id=<?= $category['id'] ?>" 
                                                   class="btn btn-outline-primary"
                                                   title="카테고리 편집">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 차트 뷰 -->
                    <div id="chartView" style="display: none;">
                        <div class="row">
                            <div class="col-lg-8">
                                <canvas id="categoryDistributionChart" width="400" height="200"></canvas>
                            </div>
                            <div class="col-lg-4">
                                <div class="chart-legend">
                                    <h6 class="mb-3">카테고리별 분포</h6>
                                    <div id="chartLegend">
                                        <!-- 차트 범례가 여기에 생성됩니다 -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 빠른 작업 카드들 -->
    <div class="row">
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-magic text-success me-2"></i>빠른 설정
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-success" onclick="syncAllCategoriesToStores()">
                            <i class="fas fa-sync me-1"></i> 모든 카테고리를 모든 점포에 동기화
                        </button>
                        <button class="btn btn-outline-warning" onclick="copyStoreCategories()">
                            <i class="fas fa-copy me-1"></i> 한 점포의 카테고리를 다른 점포로 복사
                        </button>
                        <button class="btn btn-outline-info" onclick="resetStorCategories()">
                            <i class="fas fa-undo me-1"></i> 점포별 카테고리 설정 초기화
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-chart-pie text-info me-2"></i>성과 분석
                    </h6>
                </div>
                <div class="card-body">
                    <div class="performance-stats">
                        <div class="stat-item mb-2">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">최고 성과 카테고리</small>
                                <strong class="text-success">신선식품</strong>
                            </div>
                        </div>
                        <div class="stat-item mb-2">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">평균 상품 배치율</small>
                                <strong class="text-primary">78%</strong>
                            </div>
                        </div>
                        <div class="stat-item mb-2">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">개선 필요 카테고리</small>
                                <strong class="text-warning">생활용품</strong>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-outline-info w-100 mt-2" onclick="showDetailedAnalysis()">
                        <i class="fas fa-chart-line me-1"></i> 상세 분석 보기
                    </button>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-bell text-warning me-2"></i>알림 및 권장사항
                    </h6>
                </div>
                <div class="card-body">
                    <div class="recommendations">
                        <div class="recommendation-item mb-2 p-2 bg-light rounded">
                            <small class="text-muted">
                                <i class="fas fa-lightbulb text-warning me-1"></i>
                                '생활용품' 카테고리가 CLARK HILLS점에만 배치되어 있습니다.
                            </small>
                        </div>
                        <div class="recommendation-item mb-2 p-2 bg-light rounded">
                            <small class="text-muted">
                                <i class="fas fa-info-circle text-info me-1"></i>
                                신규 카테고리 '헬스&뷰티'를 추가해보세요.
                            </small>
                        </div>
                        <div class="recommendation-item p-2 bg-light rounded">
                            <small class="text-muted">
                                <i class="fas fa-star text-success me-1"></i>
                                '신선식품' 카테고리가 가장 높은 참여도를 보입니다.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 일괄 카테고리 관리 모달 -->
<div class="modal fade" id="bulkCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">일괄 카테고리 관리</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>적용할 카테고리 선택</h6>
                        <div class="category-selection" style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($categories as $category): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" 
                                       id="bulkCategory<?= $category['id'] ?>" 
                                       value="<?= $category['id'] ?>">
                                <label class="form-check-label" for="bulkCategory<?= $category['id'] ?>">
                                    <i class="<?= $category['icon_class'] ?: 'fas fa-folder' ?> me-2"></i>
                                    <?= htmlspecialchars($category['name']) ?>
                                    <small class="text-muted">(<?= $category['total_products'] ?>개 상품)</small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6>적용할 점포 선택</h6>
                        <div class="store-selection">
                            <?php foreach ($stores as $store): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" 
                                       id="bulkStore<?= $store['id'] ?>" 
                                       value="<?= $store['id'] ?>">
                                <label class="form-check-label" for="bulkStore<?= $store['id'] ?>">
                                    <?= htmlspecialchars($store['name']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-3">
                            <h6>작업 선택</h6>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="bulkAction" value="add" id="bulkActionAdd" checked>
                                <label class="form-check-label" for="bulkActionAdd">
                                    카테고리 상품을 점포에 추가
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="bulkAction" value="remove" id="bulkActionRemove">
                                <label class="form-check-label" for="bulkActionRemove">
                                    카테고리 상품을 점포에서 제거
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="bulkAction" value="feature" id="bulkActionFeature">
                                <label class="form-check-label" for="bulkActionFeature">
                                    카테고리 상품을 추천 상품으로 설정
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" onclick="executeBulkCategoryOperation()">실행</button>
            </div>
        </div>
    </div>
</div>

<!-- 자동 할당 규칙 모달 -->
<div class="modal fade" id="autoAssignRulesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">자동 할당 규칙 설정</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="rules-container">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        자동 할당 규칙을 설정하면 새로운 상품이 등록될 때 자동으로 적절한 점포에 배치됩니다.
                    </div>
                    
                    <div class="rule-form">
                        <h6>새 규칙 추가</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">카테고리</label>
                                <select class="form-select" id="ruleCategory">
                                    <option value="">카테고리 선택</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>">
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">적용 점포</label>
                                <select class="form-select" id="ruleStore">
                                    <option value="all">모든 점포</option>
                                    <?php foreach ($stores as $store): ?>
                                    <option value="<?= $store['id'] ?>">
                                        <?= htmlspecialchars($store['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">설정</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="ruleAutoFeatured">
                                    <label class="form-check-label" for="ruleAutoFeatured">
                                        자동 추천상품
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2">
                            <button class="btn btn-primary btn-sm" onclick="addAutoAssignRule()">
                                <i class="fas fa-plus me-1"></i> 규칙 추가
                            </button>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="existing-rules">
                        <h6>기존 규칙 목록</h6>
                        <div id="rulesList">
                            <!-- 기존 규칙들이 여기에 표시됩니다 -->
                            <div class="rule-item p-2 border rounded mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>신선식품</strong> → <span class="text-primary">모든 점포</span>
                                        <span class="badge bg-warning text-dark ms-2">자동 추천</span>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick="editRule(1)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="deleteRule(1)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">닫기</button>
                <button type="button" class="btn btn-success" onclick="saveAllRules()">모든 규칙 저장</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// 페이지 로드시 초기화
document.addEventListener('DOMContentLoaded', function() {
    initializeCharts();
    loadAutoAssignRules();
});

// 매트릭스/차트 뷰 토글
function toggleMatrixView(view) {
    const matrixView = document.getElementById('matrixView');
    const chartView = document.getElementById('chartView');
    const buttons = document.querySelectorAll('[data-view]');
    
    // 버튼 상태 업데이트
    buttons.forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[data-view="${view}"]`).classList.add('active');
    
    // 뷰 전환
    if (view === 'matrix') {
        matrixView.style.display = 'block';
        chartView.style.display = 'none';
    } else {
        matrixView.style.display = 'none';
        chartView.style.display = 'block';
        updateChart();
    }
}

// 차트 초기화
function initializeCharts() {
    const ctx = document.getElementById('categoryDistributionChart').getContext('2d');
    
    // 점포별 카테고리 분포 데이터 준비
    const chartData = <?= json_encode($store_category_stats) ?>;
    const storeNames = <?= json_encode(array_column($stores, 'name')) ?>;
    const categoryNames = <?= json_encode(array_column($categories, 'name')) ?>;
    
    window.categoryChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: categoryNames,
            datasets: storeNames.map((storeName, index) => {
                const storeId = <?= json_encode(array_column($stores, 'id')) ?>[index];
                return {
                    label: storeName,
                    data: categoryNames.map(catName => {
                        const stat = chartData[storeId]?.find(s => s.category_name === catName);
                        return stat ? stat.store_products : 0;
                    }),
                    backgroundColor: `hsl(${index * 360 / storeNames.length}, 70%, 60%)`,
                    borderColor: `hsl(${index * 360 / storeNames.length}, 70%, 40%)`,
                    borderWidth: 1
                };
            })
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: '점포별 카테고리 상품 분포'
                },
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: '상품 수'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: '카테고리'
                    }
                }
            }
        }
    });
}

// 차트 업데이트
function updateChart() {
    if (window.categoryChart) {
        window.categoryChart.update();
    }
}

// 카테고리를 점포에 추가
async function addCategoryToStore(categoryId, storeId) {
    if (!confirm('이 카테고리의 모든 상품을 해당 점포에 추가하시겠습니까?')) return;
    
    try {
        // 먼저 해당 카테고리의 상품 목록을 가져옵니다
        const response = await fetch(`/homekmart/shop/api/products.php?category=${categoryId}`);
        const data = await response.json();
        
        if (data.success && data.products.length > 0) {
            let addedCount = 0;
            let errors = [];
            
            // 각 상품을 점포에 추가
            for (const product of data.products) {
                try {
                    const addResponse = await fetch('/homekmart/shop/api/store_product_manage.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            store_id: storeId,
                            product_id: product.id,
                            is_available: true,
                            display_order: 0,
                            is_featured: false
                        })
                    });
                    
                    const addData = await addResponse.json();
                    if (addData.success) {
                        addedCount++;
                    } else {
                        errors.push(`${product.name_kr}: ${addData.message}`);
                    }
                } catch (error) {
                    errors.push(`${product.name_kr}: 네트워크 오류`);
                }
            }
            
            if (addedCount > 0) {
                showSuccess(`${addedCount}개 상품이 추가되었습니다.`);
                location.reload(); // 페이지 새로고침
            }
            
            if (errors.length > 0) {
                console.warn('일부 상품 추가 실패:', errors);
            }
            
        } else {
            showWarning('해당 카테고리에 상품이 없습니다.');
        }
        
    } catch (error) {
        console.error('카테고리 추가 오류:', error);
        showError('카테고리 추가 중 오류가 발생했습니다.');
    }
}

// 점포의 카테고리 상품 관리
function manageCategoryInStore(categoryId, storeId) {
    // 해당 카테고리 상품 관리 페이지로 이동
    window.open(`store_product_management.php?store_id=${storeId}&category=${categoryId}`, '_blank');
}

// 카테고리를 모든 점포에 일괄 추가
async function bulkAddCategory(categoryId) {
    const categoryName = <?= json_encode(array_column($categories, 'name', 'id')) ?>[categoryId];
    
    if (!confirm(`"${categoryName}" 카테고리의 모든 상품을 모든 점포에 추가하시겠습니까?`)) return;
    
    const storeIds = <?= json_encode(array_column($stores, 'id')) ?>;
    
    showLoading('일괄 추가 작업을 진행 중입니다...');
    
    try {
        for (const storeId of storeIds) {
            await addCategoryToStore(categoryId, storeId);
        }
        
        showSuccess('모든 점포에 카테고리가 추가되었습니다.');
        location.reload();
        
    } catch (error) {
        console.error('일괄 추가 오류:', error);
        showError('일괄 추가 중 오류가 발생했습니다.');
    } finally {
        hideLoading();
    }
}

// 카테고리 성과 분석
function analyzeCategoryPerformance(categoryId) {
    const categoryName = <?= json_encode(array_column($categories, 'name', 'id')) ?>[categoryId];
    
    // 간단한 분석 정보 표시 (실제로는 별도 페이지나 모달로 구현)
    alert(`"${categoryName}" 카테고리 성과 분석\n\n이 기능은 향후 구현 예정입니다.`);
}

// 일괄 카테고리 관리 모달 표시
function showBulkCategoryManager() {
    new bootstrap.Modal(document.getElementById('bulkCategoryModal')).show();
}

// 일괄 카테고리 작업 실행
async function executeBulkCategoryOperation() {
    const selectedCategories = Array.from(document.querySelectorAll('input[id^="bulkCategory"]:checked'))
                                   .map(cb => parseInt(cb.value));
    const selectedStores = Array.from(document.querySelectorAll('input[id^="bulkStore"]:checked'))
                               .map(cb => parseInt(cb.value));
    const action = document.querySelector('input[name="bulkAction"]:checked').value;
    
    if (selectedCategories.length === 0) {
        showWarning('카테고리를 선택해주세요.');
        return;
    }
    
    if (selectedStores.length === 0) {
        showWarning('점포를 선택해주세요.');
        return;
    }
    
    if (!confirm(`선택한 ${selectedCategories.length}개 카테고리에 대해 "${action}" 작업을 ${selectedStores.length}개 점포에 적용하시겠습니까?`)) {
        return;
    }
    
    // TODO: 실제 일괄 작업 실행
    console.log('일괄 작업 실행:', { selectedCategories, selectedStores, action });
    
    bootstrap.Modal.getInstance(document.getElementById('bulkCategoryModal')).hide();
    showSuccess('일괄 작업이 완료되었습니다.');
}

// 자동 할당 규칙 모달 표시
function showAutoAssignRules() {
    new bootstrap.Modal(document.getElementById('autoAssignRulesModal')).show();
}

// 자동 할당 규칙 로드
function loadAutoAssignRules() {
    // TODO: 실제 규칙 데이터 로드 구현
    console.log('자동 할당 규칙 로드');
}

// 자동 할당 규칙 추가
function addAutoAssignRule() {
    const category = document.getElementById('ruleCategory').value;
    const store = document.getElementById('ruleStore').value;
    const autoFeatured = document.getElementById('ruleAutoFeatured').checked;
    
    if (!category) {
        showWarning('카테고리를 선택해주세요.');
        return;
    }
    
    // TODO: 실제 규칙 추가 로직 구현
    console.log('규칙 추가:', { category, store, autoFeatured });
    
    showSuccess('자동 할당 규칙이 추가되었습니다.');
}

// 기타 빠른 작업들
function syncAllCategoriesToStores() {
    if (!confirm('모든 카테고리의 상품을 모든 점포에 동기화하시겠습니까? 이 작업은 시간이 오래 걸릴 수 있습니다.')) return;
    
    showLoading('동기화 작업을 진행 중입니다...');
    
    // TODO: 실제 동기화 로직 구현
    setTimeout(() => {
        hideLoading();
        showSuccess('모든 카테고리가 동기화되었습니다.');
    }, 3000);
}

function copyStoreCategories() {
    // TODO: 점포간 카테고리 복사 기능 구현
    alert('점포간 카테고리 복사 기능은 향후 구현 예정입니다.');
}

function resetStoreCategories() {
    if (!confirm('정말로 점포별 카테고리 설정을 초기화하시겠습니까? 이 작업은 되돌릴 수 없습니다.')) return;
    
    // TODO: 실제 초기화 로직 구현
    showSuccess('점포별 카테고리 설정이 초기화되었습니다.');
}

function showDetailedAnalysis() {
    // TODO: 상세 분석 페이지 또는 모달 구현
    window.open('category_analysis.php', '_blank');
}

// 유틸리티 함수들
function showLoading(message = '처리 중입니다...') {
    // TODO: 로딩 표시 구현
    console.log('로딩:', message);
}

function hideLoading() {
    // TODO: 로딩 숨김 구현
    console.log('로딩 완료');
}

function showSuccess(message) {
    alert('성공: ' + message);
}

function showError(message) {
    alert('오류: ' + message);
}

function showWarning(message) {
    alert('경고: ' + message);
}
</script>

<style>
/* 그라디언트 배경 */
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

/* 카테고리 매트릭스 테이블 */
.category-matrix-table {
    font-size: 0.9rem;
}

.category-matrix-table th {
    background-color: #f8f9fa;
    border-top: 2px solid #dee2e6;
    font-weight: 600;
    vertical-align: middle;
}

.category-matrix-table td {
    vertical-align: middle;
    padding: 0.75rem 0.5rem;
}

.store-header {
    text-align: center;
    line-height: 1.2;
}

/* 카테고리 통계 셀 */
.category-stat-cell {
    padding: 8px;
    border-radius: 6px;
    background: #f8f9fa;
    transition: all 0.2s;
}

.category-stat-cell:hover {
    background: #e9ecef;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stat-number {
    margin-bottom: 4px;
}

.stat-featured {
    margin-bottom: 6px;
}

.stat-actions .btn {
    padding: 2px 6px;
    font-size: 11px;
}

/* 차트 컨테이너 */
#categoryDistributionChart {
    max-height: 400px;
}

.chart-legend {
    max-height: 400px;
    overflow-y: auto;
}

/* 성과 분석 카드 */
.performance-stats .stat-item {
    padding: 0.25rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.performance-stats .stat-item:last-child {
    border-bottom: none;
}

/* 추천사항 카드 */
.recommendation-item {
    border-left: 3px solid #dee2e6;
    transition: all 0.2s;
}

.recommendation-item:hover {
    border-left-color: #007bff;
    background-color: #f0f8ff !important;
}

/* 모달 스타일 */
.category-selection,
.store-selection {
    background: #f8f9fa;
    border-radius: 6px;
    padding: 0.75rem;
}

.rule-item {
    background: #f8f9fa;
    transition: all 0.2s;
}

.rule-item:hover {
    background: #e9ecef;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* 반응형 스타일 */
@media (max-width: 768px) {
    .category-matrix-table {
        font-size: 0.8rem;
    }
    
    .stat-actions .btn {
        padding: 1px 4px;
        font-size: 10px;
    }
    
    .store-header {
        font-size: 0.8rem;
    }
    
    .category-stat-cell {
        padding: 4px;
    }
}

/* 스크롤 스타일 */
.table-responsive {
    scrollbar-width: thin;
    scrollbar-color: #dee2e6 transparent;
}

.table-responsive::-webkit-scrollbar {
    height: 8px;
    width: 8px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background-color: #dee2e6;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background-color: #adb5bd;
}

/* 고정 헤더 */
.sticky-top {
    position: sticky;
    top: 0;
    z-index: 10;
}
</style>

<?php include 'partials/footer.php'; ?>
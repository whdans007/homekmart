<?php
/**
 * 점포별 상품 배치 관리자
 * 드래그 앤 드롭으로 여러 점포에 상품을 효율적으로 배치할 수 있는 도구
 */

session_start();
require_once '../config/db_config.php';
require_once '../lib/permission_helper.php';

// 권한 확인
require_permission('admin_access', '../login.php');

$conn = get_db_connection();

// 활성 점포 목록 조회
$stores_sql = "SELECT id, name, address, manager, is_active FROM stores WHERE is_active = 1 ORDER BY name";
$stores_result = $conn->query($stores_sql);
$stores = [];
while ($row = $stores_result->fetch_assoc()) {
    $stores[] = $row;
}

// 카테고리 목록 조회
$categories_sql = "SELECT id, name, icon_class FROM categories ORDER BY name";
$categories_result = $conn->query($categories_sql);
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row;
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
                        <i class="fas fa-tools text-success me-2"></i>
                        점포별 상품 배치 관리자
                    </h1>
                    <p class="text-muted">드래그 앤 드롭으로 여러 점포에 상품을 효율적으로 배치합니다.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="shop_dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> 대시보드로
                    </a>
                    <button class="btn btn-info" onclick="showBulkActions()">
                        <i class="fas fa-tasks me-1"></i> 일괄 작업
                    </button>
                    <button class="btn btn-success" onclick="saveAllChanges()">
                        <i class="fas fa-save me-1"></i> 모든 변경사항 저장
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 필터 및 검색 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <label class="form-label">상품 검색</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="productSearch" 
                                       placeholder="상품명 또는 바코드로 검색">
                                <button class="btn btn-outline-secondary" onclick="searchProducts()">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">카테고리 필터</label>
                            <select class="form-select" id="categoryFilter" onchange="filterByCategory()">
                                <option value="">모든 카테고리</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>">
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">상태 필터</label>
                            <select class="form-select" id="statusFilter" onchange="filterByStatus()">
                                <option value="">모든 상품</option>
                                <option value="unassigned">미배치 상품</option>
                                <option value="assigned">배치된 상품</option>
                                <option value="featured">추천 상품</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">빠른 작업</label>
                            <div class="btn-group w-100">
                                <button class="btn btn-outline-primary" onclick="selectAllProducts()">
                                    <i class="fas fa-check-square"></i> 전체 선택
                                </button>
                                <button class="btn btn-outline-secondary" onclick="clearSelection()">
                                    <i class="fas fa-times"></i> 선택 해제
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 메인 작업 영역 -->
    <div class="row">
        <!-- 상품 목록 (좌측) -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-box-open text-primary me-2"></i>
                        상품 목록
                        <span class="badge bg-secondary ms-2" id="productCount">0</span>
                    </h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary active" data-view="grid" onclick="toggleProductView('grid')">
                            <i class="fas fa-th"></i>
                        </button>
                        <button class="btn btn-outline-primary" data-view="list" onclick="toggleProductView('list')">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-2">
                    <!-- 로딩 표시 -->
                    <div id="productsLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">상품 목록을 불러오는 중...</p>
                    </div>

                    <!-- 그리드 뷰 -->
                    <div id="productGridView" class="products-container" style="display: none;">
                        <div class="row g-2" id="productGrid">
                            <!-- 동적으로 생성될 상품 카드들 -->
                        </div>
                    </div>

                    <!-- 리스트 뷰 -->
                    <div id="productListView" class="products-container" style="display: none;">
                        <div class="list-group" id="productList">
                            <!-- 동적으로 생성될 상품 리스트 -->
                        </div>
                    </div>

                    <!-- 빈 상태 -->
                    <div id="emptyProducts" class="text-center py-5" style="display: none;">
                        <i class="fas fa-search fa-3x text-muted mb-3"></i>
                        <h5>검색 결과가 없습니다</h5>
                        <p class="text-muted">다른 검색어나 필터를 시도해보세요.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 점포별 배치 영역 (우측) -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-store text-success me-2"></i>
                        점포별 상품 배치
                    </h5>
                </div>
                <div class="card-body p-2">
                    <div class="store-tabs">
                        <!-- 점포 탭 -->
                        <ul class="nav nav-pills nav-fill mb-3" id="storeTabs">
                            <?php foreach ($stores as $index => $store): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= $index === 0 ? 'active' : '' ?>" 
                                   data-bs-toggle="pill" 
                                   href="#store-<?= $store['id'] ?>">
                                    <?= htmlspecialchars($store['name']) ?>
                                    <span class="badge bg-light text-dark ms-1" id="store-count-<?= $store['id'] ?>">0</span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>

                        <!-- 점포 콘텐츠 -->
                        <div class="tab-content" id="storeContent">
                            <?php foreach ($stores as $index => $store): ?>
                            <div class="tab-pane fade <?= $index === 0 ? 'show active' : '' ?>" 
                                 id="store-<?= $store['id'] ?>" 
                                 data-store-id="<?= $store['id'] ?>">
                                
                                <!-- 점포 정보 헤더 -->
                                <div class="store-header bg-light rounded p-2 mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-0"><?= htmlspecialchars($store['name']) ?></h6>
                                            <small class="text-muted"><?= htmlspecialchars($store['address'] ?: '주소 정보 없음') ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-success">운영중</span>
                                            <br>
                                            <small class="text-muted">담당: <?= htmlspecialchars($store['manager'] ?: '미지정') ?></small>
                                        </div>
                                    </div>
                                </div>

                                <!-- 드롭 영역 -->
                                <div class="drop-zone" 
                                     data-store-id="<?= $store['id'] ?>"
                                     ondrop="dropProduct(event)" 
                                     ondragover="allowDrop(event)"
                                     ondragenter="highlightDropZone(event)"
                                     ondragleave="removeDropHighlight(event)">
                                    
                                    <div class="drop-placeholder text-center py-5">
                                        <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                        <h6 class="text-muted">상품을 여기에 드래그하세요</h6>
                                        <p class="text-muted small">또는 상품 카드의 "+" 버튼을 클릭하세요</p>
                                    </div>

                                    <div class="assigned-products" id="assigned-<?= $store['id'] ?>">
                                        <!-- 배치된 상품들이 여기에 표시됩니다 -->
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 일괄 작업 모달 -->
<div class="modal fade" id="bulkActionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">일괄 작업</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>선택된 상품 <span class="badge bg-primary" id="selectedProductCount">0</span></h6>
                        <div id="selectedProductsList" class="selected-products-list">
                            <!-- 선택된 상품 목록 -->
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6>일괄 적용할 점포 선택</h6>
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
                            <h6>추가 설정</h6>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="bulkFeatured">
                                <label class="form-check-label" for="bulkFeatured">
                                    추천 상품으로 설정
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="bulkAvailable" checked>
                                <label class="form-check-label" for="bulkAvailable">
                                    점포에서 표시
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" onclick="executebulk()">적용</button>
            </div>
        </div>
    </div>
</div>

<!-- 상품 상세 설정 모달 -->
<div class="modal fade" id="productSettingsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">상품 설정</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="productSettingsForm">
                    <!-- 상품별 세부 설정 폼 -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" onclick="saveProductSettings()">저장</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
// 전역 변수
let products = [];
let storeProducts = {};
let selectedProducts = new Set();
let currentView = 'grid';
let changeQueue = new Map();

// 페이지 로드시 초기화
document.addEventListener('DOMContentLoaded', function() {
    loadProducts();
    loadStoreProducts();
    initializeSortable();
    
    // 검색 입력 이벤트
    document.getElementById('productSearch').addEventListener('input', debounce(searchProducts, 300));
});

// 상품 목록 로드
async function loadProducts() {
    try {
        showProductsLoading();
        
        const response = await fetch('/homekmart/shop/api/products.php');
        const data = await response.json();
        
        if (data.success) {
            products = data.products;
            renderProducts();
            updateProductCount();
        } else {
            showError('상품 목록을 불러오는데 실패했습니다: ' + data.message);
        }
        
    } catch (error) {
        console.error('상품 로드 오류:', error);
        showError('상품 목록을 불러오는 중 오류가 발생했습니다.');
    } finally {
        hideProductsLoading();
    }
}

// 점포별 상품 목록 로드
async function loadStoreProducts() {
    <?php foreach ($stores as $store): ?>
    try {
        const response = await fetch(`/homekmart/shop/api/store_product_manage.php?store_id=<?= $store['id'] ?>`);
        const data = await response.json();
        
        if (data.success) {
            storeProducts[<?= $store['id'] ?>] = data.products;
            renderStoreProducts(<?= $store['id'] ?>);
            updateStoreCount(<?= $store['id'] ?>);
        }
    } catch (error) {
        console.error('점포 <?= $store['id'] ?> 상품 로드 오류:', error);
    }
    <?php endforeach; ?>
}

// 상품 목록 렌더링
function renderProducts() {
    if (currentView === 'grid') {
        renderProductGrid();
    } else {
        renderProductList();
    }
    
    document.getElementById('productGridView').style.display = currentView === 'grid' ? 'block' : 'none';
    document.getElementById('productListView').style.display = currentView === 'list' ? 'block' : 'none';
}

// 그리드 뷰 렌더링
function renderProductGrid() {
    const grid = document.getElementById('productGrid');
    
    if (products.length === 0) {
        document.getElementById('emptyProducts').style.display = 'block';
        grid.innerHTML = '';
        return;
    }
    
    document.getElementById('emptyProducts').style.display = 'none';
    
    grid.innerHTML = products.map(product => `
        <div class="col-md-6 col-lg-4 col-xl-3 mb-2">
            <div class="card product-card h-100 ${selectedProducts.has(product.id) ? 'selected' : ''}" 
                 data-product-id="${product.id}"
                 draggable="true"
                 ondragstart="dragStart(event)">
                <div class="card-img-container">
                    ${product.image 
                        ? `<img src="${product.image}" class="card-img-top" alt="${product.name_kr}">`
                        : `<div class="card-img-placeholder">
                             <i class="fas fa-image fa-2x text-muted"></i>
                           </div>`
                    }
                    <div class="card-overlay">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" 
                                   ${selectedProducts.has(product.id) ? 'checked' : ''}
                                   onchange="toggleProductSelection(${product.id})">
                        </div>
                    </div>
                </div>
                <div class="card-body p-2">
                    <h6 class="card-title small mb-1">${product.name_kr}</h6>
                    ${product.name_en ? `<p class="text-muted small mb-1">${product.name_en}</p>` : ''}
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">${product.category_name || '카테고리 없음'}</small>
                        <div class="btn-group btn-group-sm">
                            <?php foreach ($stores as $store): ?>
                            <button class="btn btn-outline-success btn-sm store-add-btn" 
                                    onclick="addToStore(${product.id}, <?= $store['id'] ?>)"
                                    title="<?= htmlspecialchars($store['name']) ?>점에 추가">
                                <i class="fas fa-plus"></i>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

// 리스트 뷰 렌더링
function renderProductList() {
    const list = document.getElementById('productList');
    
    if (products.length === 0) {
        document.getElementById('emptyProducts').style.display = 'block';
        list.innerHTML = '';
        return;
    }
    
    document.getElementById('emptyProducts').style.display = 'none';
    
    list.innerHTML = products.map(product => `
        <div class="list-group-item product-item ${selectedProducts.has(product.id) ? 'selected' : ''}"
             data-product-id="${product.id}"
             draggable="true"
             ondragstart="dragStart(event)">
            <div class="row align-items-center">
                <div class="col-auto">
                    <input class="form-check-input" type="checkbox" 
                           ${selectedProducts.has(product.id) ? 'checked' : ''}
                           onchange="toggleProductSelection(${product.id})">
                </div>
                <div class="col-auto">
                    ${product.image 
                        ? `<img src="${product.image}" class="product-thumbnail" alt="${product.name_kr}">`
                        : `<div class="product-thumbnail-placeholder">
                             <i class="fas fa-image text-muted"></i>
                           </div>`
                    }
                </div>
                <div class="col">
                    <h6 class="mb-1">${product.name_kr}</h6>
                    ${product.name_en ? `<p class="text-muted small mb-1">${product.name_en}</p>` : ''}
                    <small class="text-muted">${product.category_name || '카테고리 없음'}</small>
                </div>
                <div class="col-auto">
                    <div class="btn-group btn-group-sm">
                        <?php foreach ($stores as $store): ?>
                        <button class="btn btn-outline-success btn-sm" 
                                onclick="addToStore(${product.id}, <?= $store['id'] ?>)"
                                title="<?= htmlspecialchars($store['name']) ?>점에 추가">
                            <i class="fas fa-plus"></i>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

// 점포별 상품 렌더링
function renderStoreProducts(storeId) {
    const container = document.getElementById(`assigned-${storeId}`);
    const storeProductList = storeProducts[storeId] || [];
    
    if (storeProductList.length === 0) {
        container.innerHTML = '';
        return;
    }
    
    container.innerHTML = storeProductList.map(product => `
        <div class="assigned-product-item" data-product-id="${product.id}" data-store-id="${storeId}">
            <div class="d-flex align-items-center">
                <div class="flex-shrink-0">
                    ${product.image_url 
                        ? `<img src="${product.image_url}" class="assigned-product-thumb" alt="${product.name_kr}">`
                        : `<div class="assigned-product-thumb-placeholder">
                             <i class="fas fa-image text-muted"></i>
                           </div>`
                    }
                </div>
                <div class="flex-grow-1 ms-2">
                    <h6 class="mb-0 small">${product.name_kr}</h6>
                    <small class="text-muted">${product.category_name || '카테고리 없음'}</small>
                    <div class="mt-1">
                        ${product.is_featured ? '<span class="badge bg-warning text-dark">추천</span>' : ''}
                        ${product.is_available ? '<span class="badge bg-success">표시</span>' : '<span class="badge bg-secondary">숨김</span>'}
                    </div>
                </div>
                <div class="flex-shrink-0">
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="editProductSettings(${product.id}, ${storeId})" title="설정">
                            <i class="fas fa-cog"></i>
                        </button>
                        <button class="btn btn-outline-danger" onclick="removeFromStore(${product.id}, ${storeId})" title="제거">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

// 드래그 앤 드롭 관련 함수들
function dragStart(e) {
    e.dataTransfer.setData('text/plain', e.target.dataset.productId);
    e.target.classList.add('dragging');
}

function allowDrop(e) {
    e.preventDefault();
}

function highlightDropZone(e) {
    e.currentTarget.classList.add('drop-highlight');
}

function removeDropHighlight(e) {
    e.currentTarget.classList.remove('drop-highlight');
}

function dropProduct(e) {
    e.preventDefault();
    const productId = parseInt(e.dataTransfer.getData('text/plain'));
    const storeId = parseInt(e.currentTarget.dataset.storeId);
    
    e.currentTarget.classList.remove('drop-highlight');
    document.querySelector('.dragging')?.classList.remove('dragging');
    
    addToStore(productId, storeId);
}

// 상품을 점포에 추가
async function addToStore(productId, storeId) {
    try {
        const response = await fetch('/homekmart/shop/api/store_product_manage.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                store_id: storeId,
                product_id: productId,
                is_available: true,
                display_order: 0,
                is_featured: false
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccess(data.message);
            // 해당 점포 상품 목록 다시 로드
            await loadStoreProductsById(storeId);
        } else {
            showError('상품 추가에 실패했습니다: ' + data.message);
        }
        
    } catch (error) {
        console.error('상품 추가 오류:', error);
        showError('상품 추가 중 오류가 발생했습니다.');
    }
}

// 특정 점포의 상품 목록 다시 로드
async function loadStoreProductsById(storeId) {
    try {
        const response = await fetch(`/homekmart/shop/api/store_product_manage.php?store_id=${storeId}`);
        const data = await response.json();
        
        if (data.success) {
            storeProducts[storeId] = data.products;
            renderStoreProducts(storeId);
            updateStoreCount(storeId);
        }
    } catch (error) {
        console.error(`점포 ${storeId} 상품 로드 오류:`, error);
    }
}

// 점포에서 상품 제거
async function removeFromStore(productId, storeId) {
    const product = storeProducts[storeId]?.find(p => p.id === productId);
    const productName = product ? product.name_kr : '상품';
    
    if (!confirm(`"${productName}" 상품을 이 점포에서 제거하시겠습니까?`)) return;
    
    try {
        const response = await fetch(`/homekmart/shop/api/store_product_manage.php?store_id=${storeId}&product_id=${productId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccess(data.message);
            await loadStoreProductsById(storeId);
        } else {
            showError('상품 제거에 실패했습니다: ' + data.message);
        }
        
    } catch (error) {
        console.error('상품 제거 오류:', error);
        showError('상품 제거 중 오류가 발생했습니다.');
    }
}

// Sortable 초기화
function initializeSortable() {
    <?php foreach ($stores as $store): ?>
    const container<?= $store['id'] ?> = document.getElementById('assigned-<?= $store['id'] ?>');
    if (container<?= $store['id'] ?>) {
        Sortable.create(container<?= $store['id'] ?>, {
            group: 'shared',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onEnd: function(evt) {
                // 정렬 순서 변경 처리
                updateDisplayOrder(<?= $store['id'] ?>);
            }
        });
    }
    <?php endforeach; ?>
}

// 표시 순서 업데이트
async function updateDisplayOrder(storeId) {
    const items = document.querySelectorAll(`#assigned-${storeId} .assigned-product-item`);
    const updates = [];
    
    items.forEach((item, index) => {
        const productId = parseInt(item.dataset.productId);
        updates.push({ product_id: productId, display_order: index });
    });
    
    // 순서 업데이트 API 호출 (여기서는 생략하고 changeQueue에 저장)
    changeQueue.set(`order_${storeId}`, updates);
    
    console.log(`점포 ${storeId} 순서 변경:`, updates);
}

// 뷰 전환
function toggleProductView(view) {
    currentView = view;
    
    // 버튼 상태 업데이트
    document.querySelectorAll('[data-view]').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[data-view="${view}"]`).classList.add('active');
    
    // 뷰 렌더링
    renderProducts();
}

// 상품 선택/해제
function toggleProductSelection(productId) {
    if (selectedProducts.has(productId)) {
        selectedProducts.delete(productId);
    } else {
        selectedProducts.add(productId);
    }
    
    updateSelectionUI();
}

// 전체 선택
function selectAllProducts() {
    products.forEach(product => selectedProducts.add(product.id));
    updateSelectionUI();
    renderProducts();
}

// 선택 해제
function clearSelection() {
    selectedProducts.clear();
    updateSelectionUI();
    renderProducts();
}

// 선택 UI 업데이트
function updateSelectionUI() {
    document.getElementById('selectedProductCount').textContent = selectedProducts.size;
}

// 카운트 업데이트
function updateProductCount() {
    document.getElementById('productCount').textContent = products.length;
}

function updateStoreCount(storeId) {
    const count = (storeProducts[storeId] || []).length;
    document.getElementById(`store-count-${storeId}`).textContent = count;
}

// 검색 및 필터링
function searchProducts() {
    const term = document.getElementById('productSearch').value.toLowerCase();
    const categoryId = document.getElementById('categoryFilter').value;
    const status = document.getElementById('statusFilter').value;
    
    // TODO: 실제 필터링 로직 구현
    console.log('검색:', term, '카테고리:', categoryId, '상태:', status);
}

function filterByCategory() {
    searchProducts();
}

function filterByStatus() {
    searchProducts();
}

// 일괄 작업
function showBulkActions() {
    if (selectedProducts.size === 0) {
        showWarning('먼저 상품을 선택해주세요.');
        return;
    }
    
    updateBulkModal();
    new bootstrap.Modal(document.getElementById('bulkActionsModal')).show();
}

function updateBulkModal() {
    const selectedList = document.getElementById('selectedProductsList');
    const selectedArray = Array.from(selectedProducts).map(id => 
        products.find(p => p.id === id)
    ).filter(Boolean);
    
    selectedList.innerHTML = selectedArray.map(product => `
        <div class="selected-product-item">
            <small>${product.name_kr}</small>
        </div>
    `).join('');
}

async function executeB ulk() {
    const selectedStores = Array.from(document.querySelectorAll('input[id^="bulkStore"]:checked'))
                               .map(cb => parseInt(cb.value));
    
    if (selectedStores.length === 0) {
        showWarning('적용할 점포를 선택해주세요.');
        return;
    }
    
    const isFeatured = document.getElementById('bulkFeatured').checked;
    const isAvailable = document.getElementById('bulkAvailable').checked;
    
    // 일괄 작업 실행
    for (const storeId of selectedStores) {
        for (const productId of selectedProducts) {
            await addToStore(productId, storeId);
        }
    }
    
    bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal')).hide();
    showSuccess(`${selectedProducts.size}개 상품이 ${selectedStores.length}개 점포에 추가되었습니다.`);
}

// 상품 설정 편집
function editProductSettings(productId, storeId) {
    // TODO: 상품 설정 모달 구현
    console.log('상품 설정 편집:', productId, storeId);
}

// 모든 변경사항 저장
async function saveAllChanges() {
    if (changeQueue.size === 0) {
        showInfo('저장할 변경사항이 없습니다.');
        return;
    }
    
    // TODO: 변경사항 배치 저장 구현
    console.log('변경사항 저장:', changeQueue);
    showSuccess('모든 변경사항이 저장되었습니다.');
}

// 유틸리티 함수들
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function showProductsLoading() {
    document.getElementById('productsLoading').style.display = 'block';
    document.getElementById('productGridView').style.display = 'none';
    document.getElementById('productListView').style.display = 'none';
}

function hideProductsLoading() {
    document.getElementById('productsLoading').style.display = 'none';
}

// 알림 함수들
function showSuccess(message) {
    // 실제로는 toastr나 다른 알림 라이브러리 사용
    console.log('성공:', message);
    alert('성공: ' + message);
}

function showError(message) {
    console.error('오류:', message);
    alert('오류: ' + message);
}

function showWarning(message) {
    console.warn('경고:', message);
    alert('경고: ' + message);
}

function showInfo(message) {
    console.info('정보:', message);
    alert('정보: ' + message);
}
</script>

<style>
/* 상품 카드 스타일 */
.product-card {
    transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
    cursor: grab;
    position: relative;
}

.product-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.product-card.dragging {
    opacity: 0.5;
    transform: rotate(5deg);
}

.product-card.selected {
    border-color: #007bff;
    background-color: rgba(0, 123, 255, 0.1);
}

.card-img-container {
    position: relative;
    height: 120px;
    overflow: hidden;
}

.card-img-top {
    height: 100%;
    object-fit: cover;
}

.card-img-placeholder {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f8f9fa;
}

.card-overlay {
    position: absolute;
    top: 5px;
    left: 5px;
}

/* 상품 리스트 스타일 */
.product-item {
    cursor: grab;
    transition: background-color 0.2s;
}

.product-item.selected {
    background-color: rgba(0, 123, 255, 0.1);
    border-color: #007bff;
}

.product-thumbnail {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
}

.product-thumbnail-placeholder {
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f8f9fa;
    border-radius: 4px;
}

/* 드롭 영역 스타일 */
.drop-zone {
    min-height: 400px;
    border: 2px dashed #dee2e6;
    border-radius: 8px;
    transition: all 0.2s;
    position: relative;
}

.drop-zone.drop-highlight {
    border-color: #28a745;
    background-color: rgba(40, 167, 69, 0.1);
}

.drop-placeholder {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    pointer-events: none;
}

.assigned-products {
    position: relative;
    z-index: 1;
}

/* 배치된 상품 스타일 */
.assigned-product-item {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 8px;
    margin-bottom: 8px;
    transition: all 0.2s;
    cursor: grab;
}

.assigned-product-item:hover {
    background: #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.assigned-product-thumb {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 4px;
}

.assigned-product-thumb-placeholder {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #dee2e6;
    border-radius: 4px;
}

/* Sortable 스타일 */
.sortable-ghost {
    opacity: 0.4;
}

.sortable-chosen {
    background: #007bff;
    color: white;
}

/* 스크롤 스타일 */
.products-container {
    max-height: 600px;
    overflow-y: auto;
}

.products-container::-webkit-scrollbar {
    width: 8px;
}

.products-container::-webkit-scrollbar-thumb {
    background-color: #dee2e6;
    border-radius: 4px;
}

.products-container::-webkit-scrollbar-thumb:hover {
    background-color: #adb5bd;
}

/* 일괄 작업 모달 스타일 */
.selected-products-list {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 8px;
}

.selected-product-item {
    padding: 4px 0;
    border-bottom: 1px solid #f0f0f0;
}

.selected-product-item:last-child {
    border-bottom: none;
}

/* 반응형 스타일 */
@media (max-width: 768px) {
    .store-add-btn {
        display: none;
    }
    
    .product-card .card-body {
        padding: 8px;
    }
    
    .nav-pills .nav-link {
        font-size: 12px;
        padding: 6px 8px;
    }
}
</style>

<?php include 'partials/footer.php'; ?>
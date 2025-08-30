<?php
/**
 * 점포별 상품 관리 페이지
 * 관리자가 특정 점포의 상품을 관리할 수 있는 인라인 편집 인터페이스
 */

session_start();
require_once '../config/db_config.php';
require_once '../lib/permission_helper.php';

// 권한 확인
require_permission('admin_access', '../login.php');

$conn = get_db_connection();
$current_store_id = $_GET['store_id'] ?? $_SESSION['store_id'] ?? 1;

// 점포 정보 조회
$store_sql = "SELECT id, name, address, phone, manager, is_active FROM stores WHERE id = ?";
$store_stmt = $conn->prepare($store_sql);
$store_stmt->bind_param("i", $current_store_id);
$store_stmt->execute();
$store_result = $store_stmt->get_result();
$store_info = $store_result->fetch_assoc();

if (!$store_info) {
    header('Location: store_management.php');
    exit();
}

// 모든 점포 목록 조회 (드롭다운용)
$stores_sql = "SELECT id, name FROM stores WHERE is_active = 1 ORDER BY name";
$stores_result = $conn->query($stores_sql);
$all_stores = [];
while ($row = $stores_result->fetch_assoc()) {
    $all_stores[] = $row;
}

include 'partials/header.php';
?>

<div class="container-fluid">
    <!-- 페이지 헤더 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">점포별 상품 관리</h1>
                    <p class="text-muted">점포에 표시할 상품을 추가/제거하고 설정을 관리합니다.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="store_management.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> 점포 관리로
                    </a>
                    <button class="btn btn-success" onclick="addNewProduct()">
                        <i class="fas fa-plus me-1"></i> 상품 추가
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 점포 선택 및 정보 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <label class="form-label">점포 선택</label>
                            <select class="form-select" id="storeSelect" onchange="changeStore()">
                                <?php foreach ($all_stores as $store): ?>
                                <option value="<?= $store['id'] ?>" <?= $store['id'] == $current_store_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($store['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="store-info">
                                <h5 class="mb-1"><?= htmlspecialchars($store_info['name']) ?></h5>
                                <p class="text-muted mb-1"><?= htmlspecialchars($store_info['address']) ?></p>
                                <p class="text-muted mb-0">담당자: <?= htmlspecialchars($store_info['manager']) ?></p>
                                <span class="badge bg-<?= $store_info['is_active'] ? 'success' : 'secondary' ?> mt-1">
                                    <?= $store_info['is_active'] ? '운영중' : '준비중' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 상품 목록 테이블 -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">등록된 상품 목록</h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary" onclick="refreshProducts()">
                            <i class="fas fa-sync-alt"></i> 새로고침
                        </button>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary active" data-view="card" onclick="changeView('card')">
                                <i class="fas fa-th"></i>
                            </button>
                            <button class="btn btn-outline-secondary" data-view="table" onclick="changeView('table')">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- 로딩 표시 -->
                    <div id="loadingIndicator" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">로딩 중...</span>
                        </div>
                        <p class="mt-2 text-muted">상품 목록을 불러오는 중입니다...</p>
                    </div>

                    <!-- 카드 뷰 -->
                    <div id="cardView" class="row g-3" style="display: none;">
                        <!-- 동적으로 생성될 상품 카드들 -->
                    </div>

                    <!-- 테이블 뷰 -->
                    <div id="tableView" style="display: none;">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>이미지</th>
                                        <th>상품명</th>
                                        <th>카테고리</th>
                                        <th>바코드</th>
                                        <th>표시여부</th>
                                        <th>추천상품</th>
                                        <th>순서</th>
                                        <th>메모</th>
                                        <th>작업</th>
                                    </tr>
                                </thead>
                                <tbody id="productTableBody">
                                    <!-- 동적으로 생성될 상품 행들 -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 빈 결과 -->
                    <div id="emptyState" class="text-center py-5" style="display: none;">
                        <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                        <h5>등록된 상품이 없습니다</h5>
                        <p class="text-muted">이 점포에 상품을 추가해보세요.</p>
                        <button class="btn btn-primary" onclick="addNewProduct()">
                            <i class="fas fa-plus me-1"></i> 첫 번째 상품 추가
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 상품 추가 모달 -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">상품 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="availableProductsLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">추가 가능한 상품을 불러오는 중...</p>
                </div>
                <div id="availableProductsContainer" style="display: none;">
                    <div class="row g-3" id="availableProductsGrid">
                        <!-- 동적으로 생성될 추가 가능한 상품들 -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 상품 편집 모달 -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">상품 설정 편집</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editProductForm">
                    <input type="hidden" id="editProductId">
                    
                    <div class="mb-3">
                        <label class="form-label">상품명</label>
                        <div id="editProductName" class="form-control-plaintext"></div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="editIsAvailable">
                                <label class="form-check-label" for="editIsAvailable">
                                    점포에서 표시
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="editIsFeatured">
                                <label class="form-check-label" for="editIsFeatured">
                                    추천 상품
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editDisplayOrder" class="form-label">표시 순서</label>
                        <input type="number" class="form-control" id="editDisplayOrder" min="0" max="999">
                        <div class="form-text">숫자가 작을수록 먼저 표시됩니다.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editNotes" class="form-label">점포 메모</label>
                        <textarea class="form-control" id="editNotes" rows="3" placeholder="이 점포에서만 표시할 특별한 메모나 설명"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" onclick="saveProductSettings()">저장</button>
            </div>
        </div>
    </div>
</div>

<script>
// 전역 변수
let currentStoreId = <?= $current_store_id ?>;
let currentProducts = [];
let currentView = 'card';

// 페이지 로드시 초기화
document.addEventListener('DOMContentLoaded', function() {
    loadProducts();
});

// 점포 변경
function changeStore() {
    const storeId = document.getElementById('storeSelect').value;
    window.location.href = `store_product_management.php?store_id=${storeId}`;
}

// 상품 목록 로드
async function loadProducts() {
    try {
        showLoading();
        
        const response = await fetch(`/homekmart/shop/api/store_product_manage.php?store_id=${currentStoreId}`);
        const data = await response.json();
        
        if (data.success) {
            currentProducts = data.products;
            renderProducts(currentProducts);
        } else {
            showError('상품 목록을 불러오는데 실패했습니다: ' + data.message);
        }
        
    } catch (error) {
        console.error('상품 로드 오류:', error);
        showError('상품 목록을 불러오는 중 오류가 발생했습니다.');
    } finally {
        hideLoading();
    }
}

// 상품 목록 렌더링
function renderProducts(products) {
    if (products.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('cardView').style.display = 'none';
        document.getElementById('tableView').style.display = 'none';
        return;
    }
    
    document.getElementById('emptyState').style.display = 'none';
    
    if (currentView === 'card') {
        renderCardView(products);
        document.getElementById('cardView').style.display = 'block';
        document.getElementById('tableView').style.display = 'none';
    } else {
        renderTableView(products);
        document.getElementById('tableView').style.display = 'block';
        document.getElementById('cardView').style.display = 'none';
    }
}

// 카드 뷰 렌더링
function renderCardView(products) {
    const cardView = document.getElementById('cardView');
    
    cardView.innerHTML = products.map(product => `
        <div class="col-md-6 col-lg-4 col-xl-3">
            <div class="card product-card h-100">
                <div class="card-img-container">
                    ${product.image_url 
                        ? `<img src="${product.image_url}" class="card-img-top" alt="${product.name_kr}" style="height: 200px; object-fit: cover;">`
                        : `<div class="card-img-placeholder d-flex align-items-center justify-content-center" style="height: 200px; background: #f8f9fa;">
                             <i class="fas fa-image fa-3x text-muted"></i>
                           </div>`
                    }
                    <div class="card-badges">
                        ${product.is_featured ? '<span class="badge bg-warning text-dark">추천</span>' : ''}
                        <span class="badge bg-${product.is_available ? 'success' : 'secondary'}">
                            ${product.is_available ? '표시중' : '숨김'}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <h6 class="card-title">${product.name_kr}</h6>
                    ${product.name_en ? `<p class="text-muted small">${product.name_en}</p>` : ''}
                    <p class="text-muted small mb-2">${product.category_name || '카테고리 없음'}</p>
                    ${product.store_notes ? `<p class="text-info small mb-2"><i class="fas fa-sticky-note"></i> ${product.store_notes}</p>` : ''}
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">순서: ${product.display_order}</small>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="editProduct(${product.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-outline-danger" onclick="removeProduct(${product.id}, '${product.name_kr}')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

// 테이블 뷰 렌더링
function renderTableView(products) {
    const tableBody = document.getElementById('productTableBody');
    
    tableBody.innerHTML = products.map(product => `
        <tr>
            <td>
                ${product.image_url 
                    ? `<img src="${product.image_url}" alt="${product.name_kr}" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">`
                    : `<div class="bg-light d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; border-radius: 4px;">
                         <i class="fas fa-image text-muted"></i>
                       </div>`
                }
            </td>
            <td>
                <div>
                    <strong>${product.name_kr}</strong>
                    ${product.name_en ? `<br><small class="text-muted">${product.name_en}</small>` : ''}
                </div>
            </td>
            <td>${product.category_name || '-'}</td>
            <td><code>${product.barcode || '-'}</code></td>
            <td>
                <span class="badge bg-${product.is_available ? 'success' : 'secondary'}">
                    ${product.is_available ? '표시' : '숨김'}
                </span>
            </td>
            <td>
                <span class="badge bg-${product.is_featured ? 'warning text-dark' : 'light text-muted'}">
                    ${product.is_featured ? '추천' : '일반'}
                </span>
            </td>
            <td>${product.display_order}</td>
            <td>
                ${product.store_notes 
                    ? `<span class="text-info" title="${product.store_notes}"><i class="fas fa-sticky-note"></i></span>`
                    : '-'
                }
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="editProduct(${product.id})" title="편집">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-outline-danger" onclick="removeProduct(${product.id}, '${product.name_kr}')" title="제거">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

// 뷰 변경
function changeView(view) {
    currentView = view;
    
    // 버튼 상태 업데이트
    document.querySelectorAll('[data-view]').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-view="${view}"]`).classList.add('active');
    
    // 뷰 렌더링
    renderProducts(currentProducts);
}

// 새 상품 추가 모달 열기
function addNewProduct() {
    const modal = new bootstrap.Modal(document.getElementById('addProductModal'));
    loadAvailableProducts();
    modal.show();
}

// 추가 가능한 상품 로드
async function loadAvailableProducts() {
    try {
        document.getElementById('availableProductsLoading').style.display = 'block';
        document.getElementById('availableProductsContainer').style.display = 'none';
        
        const response = await fetch(`/homekmart/shop/api/store_product_manage.php?store_id=${currentStoreId}&action=available`);
        const data = await response.json();
        
        if (data.success) {
            renderAvailableProducts(data.products);
        } else {
            showError('추가 가능한 상품을 불러오는데 실패했습니다: ' + data.message);
        }
        
    } catch (error) {
        console.error('추가 가능한 상품 로드 오류:', error);
        showError('추가 가능한 상품을 불러오는 중 오류가 발생했습니다.');
    } finally {
        document.getElementById('availableProductsLoading').style.display = 'none';
        document.getElementById('availableProductsContainer').style.display = 'block';
    }
}

// 추가 가능한 상품 렌더링
function renderAvailableProducts(products) {
    const grid = document.getElementById('availableProductsGrid');
    
    if (products.length === 0) {
        grid.innerHTML = `
            <div class="col-12 text-center py-4">
                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                <h5>모든 상품이 이미 등록되었습니다</h5>
                <p class="text-muted">이 점포에 추가할 수 있는 상품이 없습니다.</p>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = products.map(product => `
        <div class="col-md-6 col-lg-4">
            <div class="card h-100">
                <div class="card-img-container">
                    ${product.image_url 
                        ? `<img src="${product.image_url}" class="card-img-top" alt="${product.name_kr}" style="height: 150px; object-fit: cover;">`
                        : `<div class="card-img-placeholder d-flex align-items-center justify-content-center" style="height: 150px; background: #f8f9fa;">
                             <i class="fas fa-image fa-2x text-muted"></i>
                           </div>`
                    }
                </div>
                <div class="card-body">
                    <h6 class="card-title">${product.name_kr}</h6>
                    ${product.name_en ? `<p class="text-muted small">${product.name_en}</p>` : ''}
                    <p class="text-muted small mb-2">${product.category_name || '카테고리 없음'}</p>
                    <div class="d-grid">
                        <button class="btn btn-primary btn-sm" onclick="addProductToStore(${product.id}, '${product.name_kr}')">
                            <i class="fas fa-plus me-1"></i> 추가
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

// 점포에 상품 추가
async function addProductToStore(productId, productName) {
    if (!confirm(`"${productName}" 상품을 이 점포에 추가하시겠습니까?`)) return;
    
    try {
        const response = await fetch(`/homekmart/shop/api/store_product_manage.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                store_id: currentStoreId,
                product_id: productId,
                is_available: true,
                display_order: 0,
                is_featured: false
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccess(data.message);
            
            // 모달 닫기
            bootstrap.Modal.getInstance(document.getElementById('addProductModal')).hide();
            
            // 상품 목록 새로고침
            loadProducts();
        } else {
            showError('상품 추가에 실패했습니다: ' + data.message);
        }
        
    } catch (error) {
        console.error('상품 추가 오류:', error);
        showError('상품 추가 중 오류가 발생했습니다.');
    }
}

// 상품 편집 모달 열기
function editProduct(productId) {
    const product = currentProducts.find(p => p.id === productId);
    if (!product) return;
    
    // 폼 데이터 설정
    document.getElementById('editProductId').value = productId;
    document.getElementById('editProductName').textContent = product.name_kr;
    document.getElementById('editIsAvailable').checked = product.is_available;
    document.getElementById('editIsFeatured').checked = product.is_featured;
    document.getElementById('editDisplayOrder').value = product.display_order;
    document.getElementById('editNotes').value = product.store_notes || '';
    
    // 모달 열기
    const modal = new bootstrap.Modal(document.getElementById('editProductModal'));
    modal.show();
}

// 상품 설정 저장
async function saveProductSettings() {
    const productId = document.getElementById('editProductId').value;
    const isAvailable = document.getElementById('editIsAvailable').checked;
    const isFeatured = document.getElementById('editIsFeatured').checked;
    const displayOrder = parseInt(document.getElementById('editDisplayOrder').value) || 0;
    const notes = document.getElementById('editNotes').value.trim();
    
    try {
        const response = await fetch(`/homekmart/shop/api/store_product_manage.php`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                store_id: currentStoreId,
                product_id: parseInt(productId),
                is_available: isAvailable,
                is_featured: isFeatured,
                display_order: displayOrder,
                notes: notes || null
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccess(data.message);
            
            // 모달 닫기
            bootstrap.Modal.getInstance(document.getElementById('editProductModal')).hide();
            
            // 상품 목록 새로고침
            loadProducts();
        } else {
            showError('설정 저장에 실패했습니다: ' + data.message);
        }
        
    } catch (error) {
        console.error('설정 저장 오류:', error);
        showError('설정 저장 중 오류가 발생했습니다.');
    }
}

// 상품 제거
async function removeProduct(productId, productName) {
    if (!confirm(`"${productName}" 상품을 이 점포에서 제거하시겠습니까?\n\n주의: 메인 상품 데이터는 삭제되지 않으며, 이 점포에서만 보이지 않게 됩니다.`)) {
        return;
    }
    
    try {
        const response = await fetch(`/homekmart/shop/api/store_product_manage.php?store_id=${currentStoreId}&product_id=${productId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccess(data.message);
            loadProducts();
        } else {
            showError('상품 제거에 실패했습니다: ' + data.message);
        }
        
    } catch (error) {
        console.error('상품 제거 오류:', error);
        showError('상품 제거 중 오류가 발생했습니다.');
    }
}

// 상품 목록 새로고침
function refreshProducts() {
    loadProducts();
}

// 유틸리티 함수들
function showLoading() {
    document.getElementById('loadingIndicator').style.display = 'block';
}

function hideLoading() {
    document.getElementById('loadingIndicator').style.display = 'none';
}

function showSuccess(message) {
    // 실제로는 toastr나 다른 알림 라이브러리를 사용
    alert('성공: ' + message);
}

function showError(message) {
    // 실제로는 toastr나 다른 알림 라이브러리를 사용
    alert('오류: ' + message);
}
</script>

<style>
.product-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.product-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.card-img-container {
    position: relative;
    overflow: hidden;
}

.card-badges {
    position: absolute;
    top: 8px;
    right: 8px;
    display: flex;
    gap: 4px;
    flex-direction: column;
    align-items: flex-end;
}

.card-badges .badge {
    font-size: 0.7em;
}

.store-info {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
}

.table th {
    border-top: none;
    font-weight: 600;
    background: #f8f9fa;
}
</style>

<?php include 'partials/footer.php'; ?>
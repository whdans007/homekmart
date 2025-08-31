<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 로그인 체크 및 권한 확인
ensure_logged_in();
require_permission('admin_access');

$conn = get_db_connection();
$page_title = '상품 진열 관리';

// 현재 사용자의 점포 정보 가져오기
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    $user_stmt = $conn->prepare("SELECT s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_row = $user_result->fetch_assoc()) {
        $current_store_id = $user_row['store_id'];
        
        // super_admin이고 store_id가 없는 경우 기본 점포 설정
        if ($_SESSION['role'] === 'super_admin' && empty($current_store_id)) {
            $current_store_id = 1; // CLARK HILLS
        }
    }
    $user_stmt->close();
}

// 메시지 처리
$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// 선택된 섹션 ID 가져오기
$selected_section_id = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;

// 섹션 정보 조회
$sections_query = "SELECT * FROM display_sections ORDER BY display_order ASC";
$sections_result = $conn->query($sections_query);

$selected_section = null;
if ($selected_section_id > 0) {
    $section_stmt = $conn->prepare("SELECT * FROM display_sections WHERE id = ?");
    $section_stmt->bind_param("i", $selected_section_id);
    $section_stmt->execute();
    $section_result = $section_stmt->get_result();
    $selected_section = $section_result->fetch_assoc();
    $section_stmt->close();
}

// 폼 데이터 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add_product') {
        $section_id = (int)$_POST['section_id'];
        $product_id = (int)$_POST['product_id'];
        $display_order = (int)$_POST['display_order'];
        $custom_title = trim($_POST['custom_title']);
        $custom_description = trim($_POST['custom_description']);
        $custom_image_url = trim($_POST['custom_image_url']);
        $badge_text = trim($_POST['badge_text']);
        $badge_color = ($_POST['badge_color'] !== '') ? $_POST['badge_color'] : NULL;
        $start_date = $_POST['start_date'] ?: NULL;
        $end_date = $_POST['end_date'] ?: NULL;
        
        if ($section_id > 0 && $product_id > 0) {
            // 중복 체크
            $check_stmt = $conn->prepare("SELECT id FROM product_displays WHERE section_id = ? AND product_id = ? AND store_id = ?");
            $check_stmt->bind_param("iii", $section_id, $product_id, $current_store_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_stmt->close();
            
            if ($check_result->num_rows > 0) {
                $_SESSION['message'] = '이미 해당 섹션에 진열된 상품입니다.';
                $_SESSION['message_type'] = 'error';
            } else {
                // 디버깅을 위한 값 검증
                if ($current_store_id === null || $current_store_id === '') {
                    $_SESSION['message'] = '점포 정보가 없습니다. 다시 로그인해주세요.';
                    $_SESSION['message_type'] = 'error';
                } else {
                    $stmt = $conn->prepare("INSERT INTO product_displays (section_id, product_id, store_id, display_order, custom_title, custom_description, custom_image_url, badge_text, badge_color, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiiisssssss", $section_id, $product_id, $current_store_id, $display_order, $custom_title, $custom_description, $custom_image_url, $badge_text, $badge_color, $start_date, $end_date);
                
                    if ($stmt->execute()) {
                        $_SESSION['message'] = '상품이 진열되었습니다.';
                        $_SESSION['message_type'] = 'success';
                    } else {
                        $_SESSION['message'] = '상품 진열에 실패했습니다.';
                        $_SESSION['message_type'] = 'error';
                    }
                    $stmt->close();
                }
            }
        }
        header("Location: product_display.php?section_id=$section_id");
        exit;
    }
    
    if ($action == 'update_display') {
        $id = (int)$_POST['id'];
        $display_order = (int)$_POST['display_order'];
        $custom_title = trim($_POST['custom_title']);
        $custom_description = trim($_POST['custom_description']);
        $custom_image_url = trim($_POST['custom_image_url']);
        $badge_text = trim($_POST['badge_text']);
        $badge_color = $_POST['badge_color'];
        $start_date = $_POST['start_date'] ?: null;
        $end_date = $_POST['end_date'] ?: null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE product_displays SET display_order=?, custom_title=?, custom_description=?, custom_image_url=?, badge_text=?, badge_color=?, start_date=?, end_date=?, is_active=? WHERE id=?");
            $stmt->bind_param("isssssssii", $display_order, $custom_title, $custom_description, $custom_image_url, $badge_text, $badge_color, $start_date, $end_date, $is_active, $id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = '진열 정보가 업데이트되었습니다.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = '진열 정보 업데이트에 실패했습니다.';
                $_SESSION['message_type'] = 'error';
            }
            $stmt->close();
        }
        header("Location: product_display.php?section_id=" . $_POST['section_id']);
        exit;
    }
    
    if ($action == 'remove_product') {
        $id = (int)$_POST['id'];
        $section_id = (int)$_POST['section_id'];
        
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM product_displays WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = '상품이 진열에서 제거되었습니다.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = '상품 제거에 실패했습니다.';
                $_SESSION['message_type'] = 'error';
            }
            $stmt->close();
        }
        header("Location: product_display.php?section_id=$section_id");
        exit;
    }
}

// 현재 점포 ID 가져오기 (세션 또는 사용자 정보에서)
$current_store_id = $_SESSION['store_id'] ?? 1; // 기본값 1

// 현재 섹션의 진열 상품 조회
$displayed_products = [];
if ($selected_section_id > 0) {
    $products_query = "
        SELECT pd.*, p.name_ko as product_name, p.barcode, p.description as product_description, 
               b.name_ko as brand_name, c.name as category_name,
               i.selling_price, i.cost_price
        FROM product_displays pd
        JOIN products p ON pd.product_id = p.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
        WHERE pd.section_id = ? AND pd.store_id = ?
        ORDER BY pd.display_order ASC, pd.created_at ASC
    ";
    $products_stmt = $conn->prepare($products_query);
    $products_stmt->bind_param("iii", $current_store_id, $selected_section_id, $current_store_id);
    $products_stmt->execute();
    $products_result = $products_stmt->get_result();
    $displayed_products = $products_result->fetch_all(MYSQLI_ASSOC);
    $products_stmt->close();
}

include 'partials/header.php';
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">상품 진열 관리</h1>
        <div>
            <a href="display_sections.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left me-2"></i>섹션 관리로
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : $message_type; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 섹션 선택 -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <label for="sectionSelect" class="form-label">진열 섹션 선택</label>
                    <select class="form-select" id="sectionSelect" onchange="changeSe">
                        <option value="">섹션을 선택하세요</option>
                        <?php if ($sections_result): ?>
                            <?php $sections_result->data_seek(0); ?>
                            <?php while ($section = $sections_result->fetch_assoc()): ?>
                                <option value="<?php echo $section['id']; ?>" <?php echo $section['id'] == $selected_section_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section['name']); ?>
                                    (<?php echo $section['section_type']; ?>)
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <?php if ($selected_section): ?>
                <div class="col-md-6">
                    <div class="text-end">
                        <h6 class="mb-1"><?php echo htmlspecialchars($selected_section['name']); ?></h6>
                        <small class="text-muted">
                            <?php echo htmlspecialchars($selected_section['description'] ?: ''); ?><br>
                            최대 상품: <?php echo $selected_section['max_products'] ?: '무제한'; ?> | 
                            레이아웃: <?php echo $selected_section['layout_type']; ?>
                        </small>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($selected_section): ?>
        <!-- 진열 상품 목록 -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-th me-2"></i><?php echo htmlspecialchars($selected_section['name']); ?> 진열 상품
                        <span class="badge bg-info ms-2"><?php echo count($displayed_products); ?>개</span>
                        <?php if ($selected_section['max_products']): ?>
                            <span class="badge bg-<?php echo count($displayed_products) >= $selected_section['max_products'] ? 'warning' : 'secondary'; ?> ms-1">
                                / <?php echo $selected_section['max_products']; ?>
                            </span>
                        <?php endif; ?>
                    </h5>
                    <div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="fas fa-plus me-1"></i> 상품 추가
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($displayed_products)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th width="40"></th>
                                    <th width="60">순서</th>
                                    <th>상품정보</th>
                                    <th>가격</th>
                                    <th>커스텀 설정</th>
                                    <th>배지</th>
                                    <th>진열기간</th>
                                    <th width="80">상태</th>
                                    <th width="150">관리</th>
                                </tr>
                            </thead>
                            <tbody id="sortable-products">
                                <?php foreach ($displayed_products as $product): ?>
                                    <tr data-product-id="<?php echo $product['id']; ?>">
                                        <td class="drag-handle" style="cursor: move; text-align: center;">
                                            <i class="fas fa-grip-vertical text-muted"></i>
                                        </td>
                                        <td><?php echo $product['display_order']; ?></td>
                                        <td>
                                            <div class="d-flex align-items-start">
                                                <div class="flex-shrink-0">
                                                    <?php if ($product['custom_image_url']): ?>
                                                        <img src="<?php echo htmlspecialchars($product['custom_image_url'] ?? ''); ?>" 
                                                             class="rounded" width="60" height="60" style="object-fit: cover;">
                                                    <?php else: ?>
                                                        <div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                                            <i class="fas fa-image text-muted"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <h6 class="mb-1">
                                                        <?php echo $product['custom_title'] ?: htmlspecialchars($product['product_name']); ?>
                                                    </h6>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($product['brand_name'] ?? ''); ?> |
                                                        <?php echo htmlspecialchars($product['category_name'] ?? ''); ?>
                                                        <?php if ($product['barcode']): ?>
                                                            <br>바코드: <?php echo htmlspecialchars($product['barcode']); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                    <?php if ($product['custom_description']): ?>
                                                        <p class="mb-0 mt-1 small text-primary">
                                                            <?php echo htmlspecialchars($product['custom_description'] ?? ''); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($product['selling_price']): ?>
                                                <strong><?php echo number_format($product['selling_price'], 2); ?></strong>
                                                <?php if ($product['cost_price']): ?>
                                                    <br><small class="text-muted">원가: <?php echo number_format($product['cost_price'], 2); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">미설정</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($product['custom_title'] || $product['custom_description']): ?>
                                                <span class="badge bg-success">사용자정의</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">기본</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($product['badge_text']): ?>
                                                <span class="badge bg-<?php echo $product['badge_color'] ?: 'primary'; ?>">
                                                    <?php echo htmlspecialchars($product['badge_text'] ?? ''); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">없음</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($product['start_date'] || $product['end_date']): ?>
                                                <small>
                                                    <?php if ($product['start_date']): ?>
                                                        <?php echo date('m/d', strtotime($product['start_date'])); ?>
                                                    <?php endif; ?>
                                                    <?php if ($product['start_date'] && $product['end_date']): ?>~<?php endif; ?>
                                                    <?php if ($product['end_date']): ?>
                                                        <?php echo date('m/d', strtotime($product['end_date'])); ?>
                                                    <?php endif; ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="text-muted">무기한</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($product['is_active']): ?>
                                                <span class="badge bg-success">활성</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">비활성</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary" onclick="editDisplay(<?php echo htmlspecialchars(json_encode($product)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" onclick="removeProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['product_name'] ?? ''); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">진열된 상품이 없습니다</h5>
                        <p class="text-muted">상품을 추가하여 진열을 시작해보세요.</p>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="fas fa-plus me-2"></i>첫 상품 추가하기
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-th-large fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">섹션을 선택해주세요</h5>
                <p class="text-muted">상품을 진열할 섹션을 먼저 선택하세요.</p>
                <a href="display_sections.php" class="btn btn-outline-primary">
                    <i class="fas fa-plus me-2"></i>새 섹션 만들기
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- 상품 추가 모달 -->
<?php if ($selected_section): ?>
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">상품 진열 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_product">
                <input type="hidden" name="section_id" value="<?php echo $selected_section_id; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">상품 선택 *</label>
                        <input type="text" class="form-control" id="productSearch" placeholder="상품명 또는 바코드로 검색">
                        <input type="hidden" name="product_id" id="selectedProductId">
                        <div id="productSearchResults" class="mt-2"></div>
                        <div id="selectedProduct" class="mt-2" style="display: none;">
                            <div class="border rounded p-3 bg-light">
                                <div class="d-flex justify-content-between">
                                    <div id="selectedProductInfo"></div>
                                    <button type="button" class="btn-close" onclick="clearSelectedProduct()"></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">진열 순서</label>
                                <input type="number" class="form-control" name="display_order" value="<?php echo count($displayed_products) + 1; ?>" min="1">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">배지 색상</label>
                                <select class="form-select" name="badge_color">
                                    <option value="">선택 안함</option>
                                    <option value="red">빨강</option>
                                    <option value="blue">파랑</option>
                                    <option value="green">녹색</option>
                                    <option value="orange">주황</option>
                                    <option value="purple">보라</option>
                                    <option value="gray">회색</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">커스텀 제목</label>
                        <input type="text" class="form-control" name="custom_title" placeholder="비워두면 원본 상품명 사용">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">커스텀 설명</label>
                        <textarea class="form-control" name="custom_description" rows="2" placeholder="추가 설명이나 마케팅 문구"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">커스텀 이미지 URL</label>
                        <input type="url" class="form-control" name="custom_image_url" placeholder="이미지 URL (선택사항)">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">배지 텍스트</label>
                        <input type="text" class="form-control" name="badge_text" placeholder="예: NEW, SALE, 추천" maxlength="50">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">진열 시작일</label>
                                <input type="date" class="form-control" name="start_date">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">진열 종료일</label>
                                <input type="date" class="form-control" name="end_date">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="submit" class="btn btn-primary">진열 추가</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 진열 편집 모달 -->
<div class="modal fade" id="editDisplayModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">진열 정보 편집</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editDisplayForm">
                <input type="hidden" name="action" value="update_display">
                <input type="hidden" name="id" id="edit_display_id">
                <input type="hidden" name="section_id" value="<?php echo $selected_section_id; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">진열 순서</label>
                                <input type="number" class="form-control" name="display_order" id="edit_display_order" min="1">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">배지 색상</label>
                                <select class="form-select" name="badge_color" id="edit_badge_color">
                                    <option value="">선택 안함</option>
                                    <option value="red">빨강</option>
                                    <option value="blue">파랑</option>
                                    <option value="green">녹색</option>
                                    <option value="orange">주황</option>
                                    <option value="purple">보라</option>
                                    <option value="gray">회색</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">커스텀 제목</label>
                        <input type="text" class="form-control" name="custom_title" id="edit_custom_title" placeholder="비워두면 원본 상품명 사용">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">커스텀 설명</label>
                        <textarea class="form-control" name="custom_description" id="edit_custom_description" rows="2"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">커스텀 이미지 URL</label>
                        <input type="url" class="form-control" name="custom_image_url" id="edit_custom_image_url">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">배지 텍스트</label>
                        <input type="text" class="form-control" name="badge_text" id="edit_badge_text" maxlength="50">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">진열 시작일</label>
                                <input type="date" class="form-control" name="start_date" id="edit_start_date">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">진열 종료일</label>
                                <input type="date" class="form-control" name="end_date" id="edit_end_date">
                            </div>
                        </div>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                        <label class="form-check-label">활성 상태</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="submit" class="btn btn-primary">저장</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function changeSection() {
    const sectionId = document.getElementById('sectionSelect').value;
    if (sectionId) {
        window.location.href = 'product_display.php?section_id=' + sectionId;
    } else {
        window.location.href = 'product_display.php';
    }
}

document.getElementById('sectionSelect').addEventListener('change', changeSection);

// 상품 검색 기능
let searchTimeout;

// 바코드 스캐너 엔터키 방지
document.getElementById('productSearch')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        return false;
    }
});

document.getElementById('productSearch')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        return false;
    }
});

document.getElementById('productSearch')?.addEventListener('input', function() {
    const query = this.value;
    clearTimeout(searchTimeout);
    
    if (query.length < 2) {
        document.getElementById('productSearchResults').innerHTML = '';
        return;
    }
    
    // 바코드인 경우 (숫자만 8자리 이상) 즉시 검색
    const isBarcode = /^\d{8,}$/.test(query);
    const delay = isBarcode ? 0 : 300;
    
    searchTimeout = setTimeout(() => {
        fetch('ajax_search_products.php?term=' + encodeURIComponent(query) + '&store_id=<?php echo $current_store_id; ?>')
            .then(response => response.json())
            .then(data => {
                let html = '';
                if (data && data.length > 0) {
                    html = '<div class="list-group mt-2">';
                    data.slice(0, 5).forEach(product => {
                        html += `
                            <a href="#" class="list-group-item list-group-item-action" onclick="selectProduct(${product.id}, '${product.name_ko || product.name}', '${product.brand_name || ''}', '${product.barcode || ''}', ${product.selling_price || 0})">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="mb-1">${product.name_ko || product.name}</h6>
                                        <small>${product.brand_name || ''} ${product.barcode ? '| ' + product.barcode : ''}</small>
                                    </div>
                                    <small class="text-muted">${product.selling_price ? product.selling_price : '가격 미설정'}</small>
                                </div>
                            </a>
                        `;
                    });
                    html += '</div>';
                } else {
                    html = '<div class="alert alert-warning mt-2">검색된 상품이 없습니다.</div>';
                }
                document.getElementById('productSearchResults').innerHTML = html;
            })
            .catch(error => {
                console.error('검색 오류:', error);
                document.getElementById('productSearchResults').innerHTML = '<div class="alert alert-danger mt-2">검색 중 오류가 발생했습니다.</div>';
            });
    }, delay);
});

function selectProduct(id, name, brand, barcode, price) {
    document.getElementById('selectedProductId').value = id;
    document.getElementById('productSearch').value = '';
    document.getElementById('productSearchResults').innerHTML = '';
    
    document.getElementById('selectedProductInfo').innerHTML = `
        <div>
            <h6 class="mb-1">${name}</h6>
            <small class="text-muted">${brand} ${barcode ? '| ' + barcode : ''}</small>
            <br><small class="text-primary">${price > 0 ? price.toFixed(2) : '가격 미설정'}</small>
        </div>
    `;
    document.getElementById('selectedProduct').style.display = 'block';
}

function clearSelectedProduct() {
    document.getElementById('selectedProductId').value = '';
    document.getElementById('selectedProduct').style.display = 'none';
}

function editDisplay(display) {
    document.getElementById('edit_display_id').value = display.id;
    document.getElementById('edit_display_order').value = display.display_order;
    document.getElementById('edit_custom_title').value = display.custom_title || '';
    document.getElementById('edit_custom_description').value = display.custom_description || '';
    document.getElementById('edit_custom_image_url').value = display.custom_image_url || '';
    document.getElementById('edit_badge_text').value = display.badge_text || '';
    document.getElementById('edit_badge_color').value = display.badge_color || '';
    document.getElementById('edit_start_date').value = display.start_date || '';
    document.getElementById('edit_end_date').value = display.end_date || '';
    document.getElementById('edit_is_active').checked = display.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editDisplayModal')).show();
}

function removeProduct(id, name) {
    if (confirm('정말로 "' + name + '" 상품을 진열에서 제거하시겠습니까?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="remove_product">
            <input type="hidden" name="id" value="${id}">
            <input type="hidden" name="section_id" value="<?php echo $selected_section_id; ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// 모달이 열릴 때 자동 포커스 설정
document.addEventListener('DOMContentLoaded', function() {
    const addProductModal = document.getElementById('addProductModal');
    if (addProductModal) {
        addProductModal.addEventListener('shown.bs.modal', function () {
            const productSearch = document.getElementById('productSearch');
            if (productSearch) {
                productSearch.focus();
            }
        });
    }
    
    // SortableJS 초기화 (상품 순서 드래그 앤 드롭)
    const sortableElement = document.getElementById('sortable-products');
    if (sortableElement) {
        new Sortable(sortableElement, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            onEnd: function (evt) {
                updateProductOrder();
            }
        });
    }
});

// 상품 순서 업데이트 함수
function updateProductOrder() {
    const rows = document.querySelectorAll('#sortable-products tr');
    const orderData = [];
    
    rows.forEach((row, index) => {
        const productId = row.getAttribute('data-product-id');
        if (productId) {
            orderData.push({
                id: productId,
                order: index + 1
            });
            
            // 화면의 순서 번호 업데이트
            const orderCell = row.cells[1]; // 두 번째 셀 (순서)
            if (orderCell) {
                orderCell.textContent = index + 1;
            }
        }
    });
    
    // AJAX로 서버에 순서 업데이트 전송
    fetch('ajax_update_product_order.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            section_id: <?php echo $selected_section_id ?: 'null'; ?>,
            products: orderData
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 성공 시 알림 (선택사항)
            console.log('상품 순서가 업데이트되었습니다.');
        } else {
            console.error('순서 업데이트 실패:', data.message);
            // 실패 시 페이지 새로고침으로 원상복구
            location.reload();
        }
    })
    .catch(error => {
        console.error('순서 업데이트 오류:', error);
        location.reload();
    });
}
</script>

<!-- SortableJS 라이브러리 -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

<style>
/* 드래그 앤 드롭 스타일 */
.sortable-ghost {
    opacity: 0.4;
}
.sortable-chosen {
    background-color: #f8f9fa;
}
.sortable-drag {
    background-color: #ffffff;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}
.drag-handle:hover {
    background-color: #f8f9fa;
}
</style>

<?php
require_once 'partials/footer.php';
?>
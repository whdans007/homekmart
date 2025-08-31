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
$page_title = '진열 섹션 관리';

// 메시지 처리
$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// 폼 데이터 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add') {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $section_type = $_POST['section_type'];
        $display_order = (int)$_POST['display_order'];
        $max_products = !empty($_POST['max_products']) ? (int)$_POST['max_products'] : null;
        $layout_type = $_POST['layout_type'];
        $show_on_main = isset($_POST['show_on_main']) ? 1 : 0;
        $custom_css_class = trim($_POST['custom_css_class']);
        
        if (!empty($name)) {
            $stmt = $conn->prepare("INSERT INTO display_sections (name, description, section_type, display_order, max_products, layout_type, show_on_main, custom_css_class) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssiisii", $name, $description, $section_type, $display_order, $max_products, $layout_type, $show_on_main, $custom_css_class);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = '진열 섹션이 추가되었습니다.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = '진열 섹션 추가에 실패했습니다.';
                $_SESSION['message_type'] = 'error';
            }
            $stmt->close();
        }
        header('Location: display_sections.php');
        exit;
    }
    
    if ($action == 'edit') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $section_type = $_POST['section_type'];
        $display_order = (int)$_POST['display_order'];
        $max_products = !empty($_POST['max_products']) ? (int)$_POST['max_products'] : null;
        $layout_type = $_POST['layout_type'];
        $show_on_main = isset($_POST['show_on_main']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $custom_css_class = trim($_POST['custom_css_class']);
        
        if (!empty($name) && $id > 0) {
            $stmt = $conn->prepare("UPDATE display_sections SET name=?, description=?, section_type=?, display_order=?, max_products=?, layout_type=?, show_on_main=?, is_active=?, custom_css_class=? WHERE id=?");
            $stmt->bind_param("sssiisissi", $name, $description, $section_type, $display_order, $max_products, $layout_type, $show_on_main, $is_active, $custom_css_class, $id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = '진열 섹션이 수정되었습니다.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = '진열 섹션 수정에 실패했습니다.';
                $_SESSION['message_type'] = 'error';
            }
            $stmt->close();
        }
        header('Location: display_sections.php');
        exit;
    }
    
    if ($action == 'delete') {
        $id = (int)$_POST['id'];
        
        if ($id > 0) {
            // 먼저 해당 섹션에 진열된 상품이 있는지 확인
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_displays WHERE section_id = ?");
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $count_result = $check_stmt->get_result();
            $count_row = $count_result->fetch_assoc();
            $check_stmt->close();
            
            if ($count_row['count'] > 0) {
                $_SESSION['message'] = '이 섹션에 진열된 상품이 있어 삭제할 수 없습니다. 먼저 상품을 다른 섹션으로 이동하세요.';
                $_SESSION['message_type'] = 'error';
            } else {
                $stmt = $conn->prepare("DELETE FROM display_sections WHERE id = ?");
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $_SESSION['message'] = '진열 섹션이 삭제되었습니다.';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = '진열 섹션 삭제에 실패했습니다.';
                    $_SESSION['message_type'] = 'error';
                }
                $stmt->close();
            }
        }
        header('Location: display_sections.php');
        exit;
    }
}

// 진열 섹션 목록 조회 (한 번만 조회하여 배열로 저장)
$sections_query = "SELECT * FROM display_sections ORDER BY display_order ASC, created_at ASC";
$sections_result = $conn->query($sections_query);
$sections_data = [];

if ($sections_result && $sections_result->num_rows > 0) {
    while ($section = $sections_result->fetch_assoc()) {
        // 각 섹션의 상품 수도 미리 조회
        $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_displays WHERE section_id = ? AND is_active = 1");
        $count_stmt->bind_param("i", $section['id']);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $section['product_count'] = $count_result->fetch_assoc()['count'];
        $count_stmt->close();
        
        $sections_data[] = $section;
    }
}

include 'partials/header.php';
?>

<div class="container-fluid px-4 pb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">진열 섹션 관리</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSectionModal">
            <i class="fas fa-plus me-2"></i>새 섹션 추가
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : $message_type; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 빠른 섹션 생성 템플릿 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0" style="background: linear-gradient(135deg, #DE121C, #FF4444); color: white;">
                <div class="card-body py-3">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-1"><i class="fas fa-magic me-2"></i>빠른 섹션 생성</h6>
                            <small class="opacity-75">자주 사용하는 템플릿으로 빠르게 섹션을 만들어보세요</small>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-light btn-sm" onclick="createQuickSection('banner', '메인 배너')">
                                    <i class="fas fa-image me-1"></i>배너
                                </button>
                                <button type="button" class="btn btn-light btn-sm" onclick="createQuickSection('featured', '추천 상품')">
                                    <i class="fas fa-star me-1"></i>추천
                                </button>
                                <button type="button" class="btn btn-light btn-sm" onclick="createQuickSection('new_products', '신상품')">
                                    <i class="fas fa-certificate me-1"></i>신상품
                                </button>
                                <button type="button" class="btn btn-outline-light btn-sm" onclick="previewShop()" title="전체 쇼핑몰 미리보기">
                                    <i class="fas fa-external-link-alt me-1"></i>쇼핑몰
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 진열 섹션 카드 목록 -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">
            <i class="fas fa-th-large me-2" style="color: #DE121C;"></i>진열 섹션 목록
        </h5>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" id="viewToggle" onclick="toggleView('card')">
                <i class="fas fa-th me-1"></i>카드뷰
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="toggleView('table')">
                <i class="fas fa-list me-1"></i>테이블뷰
            </button>
        </div>
    </div>

    <!-- 카드 뷰 -->
    <div id="cardView" class="row sortable-container" style="display: block;">
        <?php if (!empty($sections_data)): ?>
            <?php foreach ($sections_data as $section): ?>
                <?php
                $product_count = $section['product_count'];
                
                $section_type_labels = [
                    'banner' => '배너',
                    'featured' => '추천상품',
                    'new_products' => '신상품',
                    'category' => '카테고리',
                    'custom' => '사용자정의'
                ];
                
                $layout_type_labels = [
                    'grid' => '격자형',
                    'list' => '목록형',
                    'carousel' => '슬라이드',
                    'banner' => '배너형'
                ];
                
                // 섹션 유형별 아이콘과 색상
                $type_config = [
                    'banner' => ['icon' => 'fa-image', 'color' => '#DE121C'],
                    'featured' => ['icon' => 'fa-star', 'color' => '#FF6B35'],
                    'new_products' => ['icon' => 'fa-certificate', 'color' => '#28a745'],
                    'category' => ['icon' => 'fa-folder', 'color' => '#6f42c1'],
                    'custom' => ['icon' => 'fa-cog', 'color' => '#6c757d']
                ];
                
                $config = $type_config[$section['section_type']] ?? $type_config['custom'];
                ?>
                <div class="col-xl-4 col-lg-6 col-md-6 mb-4" data-section-id="<?php echo $section['id']; ?>">
                    <div class="card section-card border-0 h-100" style="transition: all 0.3s ease; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                        <!-- 카드 헤더 -->
                        <div class="card-header border-0 pb-2" style="background: linear-gradient(135deg, <?php echo $config['color']; ?>, <?php echo $config['color']; ?>dd); color: white;">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 fw-bold">
                                        <i class="fas <?php echo $config['icon']; ?> me-2"></i>
                                        <?php echo htmlspecialchars($section['name']); ?>
                                    </h6>
                                    <small class="opacity-75">
                                        <i class="fas fa-sort-numeric-up me-1"></i>순서: <?php echo $section['display_order']; ?>
                                    </small>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm text-white" type="button" data-bs-toggle="dropdown" style="border: none; background: none;">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="#" onclick="previewSection(<?php echo $section['id']; ?>)"><i class="fas fa-eye me-2"></i>미리보기</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="#" onclick="editSection(<?php echo htmlspecialchars(json_encode($section)); ?>)"><i class="fas fa-edit me-2"></i>편집</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="manageProducts(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['name']); ?>')"><i class="fas fa-cogs me-2"></i>상품 관리</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="deleteSection(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['name']); ?>')"><i class="fas fa-trash me-2"></i>삭제</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 카드 바디 -->
                        <div class="card-body pt-3">
                            <?php if ($section['description']): ?>
                                <p class="text-muted small mb-3"><?php echo htmlspecialchars($section['description']); ?></p>
                            <?php endif; ?>
                            
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="text-center p-2 bg-light rounded">
                                        <div class="fw-bold text-primary" style="font-size: 1.5rem;"><?php echo $product_count; ?></div>
                                        <small class="text-muted">등록 상품</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center p-2 bg-light rounded">
                                        <div class="fw-bold" style="font-size: 1.2rem; color: <?php echo $config['color']; ?>;">
                                            <?php echo $section['max_products'] ?: '∞'; ?>
                                        </div>
                                        <small class="text-muted">최대 상품</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex flex-wrap gap-1 mb-3">
                                <span class="badge" style="background-color: <?php echo $config['color']; ?>; color: white;">
                                    <?php echo $section_type_labels[$section['section_type']] ?? $section['section_type']; ?>
                                </span>
                                <span class="badge bg-secondary">
                                    <?php echo $layout_type_labels[$section['layout_type']] ?? $section['layout_type']; ?>
                                </span>
                                <?php if ($section['show_on_main']): ?>
                                    <span class="badge bg-success">메인 표시</span>
                                <?php endif; ?>
                                <?php if (!$section['is_active']): ?>
                                    <span class="badge bg-danger">비활성</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- 카드 푸터 -->
                        <div class="card-footer bg-transparent border-0">
                            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="previewSection(<?php echo $section['id']; ?>)">
                                    <i class="fas fa-eye me-1"></i>미리보기
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="manageProducts(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['name']); ?>')">
                                    <i class="fas fa-cogs me-1"></i>상품 관리
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="editSection(<?php echo htmlspecialchars(json_encode($section)); ?>)">
                                    <i class="fas fa-edit me-1"></i>편집
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-th-large fa-4x text-muted" style="opacity: 0.3;"></i>
                    </div>
                    <h5 class="text-muted mb-3">등록된 진열 섹션이 없습니다</h5>
                    <p class="text-muted mb-4">새로운 섹션을 추가하여 쇼핑몰을 구성해보세요.</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSectionModal">
                        <i class="fas fa-plus me-2"></i>첫 번째 섹션 만들기
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 테이블 뷰 (숨김 처리) -->
    <div id="tableView" style="display: none;">
        <div class="card border-0" style="box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>순서</th>
                            <th>섹션명</th>
                            <th>유형</th>
                            <th>레이아웃</th>
                            <th>최대 상품수</th>
                            <th>메인 표시</th>
                            <th>활성 상태</th>
                            <th>상품수</th>
                            <th>관리</th>
                        </tr>
                    </thead>
                    <tbody id="sectionsTable">
                        <?php if (!empty($sections_data)): ?>
                            <?php foreach ($sections_data as $section): ?>
                                <?php
                                $product_count = $section['product_count'];
                            
                            $section_type_labels = [
                                'banner' => '배너',
                                'featured' => '추천상품',
                                'new_products' => '신상품',
                                'category' => '카테고리',
                                'custom' => '사용자정의'
                            ];
                            
                            $layout_type_labels = [
                                'grid' => '격자형',
                                'list' => '목록형',
                                'carousel' => '슬라이드',
                                'banner' => '배너형'
                            ];
                            ?>
                            <tr data-section-id="<?php echo $section['id']; ?>" style="cursor: move;">
                                <td>
                                    <i class="fas fa-grip-vertical text-muted me-2" style="cursor: move;" title="드래그해서 순서 변경"></i>
                                    <?php echo htmlspecialchars($section['display_order']); ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($section['name']); ?></strong>
                                    <?php if ($section['description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($section['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo $section_type_labels[$section['section_type']] ?? $section['section_type']; ?>
                                    </span>
                                </td>
                                <td><?php echo $layout_type_labels[$section['layout_type']] ?? $section['layout_type']; ?></td>
                                <td><?php echo $section['max_products'] ? $section['max_products'] : '무제한'; ?></td>
                                <td>
                                    <?php if ($section['show_on_main']): ?>
                                        <span class="badge bg-success">표시</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">숨김</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($section['is_active']): ?>
                                        <span class="badge bg-success">활성</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">비활성</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo $product_count; ?>개</span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="editSection(<?php echo htmlspecialchars(json_encode($section)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-success" onclick="manageProducts(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['name']); ?>')">
                                            <i class="fas fa-cogs"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteSection(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['name']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 하단 여백 추가 -->
<div class="mb-5 pb-4"></div>

<!-- 새 섹션 추가 모달 -->
<div class="modal fade" id="addSectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">새 진열 섹션 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="name" class="form-label">섹션명 *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="section_type" class="form-label">섹션 유형</label>
                                <select class="form-select" name="section_type">
                                    <option value="banner">배너</option>
                                    <option value="featured">추천상품</option>
                                    <option value="new_products">신상품</option>
                                    <option value="category">카테고리</option>
                                    <option value="custom" selected>사용자정의</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">설명</label>
                        <textarea class="form-control" name="description" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="display_order" class="form-label">표시 순서</label>
                                <input type="number" class="form-control" name="display_order" value="10" min="1">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="max_products" class="form-label">최대 상품수</label>
                                <input type="number" class="form-control" name="max_products" min="1" placeholder="무제한">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="layout_type" class="form-label">레이아웃 유형</label>
                                <select class="form-select" name="layout_type">
                                    <option value="grid" selected>격자형</option>
                                    <option value="list">목록형</option>
                                    <option value="carousel">슬라이드</option>
                                    <option value="banner">배너형</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="custom_css_class" class="form-label">커스텀 CSS 클래스</label>
                        <input type="text" class="form-control" name="custom_css_class" placeholder="예: special-section">
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_on_main" checked>
                        <label class="form-check-label">메인페이지에 표시</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="submit" class="btn btn-primary">추가</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 섹션 편집 모달 -->
<div class="modal fade" id="editSectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">진열 섹션 편집</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editSectionForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_name" class="form-label">섹션명 *</label>
                                <input type="text" class="form-control" name="name" id="edit_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_section_type" class="form-label">섹션 유형</label>
                                <select class="form-select" name="section_type" id="edit_section_type">
                                    <option value="banner">배너</option>
                                    <option value="featured">추천상품</option>
                                    <option value="new_products">신상품</option>
                                    <option value="category">카테고리</option>
                                    <option value="custom">사용자정의</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">설명</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="edit_display_order" class="form-label">표시 순서</label>
                                <input type="number" class="form-control" name="display_order" id="edit_display_order" min="1">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="edit_max_products" class="form-label">최대 상품수</label>
                                <input type="number" class="form-control" name="max_products" id="edit_max_products" min="1" placeholder="무제한">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_layout_type" class="form-label">레이아웃 유형</label>
                                <select class="form-select" name="layout_type" id="edit_layout_type">
                                    <option value="grid">격자형</option>
                                    <option value="list">목록형</option>
                                    <option value="carousel">슬라이드</option>
                                    <option value="banner">배너형</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_custom_css_class" class="form-label">커스텀 CSS 클래스</label>
                        <input type="text" class="form-control" name="custom_css_class" id="edit_custom_css_class" placeholder="예: special-section">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="show_on_main" id="edit_show_on_main">
                                <label class="form-check-label">메인페이지에 표시</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                <label class="form-check-label">활성 상태</label>
                            </div>
                        </div>
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

<style>
/* 카드 호버 효과 */
.section-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.15) !important;
}

/* 뷰 토글 버튼 활성 상태 */
.btn-group .btn.active {
    background-color: #DE121C;
    border-color: #DE121C;
    color: white;
}

/* 빠른 생성 버튼 호버 효과 */
.btn-light:hover {
    background-color: rgba(255,255,255,0.9);
    color: #DE121C;
}

/* 드래그 앤 드롭 스타일 */
.sortable-container {
    min-height: 200px;
}

.sortable-ghost {
    opacity: 0.4;
    background-color: #f8f9fa;
    border: 2px dashed #DE121C;
    border-radius: 8px;
}

.sortable-chosen {
    transform: rotate(5deg);
    z-index: 1000;
    box-shadow: 0 8px 30px rgba(0,0,0,0.3) !important;
}

.sortable-drag {
    opacity: 0.8;
}

/* 드래그 핸들 스타일 */
.drag-handle {
    cursor: grab;
    opacity: 0;
    transition: opacity 0.2s ease;
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(255,255,255,0.9);
    border-radius: 50%;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #666;
    z-index: 10;
}

.section-card:hover .drag-handle {
    opacity: 1;
}

.drag-handle:active {
    cursor: grabbing;
}

/* 순서 변경 통지 */
.order-change-notice {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    background: #28a745;
    color: white;
    padding: 10px 20px;
    border-radius: 5px;
    transform: translateX(100%);
    transition: transform 0.3s ease;
}

.order-change-notice.show {
    transform: translateX(0);
}

/* 페이지 하단 여백 개선 */
body {
    padding-bottom: 40px;
}

.container-fluid {
    margin-bottom: 60px;
}

/* 테이블뷰 드래그 스타일 */
#sectionsTable tr.sortable-ghost {
    opacity: 0.4;
    background-color: #f8f9fa !important;
}

#sectionsTable tr.sortable-chosen {
    background-color: #e3f2fd !important;
    transform: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2) !important;
}

#sectionsTable tr:hover {
    background-color: #f8f9fa;
}

#sectionsTable tr[data-section-id] td:first-child i.fa-grip-vertical {
    opacity: 0.5;
    transition: opacity 0.2s ease;
}

#sectionsTable tr[data-section-id]:hover td:first-child i.fa-grip-vertical {
    opacity: 1;
    color: #DE121C;
}
</style>

<!-- SortableJS CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>

<script>
// 뷰 전환 기능
function toggleView(viewType) {
    const cardView = document.getElementById('cardView');
    const tableView = document.getElementById('tableView');
    const toggleButtons = document.querySelectorAll('#viewToggle, #viewToggle + button');
    
    if (viewType === 'card') {
        cardView.style.display = 'block';
        tableView.style.display = 'none';
        toggleButtons[0].classList.add('active');
        toggleButtons[1].classList.remove('active');
    } else {
        cardView.style.display = 'none';
        tableView.style.display = 'block';
        toggleButtons[0].classList.remove('active');
        toggleButtons[1].classList.add('active');
    }
    
    // 뷰 전환 후 Sortable 재초기화
    setTimeout(() => {
        initSortable();
    }, 100);
    
    // 사용자 선호도 저장
    localStorage.setItem('sectionViewType', viewType);
}

// 페이지 로드 시 사용자 선호도 복원
document.addEventListener('DOMContentLoaded', function() {
    const savedViewType = localStorage.getItem('sectionViewType') || 'card';
    toggleView(savedViewType);
});

// 빠른 섹션 생성
function createQuickSection(type, name) {
    // 기본값 설정
    const defaults = {
        'banner': { layout: 'banner', maxProducts: 1, order: 1 },
        'featured': { layout: 'grid', maxProducts: 8, order: 10 },
        'new_products': { layout: 'carousel', maxProducts: 12, order: 20 }
    };
    
    const config = defaults[type] || { layout: 'grid', maxProducts: null, order: 50 };
    
    // 모달 폼에 기본값 설정
    document.querySelector('#addSectionModal input[name="name"]').value = name;
    document.querySelector('#addSectionModal select[name="section_type"]').value = type;
    document.querySelector('#addSectionModal select[name="layout_type"]').value = config.layout;
    document.querySelector('#addSectionModal input[name="display_order"]').value = config.order;
    document.querySelector('#addSectionModal input[name="max_products"]').value = config.maxProducts || '';
    document.querySelector('#addSectionModal input[name="show_on_main"]').checked = true;
    
    // 모달 표시
    new bootstrap.Modal(document.getElementById('addSectionModal')).show();
}

// 기존 함수들
function editSection(section) {
    document.getElementById('edit_id').value = section.id;
    document.getElementById('edit_name').value = section.name;
    document.getElementById('edit_description').value = section.description || '';
    document.getElementById('edit_section_type').value = section.section_type;
    document.getElementById('edit_display_order').value = section.display_order;
    document.getElementById('edit_max_products').value = section.max_products || '';
    document.getElementById('edit_layout_type').value = section.layout_type;
    document.getElementById('edit_custom_css_class').value = section.custom_css_class || '';
    document.getElementById('edit_show_on_main').checked = section.show_on_main == 1;
    document.getElementById('edit_is_active').checked = section.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editSectionModal')).show();
}

function deleteSection(id, name) {
    if (confirm('정말로 "' + name + '" 섹션을 삭제하시겠습니까?\n\n주의: 이 섹션에 진열된 상품이 있으면 삭제할 수 없습니다.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function manageProducts(sectionId, sectionName) {
    window.location.href = 'product_display.php?section_id=' + sectionId;
}

// 실시간 미리보기
function previewSection(sectionId) {
    // 미리보기 창 설정
    const previewWindow = window.open('', 'sectionPreview', 'width=1200,height=800,scrollbars=yes,resizable=yes');
    
    // 로딩 화면 표시
    previewWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>섹션 미리보기</title>
            <meta charset="UTF-8">
            <style>
                body { 
                    font-family: 'Noto Sans KR', sans-serif; 
                    margin: 0; 
                    padding: 20px; 
                    background: #f8f9fa; 
                    display: flex; 
                    align-items: center; 
                    justify-content: center; 
                    height: 100vh;
                }
                .loading {
                    text-align: center;
                    color: #DE121C;
                }
                .loading i {
                    font-size: 3rem;
                    animation: spin 1s linear infinite;
                    margin-bottom: 20px;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .toolbar {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    background: #DE121C;
                    color: white;
                    padding: 10px 20px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    z-index: 1000;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .preview-content {
                    margin-top: 60px;
                    width: 100%;
                    height: calc(100vh - 60px);
                }
                .preview-frame {
                    width: 100%;
                    height: 100%;
                    border: none;
                    background: white;
                }
            </style>
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        </head>
        <body>
            <div class="loading">
                <i class="fas fa-spinner"></i>
                <h3>쇼핑몰 미리보기 로딩 중...</h3>
                <p>섹션 내용을 불러오고 있습니다.</p>
            </div>
        </body>
        </html>
    `);
    
    // 로딩 완료 후 실제 내용 로드
    setTimeout(() => {
        previewWindow.location.href = `../shop/index_hmart.php?preview_section=${sectionId}`;
    }, 1000);
    
    // 미리보기 창에 포커스
    previewWindow.focus();
}

// 전체 쇼핑몰 미리보기
function previewShop() {
    const previewWindow = window.open('../shop/index_hmart.php', 'shopPreview', 'width=1200,height=800,scrollbars=yes,resizable=yes');
    previewWindow.focus();
}

// 드래그 앤 드롭 초기화
document.addEventListener('DOMContentLoaded', function() {
    const savedViewType = localStorage.getItem('sectionViewType') || 'card';
    toggleView(savedViewType);
    
    // 드래그 앤 드롭 초기화
    initSortable();
});

function initSortable() {
    const cardContainer = document.getElementById('cardView');
    const tableBody = document.getElementById('sectionsTable');
    
    // 카드뷰 Sortable 초기화
    if (cardContainer && cardContainer.children.length > 0) {
        new Sortable(cardContainer, {
            animation: 300,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            handle: '.drag-handle',
            onStart: function (evt) {
                console.log('카드뷰 드래그 시작:', evt.oldIndex);
            },
            onEnd: function (evt) {
                if (evt.oldIndex !== evt.newIndex) {
                    updateSectionOrder(evt);
                }
            }
        });
        
        // 드래그 핸들 추가
        addDragHandles();
    }
    
    // 테이블뷰 Sortable 초기화
    if (tableBody && tableBody.children.length > 0) {
        new Sortable(tableBody, {
            animation: 300,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            onStart: function (evt) {
                console.log('테이블뷰 드래그 시작:', evt.oldIndex);
                evt.item.style.backgroundColor = '#f8f9fa';
            },
            onEnd: function (evt) {
                evt.item.style.backgroundColor = '';
                if (evt.oldIndex !== evt.newIndex) {
                    updateSectionOrder(evt);
                }
            }
        });
    }
}

function addDragHandles() {
    const sectionCards = document.querySelectorAll('.section-card');
    sectionCards.forEach(card => {
        if (!card.querySelector('.drag-handle')) {
            const handle = document.createElement('div');
            handle.className = 'drag-handle';
            handle.innerHTML = '<i class="fas fa-grip-vertical"></i>';
            handle.title = '순서 변경하려면 드래그하세요';
            card.style.position = 'relative';
            card.appendChild(handle);
        }
    });
}

function updateSectionOrder(evt) {
    const sections = Array.from(document.querySelectorAll('[data-section-id]'));
    const orderData = sections.map((section, index) => ({
        id: section.getAttribute('data-section-id'),
        order: (index + 1) * 10
    }));
    
    // AJAX로 순서 업데이트
    fetch('ajax_update_section_order.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(orderData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showOrderChangeNotice('순서가 성공적으로 변경되었습니다.');
        } else {
            showOrderChangeNotice('순서 변경에 실패했습니다.', 'error');
            // 실패 시 원래 순서로 복원
            location.reload();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showOrderChangeNotice('오류가 발생했습니다.', 'error');
        location.reload();
    });
}

function showOrderChangeNotice(message, type = 'success') {
    // 기존 통지 제거
    const existingNotice = document.querySelector('.order-change-notice');
    if (existingNotice) {
        existingNotice.remove();
    }
    
    // 새 통지 생성
    const notice = document.createElement('div');
    notice.className = 'order-change-notice';
    notice.textContent = message;
    
    if (type === 'error') {
        notice.style.backgroundColor = '#dc3545';
    }
    
    document.body.appendChild(notice);
    
    // 애니메이션 표시
    setTimeout(() => {
        notice.classList.add('show');
    }, 100);
    
    // 3초 후 자동 숨김
    setTimeout(() => {
        notice.classList.remove('show');
        setTimeout(() => {
            notice.remove();
        }, 300);
    }, 3000);
}

// 통계 업데이트 (AJAX로 실시간 업데이트)
function updateSectionStats() {
    // 향후 AJAX로 섹션별 통계 업데이트 구현
    console.log('통계 업데이트 준비됨');
}
</script>

<?php
// MySQL 연결은 PHP 스크립트 종료 시 자동으로 정리됩니다.
require_once 'partials/footer.php';
?>
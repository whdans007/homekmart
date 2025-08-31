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
            $stmt->bind_param("sssiisissii", $name, $description, $section_type, $display_order, $max_products, $layout_type, $show_on_main, $is_active, $custom_css_class, $id);
            
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

// 진열 섹션 목록 조회
$sections_query = "SELECT * FROM display_sections ORDER BY display_order ASC, created_at ASC";
$sections_result = $conn->query($sections_query);

include 'partials/header.php';
?>

<div class="container-fluid px-4">
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

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-th-large me-2"></i>진열 섹션 목록
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
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
                        <?php if ($sections_result && $sections_result->num_rows > 0): ?>
                            <?php while ($section = $sections_result->fetch_assoc()): ?>
                                <?php
                                // 해당 섹션의 진열 상품 수 조회
                                $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_displays WHERE section_id = ? AND is_active = 1");
                                $count_stmt->bind_param("i", $section['id']);
                                $count_stmt->execute();
                                $count_result = $count_stmt->get_result();
                                $product_count = $count_result->fetch_assoc()['count'];
                                $count_stmt->close();
                                
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
                                <tr>
                                    <td><?php echo htmlspecialchars($section['display_order']); ?></td>
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
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">등록된 진열 섹션이 없습니다.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

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

<script>
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
</script>

<?php
$conn->close();
require_once 'partials/footer.php';
?>
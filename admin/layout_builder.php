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
$page_title = '메인 페이지 레이아웃 빌더';

// 메시지 처리
$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// 현재 레이아웃 구조 조회
function getCurrentLayout($conn) {
    $layout = ['rows' => []];
    
    $rows_query = "SELECT * FROM layout_rows WHERE is_active = 1 ORDER BY row_order ASC";
    $rows_result = $conn->query($rows_query);
    
    if ($rows_result && $rows_result->num_rows > 0) {
        while ($row = $rows_result->fetch_assoc()) {
            $columns_query = "SELECT lc.*, ds.name as section_name, ds.section_type, ds.layout_type 
                             FROM layout_columns lc 
                             LEFT JOIN display_sections ds ON lc.section_id = ds.id 
                             WHERE lc.row_id = ? AND lc.is_active = 1 
                             ORDER BY lc.column_order ASC";
            $columns_stmt = $conn->prepare($columns_query);
            $columns_stmt->bind_param("i", $row['id']);
            $columns_stmt->execute();
            $columns_result = $columns_stmt->get_result();
            
            $columns = [];
            while ($column = $columns_result->fetch_assoc()) {
                $columns[] = $column;
            }
            $columns_stmt->close();
            
            $row['columns'] = $columns;
            $layout['rows'][] = $row;
        }
    }
    
    return $layout;
}

// 사용 가능한 섹션 목록 조회
function getAvailableSections($conn) {
    $query = "SELECT id, name, section_type, layout_type, is_active, 
                     (SELECT COUNT(*) FROM product_displays pd WHERE pd.section_id = ds.id) as product_count
              FROM display_sections ds 
              WHERE is_active = 1 
              ORDER BY display_order ASC";
    $result = $conn->query($query);
    
    $sections = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $sections[] = $row;
        }
    }
    
    return $sections;
}

// 프리셋 템플릿 조회
function getLayoutPresets($conn) {
    $query = "SELECT * FROM layout_presets ORDER BY is_system_preset DESC, usage_count DESC";
    $result = $conn->query($query);
    
    $presets = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $presets[] = $row;
        }
    }
    
    return $presets;
}

$current_layout = getCurrentLayout($conn);
$available_sections = getAvailableSections($conn);
$layout_presets = getLayoutPresets($conn);

include 'partials/header.php';
?>

<div class="container-fluid px-4 pb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3">
                <i class="fas fa-th-large me-2" style="color: #DE121C;"></i>레이아웃 빌더
            </h1>
            <p class="text-muted mb-0">메인 페이지를 1-4개 컬럼으로 자유롭게 구성하세요</p>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-secondary" onclick="previewLayout()" title="미리보기">
                <i class="fas fa-eye me-2"></i>미리보기
            </button>
            <button type="button" class="btn btn-success" onclick="saveLayout()" title="레이아웃 저장">
                <i class="fas fa-save me-2"></i>저장
            </button>
            <a href="display_sections.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i>섹션 관리
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : $message_type; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- 메인 레이아웃 빌더 영역 -->
        <div class="col-xl-8 col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-hammer me-2"></i>레이아웃 구성
                        </h5>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-primary" onclick="addNewRow()">
                                <i class="fas fa-plus me-1"></i>행 추가
                            </button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#presetsModal">
                                <i class="fas fa-plus-circle me-1"></i>프리셋 추가
                            </button>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#resetLayoutModal">
                                <i class="fas fa-refresh me-1"></i>레이아웃 초기화
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="layoutBuilder" class="layout-builder">
                        <?php if (!empty($current_layout['rows'])): ?>
                            <?php foreach ($current_layout['rows'] as $row): ?>
                                <div class="layout-row" data-row-id="<?php echo $row['id']; ?>">
                                    <div class="row-header">
                                        <div class="row-info">
                                            <div class="drag-handle">
                                                <i class="fas fa-grip-vertical"></i>
                                            </div>
                                            <div class="row-title">
                                                <strong><?php echo htmlspecialchars($row['row_name'] ?? ''); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($row['row_description'] ?? ''); ?></small>
                                            </div>
                                        </div>
                                        <div class="row-actions">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="configureRow(<?php echo $row['id']; ?>)">
                                                <i class="fas fa-cog"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-success" onclick="addColumn(<?php echo $row['id']; ?>)">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteRow(<?php echo $row['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="columns-container row g-2">
                                        <?php if (!empty($row['columns'])): ?>
                                            <?php foreach ($row['columns'] as $column): ?>
                                                <div class="col-lg-<?php echo $column['column_width']; ?> layout-column" 
                                                     data-column-id="<?php echo $column['id']; ?>"
                                                     data-width="<?php echo $column['column_width']; ?>">
                                                    <div class="column-box">
                                                        <?php if ($column['section_id']): ?>
                                                            <div class="assigned-section" data-section-id="<?php echo $column['section_id']; ?>">
                                                                <div class="section-info">
                                                                    <strong><?php echo htmlspecialchars($column['section_name']); ?></strong>
                                                                    <span class="badge bg-secondary"><?php echo $column['section_type']; ?></span>
                                                                </div>
                                                                <div class="section-actions">
                                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeSectionFromColumn(<?php echo $column['id']; ?>)">
                                                                        <i class="fas fa-times"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="empty-column">
                                                                <i class="fas fa-plus-circle fa-2x text-muted mb-2"></i>
                                                                <p class="text-muted mb-0">섹션을 드래그해서 배치하세요</p>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="column-controls">
                                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="adjustColumnWidth(<?php echo $column['id']; ?>, -1)">
                                                                <i class="fas fa-minus"></i>
                                                            </button>
                                                            <span class="column-width-indicator"><?php echo $column['column_width']; ?>/12</span>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="adjustColumnWidth(<?php echo $column['id']; ?>, 1)">
                                                                <i class="fas fa-plus"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-layout text-center py-5">
                                <i class="fas fa-th-large fa-4x text-muted mb-3" style="opacity: 0.3;"></i>
                                <h5 class="text-muted mb-3">레이아웃이 비어있습니다</h5>
                                <p class="text-muted mb-4">새로운 행을 추가하거나 프리셋을 사용하여 시작하세요.</p>
                                <div class="d-flex justify-content-center gap-2">
                                    <button type="button" class="btn btn-primary" onclick="addNewRow()">
                                        <i class="fas fa-plus me-2"></i>첫 번째 행 추가
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#presetsModal">
                                        <i class="fas fa-plus-circle me-2"></i>프리셋 추가
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 사이드바: 섹션 목록 -->
        <div class="col-xl-4 col-lg-5">
            <div class="card border-0 shadow-sm sticky-top" style="top: 20px;">
                <div class="card-header bg-light">
                    <h5 class="mb-0">
                        <i class="fas fa-puzzle-piece me-2"></i>사용 가능한 섹션
                    </h5>
                </div>
                <div class="card-body">
                    <div class="sections-list">
                        <?php if (!empty($available_sections)): ?>
                            <?php foreach ($available_sections as $section): ?>
                                <?php
                                $type_icons = [
                                    'banner' => 'fa-image',
                                    'featured' => 'fa-star',
                                    'new_products' => 'fa-certificate',
                                    'category' => 'fa-folder',
                                    'custom' => 'fa-cog'
                                ];
                                $type_colors = [
                                    'banner' => '#DE121C',
                                    'featured' => '#FF6B35',
                                    'new_products' => '#28a745',
                                    'category' => '#6f42c1',
                                    'custom' => '#6c757d'
                                ];
                                $icon = $type_icons[$section['section_type']] ?? 'fa-cog';
                                $color = $type_colors[$section['section_type']] ?? '#6c757d';
                                ?>
                                <div class="section-item draggable-section" 
                                     data-section-id="<?php echo $section['id']; ?>"
                                     data-section-name="<?php echo htmlspecialchars($section['name']); ?>"
                                     data-section-type="<?php echo $section['section_type']; ?>">
                                    <div class="section-icon" style="background-color: <?php echo $color; ?>;">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="section-details">
                                        <div class="section-name"><?php echo htmlspecialchars($section['name']); ?></div>
                                        <div class="section-meta">
                                            <span class="badge bg-secondary"><?php echo $section['layout_type']; ?></span>
                                            <span class="text-muted"><?php echo $section['product_count']; ?>개 상품</span>
                                        </div>
                                    </div>
                                    <div class="section-actions">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="previewSection(<?php echo $section['id']; ?>)" title="미리보기">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-3"></i>
                                <p>사용 가능한 섹션이 없습니다.</p>
                                <a href="display_sections.php" class="btn btn-sm btn-primary">
                                    <i class="fas fa-plus me-1"></i>섹션 만들기
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 프리셋 추가 모달 -->
<div class="modal fade" id="presetsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>레이아웃 프리셋 추가
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    선택한 프리셋이 현재 레이아웃에 추가됩니다. 기존 레이아웃은 유지됩니다.
                </div>
                <div class="row">
                    <?php foreach ($layout_presets as $preset): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card preset-card h-100" onclick="addPresetRows(<?php echo $preset['id']; ?>)">
                                <div class="card-body text-center">
                                    <div class="preset-pattern mb-3">
                                        <code class="fs-5"><?php echo htmlspecialchars($preset['pattern_code']); ?></code>
                                    </div>
                                    <h6 class="card-title">
                                        <i class="fas fa-plus-circle text-success me-1"></i>
                                        <?php echo htmlspecialchars($preset['preset_name']); ?>
                                    </h6>
                                    <p class="card-text small text-muted"><?php echo htmlspecialchars($preset['preset_description']); ?></p>
                                    <p class="card-text small text-success"><strong>클릭하여 레이아웃에 추가</strong></p>
                                    <?php if ($preset['is_system_preset']): ?>
                                        <span class="badge bg-primary">시스템</span>
                                    <?php endif; ?>
                                    <span class="badge bg-secondary"><?php echo $preset['usage_count']; ?>회 사용</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 레이아웃 초기화 모달 -->
<div class="modal fade" id="resetLayoutModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-refresh me-2"></i>레이아웃 초기화
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>경고:</strong> 현재 레이아웃을 완전히 삭제하고 선택한 프리셋으로 교체합니다. 이 작업은 되돌릴 수 없습니다.
                </div>
                <p>새로운 레이아웃으로 시작하려면 아래에서 프리셋을 선택하세요:</p>
                <div class="row">
                    <?php foreach ($layout_presets as $preset): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card preset-card h-100" onclick="applyPreset(<?php echo $preset['id']; ?>)">
                                <div class="card-body text-center">
                                    <div class="preset-pattern mb-3">
                                        <code class="fs-5"><?php echo htmlspecialchars($preset['pattern_code']); ?></code>
                                    </div>
                                    <h6 class="card-title">
                                        <i class="fas fa-refresh text-warning me-1"></i>
                                        <?php echo htmlspecialchars($preset['preset_name']); ?>
                                    </h6>
                                    <p class="card-text small text-muted"><?php echo htmlspecialchars($preset['preset_description']); ?></p>
                                    <p class="card-text small text-warning"><strong>클릭하여 레이아웃 교체</strong></p>
                                    <?php if ($preset['is_system_preset']): ?>
                                        <span class="badge bg-primary">시스템</span>
                                    <?php endif; ?>
                                    <span class="badge bg-secondary"><?php echo $preset['usage_count']; ?>회 사용</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* 레이아웃 빌더 스타일 */
.layout-builder {
    min-height: 400px;
    padding: 20px;
}

.layout-row {
    margin-bottom: 20px;
    border: 2px dashed #e0e0e0;
    border-radius: 8px;
    background-color: #fafafa;
    transition: all 0.3s ease;
}

.layout-row:hover {
    border-color: #DE121C;
    background-color: #fff5f5;
}

.layout-row.sortable-chosen {
    border-color: #DE121C;
    background-color: #fff;
    transform: rotate(2deg);
    z-index: 1000;
}

.row-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-radius: 6px 6px 0 0;
    margin-bottom: 15px;
}

.row-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.drag-handle {
    cursor: grab;
    color: #6c757d;
    padding: 5px;
}

.drag-handle:active {
    cursor: grabbing;
}

.row-title {
    display: flex;
    flex-direction: column;
}

.columns-container {
    padding: 0 15px 15px;
}

.layout-column {
    min-height: 120px;
}

.column-box {
    height: 100%;
    border: 2px dashed #dee2e6;
    border-radius: 6px;
    position: relative;
    background-color: white;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    min-height: 100px;
}

.column-box:hover {
    border-color: #DE121C;
    background-color: #fff8f8;
}

.column-box.drop-target {
    border-color: #28a745;
    background-color: #f8fff8;
}

.empty-column {
    text-align: center;
    padding: 20px;
}

.assigned-section {
    width: 100%;
    padding: 15px;
    background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
    border-radius: 4px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.section-info strong {
    display: block;
    margin-bottom: 5px;
}

.column-controls {
    position: absolute;
    bottom: 5px;
    right: 5px;
    display: flex;
    align-items: center;
    gap: 5px;
    background: rgba(255,255,255,0.9);
    padding: 2px 5px;
    border-radius: 4px;
    font-size: 0.8rem;
}

.column-width-indicator {
    font-weight: bold;
    color: #DE121C;
    min-width: 30px;
    text-align: center;
}

/* 섹션 목록 스타일 */
.section-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    margin-bottom: 10px;
    border-radius: 8px;
    background-color: #f8f9fa;
    cursor: grab;
    transition: all 0.3s ease;
}

.section-item:hover {
    background-color: #e9ecef;
    transform: translateX(5px);
}

.section-item.dragging {
    opacity: 0.5;
    transform: rotate(5deg);
    z-index: 1000;
}

.section-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
}

.section-details {
    flex: 1;
}

.section-name {
    font-weight: 600;
    margin-bottom: 4px;
}

.section-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
}

/* 프리셋 카드 스타일 */
.preset-card {
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.preset-card:hover {
    border-color: #DE121C;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(222, 18, 28, 0.2);
}

.preset-pattern {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    font-size: 1.2rem;
}

/* 반응형 디자인 */
@media (max-width: 768px) {
    .layout-builder {
        padding: 10px;
    }
    
    .row-header {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    
    .section-item {
        flex-direction: column;
        text-align: center;
    }
}

/* 드래그 앤 드롭 애니메이션 */
.sortable-ghost {
    opacity: 0.4;
    background-color: #fff3cd;
    border-color: #ffc107;
}

.sortable-drag {
    transform: rotate(5deg);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}

.drop-zone-active {
    background-color: #d4edda !important;
    border-color: #28a745 !important;
}
</style>

<!-- SortableJS CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>

<script>
// 전역 변수
let layoutData = <?php echo json_encode($current_layout); ?>;
let availableSections = <?php echo json_encode($available_sections); ?>;

// 페이지 로드 시 초기화
document.addEventListener('DOMContentLoaded', function() {
    initializeDragAndDrop();
    loadLayoutData();
});

// 전역 Sortable 인스턴스 저장
let layoutBuilderSortable = null;
let sectionsListSortable = null;
let columnSortables = [];

// 드래그 앤 드롭 초기화
function initializeDragAndDrop() {
    // 기존 인스턴스들 정리
    destroyDragAndDrop();
    
    // 행 순서 변경을 위한 Sortable
    const layoutBuilder = document.getElementById('layoutBuilder');
    if (layoutBuilder) {
        layoutBuilderSortable = new Sortable(layoutBuilder, {
            handle: '.drag-handle',
            animation: 300,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onEnd: function(evt) {
                updateRowOrder();
            }
        });
    }
    
    // 섹션 드래그를 위한 Sortable
    const sectionsList = document.querySelector('.sections-list');
    if (sectionsList) {
        sectionsListSortable = new Sortable(sectionsList, {
            group: {
                name: 'sections',
                pull: 'clone',
                put: false
            },
            animation: 300,
            sort: false,
            onStart: function(evt) {
                evt.item.classList.add('dragging');
                // 모든 컬럼박스를 드롭 타겟으로 표시
                document.querySelectorAll('.column-box').forEach(box => {
                    if (!box.querySelector('.assigned-section')) {
                        box.classList.add('drop-zone-active');
                    }
                });
            },
            onEnd: function(evt) {
                evt.item.classList.remove('dragging');
                // 드롭 타겟 표시 제거
                document.querySelectorAll('.column-box').forEach(box => {
                    box.classList.remove('drop-zone-active');
                });
            }
        });
    }
    
    // 컬럼에 섹션 드롭을 위한 Sortable
    initializeColumnDragAndDrop();
}

// 컬럼 드래그 앤 드롭만 초기화
function initializeColumnDragAndDrop() {
    // 기존 컬럼 Sortable 인스턴스들 정리
    columnSortables.forEach(sortable => {
        if (sortable && sortable.destroy) {
            sortable.destroy();
        }
    });
    columnSortables = [];
    
    // 새로운 컬럼 Sortable 인스턴스 생성
    document.querySelectorAll('.column-box').forEach(columnBox => {
        if (!columnBox.querySelector('.assigned-section')) {
            const sortable = new Sortable(columnBox, {
                group: 'sections',
                animation: 300,
                onAdd: function(evt) {
                    const sectionId = evt.item.getAttribute('data-section-id');
                    const columnId = evt.to.closest('.layout-column').getAttribute('data-column-id');
                    assignSectionToColumn(columnId, sectionId);
                    evt.item.remove(); // 복사본 제거
                }
            });
            columnSortables.push(sortable);
        }
    });
}

// 드래그 앤 드롭 인스턴스 정리
function destroyDragAndDrop() {
    if (layoutBuilderSortable && layoutBuilderSortable.destroy) {
        layoutBuilderSortable.destroy();
        layoutBuilderSortable = null;
    }
    
    if (sectionsListSortable && sectionsListSortable.destroy) {
        sectionsListSortable.destroy();
        sectionsListSortable = null;
    }
    
    columnSortables.forEach(sortable => {
        if (sortable && sortable.destroy) {
            sortable.destroy();
        }
    });
    columnSortables = [];
}

// 레이아웃 데이터 로드
function loadLayoutData() {
    // 현재 PHP에서 로드된 데이터를 사용
    console.log('레이아웃 데이터 로드:', layoutData);
}

// 새 행 추가
function addNewRow() {
    const rowName = prompt('새 행의 이름을 입력하세요:', '새로운 영역');
    if (!rowName) return;
    
    const formData = new FormData();
    formData.append('action', 'add_row');
    formData.append('row_name', rowName);
    formData.append('row_description', '');
    formData.append('row_order', 0);
    
    fetch('ajax_layout_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('새 행이 추가되었습니다.', 'success');
            location.reload(); // 페이지 새로고침으로 UI 업데이트
        } else {
            showNotification('행 추가에 실패했습니다: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('오류가 발생했습니다.', 'error');
    });
}

// 행 삭제
function deleteRow(rowId) {
    if (!confirm('정말로 이 행을 삭제하시겠습니까?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_row');
    formData.append('row_id', rowId);
    
    fetch('ajax_layout_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('행이 삭제되었습니다.', 'success');
            location.reload();
        } else {
            showNotification('행 삭제에 실패했습니다: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('오류가 발생했습니다.', 'error');
    });
}

// 컬럼 추가
function addColumn(rowId) {
    const formData = new FormData();
    formData.append('action', 'add_column');
    formData.append('row_id', rowId);
    formData.append('column_width', 3);
    
    fetch('ajax_layout_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('새 컬럼이 추가되었습니다.', 'success');
            location.reload();
        } else {
            showNotification('컬럼 추가에 실패했습니다: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('오류가 발생했습니다.', 'error');
    });
}

// 행 설정
function configureRow(rowId) {
    // 간단한 행 이름 변경 (나중에 모달로 확장 가능)
    const currentName = document.querySelector(`[data-row-id="${rowId}"] .row-title strong`).textContent;
    const newName = prompt('행 이름을 입력하세요:', currentName);
    
    if (!newName || newName === currentName) return;
    
    const formData = new FormData();
    formData.append('action', 'save_layout');
    
    // 현재 레이아웃 데이터 수집 후 해당 행 이름만 변경
    const rows = Array.from(document.querySelectorAll('.layout-row')).map(rowElement => {
        const currentRowId = parseInt(rowElement.getAttribute('data-row-id'));
        const rowName = currentRowId === rowId ? newName : rowElement.querySelector('.row-title strong').textContent.trim();
        const rowDescription = rowElement.getAttribute('data-description') || '';
        
        const columns = Array.from(rowElement.querySelectorAll('.layout-column')).map(colElement => {
            const columnId = parseInt(colElement.getAttribute('data-column-id'));
            const width = parseInt(colElement.getAttribute('data-width'));
            const sectionElement = colElement.querySelector('.assigned-section');
            const sectionId = sectionElement ? parseInt(sectionElement.getAttribute('data-section-id')) : null;
            const columnName = colElement.getAttribute('data-column-name') || '';
            
            return {
                id: columnId,
                column_width: width,
                section_id: sectionId,
                column_name: columnName
            };
        });
        
        return {
            id: currentRowId,
            row_name: rowName,
            row_description: rowDescription,
            columns: columns
        };
    });
    
    const layoutData = { rows: rows };
    formData.append('layout_data', JSON.stringify(layoutData));
    
    fetch('ajax_layout_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('행 설정이 저장되었습니다.', 'success');
            location.reload();
        } else {
            showNotification('행 설정 저장에 실패했습니다: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('오류가 발생했습니다.', 'error');
    });
}

// 컬럼 너비 조정
function adjustColumnWidth(columnId, adjustment) {
    const column = document.querySelector(`[data-column-id="${columnId}"]`);
    const currentWidth = parseInt(column.getAttribute('data-width'));
    const newWidth = Math.max(1, Math.min(12, currentWidth + adjustment));
    
    if (newWidth === currentWidth) return;
    
    // UI 즉시 업데이트
    column.className = column.className.replace(/col-lg-\d+/, `col-lg-${newWidth}`);
    column.setAttribute('data-width', newWidth);
    column.querySelector('.column-width-indicator').textContent = `${newWidth}/12`;
    
    // 서버에 업데이트 요청은 저장 시 일괄 처리
    showNotification(`컬럼 너비가 ${newWidth}/12로 변경되었습니다. 저장 버튼을 눌러 적용하세요.`, 'info');
}

// 섹션을 컬럼에 할당
function assignSectionToColumn(columnId, sectionId) {
    console.log('섹션 할당 시도:', { columnId, sectionId });
    
    const formData = new FormData();
    formData.append('action', 'assign_section');
    formData.append('column_id', columnId);
    formData.append('section_id', sectionId);
    
    // 로딩 표시
    showNotification('처리 중...', 'info');
    
    fetch('ajax_layout_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('응답 상태:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('원본 응답:', text);
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('JSON 파싱 오류:', e);
            throw new Error('서버 응답을 파싱할 수 없습니다: ' + text.substring(0, 200));
        }
    })
    .then(data => {
        console.log('파싱된 응답:', data);
        if (data.success) {
            showNotification(data.message || '섹션이 할당되었습니다.', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('섹션 할당에 실패했습니다: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('섹션 할당 오류:', error);
        showNotification(`오류가 발생했습니다: ${error.message}`, 'error');
    });
}

// 컬럼에서 섹션 제거
function removeSectionFromColumn(columnId) {
    const formData = new FormData();
    formData.append('action', 'assign_section');
    formData.append('column_id', columnId);
    formData.append('section_id', ''); // 빈 값으로 제거
    
    fetch('ajax_layout_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('섹션이 제거되었습니다.', 'success');
            location.reload();
        } else {
            showNotification('섹션 제거에 실패했습니다: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('오류가 발생했습니다.', 'error');
    });
}

// 행 순서 업데이트
function updateRowOrder() {
    const rows = Array.from(document.querySelectorAll('.layout-row'));
    const orderData = rows.map((row, index) => ({
        id: parseInt(row.getAttribute('data-row-id')),
        order: (index + 1) * 10
    }));
    
    fetch('ajax_update_layout_order.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(orderData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('행 순서가 업데이트되었습니다.', 'success');
        } else {
            showNotification('행 순서 업데이트에 실패했습니다.', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('오류가 발생했습니다.', 'error');
    });
}

// 프리셋 행 추가
function addPresetRows(presetId) {
    const formData = new FormData();
    formData.append('action', 'add_preset_rows');
    formData.append('preset_id', presetId);
    
    fetch('ajax_layout_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('프리셋 레이아웃이 추가되었습니다!', 'success');
            // 모달 닫기
            const modal = bootstrap.Modal.getInstance(document.getElementById('presetsModal'));
            if (modal) modal.hide();
            
            // 동적으로 레이아웃 업데이트
            updateLayoutDisplay(data.layout_data);
        } else {
            showNotification('프리셋 추가에 실패했습니다: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('오류가 발생했습니다.', 'error');
    });
}

// 프리셋 적용 (전체 교체) - 레이아웃 초기화에서 사용
function applyPreset(presetId) {
    if (!confirm('현재 레이아웃을 모두 삭제하고 프리셋으로 교체됩니다. 계속하시겠습니까?')) return;
    
    const formData = new FormData();
    formData.append('action', 'apply_preset');
    formData.append('preset_id', presetId);
    
    fetch('ajax_layout_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('레이아웃이 교체되었습니다. 섹션을 할당해보세요!', 'success');
            // 모달 닫기
            const modal = bootstrap.Modal.getInstance(document.getElementById('resetLayoutModal'));
            if (modal) modal.hide();
            
            // 동적으로 레이아웃 업데이트
            updateLayoutDisplay(data.layout_data);
        } else {
            showNotification('레이아웃 교체에 실패했습니다: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('오류가 발생했습니다.', 'error');
    });
}

// 동적으로 레이아웃 디스플레이 업데이트
function updateLayoutDisplay(layoutData) {
    const layoutContainer = document.getElementById('layout-container');
    if (!layoutContainer || !layoutData || !layoutData.rows) {
        console.error('레이아웃 데이터 또는 컨테이너를 찾을 수 없습니다.');
        return;
    }
    
    // 기존 레이아웃 제거
    layoutContainer.innerHTML = '';
    
    // 새 레이아웃 생성
    layoutData.rows.forEach(row => {
        const rowElement = document.createElement('div');
        rowElement.className = 'layout-row mb-4 position-relative';
        rowElement.setAttribute('data-row-id', row.id);
        rowElement.style.marginTop = (row.margin_top || 0) + 'px';
        rowElement.style.marginBottom = (row.margin_bottom || 20) + 'px';
        if (row.background_color) {
            rowElement.style.backgroundColor = row.background_color;
        }
        
        // 행 헤더 생성
        const rowHeader = document.createElement('div');
        rowHeader.className = 'row-header d-flex justify-content-between align-items-center mb-2';
        rowHeader.innerHTML = `
            <div class="row-info">
                <h5 class="mb-0">${row.row_name || '행 ' + row.row_order}</h5>
                <small class="text-muted">${row.row_description || ''}</small>
            </div>
            <div class="row-actions">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="configureRow(${row.id})">
                    <i class="fas fa-cog"></i> 설정
                </button>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="addColumn(${row.id})">
                    <i class="fas fa-plus"></i> 컬럼 추가
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteRow(${row.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        rowElement.appendChild(rowHeader);
        
        // 컬럼들 생성
        if (row.columns && row.columns.length > 0) {
            const columnsContainer = document.createElement('div');
            columnsContainer.className = 'row g-2 sortable-columns';
            columnsContainer.setAttribute('data-row-id', row.id);
            
            row.columns.forEach(column => {
                const columnElement = document.createElement('div');
                columnElement.className = `col-lg-${column.column_width} layout-column`;
                columnElement.setAttribute('data-column-id', column.id);
                columnElement.style.padding = `${column.padding_y || 15}px ${column.padding_x || 15}px`;
                if (column.background_color) {
                    columnElement.style.backgroundColor = column.background_color;
                }
                if (column.border_radius) {
                    columnElement.style.borderRadius = column.border_radius + 'px';
                }
                if (column.min_height) {
                    columnElement.style.minHeight = column.min_height + 'px';
                }
                
                // 컬럼 내용
                if (column.section_id) {
                    columnElement.innerHTML = `
                        <div class="column-content section-assigned">
                            <div class="section-info">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">${column.section_name}</h6>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeSectionFromColumn(${column.id})">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <p class="text-muted small mb-0">섹션 타입: ${column.section_type || 'unknown'}</p>
                            </div>
                        </div>
                    `;
                } else {
                    columnElement.innerHTML = `
                        <div class="column-content empty-column">
                            <div class="text-center py-4">
                                <i class="fas fa-cube fa-3x text-muted mb-3" style="opacity: 0.3;"></i>
                                <p class="text-muted mb-3">${column.column_name}</p>
                                <button type="button" class="btn btn-primary btn-sm" onclick="showSectionSelector(${column.id})">
                                    <i class="fas fa-plus me-1"></i>섹션 추가
                                </button>
                            </div>
                        </div>
                    `;
                }
                
                columnsContainer.appendChild(columnElement);
            });
            
            rowElement.appendChild(columnsContainer);
        } else {
            // 컬럼이 없는 행
            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'text-center text-muted py-4';
            emptyMessage.innerHTML = `
                <i class="fas fa-columns fa-2x mb-2" style="opacity: 0.3;"></i>
                <p>컬럼이 없습니다. 컬럼을 추가해주세요.</p>
            `;
            rowElement.appendChild(emptyMessage);
        }
        
        layoutContainer.appendChild(rowElement);
    });
    
    // 드래그 앤 드롭 다시 초기화
    initializeSortable();
    
    console.log('레이아웃 디스플레이가 업데이트되었습니다.');
}

// 레이아웃 저장
function saveLayout() {
    // 현재 DOM 상태를 기반으로 레이아웃 데이터 수집
    const rows = Array.from(document.querySelectorAll('.layout-row')).map(rowElement => {
        const rowId = parseInt(rowElement.getAttribute('data-row-id'));
        const rowNameElement = rowElement.querySelector('.row-title');
        const rowName = rowNameElement ? rowNameElement.textContent.trim() : '행 ' + rowId;
        const rowDescription = rowElement.getAttribute('data-description') || '';
        
        const columns = Array.from(rowElement.querySelectorAll('.layout-column')).map(colElement => {
            const columnId = parseInt(colElement.getAttribute('data-column-id'));
            const width = parseInt(colElement.getAttribute('data-width'));
            const sectionElement = colElement.querySelector('.assigned-section');
            const sectionId = sectionElement ? parseInt(sectionElement.getAttribute('data-section-id')) : null;
            const columnName = colElement.getAttribute('data-column-name') || '';
            
            return {
                id: columnId,
                column_width: width,
                section_id: sectionId,
                column_name: columnName
            };
        });
        
        return {
            id: rowId,
            row_name: rowName,
            row_description: rowDescription,
            columns: columns
        };
    });
    
    const layoutData = { rows: rows };
    
    const formData = new FormData();
    formData.append('action', 'save_layout');
    formData.append('layout_data', JSON.stringify(layoutData));
    
    fetch('ajax_layout_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('레이아웃이 저장되었습니다.', 'success');
        } else {
            showNotification('레이아웃 저장에 실패했습니다: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('오류가 발생했습니다.', 'error');
    });
}

// 미리보기
function previewLayout() {
    const previewWindow = window.open('../shop/index_hmart.php', 'layoutPreview', 'width=1200,height=800,scrollbars=yes,resizable=yes');
    previewWindow.focus();
}

// 섹션 미리보기
function previewSection(sectionId) {
    const previewWindow = window.open(`../shop/index_hmart.php?preview_section=${sectionId}`, 'sectionPreview', 'width=1200,height=800,scrollbars=yes,resizable=yes');
    previewWindow.focus();
}

// 알림 표시
function showNotification(message, type = 'info') {
    const alertClass = type === 'error' ? 'alert-danger' : `alert-${type}`;
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 400px;';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(alertDiv);
    
    // 3초 후 자동 제거
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 3000);
}
</script>

<?php
// 데이터베이스 연결을 닫지 않고 스크립트 종료 시 자동으로 정리되도록 함
// mysqli 연결은 스크립트 종료 시 자동으로 해제됩니다.
require_once 'partials/footer.php';
?>
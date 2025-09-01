<?php
// AJAX 레이아웃 매니저
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 헤더 설정
header('Content-Type: application/json');

try {
    // 로그인 및 권한 체크
    if (!is_logged_in()) {
        throw new Exception('로그인이 필요합니다.');
    }
    
    if (!has_permission('admin_access')) {
        throw new Exception('관리자 권한이 필요합니다.');
    }
    
    $conn = get_db_connection();
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_layout':
            // 현재 레이아웃 구조 조회
            $layout_data = getLayoutStructure($conn);
            echo json_encode([
                'success' => true,
                'data' => $layout_data
            ]);
            break;
            
        case 'save_layout':
            // 레이아웃 구조 저장
            $layout_json = $_POST['layout_data'] ?? '';
            if (empty($layout_json)) {
                throw new Exception('레이아웃 데이터가 없습니다.');
            }
            
            $layout_data = json_decode($layout_json, true);
            if (!$layout_data) {
                throw new Exception('잘못된 레이아웃 데이터 형식입니다.');
            }
            
            $result = saveLayoutStructure($conn, $layout_data);
            echo json_encode([
                'success' => true,
                'message' => '레이아웃이 성공적으로 저장되었습니다.',
                'affected_rows' => $result
            ]);
            break;
            
        case 'add_row':
            // 새 행 추가
            $row_name = $_POST['row_name'] ?? '새로운 영역';
            $row_description = $_POST['row_description'] ?? '';
            $row_order = (int)($_POST['row_order'] ?? 0);
            
            $row_id = addLayoutRow($conn, $row_name, $row_description, $row_order);
            echo json_encode([
                'success' => true,
                'message' => '새 행이 추가되었습니다.',
                'row_id' => $row_id
            ]);
            break;
            
        case 'delete_row':
            // 행 삭제
            $row_id = (int)($_POST['row_id'] ?? 0);
            if ($row_id <= 0) {
                throw new Exception('유효하지 않은 행 ID입니다.');
            }
            
            $result = deleteLayoutRow($conn, $row_id);
            echo json_encode([
                'success' => true,
                'message' => '행이 삭제되었습니다.',
                'deleted_count' => $result
            ]);
            break;
            
        case 'update_row_order':
            // 행 순서 업데이트
            $order_data = json_decode($_POST['order_data'] ?? '[]', true);
            if (empty($order_data)) {
                throw new Exception('순서 데이터가 없습니다.');
            }
            
            $result = updateRowOrder($conn, $order_data);
            echo json_encode([
                'success' => true,
                'message' => '행 순서가 업데이트되었습니다.',
                'updated_count' => $result
            ]);
            break;
            
        case 'update_column_layout':
            // 컬럼 레이아웃 업데이트
            $row_id = (int)($_POST['row_id'] ?? 0);
            $columns_data = json_decode($_POST['columns_data'] ?? '[]', true);
            
            if ($row_id <= 0) {
                throw new Exception('유효하지 않은 행 ID입니다.');
            }
            
            $result = updateColumnLayout($conn, $row_id, $columns_data);
            echo json_encode([
                'success' => true,
                'message' => '컬럼 레이아웃이 업데이트되었습니다.',
                'updated_count' => $result
            ]);
            break;
            
        case 'add_column':
            // 행에 새 컬럼 추가
            $row_id = (int)($_POST['row_id'] ?? 0);
            $column_width = (int)($_POST['column_width'] ?? 3);
            
            if ($row_id <= 0) {
                throw new Exception('유효하지 않은 행 ID입니다.');
            }
            
            $column_id = addColumnToRow($conn, $row_id, $column_width);
            echo json_encode([
                'success' => true,
                'message' => '새 컬럼이 추가되었습니다.',
                'column_id' => $column_id
            ]);
            break;
            
        case 'assign_section':
            // 섹션을 컬럼에 할당 (빈 값이면 제거)
            $column_id = (int)($_POST['column_id'] ?? 0);
            $section_id_raw = $_POST['section_id'] ?? '';
            
            if ($column_id <= 0) {
                throw new Exception('유효하지 않은 컬럼 ID입니다.');
            }
            
            // 빈 문자열이면 NULL로 처리 (섹션 제거)
            $section_id = empty($section_id_raw) ? null : (int)$section_id_raw;
            
            // section_id가 0이거나 null이 아닌 경우에만 유효성 검증
            if ($section_id !== null && $section_id <= 0) {
                throw new Exception('유효하지 않은 섹션 ID입니다.');
            }
            
            $result = assignSectionToColumn($conn, $column_id, $section_id);
            
            $message = $section_id ? '섹션이 컬럼에 할당되었습니다.' : '컬럼에서 섹션이 제거되었습니다.';
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            break;
            
        case 'get_available_sections':
            // 사용 가능한 섹션 목록 조회
            $sections = getAvailableSections($conn);
            echo json_encode([
                'success' => true,
                'data' => $sections
            ]);
            break;
            
        case 'get_presets':
            // 프리셋 템플릿 목록 조회
            $presets = getLayoutPresets($conn);
            echo json_encode([
                'success' => true,
                'data' => $presets
            ]);
            break;
            
        case 'apply_preset':
            // 프리셋 적용 (전체 교체)
            $preset_id = (int)($_POST['preset_id'] ?? 0);
            if ($preset_id <= 0) {
                throw new Exception('유효하지 않은 프리셋 ID입니다.');
            }
            
            $result = applyLayoutPreset($conn, $preset_id);
            echo json_encode([
                'success' => true,
                'message' => '프리셋이 적용되었습니다.',
                'layout_data' => $result
            ]);
            break;
            
        case 'add_preset_rows':
            // 프리셋 행 추가 (기존 레이아웃에 추가)
            $preset_id = (int)($_POST['preset_id'] ?? 0);
            if ($preset_id <= 0) {
                throw new Exception('유효하지 않은 프리셋 ID입니다.');
            }
            
            $result = addPresetRows($conn, $preset_id);
            echo json_encode([
                'success' => true,
                'message' => '프리셋 레이아웃이 추가되었습니다.',
                'layout_data' => $result
            ]);
            break;
            
        default:
            throw new Exception('지원하지 않는 작업입니다.');
    }
    
} catch (Exception $e) {
    error_log("AJAX 레이아웃 매니저 오류: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ===================================================================
// 헬퍼 함수들
// ===================================================================

/**
 * 현재 레이아웃 구조 조회
 */
function getLayoutStructure($conn) {
    $layout = ['rows' => []];
    
    // 행 목록 조회
    $rows_query = "SELECT * FROM layout_rows WHERE is_active = 1 ORDER BY row_order ASC";
    $rows_result = $conn->query($rows_query);
    
    if ($rows_result && $rows_result->num_rows > 0) {
        while ($row = $rows_result->fetch_assoc()) {
            // 각 행의 컬럼 조회
            $columns_query = "SELECT lc.*, ds.name as section_name, ds.section_type 
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

/**
 * 레이아웃 구조 저장
 */
function saveLayoutStructure($conn, $layout_data) {
    $conn->autocommit(false);
    
    try {
        $affected_rows = 0;
        
        foreach ($layout_data['rows'] as $row_index => $row_data) {
            $row_order = ($row_index + 1) * 10;
            
            // 행 업데이트 또는 생성
            if (isset($row_data['id']) && $row_data['id'] > 0) {
                $update_row_stmt = $conn->prepare("UPDATE layout_rows SET row_order = ?, row_name = ?, row_description = ? WHERE id = ?");
                $update_row_stmt->bind_param("issi", $row_order, $row_data['row_name'], $row_data['row_description'], $row_data['id']);
                $update_row_stmt->execute();
                $row_id = $row_data['id'];
                $update_row_stmt->close();
            } else {
                $insert_row_stmt = $conn->prepare("INSERT INTO layout_rows (row_order, row_name, row_description) VALUES (?, ?, ?)");
                $insert_row_stmt->bind_param("iss", $row_order, $row_data['row_name'], $row_data['row_description']);
                $insert_row_stmt->execute();
                $row_id = $conn->insert_id;
                $insert_row_stmt->close();
            }
            
            // 기존 컬럼 삭제
            $delete_columns_stmt = $conn->prepare("DELETE FROM layout_columns WHERE row_id = ?");
            $delete_columns_stmt->bind_param("i", $row_id);
            $delete_columns_stmt->execute();
            $delete_columns_stmt->close();
            
            // 새 컬럼 생성
            if (isset($row_data['columns']) && is_array($row_data['columns'])) {
                foreach ($row_data['columns'] as $col_index => $col_data) {
                    $column_order = ($col_index + 1) * 10;
                    $column_width = $col_data['column_width'] ?? 12;
                    $section_id = !empty($col_data['section_id']) ? $col_data['section_id'] : null;
                    $column_name = $col_data['column_name'] ?? "컬럼 " . ($col_index + 1);
                    
                    $insert_col_stmt = $conn->prepare("INSERT INTO layout_columns (row_id, column_order, column_width, section_id, column_name) VALUES (?, ?, ?, ?, ?)");
                    $insert_col_stmt->bind_param("iiiis", $row_id, $column_order, $column_width, $section_id, $column_name);
                    $insert_col_stmt->execute();
                    $insert_col_stmt->close();
                    $affected_rows++;
                }
            }
        }
        
        $conn->commit();
        $conn->autocommit(true);
        
        return $affected_rows;
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(true);
        throw $e;
    }
}

/**
 * 새 행 추가
 */
function addLayoutRow($conn, $row_name, $row_description, $row_order) {
    if ($row_order <= 0) {
        // 자동으로 마지막 순서 설정
        $max_order_result = $conn->query("SELECT MAX(row_order) as max_order FROM layout_rows");
        $max_order = $max_order_result->fetch_assoc()['max_order'] ?? 0;
        $row_order = $max_order + 10;
    }
    
    $stmt = $conn->prepare("INSERT INTO layout_rows (row_name, row_description, row_order) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $row_name, $row_description, $row_order);
    $stmt->execute();
    $row_id = $conn->insert_id;
    $stmt->close();
    
    // 기본 컬럼 추가 (전체 너비)
    $col_stmt = $conn->prepare("INSERT INTO layout_columns (row_id, column_order, column_width, column_name) VALUES (?, 10, 12, ?)");
    $default_col_name = $row_name . " 컬럼";
    $col_stmt->bind_param("is", $row_id, $default_col_name);
    $col_stmt->execute();
    $col_stmt->close();
    
    return $row_id;
}

/**
 * 행 삭제
 */
function deleteLayoutRow($conn, $row_id) {
    // 외래키 제약으로 자동으로 컬럼들도 삭제됨
    $stmt = $conn->prepare("DELETE FROM layout_rows WHERE id = ?");
    $stmt->bind_param("i", $row_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    return $affected;
}

/**
 * 행 순서 업데이트
 */
function updateRowOrder($conn, $order_data) {
    $conn->autocommit(false);
    
    try {
        $affected_count = 0;
        $stmt = $conn->prepare("UPDATE layout_rows SET row_order = ? WHERE id = ?");
        
        foreach ($order_data as $item) {
            if (isset($item['id']) && isset($item['order'])) {
                $stmt->bind_param("ii", $item['order'], $item['id']);
                $stmt->execute();
                $affected_count++;
            }
        }
        
        $stmt->close();
        $conn->commit();
        $conn->autocommit(true);
        
        return $affected_count;
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(true);
        throw $e;
    }
}

/**
 * 컬럼 레이아웃 업데이트
 */
function updateColumnLayout($conn, $row_id, $columns_data) {
    $conn->autocommit(false);
    
    try {
        // 기존 컬럼 삭제
        $delete_stmt = $conn->prepare("DELETE FROM layout_columns WHERE row_id = ?");
        $delete_stmt->bind_param("i", $row_id);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // 새 컬럼 생성
        $insert_stmt = $conn->prepare("INSERT INTO layout_columns (row_id, column_order, column_width, column_name, section_id) VALUES (?, ?, ?, ?, ?)");
        
        $affected_count = 0;
        foreach ($columns_data as $index => $column) {
            $column_order = ($index + 1) * 10;
            $column_width = $column['width'] ?? 12;
            $column_name = $column['name'] ?? "컬럼 " . ($index + 1);
            $section_id = !empty($column['section_id']) ? $column['section_id'] : null;
            
            $insert_stmt->bind_param("iiiisi", $row_id, $column_order, $column_width, $column_name, $section_id);
            $insert_stmt->execute();
            $affected_count++;
        }
        
        $insert_stmt->close();
        $conn->commit();
        $conn->autocommit(true);
        
        return $affected_count;
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(true);
        throw $e;
    }
}

/**
 * 행에 새 컬럼 추가
 */
function addColumnToRow($conn, $row_id, $column_width = 3) {
    // 현재 행의 최대 순서값 가져오기
    $max_order_query = "SELECT MAX(column_order) as max_order FROM layout_columns WHERE row_id = ?";
    $max_stmt = $conn->prepare($max_order_query);
    $max_stmt->bind_param("i", $row_id);
    $max_stmt->execute();
    $max_result = $max_stmt->get_result();
    $max_order = 0;
    
    if ($row = $max_result->fetch_assoc()) {
        $max_order = $row['max_order'] ?? 0;
    }
    $max_stmt->close();
    
    $new_order = $max_order + 10;
    $column_name = "컬럼 " . (($new_order / 10));
    
    // 새 컬럼 추가
    $stmt = $conn->prepare("INSERT INTO layout_columns (row_id, column_order, column_width, column_name) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiis", $row_id, $new_order, $column_width, $column_name);
    $stmt->execute();
    $column_id = $conn->insert_id;
    $stmt->close();
    
    return $column_id;
}

/**
 * 섹션을 컬럼에 할당
 */
function assignSectionToColumn($conn, $column_id, $section_id) {
    $stmt = $conn->prepare("UPDATE layout_columns SET section_id = ? WHERE id = ?");
    
    // NULL 값 처리
    if ($section_id === null) {
        $stmt->bind_param("si", $section_id, $column_id);
    } else {
        $stmt->bind_param("ii", $section_id, $column_id);
    }
    
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    return $affected;
}

/**
 * 사용 가능한 섹션 목록 조회
 */
function getAvailableSections($conn) {
    $query = "SELECT id, name, section_type, layout_type, is_active FROM display_sections WHERE is_active = 1 ORDER BY display_order ASC";
    $result = $conn->query($query);
    
    $sections = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $sections[] = $row;
        }
    }
    
    return $sections;
}

/**
 * 레이아웃 프리셋 목록 조회
 */
function getLayoutPresets($conn) {
    $query = "SELECT * FROM layout_presets ORDER BY is_system_preset DESC, usage_count DESC";
    $result = $conn->query($query);
    
    $presets = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $row['layout_config'] = json_decode($row['layout_config'], true);
            $presets[] = $row;
        }
    }
    
    return $presets;
}

/**
 * 프리셋 행들을 기존 레이아웃에 추가
 */
function addPresetRows($conn, $preset_id) {
    // DB 연결 상태 확인
    if (!$conn || $conn->connect_error) {
        throw new Exception("데이터베이스 연결 오류");
    }
    
    $conn->autocommit(false);
    
    try {
        // 프리셋 조회
        $preset_stmt = $conn->prepare("SELECT * FROM layout_presets WHERE id = ?");
        $preset_stmt->bind_param("i", $preset_id);
        $preset_stmt->execute();
        $preset_result = $preset_stmt->get_result();
        $preset = $preset_result->fetch_assoc();
        $preset_stmt->close();
        
        if (!$preset) {
            throw new Exception('프리셋을 찾을 수 없습니다.');
        }
        
        $layout_config = json_decode($preset['layout_config'], true);
        if (!$layout_config || !isset($layout_config['rows'])) {
            throw new Exception('잘못된 프리셋 구성입니다.');
        }
        
        // 현재 최대 row_order 조회
        $max_order_result = $conn->query("SELECT COALESCE(MAX(row_order), 0) as max_order FROM layout_rows");
        $max_order = $max_order_result->fetch_assoc()['max_order'];
        $next_order = $max_order + 10;
        
        // 프리셋 데이터로 새 행들 추가 (기존 레이아웃은 유지)
        foreach ($layout_config['rows'] as $row_index => $row_data) {
            $row_order = $next_order + ($row_index * 10);
            $row_name = $row_data['row_name'] ?? ("행 " . ($row_index + 1));
            $row_description = $row_data['row_description'] ?? "";
            
            // 행 생성 - 스타일 기본값 포함
            $margin_top = 0;
            $margin_bottom = 20;
            $row_background_color = null;
            $row_is_active = 1;
            
            $insert_row_stmt = $conn->prepare("INSERT INTO layout_rows (row_order, row_name, row_description, margin_top, margin_bottom, background_color, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert_row_stmt->bind_param("issiisi", $row_order, $row_name, $row_description, $margin_top, $margin_bottom, $row_background_color, $row_is_active);
            $insert_row_stmt->execute();
            $row_id = $conn->insert_id;
            $insert_row_stmt->close();
            
            // 행에 컬럼들 생성
            if (isset($row_data['columns']) && is_array($row_data['columns'])) {
                foreach ($row_data['columns'] as $col_index => $col_data) {
                    $column_order = ($col_index + 1) * 10;
                    $column_width = $col_data['column_width'] ?? 12;
                    $column_name = $col_data['column_name'] ?? ("컬럼 " . ($col_index + 1));
                    
                    // 스타일 기본값 설정
                    $padding_x = 15;
                    $padding_y = 15;
                    $border_radius = 0;
                    $min_height = null;
                    $background_color = null;
                    $is_active = 1;
                    
                    $insert_col_stmt = $conn->prepare("INSERT INTO layout_columns (row_id, column_order, column_width, column_name, padding_x, padding_y, border_radius, min_height, background_color, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_col_stmt->bind_param("iiisiiiisi", $row_id, $column_order, $column_width, $column_name, $padding_x, $padding_y, $border_radius, $min_height, $background_color, $is_active);
                    $insert_col_stmt->execute();
                    $insert_col_stmt->close();
                }
            }
        }
        
        // 사용 횟수 증가
        $usage_stmt = $conn->prepare("UPDATE layout_presets SET usage_count = usage_count + 1 WHERE id = ?");
        $usage_stmt->bind_param("i", $preset_id);
        $usage_stmt->execute();
        $usage_stmt->close();
        
        $conn->commit();
        $conn->autocommit(true);
        
        return getLayoutStructure($conn);
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(true);
        throw new Exception('프리셋 행 추가 중 오류가 발생했습니다: ' . $e->getMessage());
    }
}

/**
 * 프리셋 적용 (전체 교체)
 */
function applyLayoutPreset($conn, $preset_id) {
    // DB 연결 상태 확인
    if (!$conn || $conn->connect_error) {
        throw new Exception("데이터베이스 연결 오류");
    }
    
    $conn->autocommit(false);
    
    try {
        // 프리셋 조회
        $preset_stmt = $conn->prepare("SELECT * FROM layout_presets WHERE id = ?");
        $preset_stmt->bind_param("i", $preset_id);
        $preset_stmt->execute();
        $preset_result = $preset_stmt->get_result();
        $preset = $preset_result->fetch_assoc();
        $preset_stmt->close();
        
        if (!$preset) {
            throw new Exception('프리셋을 찾을 수 없습니다.');
        }
        
        $layout_config = json_decode($preset['layout_config'], true);
        if (!$layout_config || !isset($layout_config['rows'])) {
            throw new Exception('잘못된 프리셋 구성입니다.');
        }
        
        // 1. 기존 모든 레이아웃 데이터 완전 삭제
        $conn->query("DELETE FROM layout_columns");
        $conn->query("DELETE FROM layout_rows");
        
        // AUTO_INCREMENT 리셋
        $conn->query("ALTER TABLE layout_rows AUTO_INCREMENT = 1");
        $conn->query("ALTER TABLE layout_columns AUTO_INCREMENT = 1");
        
        // 2. 프리셋 데이터로 새 레이아웃 생성
        foreach ($layout_config['rows'] as $row_index => $row_data) {
            $row_order = ($row_index + 1) * 10;
            $row_name = $row_data['row_name'] ?? ("행 " . ($row_index + 1));
            $row_description = $row_data['row_description'] ?? "";
            
            // 행 생성 - 스타일 기본값 포함
            $margin_top = 0;
            $margin_bottom = 20;
            $row_background_color = null;
            $row_is_active = 1;
            
            $insert_row_stmt = $conn->prepare("INSERT INTO layout_rows (row_order, row_name, row_description, margin_top, margin_bottom, background_color, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert_row_stmt->bind_param("issiisi", $row_order, $row_name, $row_description, $margin_top, $margin_bottom, $row_background_color, $row_is_active);
            $insert_row_stmt->execute();
            $row_id = $conn->insert_id;
            $insert_row_stmt->close();
            
            // 행에 컬럼들 생성
            if (isset($row_data['columns']) && is_array($row_data['columns'])) {
                foreach ($row_data['columns'] as $col_index => $col_data) {
                    $column_order = ($col_index + 1) * 10;
                    $column_width = $col_data['column_width'] ?? 12;
                    $column_name = $col_data['column_name'] ?? ("컬럼 " . ($col_index + 1));
                    
                    // 스타일 기본값 설정
                    $padding_x = 15;
                    $padding_y = 15;
                    $border_radius = 0;
                    $min_height = null;
                    $background_color = null;
                    $is_active = 1;
                    
                    $insert_col_stmt = $conn->prepare("INSERT INTO layout_columns (row_id, column_order, column_width, column_name, padding_x, padding_y, border_radius, min_height, background_color, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_col_stmt->bind_param("iiisiiiisi", $row_id, $column_order, $column_width, $column_name, $padding_x, $padding_y, $border_radius, $min_height, $background_color, $is_active);
                    $insert_col_stmt->execute();
                    $insert_col_stmt->close();
                }
            }
        }
        
        // 3. 사용 횟수 증가
        $usage_stmt = $conn->prepare("UPDATE layout_presets SET usage_count = usage_count + 1 WHERE id = ?");
        $usage_stmt->bind_param("i", $preset_id);
        $usage_stmt->execute();
        $usage_stmt->close();
        
        $conn->commit();
        $conn->autocommit(true);
        
        return getLayoutStructure($conn);
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(true);
        throw new Exception('프리셋 적용 중 오류가 발생했습니다: ' . $e->getMessage());
    }
}

$conn->close();
?>
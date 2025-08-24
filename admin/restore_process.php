<?php
// JSON 응답을 위한 헤더 설정 (맨 먼저 설정)
header('Content-Type: application/json; charset=utf-8');

// 세션 시작
session_start();

// 필요한 파일들만 include
require_once '../config/db_config.php';
require_once '../lib/session_helper.php';
require_once '../lib/permission_helper.php';

// 로그인 확인
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// 권한 확인 (settings 권한 필요)
if (!has_permission('settings')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '허용되지 않는 요청 방식입니다.']);
    exit;
}

// 액션 확인
$action = $_POST['action'] ?? '';
if ($action !== 'restore_data') {
    echo json_encode(['success' => false, 'message' => '잘못된 액션입니다.']);
    exit;
}

// 파일 업로드 확인
if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => '백업 파일이 업로드되지 않았습니다.']);
    exit;
}

$uploaded_file = $_FILES['backup_file'];

// 파일 확장자 검사
$file_ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
if ($file_ext !== 'sql') {
    echo json_encode(['success' => false, 'message' => 'SQL 파일만 업로드 가능합니다.']);
    exit;
}

// 파일 크기 검사 (최대 100MB)
$max_size = 100 * 1024 * 1024; // 100MB
if ($uploaded_file['size'] > $max_size) {
    echo json_encode(['success' => false, 'message' => '파일 크기가 너무 큽니다. (최대 100MB)']);
    exit;
}

try {
    // 임시 파일 위치
    $temp_file = $uploaded_file['tmp_name'];
    
    // 파일 내용 안전성 검사
    $file_content = file_get_contents($temp_file);
    if ($file_content === false) {
        echo json_encode(['success' => false, 'message' => '파일을 읽을 수 없습니다.']);
        exit;
    }
    
    // 위험한 SQL 구문 검사 (DROP DATABASE, CREATE DATABASE는 제외)
    $dangerous_patterns = [
        '/DROP\s+DATABASE\s+/i',
        '/CREATE\s+DATABASE\s+/i',
        '/LOAD_FILE\s*\(/i',
        '/INTO\s+OUTFILE\s+/i',
        '/INTO\s+DUMPFILE\s+/i'
    ];
    
    foreach ($dangerous_patterns as $pattern) {
        if (preg_match($pattern, $file_content)) {
            echo json_encode(['success' => false, 'message' => '백업 파일에 위험한 SQL 구문이 포함되어 있습니다.']);
            exit;
        }
    }
    
    // 복원 전 현재 데이터 백업 생성
    $backup_dir = __DIR__ . '/../backups/';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    $pre_restore_backup = $backup_dir . 'pre_restore_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $conn = get_db_connection();
    
    // 현재 데이터 백업 (간단한 방식)
    createPreRestoreBackup($pre_restore_backup, $conn);
    
    // 백업 파일 타입 감지
    $backup_type = 'full'; // 기본값
    if (strpos($uploaded_file['name'], '_data_') !== false) {
        $backup_type = 'data_only';
    } elseif (strpos($file_content, '-- Backup Type: DATA_ONLY') !== false) {
        $backup_type = 'data_only';
    }
    
    // 데이터 복원 실행
    if ($backup_type === 'data_only') {
        $result = restoreDataOnly($file_content, $conn);
    } else {
        $result = restoreDatabase($file_content, $conn);
    }
    
    $conn->close();
    
    if ($result['success']) {
        echo json_encode([
            'success' => true, 
            'message' => '데이터 복원이 성공적으로 완료되었습니다.',
            'pre_backup' => basename($pre_restore_backup)
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
    
} catch (Exception $e) {
    error_log("Restore process error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '복원 중 오류가 발생했습니다: ' . $e->getMessage()]);
}

/**
 * 복원 전 현재 데이터 백업
 */
function createPreRestoreBackup($filepath, $conn) {
    try {
        $handle = fopen($filepath, 'w');
        if (!$handle) {
            return false;
        }
        
        fwrite($handle, "-- Pre-restore backup created on: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database: " . DB_NAME . "\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");
        
        // 모든 테이블 백업 (구조 + 데이터)
        $tables_result = $conn->query("SHOW TABLES");
        while ($table_row = $tables_result->fetch_array()) {
            $table = $table_row[0];
            
            // 테이블 구조
            $create_result = $conn->query("SHOW CREATE TABLE `$table`");
            if ($create_result) {
                $create_row = $create_result->fetch_array();
                fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($handle, $create_row[1] . ";\n\n");
            }
            
            // 테이블 데이터
            $data_result = $conn->query("SELECT * FROM `$table`");
            if ($data_result && $data_result->num_rows > 0) {
                $fields_result = $conn->query("SHOW COLUMNS FROM `$table`");
                $columns = [];
                while ($field = $fields_result->fetch_assoc()) {
                    $columns[] = '`' . $field['Field'] . '`';
                }
                $columns_str = implode(', ', $columns);
                
                while ($row = $data_result->fetch_assoc()) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . $conn->real_escape_string($value) . "'";
                        }
                    }
                    $values_str = implode(', ', $values);
                    fwrite($handle, "INSERT INTO `$table` ($columns_str) VALUES ($values_str);\n");
                }
                fwrite($handle, "\n");
            }
        }
        
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($handle);
        return true;
        
    } catch (Exception $e) {
        if (isset($handle)) {
            fclose($handle);
        }
        error_log("Pre-restore backup error: " . $e->getMessage());
        return false;
    }
}

/**
 * SQL 쿼리를 올바르게 분리하는 함수
 */
function splitSqlQueries($sql_content) {
    $queries = [];
    $current_query = '';
    $in_string = false;
    $string_char = '';
    $escaped = false;
    
    for ($i = 0; $i < strlen($sql_content); $i++) {
        $char = $sql_content[$i];
        $next_char = ($i < strlen($sql_content) - 1) ? $sql_content[$i + 1] : '';
        
        // 이스케이프 처리
        if ($escaped) {
            $current_query .= $char;
            $escaped = false;
            continue;
        }
        
        if ($char === '\\') {
            $escaped = true;
            $current_query .= $char;
            continue;
        }
        
        // 문자열 처리
        if (!$in_string && ($char === '"' || $char === "'")) {
            $in_string = true;
            $string_char = $char;
            $current_query .= $char;
        } elseif ($in_string && $char === $string_char) {
            $in_string = false;
            $string_char = '';
            $current_query .= $char;
        } elseif (!$in_string && $char === ';') {
            // 쿼리 끝
            $current_query = trim($current_query);
            if (!empty($current_query)) {
                $queries[] = $current_query;
            }
            $current_query = '';
        } else {
            $current_query .= $char;
        }
    }
    
    // 마지막 쿼리 처리
    $current_query = trim($current_query);
    if (!empty($current_query)) {
        $queries[] = $current_query;
    }
    
    return $queries;
}

/**
 * 데이터 전용 복원 실행
 */
function restoreDataOnly($sql_content, $conn) {
    try {
        // 자동 커밋 비활성화
        $conn->autocommit(false);
        
        // SQL 모드 설정 (더 관대한 모드로 설정)
        $conn->query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $conn->query("SET SESSION foreign_key_checks = 0");
        $conn->query("SET SESSION unique_checks = 0");
        $conn->query("SET SESSION autocommit = 0");
        
        // 문자셋 설정
        $conn->query("SET NAMES utf8mb4");
        $conn->query("SET CHARACTER SET utf8mb4");
        $conn->query("SET character_set_connection = utf8mb4");
        
        // SQL 문을 올바르게 분리하여 실행
        $queries = splitSqlQueries($sql_content);
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        $skip_patterns = [
            '/^SET\s+NAMES/i',
            '/^SET\s+FOREIGN_KEY_CHECKS/i',
            '/^SET\s+SQL_MODE/i',
            '/^SET\s+time_zone/i',
            '/^--/',
            '/^\/\*/',
            '/^USE\s+/i',
            '/^DROP\s+TABLE/i',  // 데이터 전용이므로 DROP TABLE 무시
            '/^CREATE\s+TABLE/i' // 데이터 전용이므로 CREATE TABLE 무시
        ];
        
        foreach ($queries as $query) {
            $query = trim($query);
            
            // 빈 쿼리 건너뛰기
            if (empty($query)) {
                continue;
            }
            
            // 특정 구문 건너뛰기
            $skip = false;
            foreach ($skip_patterns as $pattern) {
                if (preg_match($pattern, $query)) {
                    $skip = true;
                    break;
                }
            }
            
            if ($skip) {
                continue;
            }
            
            // 쿼리 실행
            $result = $conn->query($query);
            if ($result) {
                $success_count++;
            } else {
                $error_count++;
                $error_msg = $conn->error;
                
                // "Data truncated" 또는 "Incorrect" 오류는 경고로 처리
                if (strpos($error_msg, 'Data truncated') !== false || 
                    strpos($error_msg, 'Incorrect') !== false ||
                    strpos($error_msg, "doesn't exist") !== false) {
                    // 테이블이 존재하지 않는 경우나 데이터 타입 문제는 경고로 처리
                    error_log("Warning during data restore: " . $error_msg . " Query: " . substr($query, 0, 200));
                    $error_count--;
                    continue;
                }
                
                $errors[] = "Query failed: " . substr($query, 0, 100) . "... Error: " . $error_msg;
                
                // 심각한 오류인 경우 복원 중단
                if ($error_count > 10) {
                    $conn->rollback();
                    $conn->query("SET SESSION foreign_key_checks = 1");
                    $conn->query("SET SESSION unique_checks = 1");
                    $conn->query("SET SESSION autocommit = 1");
                    
                    return [
                        'success' => false, 
                        'message' => "너무 많은 오류가 발생하여 데이터 복원을 중단했습니다. 첫 번째 오류: " . $errors[0]
                    ];
                }
            }
        }
        
        // 설정 복구
        $conn->query("SET SESSION foreign_key_checks = 1");
        $conn->query("SET SESSION unique_checks = 1");
        
        if ($error_count === 0) {
            // 모든 쿼리 성공
            $conn->commit();
            $conn->query("SET SESSION autocommit = 1");
            
            return [
                'success' => true,
                'message' => "데이터 복원 완료. {$success_count}개의 쿼리가 성공적으로 실행되었습니다."
            ];
        } else {
            // 일부 오류가 있지만 계속 진행할지 결정
            if ($error_count < $success_count / 10) {
                $conn->commit();
                $conn->query("SET SESSION autocommit = 1");
                
                return [
                    'success' => true,
                    'message' => "데이터 복원 완료 (일부 경고 포함). 성공: {$success_count}개, 실패: {$error_count}개"
                ];
            } else {
                // 오류가 너무 많으면 롤백
                $conn->rollback();
                $conn->query("SET SESSION foreign_key_checks = 1");
                $conn->query("SET SESSION unique_checks = 1");
                $conn->query("SET SESSION autocommit = 1");
                
                return [
                    'success' => false,
                    'message' => "데이터 복원 실패. 너무 많은 오류가 발생했습니다. 성공: {$success_count}개, 실패: {$error_count}개. 첫 번째 오류: " . ($errors[0] ?? 'Unknown error')
                ];
            }
        }
        
    } catch (Exception $e) {
        // 오류 발생시 롤백
        if (isset($conn) && $conn) {
            $conn->rollback();
            $conn->query("SET SESSION foreign_key_checks = 1");
            $conn->query("SET SESSION unique_checks = 1");
            $conn->query("SET SESSION autocommit = 1");
        }
        
        return [
            'success' => false,
            'message' => '데이터 복원 중 예외가 발생했습니다: ' . $e->getMessage()
        ];
    }
}

/**
 * 데이터베이스 복원 실행 (전체 복원)
 */
function restoreDatabase($sql_content, $conn) {
    try {
        // 자동 커밋 비활성화
        $conn->autocommit(false);
        
        // SQL 모드 설정 (더 관대한 모드로 설정)
        // STRICT_TRANS_TABLES를 비활성화하여 데이터 타입 불일치 문제 완화
        $conn->query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $conn->query("SET SESSION foreign_key_checks = 0");
        $conn->query("SET SESSION unique_checks = 0");
        $conn->query("SET SESSION autocommit = 0");
        
        // 문자셋 설정
        $conn->query("SET NAMES utf8mb4");
        $conn->query("SET CHARACTER SET utf8mb4");
        $conn->query("SET character_set_connection = utf8mb4");
        
        // 먼저 모든 테이블 삭제 (깨끗한 상태로 만들기)
        $tables_result = $conn->query("SHOW TABLES");
        if ($tables_result) {
            while ($table_row = $tables_result->fetch_array()) {
                $table = $table_row[0];
                $conn->query("DROP TABLE IF EXISTS `$table`");
            }
        }
        
        // SQL 문을 올바르게 분리하여 실행
        $queries = splitSqlQueries($sql_content);
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        $skip_patterns = [
            '/^SET\s+NAMES/i',
            '/^SET\s+FOREIGN_KEY_CHECKS/i',
            '/^SET\s+SQL_MODE/i',
            '/^SET\s+time_zone/i',
            '/^--/',
            '/^\/\*/',
            '/^USE\s+/i'
        ];
        
        foreach ($queries as $query) {
            $query = trim($query);
            
            // 빈 쿼리 건너뛰기
            if (empty($query)) {
                continue;
            }
            
            // 특정 SET 구문이나 주석 건너뛰기
            $skip = false;
            foreach ($skip_patterns as $pattern) {
                if (preg_match($pattern, $query)) {
                    $skip = true;
                    break;
                }
            }
            
            if ($skip) {
                continue;
            }
            
            // 쿼리 실행
            $result = $conn->query($query);
            if ($result) {
                $success_count++;
            } else {
                $error_count++;
                $error_msg = $conn->error;
                
                // "Table already exists" 오류는 무시 (DROP TABLE IF EXISTS가 실패한 경우)
                if (strpos($error_msg, 'already exists') !== false) {
                    // 테이블이 이미 존재하면 삭제 후 재시도
                    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $query, $matches)) {
                        $table_name = $matches[1];
                        $conn->query("DROP TABLE IF EXISTS `$table_name`");
                        
                        // 다시 시도
                        if ($conn->query($query)) {
                            $success_count++;
                            $error_count--;
                            continue;
                        }
                    }
                }
                
                // "Data truncated" 오류 처리 - ENUM이나 SET 타입 문제
                if (strpos($error_msg, 'Data truncated') !== false || 
                    strpos($error_msg, 'Incorrect') !== false) {
                    // 경고로 처리하고 계속 진행
                    error_log("Warning during restore: " . $error_msg . " Query: " . substr($query, 0, 200));
                    // 오류 카운트는 증가시키지 않고 계속 진행
                    $error_count--;
                    continue;
                }
                
                $errors[] = "Query failed: " . substr($query, 0, 100) . "... Error: " . $error_msg;
                
                // 심각한 오류인 경우 복원 중단 (단, CREATE TABLE 관련 오류는 제외)
                if ($error_count > 10 && strpos($error_msg, 'already exists') === false) {
                    $conn->rollback();
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    $conn->autocommit(true);
                    
                    return [
                        'success' => false, 
                        'message' => "너무 많은 오류가 발생하여 복원을 중단했습니다. 첫 번째 오류: " . $errors[0]
                    ];
                }
            }
        }
        
        // 설정 복구
        $conn->query("SET SESSION foreign_key_checks = 1");
        $conn->query("SET SESSION unique_checks = 1");
        
        if ($error_count === 0) {
            // 모든 쿼리 성공
            $conn->commit();
            $conn->query("SET SESSION autocommit = 1");
            
            return [
                'success' => true,
                'message' => "복원 완료. {$success_count}개의 쿼리가 성공적으로 실행되었습니다."
            ];
        } else {
            // 일부 오류가 있지만 계속 진행할지 결정
            if ($error_count < $success_count / 10) { // 오류가 전체의 10% 미만인 경우
                $conn->commit();
                $conn->query("SET SESSION autocommit = 1");
                
                return [
                    'success' => true,
                    'message' => "복원 완료 (일부 경고 포함). 성공: {$success_count}개, 실패: {$error_count}개"
                ];
            } else {
                // 오류가 너무 많으면 롤백
                $conn->rollback();
                $conn->query("SET SESSION foreign_key_checks = 1");
                $conn->query("SET SESSION unique_checks = 1");
                $conn->query("SET SESSION autocommit = 1");
                
                return [
                    'success' => false,
                    'message' => "복원 실패. 너무 많은 오류가 발생했습니다. 성공: {$success_count}개, 실패: {$error_count}개. 첫 번째 오류: " . ($errors[0] ?? 'Unknown error')
                ];
            }
        }
        
    } catch (Exception $e) {
        // 오류 발생시 롤백
        if (isset($conn) && $conn) {
            $conn->rollback();
            $conn->query("SET SESSION foreign_key_checks = 1");
            $conn->query("SET SESSION unique_checks = 1");
            $conn->query("SET SESSION autocommit = 1");
        }
        
        return [
            'success' => false,
            'message' => '복원 중 예외가 발생했습니다: ' . $e->getMessage()
        ];
    }
}
?>
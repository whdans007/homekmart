<?php
// 에러 출력 방지
error_reporting(0);
ini_set('display_errors', 0);

// 출력 버퍼링 시작
ob_start();

// JSON 응답을 위한 헤더 설정 (맨 먼저 설정)
header('Content-Type: application/json; charset=utf-8');

// 세션 시작
session_start();

// 필요한 파일들만 include
require_once '../config/db_config.php';
require_once '../lib/session_helper.php';
require_once '../lib/permission_helper.php';

// 버퍼 내용 정리 (혹시 있을 수 있는 출력 제거)
ob_clean();

// 로그인 확인
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    ob_end_flush();
    exit;
}

// 권한 확인 (settings 권한 필요)
if (!has_permission('settings')) {
    http_response_code(403);
    ob_clean();
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    ob_end_flush();
    exit;
}

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_clean();
    echo json_encode(['success' => false, 'message' => '허용되지 않는 요청 방식입니다.']);
    ob_end_flush();
    exit;
}

// 액션 확인
$action = $_POST['action'] ?? '';

// 백업 시작 요청 (progress_id만 반환)
if ($action === 'start_backup') {
    $progress_id = uniqid('backup_', true);
    ob_clean();
    echo json_encode(['success' => true, 'progress_id' => $progress_id]);
    ob_end_flush();
    exit;
}

if ($action !== 'create_backup') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '잘못된 액션입니다.']);
    ob_end_flush();
    exit;
}

// 백업 타입 확인
$backup_type = $_POST['backup_type'] ?? 'full';
if (!in_array($backup_type, ['full', 'data_only'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '잘못된 백업 타입입니다.']);
    ob_end_flush();
    exit;
}

try {
    // 실행 시간과 메모리 제한 증가
    ini_set('max_execution_time', 300); // 5분
    ini_set('memory_limit', '256M');
    
    // 진행 상황 추적을 위한 고유 ID (POST로 받거나 새로 생성)
    $progress_id = $_POST['progress_id'] ?? uniqid('backup_', true);
    $progress_file = sys_get_temp_dir() . '/backup_progress_' . $progress_id . '.json';
    
    // 진행 상황 업데이트 함수
    function updateProgress($file, $status, $message, $current, $total, $table = '') {
        $progress = [
            'status' => $status,
            'message' => $message,
            'current' => $current,
            'total' => $total,
            'percentage' => $total > 0 ? round(($current / $total) * 100) : 0,
            'current_table' => $table
        ];
        file_put_contents($file, json_encode($progress));
    }
    
    // 백업 디렉토리 확인
    $backup_dir = __DIR__ . '/../backups/';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    // 백업 파일명 생성 (타입별로 구분)
    $timestamp = date('Y-m-d_H-i-s');
    if ($backup_type === 'data_only') {
        $filename = "homekmart_data_{$timestamp}.sql";
    } else {
        $filename = "homekmart_backup_{$timestamp}.sql";
    }
    $filepath = $backup_dir . $filename;
    
    // 데이터베이스 연결
    $conn = get_db_connection();
    
    // exec 함수 사용 가능 여부 확인
    if (function_exists('exec') && function_exists('shell_exec')) {
        // 백업 생성 방법 1: mysqldump 사용 (가능한 경우)
        $success = createBackupWithMysqldump($filepath, $conn, $backup_type);
        
        // mysqldump가 실패하면 PHP로 백업 생성
        if (!$success) {
            $success = createBackupWithPHP($filepath, $conn, $backup_type);
        }
    } else {
        // exec 함수 사용 불가능한 경우 PHP 백업만 사용
        $success = createBackupWithPHP($filepath, $conn, $backup_type);
    }
    
    $conn->close();
    
    if ($success && file_exists($filepath) && filesize($filepath) > 0) {
        // 파일 크기 확인
        $filesize = filesize($filepath);
        $filesizeFormatted = formatFileSize($filesize);
        
        // 최종 JSON 출력 전 버퍼 정리
        ob_clean();
        echo json_encode([
            'success' => true, 
            'message' => "백업이 성공적으로 생성되었습니다. ({$filesizeFormatted})",
            'filename' => $filename,
            'filesize' => $filesize,
            'progress_id' => $progress_id
        ]);
        ob_end_flush();
        
        // 진행 상황 파일 삭제
        if (file_exists($progress_file)) {
            unlink($progress_file);
        }
    } else {
        // 실패한 파일이 있으면 삭제
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        ob_clean();
        echo json_encode(['success' => false, 'message' => '백업 파일 생성에 실패했습니다.']);
        ob_end_flush();
    }
    
} catch (Exception $e) {
    error_log("Backup creation error: " . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'message' => '백업 생성 중 오류가 발생했습니다: ' . $e->getMessage()]);
    ob_end_flush();
}

/**
 * mysqldump를 사용하여 백업 생성
 */
function createBackupWithMysqldump($filepath, $conn, $backup_type = 'full') {
    try {
        // exec 함수 사용 불가능한 경우 바로 실패 반환
        if (!function_exists('exec') || !function_exists('shell_exec')) {
            return false;
        }
        
        // Windows 환경에서 mysqldump 경로 찾기
        $mysqldump_paths = [
            'mysqldump',  // PATH에 있는 경우
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',  // XAMPP
            'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',  // WAMP
            'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqldump.exe'  // Laragon
        ];
        
        $mysqldump_cmd = null;
        foreach ($mysqldump_paths as $path) {
            if (is_file($path) || shell_exec("where $path 2>nul")) {
                $mysqldump_cmd = $path;
                break;
            }
        }
        
        if (!$mysqldump_cmd) {
            return false;  // mysqldump를 찾을 수 없음
        }
        
        // mysqldump 명령어 구성
        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASS;
        $dbname = DB_NAME;
        
        // 백업 타입에 따른 옵션 설정
        if ($backup_type === 'data_only') {
            // 데이터만: 구조 제외, 트리거 제외, 완전한 INSERT
            $options = '--no-create-info --skip-triggers --complete-insert --extended-insert=FALSE';
        } else {
            // 전체: 구조 + 데이터 + 루틴 + 트리거
            $options = '--single-transaction --routines --triggers --complete-insert';
        }
        
        // 명령어 생성
        $cmd = sprintf(
            '"%s" --host=%s --user=%s --password=%s --default-character-set=utf8mb4 %s %s > "%s" 2>&1',
            $mysqldump_cmd,
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass),
            $options,
            escapeshellarg($dbname),
            $filepath
        );
        
        // 명령어 실행
        $output = [];
        $return_code = 0;
        exec($cmd, $output, $return_code);
        
        // 성공 여부 확인
        if ($return_code === 0 && file_exists($filepath) && filesize($filepath) > 0) {
            return true;
        }
        
        // 실패시 로그 기록
        error_log("mysqldump failed. Return code: $return_code, Output: " . implode("\n", $output));
        return false;
        
    } catch (Exception $e) {
        error_log("mysqldump error: " . $e->getMessage());
        return false;
    }
}

/**
 * PHP로 직접 백업 생성
 */
function createBackupWithPHP($filepath, $conn, $backup_type = 'full') {
    try {
        $handle = fopen($filepath, 'w');
        if (!$handle) {
            return false;
        }
        
        // 헤더 작성
        $backup_label = ($backup_type === 'data_only') ? 'Data Only Backup' : 'Complete Database Backup';
        fwrite($handle, "-- HOME K MART {$backup_label}\n");
        fwrite($handle, "-- Generated on: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database: " . DB_NAME . "\n");
        fwrite($handle, "-- Backup Type: " . strtoupper($backup_type) . "\n");
        fwrite($handle, "-- =============================================\n\n");
        
        // 문자셋 설정
        fwrite($handle, "SET NAMES utf8mb4;\n");
        if ($backup_type === 'data_only') {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");
        } else {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");
        }
        
        // 모든 테이블 가져오기
        $tables_result = $conn->query("SHOW TABLES");
        if (!$tables_result) {
            fclose($handle);
            return false;
        }
        
        // 전체 테이블 수 계산
        $tables = [];
        while ($table_row = $tables_result->fetch_array()) {
            $tables[] = $table_row[0];
        }
        $total_tables = count($tables);
        $current_table = 0;
        
        // 진행 상황 초기화
        updateProgress($progress_file, 'processing', '백업 생성 중', 0, $total_tables);
        
        foreach ($tables as $table) {
            $current_table++;
            
            // 진행 상황 업데이트
            updateProgress($progress_file, 'processing', "테이블 백업 중: $table", $current_table, $total_tables, $table);
            
            // 전체 백업인 경우만 테이블 구조 백업
            if ($backup_type === 'full') {
                $create_result = $conn->query("SHOW CREATE TABLE `$table`");
                if ($create_result) {
                    $create_row = $create_result->fetch_array();
                    fwrite($handle, "-- Table structure for `$table`\n");
                    fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
                    fwrite($handle, $create_row[1] . ";\n\n");
                }
            } else {
                // 데이터만 백업인 경우 DELETE 사용 (TRUNCATE보다 안전함)
                fwrite($handle, "-- Data for table `$table`\n");
                fwrite($handle, "DELETE FROM `$table`;\n");
                // AUTO_INCREMENT 리셋 (PRIMARY KEY가 있는 경우)
                $auto_increment_check = $conn->query("SHOW TABLE STATUS LIKE '$table'");
                if ($auto_increment_check) {
                    $table_info = $auto_increment_check->fetch_assoc();
                    if ($table_info && $table_info['Auto_increment']) {
                        fwrite($handle, "ALTER TABLE `$table` AUTO_INCREMENT = 1;\n");
                    }
                }
            }
            
            // 테이블 데이터 백업
            $data_result = $conn->query("SELECT * FROM `$table`");
            if ($data_result && $data_result->num_rows > 0) {
                if ($backup_type === 'full') {
                    fwrite($handle, "-- Data for table `$table`\n");
                }
                
                // 컬럼 정보 가져오기
                $fields_result = $conn->query("SHOW COLUMNS FROM `$table`");
                $columns = [];
                while ($field = $fields_result->fetch_assoc()) {
                    $columns[] = '`' . $field['Field'] . '`';
                }
                $columns_str = implode(', ', $columns);
                
                // INSERT 문 생성
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
        
        // 마무리
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fwrite($handle, "-- Backup completed successfully\n");
        
        fclose($handle);
        
        // 진행 상황 완료
        updateProgress($progress_file, 'completed', '백업 완료', $total_tables, $total_tables);
        
        return true;
        
    } catch (Exception $e) {
        if (isset($handle)) {
            fclose($handle);
        }
        error_log("PHP backup error: " . $e->getMessage());
        return false;
    }
}

/**
 * 파일 크기 포맷팅
 */
function formatFileSize($size) {
    if ($size >= 1024 * 1024 * 1024) {
        return number_format($size / (1024 * 1024 * 1024), 2) . ' GB';
    } elseif ($size >= 1024 * 1024) {
        return number_format($size / (1024 * 1024), 2) . ' MB';
    } elseif ($size >= 1024) {
        return number_format($size / 1024, 2) . ' KB';
    } else {
        return $size . ' bytes';
    }
}
?>
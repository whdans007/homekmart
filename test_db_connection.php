<?php
/**
 * 데이터베이스 연결 테스트
 * 로컬 개발환경 재설정 시 연결 상태 확인용
 */

require_once __DIR__ . '/config/db_config.php';

echo "=== 데이터베이스 연결 테스트 ===\n\n";

try {
    $conn = get_db_connection();
    echo "✅ 데이터베이스 연결 성공!\n";
    echo "서버 정보: " . $conn->server_info . "\n";
    echo "데이터베이스명: " . DB_NAME . "\n";
    echo "문자셋: " . $conn->character_set_name() . "\n\n";
    
    // 기본 테이블 존재 확인
    $required_tables = [
        'users', 'stores', 'products', 'inventory', 
        'brands', 'categories', 'suppliers', 'purchases'
    ];
    
    echo "=== 필수 테이블 확인 ===\n";
    foreach ($required_tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            echo "✅ 테이블 '$table' 존재\n";
        } else {
            echo "❌ 테이블 '$table' 없음\n";
        }
    }
    
    // 기본 사용자 확인
    echo "\n=== 관리자 계정 확인 ===\n";
    $admin_check = $conn->query("SELECT id, username, role FROM users WHERE role IN ('super_admin', 'admin') LIMIT 3");
    if ($admin_check && $admin_check->num_rows > 0) {
        while ($admin = $admin_check->fetch_assoc()) {
            echo "👤 사용자: {$admin['username']} (역할: {$admin['role']})\n";
        }
    } else {
        echo "⚠️ 관리자 계정이 없습니다. 초기 계정 생성이 필요합니다.\n";
    }
    
    // 점포 정보 확인
    echo "\n=== 점포 정보 확인 ===\n";
    $store_check = $conn->query("SELECT id, name FROM stores LIMIT 3");
    if ($store_check && $store_check->num_rows > 0) {
        while ($store = $store_check->fetch_assoc()) {
            echo "🏪 점포: {$store['name']} (ID: {$store['id']})\n";
        }
    } else {
        echo "⚠️ 점포 정보가 없습니다. 초기 점포 생성이 필요합니다.\n";
    }
    
    $conn->close();
    echo "\n✅ 데이터베이스 테스트 완료!\n";
    
} catch (Exception $e) {
    echo "❌ 데이터베이스 연결 실패: " . $e->getMessage() . "\n";
    echo "설정을 확인해주세요: config/db_config.php\n";
}
?>
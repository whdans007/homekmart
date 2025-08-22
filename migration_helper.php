<?php
/**
 * 데이터베이스 마이그레이션 도우미
 * 운영 서버에서 마이그레이션 상태 확인 및 후처리 작업
 */

echo "<!DOCTYPE html>";
echo "<html lang='ko'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<title>마이그레이션 도우미</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }";
echo ".container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }";
echo ".success { color: #22c55e; }";
echo ".error { color: #ef4444; }";
echo ".warning { color: #f59e0b; }";
echo ".info { color: #3b82f6; }";
echo ".section { margin: 20px 0; padding: 15px; border-left: 4px solid #3b82f6; background: #f8f9ff; }";
echo "table { width: 100%; border-collapse: collapse; margin: 10px 0; }";
echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
echo "th { background-color: #f2f2f2; }";
echo ".btn { display: inline-block; padding: 8px 15px; margin: 5px; background: #3b82f6; color: white; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; }";
echo ".btn:hover { background: #2563eb; }";
echo ".btn-danger { background: #ef4444; }";
echo ".btn-danger:hover { background: #dc2626; }";
echo "</style>";
echo "</head>";
echo "<body>";
echo "<div class='container'>";
echo "<h1>🔄 마이그레이션 도우미</h1>";

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        echo "<p class='error'>❌ 데이터베이스 연결 실패: " . $conn->connect_error . "</p>";
        exit;
    }
    
    $conn->set_charset('utf8mb4');
    echo "<p class='success'>✅ 운영 데이터베이스 연결 성공</p>";
    
    // 액션 처리
    $action = $_GET['action'] ?? '';
    
    if ($action === 'fix_passwords') {
        // 비밀번호 해시 수정
        $password_hash = password_hash('password', PASSWORD_DEFAULT);
        $result = $conn->query("UPDATE users SET password = '{$password_hash}'");
        
        if ($result) {
            echo "<div class='section'>";
            echo "<p class='success'>✅ 모든 사용자 비밀번호를 'password'로 재설정했습니다.</p>";
            echo "</div>";
        }
    }
    
    if ($action === 'update_settings') {
        // 운영 환경용 설정 업데이트
        $settings_updates = [
            ['currency_symbol', '₩'],
            ['currency_code', 'KRW'],
            ['company_name', 'HOME K MART'],
            ['default_language', 'ko'],
            ['enable_barcode_system', 'true'],
            ['enable_wholesale_system', 'true']
        ];
        
        foreach ($settings_updates as $setting) {
            $key = $setting[0];
            $value = $setting[1];
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('{$key}', '{$value}') ON DUPLICATE KEY UPDATE setting_value = '{$value}'");
        }
        
        echo "<div class='section'>";
        echo "<p class='success'>✅ 시스템 설정을 운영 환경용으로 업데이트했습니다.</p>";
        echo "</div>";
    }
    
    // 현재 상태 확인
    echo "<div class='section'>";
    echo "<h2>📊 마이그레이션 상태 확인</h2>";
    
    // 테이블 목록과 데이터 개수
    $tables_result = $conn->query("SHOW TABLES");
    if ($tables_result) {
        echo "<table>";
        echo "<tr><th>테이블명</th><th>레코드 수</th><th>상태</th></tr>";
        
        $total_tables = 0;
        $total_records = 0;
        
        while ($row = $tables_result->fetch_array()) {
            $table = $row[0];
            $count_result = $conn->query("SELECT COUNT(*) as count FROM `{$table}`");
            $count = $count_result ? $count_result->fetch_assoc()['count'] : 0;
            
            $status = $count > 0 ? "<span class='success'>✅ 데이터 있음</span>" : "<span class='warning'>⚠️ 데이터 없음</span>";
            
            echo "<tr>";
            echo "<td>{$table}</td>";
            echo "<td>{$count}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
            
            $total_tables++;
            $total_records += $count;
        }
        
        echo "</table>";
        echo "<p><strong>총 {$total_tables}개 테이블, {$total_records}개 레코드</strong></p>";
    }
    echo "</div>";
    
    // 사용자 계정 확인
    echo "<div class='section'>";
    echo "<h2>👤 사용자 계정 확인</h2>";
    
    $users_result = $conn->query("SELECT id, username, email, role, store_id, is_active FROM users ORDER BY id");
    if ($users_result && $users_result->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>사용자명</th><th>이메일</th><th>역할</th><th>점포</th><th>상태</th></tr>";
        
        while ($user = $users_result->fetch_assoc()) {
            $status = $user['is_active'] ? "<span class='success'>활성</span>" : "<span class='error'>비활성</span>";
            $store = $user['store_id'] ? "점포 {$user['store_id']}" : "미지정";
            
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['username']}</td>";
            echo "<td>{$user['email']}</td>";
            echo "<td>{$user['role']}</td>";
            echo "<td>{$store}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<p class='info'>💡 모든 계정의 기본 비밀번호: <strong>password</strong></p>";
        echo "<a href='?action=fix_passwords' class='btn'>🔒 비밀번호 재설정</a>";
    } else {
        echo "<p class='error'>❌ 사용자 계정이 없습니다. 마이그레이션을 다시 확인하세요.</p>";
    }
    echo "</div>";
    
    // 점포 정보 확인
    echo "<div class='section'>";
    echo "<h2>🏪 점포 정보 확인</h2>";
    
    $stores_result = $conn->query("SELECT id, name, address, phone, manager, is_active FROM stores ORDER BY id");
    if ($stores_result && $stores_result->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>점포명</th><th>주소</th><th>전화번호</th><th>관리자</th><th>상태</th></tr>";
        
        while ($store = $stores_result->fetch_assoc()) {
            $status = $store['is_active'] ? "<span class='success'>운영중</span>" : "<span class='error'>중지</span>";
            
            echo "<tr>";
            echo "<td>{$store['id']}</td>";
            echo "<td>{$store['name']}</td>";
            echo "<td>{$store['address']}</td>";
            echo "<td>{$store['phone']}</td>";
            echo "<td>{$store['manager']}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>❌ 점포 정보가 없습니다.</p>";
    }
    echo "</div>";
    
    // 상품 및 재고 확인
    echo "<div class='section'>";
    echo "<h2>📦 상품 및 재고 현황</h2>";
    
    $product_stats = $conn->query("
        SELECT 
            COUNT(p.id) as total_products,
            COUNT(i.id) as inventory_items,
            SUM(i.quantity) as total_quantity,
            AVG(i.selling_price) as avg_price
        FROM products p 
        LEFT JOIN inventory i ON p.id = i.product_id
    ");
    
    if ($product_stats) {
        $stats = $product_stats->fetch_assoc();
        echo "<ul>";
        echo "<li><strong>총 상품 수</strong>: {$stats['total_products']}개</li>";
        echo "<li><strong>재고 항목</strong>: {$stats['inventory_items']}개</li>";
        echo "<li><strong>총 재고량</strong>: " . number_format($stats['total_quantity']) . "개</li>";
        echo "<li><strong>평균 판매가</strong>: ₩" . number_format($stats['avg_price']) . "</li>";
        echo "</ul>";
    }
    echo "</div>";
    
    // 시스템 설정 확인
    echo "<div class='section'>";
    echo "<h2>⚙️ 시스템 설정 확인</h2>";
    
    $settings_result = $conn->query("SELECT setting_key, setting_value, description FROM system_settings ORDER BY setting_key");
    if ($settings_result && $settings_result->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>설정 키</th><th>값</th><th>설명</th></tr>";
        
        while ($setting = $settings_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$setting['setting_key']}</td>";
            echo "<td>{$setting['setting_value']}</td>";
            echo "<td>{$setting['description']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<a href='?action=update_settings' class='btn'>🔧 운영 환경 설정 적용</a>";
    } else {
        echo "<p class='warning'>⚠️ 시스템 설정이 없습니다.</p>";
    }
    echo "</div>";
    
    // 마이그레이션 후 체크리스트
    echo "<div class='section'>";
    echo "<h2>✅ 마이그레이션 후 체크리스트</h2>";
    echo "<ul>";
    echo "<li>[ ] 모든 테이블이 정상적으로 생성되었는가?</li>";
    echo "<li>[ ] 사용자 계정들이 정상적으로 이전되었는가?</li>";
    echo "<li>[ ] 상품 및 재고 데이터가 정확한가?</li>";
    echo "<li>[ ] 점포 정보가 올바른가?</li>";
    echo "<li>[ ] 로그인이 정상적으로 작동하는가?</li>";
    echo "<li>[ ] 주요 기능들이 정상 동작하는가?</li>";
    echo "<li>[ ] 비밀번호를 운영용으로 변경했는가?</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section'>";
    echo "<h2>🔗 추가 도구</h2>";
    echo "<a href='deployment_test.php' class='btn'>🧪 시스템 검증 테스트</a>";
    echo "<a href='public/login.php' class='btn'>🔐 로그인 페이지</a>";
    echo "<a href='?action=clear_sessions' class='btn btn-danger'>🗑️ 세션 정리</a>";
    echo "</div>";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p class='error'>❌ 오류 발생: " . $e->getMessage() . "</p>";
}

echo "</div>";
echo "</body>";
echo "</html>";
?>
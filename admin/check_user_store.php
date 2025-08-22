<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';

// 관리자만 접근 가능
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    die('관리자만 접근 가능합니다.');
}

echo "<h2>사용자 점포 정보 확인</h2>";
echo "<style>body { font-family: Arial, sans-serif; } table { border-collapse: collapse; width: 100%; } th, td { border: 1px solid #ddd; padding: 8px; text-align: left; } th { background-color: #f2f2f2; }</style>";

echo "<h3>현재 세션 정보</h3>";
echo "<table>";
echo "<tr><th>키</th><th>값</th></tr>";
foreach ($_SESSION as $key => $value) {
    echo "<tr><td>" . htmlspecialchars($key) . "</td><td>" . htmlspecialchars($value) . "</td></tr>";
}
echo "</table>";

$conn = get_db_connection();

// 현재 사용자 정보 조회
if (isset($_SESSION['user_id'])) {
    echo "<h3>사용자 데이터베이스 정보</h3>";
    $user_stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    if ($user = $user_result->fetch_assoc()) {
        echo "<table>";
        echo "<tr><th>필드</th><th>값</th></tr>";
        foreach ($user as $field => $value) {
            echo "<tr><td>" . htmlspecialchars($field) . "</td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
        }
        echo "</table>";
        
        // store_id가 있다면 점포 정보도 조회
        if (!empty($user['store_id'])) {
            echo "<h3>점포 정보</h3>";
            $store_stmt = $conn->prepare("SELECT * FROM stores WHERE id = ?");
            $store_stmt->bind_param("i", $user['store_id']);
            $store_stmt->execute();
            $store_result = $store_stmt->get_result();
            
            if ($store = $store_result->fetch_assoc()) {
                echo "<table>";
                echo "<tr><th>필드</th><th>값</th></tr>";
                foreach ($store as $field => $value) {
                    echo "<tr><td>" . htmlspecialchars($field) . "</td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
                }
                echo "</table>";
            } else {
                echo "<p>점포 정보를 찾을 수 없습니다.</p>";
            }
        } else {
            echo "<p>사용자에게 할당된 점포가 없습니다.</p>";
        }
    } else {
        echo "<p>사용자 정보를 찾을 수 없습니다.</p>";
    }
} else {
    echo "<p>로그인되지 않았습니다.</p>";
}

// 모든 점포 목록 조회
echo "<h3>전체 점포 목록</h3>";
$stores_stmt = $conn->query("SELECT * FROM stores ORDER BY name");
if ($stores_stmt && $stores_stmt->num_rows > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>점포명</th><th>주소</th><th>상태</th></tr>";
    while ($store = $stores_stmt->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($store['id']) . "</td>";
        echo "<td>" . htmlspecialchars($store['name']) . "</td>";
        echo "<td>" . htmlspecialchars($store['address'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($store['status'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>점포 정보가 없습니다.</p>";
}

echo "<br><a href='purchase_price_change.php?purchase_id=" . ($_GET['purchase_id'] ?? '20') . "'>가격변동 페이지로 돌아가기</a>";

$conn->close();
?>
<?php
/**
 * 권한 시스템 테스트 페이지
 */

session_start();
require_once __DIR__ . '/lib/session_helper.php';
require_once __DIR__ . '/lib/permission_helper.php';
require_once __DIR__ . '/lib/lang_helper.php';
require_once __DIR__ . '/config/db_config.php';

// 로그인 확인
if (!is_logged_in()) {
    echo "<h1>❌ 로그인이 필요합니다</h1>";
    echo "<p>먼저 <a href='public/login.php'>로그인</a>해주세요.</p>";
    exit;
}

$user_info = get_user_info();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>권한 시스템 테스트</title>
    <link href="public/css/style.css" rel="stylesheet">
    <style>
        .test-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
            font-family: Arial, sans-serif;
        }
        .test-section {
            margin-bottom: 2rem;
            padding: 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .permission-item {
            padding: 0.75rem;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .permission-allowed {
            background: #d1fae5;
            color: #065f46;
        }
        .permission-denied {
            background: #fee2e2;
            color: #991b1b;
        }
        .user-info {
            background: #f3f4f6;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .role-super_admin { background: #dc2626; color: white; }
        .role-admin { background: #ea580c; color: white; }
        .role-staff { background: #0891b2; color: white; }
        .role-office_staff { background: #7c3aed; color: white; }
        .role-user { background: #6b7280; color: white; }
    </style>
</head>
<body>
    <div class="test-container">
        <h1>🔐 권한 시스템 테스트</h1>
        
        <!-- 현재 사용자 정보 -->
        <div class="user-info">
            <h3>👤 현재 사용자 정보</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div><strong>사용자명:</strong> <?php echo htmlspecialchars($user_info['username']); ?></div>
                <div><strong>이름:</strong> <?php echo htmlspecialchars($user_info['full_name'] ?? $user_info['name'] ?? '미설정'); ?></div>
                <div><strong>역할:</strong> 
                    <span class="role-badge role-<?php echo $user_info['role']; ?>">
                        <?php echo $user_info['role']; ?>
                    </span>
                </div>
                <div><strong>점포:</strong> <?php echo htmlspecialchars($user_info['store_name'] ?? '미할당'); ?></div>
            </div>
        </div>

        <!-- 권한 테스트 -->
        <div class="test-section">
            <h3>🎯 개별 권한 확인</h3>
            <div class="permission-grid">
                <?php
                $permissions_to_test = [
                    'admin_access' => '관리자 접근',
                    'user_management' => '사용자 관리',
                    'store_management' => '점포 관리',
                    'product_management' => '상품 관리',
                    'purchase_management' => '매입 관리',
                    'brand_management' => '브랜드 관리',
                    'category_management' => '카테고리 관리',
                    'supplier_management' => '공급업체 관리',
                    'settings' => '시스템 설정',
                    'shop_access' => 'POS 접근',
                    'barcode_management' => '바코드 관리',
                    'accounting_management' => '회계 관리'
                ];

                foreach ($permissions_to_test as $permission => $description) {
                    $has_permission = has_permission($permission);
                    $class = $has_permission ? 'permission-allowed' : 'permission-denied';
                    $icon = $has_permission ? '✅' : '❌';
                    
                    echo "<div class='permission-item {$class}'>";
                    echo "<span><strong>{$description}</strong><br><small>{$permission}</small></span>";
                    echo "<span>{$icon}</span>";
                    echo "</div>";
                }
                ?>
            </div>
        </div>

        <!-- 역할별 기본 권한 확인 -->
        <div class="test-section">
            <h3>👑 역할별 기본 권한 시스템</h3>
            <div style="background: #f9fafb; padding: 1rem; border-radius: 8px;">
                <?php
                $role = $user_info['role'];
                echo "<h4>현재 역할: <span class='role-badge role-{$role}'>{$role}</span></h4>";
                
                $role_descriptions = [
                    'super_admin' => '모든 권한 (시스템 최고 관리자)',
                    'admin' => '점포 관리를 제외한 대부분 권한',
                    'staff' => 'POS 접근 및 바코드 관리만',
                    'office_staff' => 'POS, 바코드, 회계 관리',
                    'user' => '기본 POS 접근만'
                ];
                
                echo "<p><strong>역할 설명:</strong> " . ($role_descriptions[$role] ?? '알 수 없는 역할') . "</p>";
                ?>
            </div>
        </div>

        <!-- JSON 권한 시스템 확인 -->
        <div class="test-section">
            <h3>📋 JSON 개별 권한 설정</h3>
            <?php
            $user_permissions = get_user_permissions($user_info['id']);
            if (!empty($user_permissions)) {
                echo "<div style='background: #f0f9ff; padding: 1rem; border-radius: 8px;'>";
                echo "<h4>사용자 정의 권한:</h4>";
                echo "<pre style='background: white; padding: 1rem; border-radius: 4px; overflow-x: auto;'>";
                echo json_encode($user_permissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                echo "</pre>";
                echo "</div>";
            } else {
                echo "<div style='background: #fffbeb; padding: 1rem; border-radius: 8px;'>";
                echo "<p>⚠️ JSON 개별 권한이 설정되지 않았습니다. 역할 기반 권한만 적용됩니다.</p>";
                echo "</div>";
            }
            ?>
        </div>

        <!-- 권한 함수 테스트 -->
        <div class="test-section">
            <h3>🧪 권한 함수 테스트</h3>
            <div style="background: #f9fafb; padding: 1rem; border-radius: 8px;">
                <h4>테스트 결과:</h4>
                <ul>
                    <li><strong>is_logged_in():</strong> <?php echo is_logged_in() ? '✅ true' : '❌ false'; ?></li>
                    <li><strong>get_user_role():</strong> <?php echo get_user_role(); ?></li>
                    <li><strong>get_user_store_id():</strong> <?php echo get_user_store_id() ?? 'null'; ?></li>
                    <li><strong>has_role('admin'):</strong> <?php echo has_role('admin') ? '✅ true' : '❌ false'; ?></li>
                    <li><strong>has_role('super_admin'):</strong> <?php echo has_role('super_admin') ? '✅ true' : '❌ false'; ?></li>
                </ul>
            </div>
        </div>

        <!-- 보안 테스트 -->
        <div class="test-section">
            <h3>🛡️ 보안 확인</h3>
            <div style="background: #fef3c7; padding: 1rem; border-radius: 8px;">
                <h4>보안 상태:</h4>
                <ul>
                    <li><strong>세션 보안:</strong> 
                        <?php echo session_status() === PHP_SESSION_ACTIVE ? '✅ 활성화' : '❌ 비활성화'; ?>
                    </li>
                    <li><strong>CSRF 토큰:</strong> 
                        <?php echo isset($_SESSION['csrf_token']) ? '✅ 설정됨' : '⚠️ 미설정'; ?>
                    </li>
                    <li><strong>권한 검증 함수:</strong> 
                        <?php echo function_exists('has_permission') ? '✅ 정상' : '❌ 오류'; ?>
                    </li>
                </ul>
            </div>
        </div>

        <div style="margin-top: 2rem; text-align: center;">
            <a href="public/index.php" class="bg-blue-500 text-white px-6 py-3 rounded hover:bg-blue-600" style="text-decoration: none; display: inline-block;">
                메인 대시보드로 이동
            </a>
        </div>
    </div>
</body>
</html>
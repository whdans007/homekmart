<?php
/**
 * 개발환경 준비 상태 종합 체크
 */

session_start();
require_once __DIR__ . '/lib/lang_helper.php';
require_once __DIR__ . '/config/db_config.php';

// 언어 설정 (기본값)
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'ko';
}

function checkStatus($condition, $success_msg, $error_msg) {
    if ($condition) {
        return "<span class='status-success'>✅ {$success_msg}</span>";
    } else {
        return "<span class='status-error'>❌ {$error_msg}</span>";
    }
}

function getFileSize($filepath) {
    if (file_exists($filepath)) {
        $size = filesize($filepath);
        if ($size > 1024 * 1024) {
            return number_format($size / (1024 * 1024), 2) . ' MB';
        } elseif ($size > 1024) {
            return number_format($size / 1024, 2) . ' KB';
        } else {
            return $size . ' bytes';
        }
    }
    return 'N/A';
}

// 데이터베이스 연결 테스트
$db_status = false;
$db_tables = [];
$db_users = [];
$db_stores = [];

try {
    $conn = get_db_connection();
    $db_status = true;
    
    // 테이블 확인
    $required_tables = ['users', 'stores', 'products', 'inventory', 'brands', 'categories', 'suppliers', 'purchases'];
    foreach ($required_tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        $db_tables[$table] = ($result && $result->num_rows > 0);
    }
    
    // 사용자 수 확인
    $user_count = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
    $admin_count = $conn->query("SELECT COUNT(*) as count FROM users WHERE role IN ('super_admin', 'admin')")->fetch_assoc()['count'];
    
    // 점포 수 확인
    $store_count = $conn->query("SELECT COUNT(*) as count FROM stores")->fetch_assoc()['count'];
    
    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>개발환경 준비 상태 체크</title>
    <link href="public/css/style.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .check-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            padding: 1.5rem;
        }
        .check-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .check-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid transparent;
        }
        .check-item.success { border-left-color: #28a745; }
        .check-item.error { border-left-color: #dc3545; }
        .check-item.warning { border-left-color: #ffc107; }
        .status-success { color: #28a745; font-weight: 600; }
        .status-error { color: #dc3545; font-weight: 600; }
        .status-warning { color: #e67e22; font-weight: 600; }
        .header {
            text-align: center;
            margin-bottom: 3rem;
            padding: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .summary-number {
            font-size: 2rem;
            font-weight: bold;
            color: #495057;
        }
        .summary-label {
            color: #6c757d;
            margin-top: 0.5rem;
        }
        .action-buttons {
            text-align: center;
            margin-top: 2rem;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            margin: 0 0.5rem;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #1e7e34; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #545b62; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container">
        <div class="header">
            <h1 style="margin: 0; font-size: 2.5rem;">🚀 HOME K MART</h1>
            <h2 style="margin: 0.5rem 0 0 0; font-size: 1.5rem; opacity: 0.9;">개발환경 준비 상태 체크</h2>
            <p style="margin: 0.5rem 0 0 0; opacity: 0.8;">데이터베이스: <?php echo DB_NAME; ?> | 언어: <?php echo get_language(); ?></p>
        </div>

        <!-- 요약 정보 -->
        <div class="summary">
            <div class="summary-card">
                <div class="summary-number"><?php echo $db_status ? '✅' : '❌'; ?></div>
                <div class="summary-label">데이터베이스 연결</div>
            </div>
            <div class="summary-card">
                <div class="summary-number"><?php echo count(array_filter($db_tables)); ?>/<?php echo count($db_tables); ?></div>
                <div class="summary-label">필수 테이블</div>
            </div>
            <div class="summary-card">
                <div class="summary-number"><?php echo isset($user_count) ? $user_count : '0'; ?></div>
                <div class="summary-label">사용자 계정</div>
            </div>
            <div class="summary-card">
                <div class="summary-number"><?php echo isset($store_count) ? $store_count : '0'; ?></div>
                <div class="summary-label">등록된 점포</div>
            </div>
        </div>

        <!-- 1. 환경 기본 설정 -->
        <div class="check-section">
            <h3>🔧 환경 기본 설정</h3>
            <div class="check-grid">
                <div class="check-item <?php echo file_exists(__DIR__ . '/package.json') ? 'success' : 'error'; ?>">
                    <span>Node.js 설정 파일</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/package.json'),
                        'package.json 존재',
                        'package.json 없음'
                    ); ?>
                </div>
                
                <div class="check-item <?php echo is_dir(__DIR__ . '/node_modules') ? 'success' : 'error'; ?>">
                    <span>Node.js 의존성</span>
                    <?php echo checkStatus(
                        is_dir(__DIR__ . '/node_modules'),
                        'node_modules 설치됨',
                        'npm install 필요'
                    ); ?>
                </div>

                <div class="check-item <?php echo file_exists(__DIR__ . '/tailwind.config.js') ? 'success' : 'error'; ?>">
                    <span>TailwindCSS 설정</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/tailwind.config.js'),
                        'tailwind.config.js 존재',
                        '설정 파일 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo extension_loaded('mysqli') ? 'success' : 'error'; ?>">
                    <span>PHP MySQLi 확장</span>
                    <?php echo checkStatus(
                        extension_loaded('mysqli'),
                        'MySQLi 활성화됨',
                        'MySQLi 확장 필요'
                    ); ?>
                </div>
            </div>
        </div>

        <!-- 2. CSS 빌드 시스템 -->
        <div class="check-section">
            <h3>🎨 CSS 빌드 시스템</h3>
            <div class="check-grid">
                <div class="check-item <?php echo file_exists(__DIR__ . '/src/input.css') ? 'success' : 'error'; ?>">
                    <span>CSS 소스 파일</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/src/input.css'),
                        'src/input.css 존재',
                        '소스 파일 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo file_exists(__DIR__ . '/public/css/style.css') ? 'success' : 'warning'; ?>">
                    <span>빌드된 CSS 파일</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/public/css/style.css'),
                        'style.css 존재 (' . getFileSize(__DIR__ . '/public/css/style.css') . ')',
                        'CSS 빌드 필요'
                    ); ?>
                </div>

                <div class="check-item success">
                    <span>CSS 빌드 명령어</span>
                    <span class="status-success">✅ npm run build:css</span>
                </div>

                <div class="check-item success">
                    <span>CSS Watch 명령어</span>
                    <span class="status-success">✅ npm run watch:css</span>
                </div>
            </div>
        </div>

        <!-- 3. 데이터베이스 시스템 -->
        <div class="check-section">
            <h3>🗄️ 데이터베이스 시스템</h3>
            <div class="check-grid">
                <div class="check-item <?php echo $db_status ? 'success' : 'error'; ?>">
                    <span>데이터베이스 연결</span>
                    <?php if ($db_status): ?>
                        <span class="status-success">✅ 연결 성공</span>
                    <?php else: ?>
                        <span class="status-error">❌ 연결 실패<?php echo isset($db_error) ? ': ' . $db_error : ''; ?></span>
                    <?php endif; ?>
                </div>

                <?php foreach ($db_tables as $table => $exists): ?>
                <div class="check-item <?php echo $exists ? 'success' : 'error'; ?>">
                    <span><?php echo $table; ?> 테이블</span>
                    <?php echo checkStatus($exists, '존재', '없음'); ?>
                </div>
                <?php endforeach; ?>

                <?php if ($db_status): ?>
                <div class="check-item <?php echo isset($admin_count) && $admin_count > 0 ? 'success' : 'warning'; ?>">
                    <span>관리자 계정</span>
                    <span class="<?php echo isset($admin_count) && $admin_count > 0 ? 'status-success' : 'status-warning'; ?>">
                        <?php echo isset($admin_count) && $admin_count > 0 ? "✅ {$admin_count}개" : '⚠️ 없음'; ?>
                    </span>
                </div>

                <div class="check-item <?php echo isset($store_count) && $store_count > 0 ? 'success' : 'warning'; ?>">
                    <span>등록된 점포</span>
                    <span class="<?php echo isset($store_count) && $store_count > 0 ? 'status-success' : 'status-warning'; ?>">
                        <?php echo isset($store_count) && $store_count > 0 ? "✅ {$store_count}개" : '⚠️ 없음'; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 4. 다국어 시스템 -->
        <div class="check-section">
            <h3>🌐 다국어 시스템</h3>
            <div class="check-grid">
                <div class="check-item <?php echo file_exists(__DIR__ . '/lib/lang_helper.php') ? 'success' : 'error'; ?>">
                    <span>다국어 헬퍼</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/lib/lang_helper.php'),
                        'lang_helper.php 존재',
                        '헬퍼 파일 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo file_exists(__DIR__ . '/lang/ko.json') ? 'success' : 'error'; ?>">
                    <span>한국어 번역파일</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/lang/ko.json'),
                        'ko.json 존재 (' . getFileSize(__DIR__ . '/lang/ko.json') . ')',
                        '번역 파일 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo file_exists(__DIR__ . '/lang/en.json') ? 'success' : 'error'; ?>">
                    <span>영어 번역파일</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/lang/en.json'),
                        'en.json 존재 (' . getFileSize(__DIR__ . '/lang/en.json') . ')',
                        '번역 파일 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo function_exists('t') ? 'success' : 'error'; ?>">
                    <span>번역 함수</span>
                    <?php echo checkStatus(
                        function_exists('t'),
                        't() 함수 로드됨',
                        '번역 함수 없음'
                    ); ?>
                </div>

                <div class="check-item success">
                    <span>현재 언어</span>
                    <span class="status-success">✅ <?php echo get_language(); ?> (<?php echo get_supported_languages()[get_language()]; ?>)</span>
                </div>

                <div class="check-item <?php echo file_exists(__DIR__ . '/public/ajax_set_language.php') ? 'success' : 'error'; ?>">
                    <span>언어 변경 API</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/public/ajax_set_language.php'),
                        'ajax_set_language.php 존재',
                        'API 파일 없음'
                    ); ?>
                </div>
            </div>
        </div>

        <!-- 5. 권한 시스템 -->
        <div class="check-section">
            <h3>🔐 권한 시스템</h3>
            <div class="check-grid">
                <div class="check-item <?php echo file_exists(__DIR__ . '/lib/permission_helper.php') ? 'success' : 'error'; ?>">
                    <span>권한 헬퍼</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/lib/permission_helper.php'),
                        'permission_helper.php 존재',
                        '헬퍼 파일 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo file_exists(__DIR__ . '/lib/session_helper.php') ? 'success' : 'error'; ?>">
                    <span>세션 헬퍼</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/lib/session_helper.php'),
                        'session_helper.php 존재',
                        '헬퍼 파일 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo function_exists('has_permission') ? 'success' : 'error'; ?>">
                    <span>권한 확인 함수</span>
                    <?php echo checkStatus(
                        function_exists('has_permission'),
                        'has_permission() 로드됨',
                        '권한 함수 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo session_status() === PHP_SESSION_ACTIVE ? 'success' : 'warning'; ?>">
                    <span>세션 상태</span>
                    <?php echo checkStatus(
                        session_status() === PHP_SESSION_ACTIVE,
                        '세션 활성화됨',
                        '세션 비활성화'
                    ); ?>
                </div>
            </div>
        </div>

        <!-- 6. 핵심 헬퍼 라이브러리 -->
        <div class="check-section">
            <h3>📚 핵심 헬퍼 라이브러리</h3>
            <div class="check-grid">
                <div class="check-item <?php echo file_exists(__DIR__ . '/lib/margin_helper.php') ? 'success' : 'error'; ?>">
                    <span>마진 관리 헬퍼</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/lib/margin_helper.php'),
                        'margin_helper.php 존재',
                        '헬퍼 파일 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo is_dir(__DIR__ . '/vendor') ? 'success' : 'warning'; ?>">
                    <span>Composer 의존성</span>
                    <?php echo checkStatus(
                        is_dir(__DIR__ . '/vendor'),
                        'vendor 디렉토리 존재',
                        'composer install 필요'
                    ); ?>
                </div>

                <div class="check-item <?php echo file_exists(__DIR__ . '/public/partials/header.php') ? 'success' : 'error'; ?>">
                    <span>공통 헤더</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/public/partials/header.php'),
                        'header.php 존재',
                        '헤더 파일 없음'
                    ); ?>
                </div>

                <div class="check-item <?php echo file_exists(__DIR__ . '/public/partials/footer.php') ? 'success' : 'error'; ?>">
                    <span>공통 푸터</span>
                    <?php echo checkStatus(
                        file_exists(__DIR__ . '/public/partials/footer.php'),
                        'footer.php 존재',
                        '푸터 파일 없음'
                    ); ?>
                </div>
            </div>
        </div>

        <!-- 종합 상태 및 액션 버튼 -->
        <?php
        $total_checks = 0;
        $passed_checks = 0;
        
        // 기본 환경 점검
        $basic_checks = [
            file_exists(__DIR__ . '/package.json'),
            is_dir(__DIR__ . '/node_modules'),
            file_exists(__DIR__ . '/tailwind.config.js'),
            extension_loaded('mysqli')
        ];
        $total_checks += count($basic_checks);
        $passed_checks += count(array_filter($basic_checks));

        // CSS 시스템 점검
        $css_checks = [
            file_exists(__DIR__ . '/src/input.css'),
            file_exists(__DIR__ . '/public/css/style.css')
        ];
        $total_checks += count($css_checks);
        $passed_checks += count(array_filter($css_checks));

        // 데이터베이스 점검
        $total_checks += 1; // DB 연결
        $passed_checks += $db_status ? 1 : 0;
        $total_checks += count($db_tables);
        $passed_checks += count(array_filter($db_tables));

        // 다국어 시스템 점검
        $lang_checks = [
            file_exists(__DIR__ . '/lib/lang_helper.php'),
            file_exists(__DIR__ . '/lang/ko.json'),
            file_exists(__DIR__ . '/lang/en.json'),
            function_exists('t'),
            file_exists(__DIR__ . '/public/ajax_set_language.php')
        ];
        $total_checks += count($lang_checks);
        $passed_checks += count(array_filter($lang_checks));

        // 권한 시스템 점검
        $permission_checks = [
            file_exists(__DIR__ . '/lib/permission_helper.php'),
            file_exists(__DIR__ . '/lib/session_helper.php'),
            function_exists('has_permission'),
            session_status() === PHP_SESSION_ACTIVE
        ];
        $total_checks += count($permission_checks);
        $passed_checks += count(array_filter($permission_checks));

        $completion_rate = round(($passed_checks / $total_checks) * 100, 1);
        ?>

        <div class="check-section" style="text-align: center; background: <?php echo $completion_rate >= 90 ? '#d4edda' : ($completion_rate >= 70 ? '#fff3cd' : '#f8d7da'); ?>;">
            <h3>📊 종합 개발환경 상태</h3>
            <div style="font-size: 3rem; margin: 1rem 0;">
                <?php echo $completion_rate >= 90 ? '🎉' : ($completion_rate >= 70 ? '⚠️' : '❌'); ?>
            </div>
            <div style="font-size: 2rem; font-weight: bold; margin: 1rem 0;">
                <?php echo $passed_checks; ?> / <?php echo $total_checks; ?> 항목 통과 (<?php echo $completion_rate; ?>%)
            </div>
            <div style="margin: 1rem 0;">
                <?php if ($completion_rate >= 90): ?>
                    <strong style="color: #155724;">🚀 개발환경이 완전히 준비되었습니다!</strong>
                <?php elseif ($completion_rate >= 70): ?>
                    <strong style="color: #856404;">⚠️ 개발환경이 거의 준비되었습니다. 몇 가지 항목을 확인해주세요.</strong>
                <?php else: ?>
                    <strong style="color: #721c24;">❌ 개발환경 설정이 필요합니다.</strong>
                <?php endif; ?>
            </div>
        </div>

        <div class="action-buttons">
            <a href="test_multilang.php" class="btn btn-success">다국어 시스템 테스트</a>
            <a href="test_permissions.php" class="btn btn-secondary">권한 시스템 테스트</a>
            <a href="test_css_workflow.php" class="btn">CSS 워크플로우 테스트</a>
            <a href="public/login.php" class="btn">로그인 페이지</a>
            <a href="public/index.php" class="btn">메인 대시보드</a>
        </div>

        <div style="margin-top: 3rem; padding: 1.5rem; background: #f8f9fa; border-radius: 8px; text-align: center; color: #6c757d;">
            <h4>🛠️ 개발 시작하기</h4>
            <p>CSS 개발 시 <code>npm run watch:css</code> 명령어를 실행하여 자동 빌드를 활성화하세요.</p>
            <p>모든 시스템이 정상 작동하면 <strong>public/index.php</strong>에서 개발을 시작할 수 있습니다.</p>
        </div>
    </div>
</body>
</html>
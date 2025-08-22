<?php
/**
 * 다국어 시스템 테스트 페이지
 */

// 세션 시작 (언어 설정을 위해 필요)
session_start();

// 다국어 헬퍼 로드
require_once __DIR__ . '/lib/lang_helper.php';

// 언어 변경 처리
if (isset($_POST['language'])) {
    set_language($_POST['language']);
    header('Location: test_multilang.php');
    exit;
}

$current_lang = get_language();
$supported_langs = get_supported_languages();
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('common.home'); ?> - 다국어 테스트</title>
    <link href="public/css/style.css" rel="stylesheet">
    <style>
        .test-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
            font-family: Arial, sans-serif;
        }
        .lang-selector {
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f3f4f6;
            border-radius: 8px;
        }
        .test-section {
            margin-bottom: 2rem;
            padding: 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        .test-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }
        .test-item {
            padding: 0.5rem;
            background: #f9fafb;
            border-radius: 4px;
        }
        .success { color: #059669; }
        .error { color: #dc2626; }
    </style>
</head>
<body>
    <div class="test-container">
        <h1>🌐 다국어 시스템 테스트</h1>
        
        <!-- 언어 선택기 -->
        <div class="lang-selector">
            <h3>언어 선택 (Language Selection)</h3>
            <form method="post" style="display: flex; gap: 1rem; align-items: center;">
                <label>현재 언어: <strong><?php echo $supported_langs[$current_lang]; ?></strong></label>
                <select name="language">
                    <?php foreach ($supported_langs as $code => $name): ?>
                        <option value="<?php echo $code; ?>" <?php echo $code === $current_lang ? 'selected' : ''; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">언어 변경</button>
            </form>
        </div>

        <!-- 기본 번역 테스트 -->
        <div class="test-section">
            <h3>📝 기본 번역 키 테스트</h3>
            <div class="test-grid">
                <div class="test-item">
                    <strong>common.save:</strong> <?php echo t('common.save'); ?>
                </div>
                <div class="test-item">
                    <strong>common.cancel:</strong> <?php echo t('common.cancel'); ?>
                </div>
                <div class="test-item">
                    <strong>common.edit:</strong> <?php echo t('common.edit'); ?>
                </div>
                <div class="test-item">
                    <strong>common.delete:</strong> <?php echo t('common.delete'); ?>
                </div>
                <div class="test-item">
                    <strong>auth.login:</strong> <?php echo t('auth.login'); ?>
                </div>
                <div class="test-item">
                    <strong>auth.logout:</strong> <?php echo t('auth.logout'); ?>
                </div>
            </div>
        </div>

        <!-- 날짜 및 숫자 형식 테스트 -->
        <div class="test-section">
            <h3>📅 날짜 및 숫자 형식 테스트</h3>
            <div class="test-grid">
                <div class="test-item">
                    <strong>날짜 (짧은 형식):</strong> <?php echo format_date(time(), 'short'); ?>
                </div>
                <div class="test-item">
                    <strong>날짜 (긴 형식):</strong> <?php echo format_date(time(), 'long'); ?>
                </div>
                <div class="test-item">
                    <strong>숫자 형식:</strong> <?php echo format_number(1234567); ?>
                </div>
                <div class="test-item">
                    <strong>통화 형식:</strong> <?php echo format_currency(1234567); ?>
                </div>
            </div>
        </div>

        <!-- 파라미터 치환 테스트 -->
        <div class="test-section">
            <h3>🔄 파라미터 치환 테스트</h3>
            <div class="test-item">
                <strong>파라미터 있는 번역:</strong> 
                <?php echo t('common.save_success'); ?>
            </div>
        </div>

        <!-- JavaScript 연동 테스트 -->
        <div class="test-section">
            <h3>⚡ JavaScript 연동 테스트</h3>
            <button onclick="testJsTranslation()" class="bg-blue-500 text-white px-4 py-2 rounded">
                JavaScript 번역 테스트
            </button>
            <div id="js-result" class="mt-2 p-2 bg-gray-100 rounded"></div>
        </div>

        <!-- 시스템 상태 -->
        <div class="test-section">
            <h3>⚙️ 시스템 상태</h3>
            <div class="test-grid">
                <div class="test-item">
                    <strong>현재 언어:</strong> 
                    <span class="success"><?php echo $current_lang; ?></span>
                </div>
                <div class="test-item">
                    <strong>지원 언어 수:</strong> 
                    <span class="success"><?php echo count($supported_langs); ?>개</span>
                </div>
                <div class="test-item">
                    <strong>번역 파일 상태:</strong>
                    <?php 
                    $ko_exists = file_exists(__DIR__ . '/lang/ko.json');
                    $en_exists = file_exists(__DIR__ . '/lang/en.json');
                    if ($ko_exists && $en_exists) {
                        echo '<span class="success">✅ 정상</span>';
                    } else {
                        echo '<span class="error">❌ 파일 누락</span>';
                    }
                    ?>
                </div>
                <div class="test-item">
                    <strong>세션 언어 설정:</strong>
                    <span class="success"><?php echo $_SESSION['language'] ?? '미설정'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript 번역 스크립트 포함 -->
    <?php echo get_js_translation_script(['common.save', 'common.cancel', 'common.loading']); ?>

    <script>
        function testJsTranslation() {
            const result = document.getElementById('js-result');
            result.innerHTML = `
                <div><strong>JavaScript 번역 결과:</strong></div>
                <div>t('common.save'): ${t('common.save')}</div>
                <div>t('common.cancel'): ${t('common.cancel')}</div>
                <div>t('common.loading'): ${t('common.loading')}</div>
                <div>현재 언어: ${window.currentLanguage}</div>
            `;
        }
    </script>
</body>
</html>
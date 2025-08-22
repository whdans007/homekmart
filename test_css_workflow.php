<?php
/**
 * CSS 개발 워크플로우 테스트 페이지
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSS 개발 워크플로우 테스트</title>
    <link href="public/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <h1 class="text-4xl font-bold text-gray-800 mb-8 text-center">
                🎨 CSS 개발 워크플로우 테스트
            </h1>
            
            <!-- TailwindCSS 기본 클래스 테스트 -->
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">TailwindCSS 기본 클래스 테스트</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div class="bg-blue-500 text-white p-4 rounded">
                        <h3 class="font-bold">Blue Card</h3>
                        <p class="text-sm">파란색 카드 예시</p>
                    </div>
                    <div class="bg-green-500 text-white p-4 rounded">
                        <h3 class="font-bold">Green Card</h3>
                        <p class="text-sm">초록색 카드 예시</p>
                    </div>
                    <div class="bg-red-500 text-white p-4 rounded">
                        <h3 class="font-bold">Red Card</h3>
                        <p class="text-sm">빨간색 카드 예시</p>
                    </div>
                </div>
            </div>

            <!-- 버튼 스타일 테스트 -->
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">버튼 스타일 테스트</h2>
                <div class="flex flex-wrap gap-4">
                    <button class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded transition-colors">
                        Primary Button
                    </button>
                    <button class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition-colors">
                        Secondary Button
                    </button>
                    <button class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded transition-colors">
                        Success Button
                    </button>
                    <button class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-4 rounded transition-colors">
                        Danger Button
                    </button>
                </div>
            </div>

            <!-- 폼 요소 테스트 -->
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">폼 요소 스타일 테스트</h2>
                <form class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">텍스트 입력</label>
                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="텍스트를 입력하세요">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">선택 박스</label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option>옵션 1</option>
                            <option>옵션 2</option>
                            <option>옵션 3</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">텍스트 영역</label>
                        <textarea class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3" placeholder="긴 텍스트를 입력하세요"></textarea>
                    </div>
                </form>
            </div>

            <!-- 반응형 테스트 -->
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">반응형 디자인 테스트</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div class="bg-purple-100 p-4 rounded text-center">
                        <div class="text-purple-600 font-bold">Mobile</div>
                        <div class="text-sm text-gray-600">전체 너비</div>
                    </div>
                    <div class="bg-blue-100 p-4 rounded text-center">
                        <div class="text-blue-600 font-bold">Tablet</div>
                        <div class="text-sm text-gray-600">2열</div>
                    </div>
                    <div class="bg-green-100 p-4 rounded text-center">
                        <div class="text-green-600 font-bold">Desktop</div>
                        <div class="text-sm text-gray-600">3열</div>
                    </div>
                    <div class="bg-yellow-100 p-4 rounded text-center">
                        <div class="text-yellow-600 font-bold">Large</div>
                        <div class="text-sm text-gray-600">4열</div>
                    </div>
                </div>
            </div>

            <!-- 아이콘 및 유틸리티 테스트 -->
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-semibent text-gray-700 mb-4">아이콘 및 유틸리티 테스트</h2>
                <div class="flex flex-wrap items-center gap-6">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-home text-blue-500"></i>
                        <span class="text-gray-700">홈</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-user text-green-500"></i>
                        <span class="text-gray-700">사용자</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-cog text-gray-500"></i>
                        <span class="text-gray-700">설정</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-shopping-cart text-orange-500"></i>
                        <span class="text-gray-700">장바구니</span>
                    </div>
                </div>
            </div>

            <!-- CSS 빌드 상태 확인 -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">CSS 빌드 상태</h2>
                <div class="space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">CSS 파일 존재:</span>
                        <span class="text-green-600 font-semibold">
                            <?php echo file_exists(__DIR__ . '/public/css/style.css') ? '✅ 존재' : '❌ 없음'; ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">CSS 파일 크기:</span>
                        <span class="text-blue-600 font-semibold">
                            <?php 
                            $css_file = __DIR__ . '/public/css/style.css';
                            echo file_exists($css_file) ? number_format(filesize($css_file)) . ' bytes' : 'N/A'; 
                            ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">CSS 수정 시간:</span>
                        <span class="text-purple-600 font-semibold">
                            <?php 
                            $css_file = __DIR__ . '/public/css/style.css';
                            echo file_exists($css_file) ? date('Y-m-d H:i:s', filemtime($css_file)) : 'N/A'; 
                            ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- 개발 명령어 안내 -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-8">
                <h3 class="text-lg font-semibold text-blue-800 mb-2">💡 개발 명령어</h3>
                <div class="space-y-2 text-blue-700">
                    <div><code class="bg-blue-100 px-2 py-1 rounded">npm run build:css</code> - CSS 한 번 빌드</div>
                    <div><code class="bg-blue-100 px-2 py-1 rounded">npm run watch:css</code> - CSS 파일 변경 감지 및 자동 빌드</div>
                </div>
            </div>
        </div>
    </div>

    <!-- FontAwesome 아이콘 -->
    <script src="https://kit.fontawesome.com/your-kit-id.js" crossorigin="anonymous"></script>
</body>
</html>
/**
 * HOME K MART PWA Service Worker
 * 오프라인 지원 및 캐싱을 위한 서비스 워커
 */

const CACHE_NAME = 'homekmart-shop-v1.0.0';
const STATIC_CACHE = 'homekmart-static-v1';
const DYNAMIC_CACHE = 'homekmart-dynamic-v1';

// 캐시할 정적 리소스
const STATIC_FILES = [
    '/homekmart/shop/',
    '/homekmart/shop/index.php',
    '/homekmart/shop/js/app.js',
    '/homekmart/shop/assets/css/style.css',
    'https://unpkg.com/vue@3/dist/vue.global.js',
    'https://cdn.tailwindcss.com',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    '/homekmart/mobile/manifest.json'
];

// 캐시하지 않을 URL 패턴
const CACHE_BLACKLIST = [
    /\/api\//,           // API 요청은 항상 최신 데이터
    /\/admin\//,         // 관리자 페이지
    /\.(php|jsp|asp)$/   // 동적 서버 파일
];

/**
 * Service Worker 설치
 */
self.addEventListener('install', (event) => {
    console.log('[SW] Installing Service Worker...');
    
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => {
                console.log('[SW] Pre-caching static assets');
                return cache.addAll(STATIC_FILES);
            })
            .catch((error) => {
                console.error('[SW] Pre-caching failed:', error);
            })
    );
    
    // 새 서비스 워커를 즉시 활성화
    self.skipWaiting();
});

/**
 * Service Worker 활성화
 */
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating Service Worker...');
    
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        // 이전 버전의 캐시 삭제
                        if (cacheName !== STATIC_CACHE && 
                            cacheName !== DYNAMIC_CACHE && 
                            cacheName !== CACHE_NAME) {
                            console.log('[SW] Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
    );
    
    // 모든 클라이언트에서 새 서비스 워커 제어
    self.clients.claim();
});

/**
 * 네트워크 요청 가로채기
 */
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    
    // CORS 또는 외부 도메인 요청은 네트워크만 사용
    if (url.origin !== location.origin && !url.hostname.includes('cdn')) {
        return;
    }
    
    // 블랙리스트 URL은 캐시하지 않음
    if (CACHE_BLACKLIST.some(pattern => pattern.test(url.pathname))) {
        event.respondWith(
            fetch(request)
                .catch(() => {
                    // 네트워크 실패 시 오프라인 페이지 반환
                    return caches.match('/homekmart/shop/') || 
                           new Response('오프라인 상태입니다. 네트워크 연결을 확인해주세요.', {
                               headers: { 'Content-Type': 'text/html; charset=utf-8' }
                           });
                })
        );
        return;
    }
    
    // GET 요청만 캐시 처리
    if (request.method === 'GET') {
        event.respondWith(handleGetRequest(request));
    }
});

/**
 * GET 요청 처리 (캐시 우선 전략)
 */
async function handleGetRequest(request) {
    const url = new URL(request.url);
    
    try {
        // 1. 캐시에서 찾기
        const cachedResponse = await caches.match(request);
        
        // 정적 파일은 캐시 우선
        if (cachedResponse && isStaticAsset(url.pathname)) {
            return cachedResponse;
        }
        
        // 2. 네트워크 요청
        const networkResponse = await fetch(request);
        
        if (networkResponse.ok) {
            // 성공적인 응답을 캐시에 저장
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, networkResponse.clone());
            return networkResponse;
        }
        
        // 3. 네트워크 실패 시 캐시된 응답 반환
        if (cachedResponse) {
            return cachedResponse;
        }
        
        throw new Error('Network response was not ok');
        
    } catch (error) {
        console.log('[SW] Fetch failed:', error);
        
        // 캐시된 응답이 있으면 반환
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        
        // 메인 페이지 요청 실패 시 오프라인 메시지
        if (url.pathname.endsWith('/') || url.pathname.endsWith('.php')) {
            return new Response(`
                <!DOCTYPE html>
                <html lang="ko">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>HOME K MART - 오프라인</title>
                    <script src="https://cdn.tailwindcss.com"></script>
                </head>
                <body class="bg-gray-100 flex items-center justify-center min-h-screen">
                    <div class="text-center p-8">
                        <i class="fas fa-wifi-slash text-6xl text-gray-400 mb-4"></i>
                        <h1 class="text-2xl font-bold text-gray-800 mb-2">오프라인 상태</h1>
                        <p class="text-gray-600 mb-4">네트워크 연결을 확인하고 다시 시도해주세요.</p>
                        <button onclick="location.reload()" class="bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">
                            다시 시도
                        </button>
                    </div>
                </body>
                </html>
            `, {
                headers: { 'Content-Type': 'text/html; charset=utf-8' }
            });
        }
        
        // 기본 오프라인 응답
        return new Response('오프라인 상태입니다.', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        });
    }
}

/**
 * 정적 자산 확인
 */
function isStaticAsset(pathname) {
    const staticExtensions = ['.js', '.css', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2'];
    return staticExtensions.some(ext => pathname.endsWith(ext)) ||
           pathname.includes('cdn.') ||
           pathname.includes('fonts.');
}

/**
 * 백그라운드 동기화 (추후 구현)
 */
self.addEventListener('sync', (event) => {
    console.log('[SW] Background sync:', event.tag);
    
    if (event.tag === 'cart-sync') {
        event.waitUntil(syncCart());
    }
});

/**
 * 장바구니 동기화 (오프라인 -> 온라인 시)
 */
async function syncCart() {
    try {
        // 로컬에 저장된 오프라인 장바구니 데이터를 서버와 동기화
        const offlineCart = await getOfflineCart();
        if (offlineCart.length > 0) {
            await syncCartToServer(offlineCart);
            await clearOfflineCart();
            console.log('[SW] Cart synced successfully');
        }
    } catch (error) {
        console.error('[SW] Cart sync failed:', error);
    }
}

/**
 * 푸시 알림 처리 (추후 구현)
 */
self.addEventListener('push', (event) => {
    const options = {
        body: event.data ? event.data.text() : 'HOME K MART에서 새로운 소식이 있습니다!',
        icon: '/homekmart/shop/assets/images/icon-192x192.png',
        badge: '/homekmart/shop/assets/images/badge-72x72.png',
        vibrate: [200, 100, 200],
        data: {
            url: '/homekmart/shop/'
        },
        actions: [
            {
                action: 'open',
                title: '보기',
                icon: '/homekmart/shop/assets/images/open-icon.png'
            },
            {
                action: 'close',
                title: '닫기',
                icon: '/homekmart/shop/assets/images/close-icon.png'
            }
        ]
    };
    
    event.waitUntil(
        self.registration.showNotification('HOME K MART', options)
    );
});

/**
 * 알림 클릭 처리
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    
    if (event.action === 'open' || !event.action) {
        event.waitUntil(
            clients.openWindow(event.notification.data.url || '/homekmart/shop/')
        );
    }
});

// 헬퍼 함수들 (추후 구현)
async function getOfflineCart() {
    // IndexedDB에서 오프라인 장바구니 데이터 가져오기
    return [];
}

async function syncCartToServer(cartData) {
    // 서버와 장바구니 동기화
    return fetch('/homekmart/shop/api/cart/sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(cartData)
    });
}

async function clearOfflineCart() {
    // 오프라인 장바구니 데이터 삭제
    return Promise.resolve();
}
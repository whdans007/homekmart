<?php
/*
 * 쇼핑몰(mall/) 전역 설정
 * Design Ref: shopping-mall.design.md §4.1 Constraints — 단일 점포 기준, store_id 상수 고정
 */

// mall_get_db_connection()이 DB_HOST 등 접속 상수를 쓰므로, 어디서 require되든 항상 보장되게 여기서도 불러온다.
require_once __DIR__ . '/../../config/db_config.php';

// 쇼핑몰이 노출하는 재고/가격의 기준 점포. 다점포 선택 기능은 v1 범위 밖(Plan §1.4).
define('MALL_STORE_ID', 1);

// mall_members 세션을 관리자(admin) 세션과 분리하기 위한 별도 세션 이름/쿠키
define('MALL_SESSION_NAME', 'MALLSESSID');

// mall_drivers(배송기사) 세션을 mall_members/admin 세션과 분리하기 위한 별도 세션 이름/쿠키
// Design Ref: mall-delivery-dispatch.design.md §7 — 기사 세션 분리
define('MALL_DRIVER_SESSION_NAME', 'MALLDRIVERSESSID');

// mall/batch/recalc_tiers.php를 cron(HTTP)으로 호출할 때 사용하는 인증 키(X-Api-Key 헤더).
// CLI(php recalc_tiers.php) 실행 시에는 이 키가 필요 없다. 운영 전 반드시 강력한 값으로 교체할 것.
define('MALL_BATCH_API_KEY', 'mallbatch_change_this_to_a_strong_32char_secret');

// 구글 로그인(Sign in with Google) OAuth 클라이언트 ID.
// Google Cloud Console(console.cloud.google.com) > API 및 서비스 > 사용자 인증 정보에서
// "OAuth 클라이언트 ID"(유형: 웹 애플리케이션)를 발급받아 아래 값을 교체한다.
// 승인된 자바스크립트 원본(Authorized JavaScript origins)에 https://homekmart.net 등록 필요.
// ID 토큰만 검증하는 방식이라 클라이언트 시크릿은 필요 없다(공개해도 안전한 값).
define('MALL_GOOGLE_CLIENT_ID', '502848247391-99k1krmshe9el3408l7itpc1nd8hrni4.apps.googleusercontent.com');

// 배송지 지도 핀 입력(mall/address.php)에 쓰는 구글 지도 API 키.
// Google Cloud Console > API 및 서비스 > 사용자 인증 정보 > "API 키" 생성 후,
// Maps JavaScript API + Geocoding API를 활성화하고 아래 값을 교체한다.
// 결제수단(빌링) 등록이 필요하며, 키를 HTTP 리퍼러 제한(https://homekmart.net/*)으로 반드시 제한할 것
// — 이 키는 프론트엔드 <script> 태그에 그대로 노출되므로 리퍼러 제한이 없으면 다른 사이트에서 도용될 수 있다.
define('MALL_GOOGLE_MAPS_API_KEY', 'AIzaSyDikJKww3XN6xmh2F0NSL7eGZqbKkDiAEk');

/**
 * 기본 배송비 / 무료배송 기준금액. mall/admin/discount_rules.php에서 system_settings에 저장한
 * 값을 읽어온다 — 관리자가 값을 아직 저장하지 않았거나 DB 조회에 실패하면 아래 기본값을 쓴다.
 * 장바구니 화면의 무료배송 진행바 표시 전용(참고용 안내일 뿐, 실제 배송비 부과 로직은
 * 체크아웃 재설계 범위에서 다룬다 — 이번 범위에는 포함되지 않음).
 * @param string $key
 * @param float $default
 * @return float
 */
function mall_load_shipping_setting($key, $default) {
    try {
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (float)$row['setting_value'] : $default;
    } catch (\Throwable $e) {
        error_log('mall_load_shipping_setting error: ' . $e->getMessage());
        return $default;
    }
}

define('MALL_FREE_SHIPPING_THRESHOLD', mall_load_shipping_setting('mall_free_shipping_threshold', 1200));
define('MALL_BASE_SHIPPING_FEE', mall_load_shipping_setting('mall_base_shipping_fee', 79));

/**
 * mall_get_db_connection()이 재사용하는 커넥션 클래스 — close()를 무시해 요청 안에서 계속 살려둔다.
 * 진짜 종료는 PHP가 요청 끝에서 자동으로 처리한다(admin/의 get_db_connection()은 건드리지 않는다 —
 * 거기는 autocommit(false) 패턴을 쓰는 곳이 있을 수 있어 전역 재사용이 위험하다).
 */
class MallPooledConnection extends mysqli {
    public function close(): bool {
        return true;
    }
}

/**
 * mall/lib 상품 조회·가격 계산 함수들 전용 DB 커넥션 — 요청당 1개만 열고 재사용한다.
 * 배경: mall_build_product_cards()가 상품마다 mall_calculate_price()/mall_get_stock_quantity()를
 * 호출하는데, 그 함수들이 각자 get_db_connection()으로 새 커넥션을 열고 닫다 보니 상품이 많은
 * 페이지(카테고리/검색 전체보기)에서 한 요청에 수십~수백 개의 MySQL 커넥션이 열려 공유 호스팅의
 * 동시 접속 제한에 걸리곤 했다 — 카테고리 클릭 시 화면이 간헐적으로 비는 원인이었다.
 * @return mysqli
 */
function mall_get_db_connection() {
    static $conn = null;

    if ($conn instanceof mysqli) {
        try {
            if (@$conn->ping()) {
                return $conn;
            }
        } catch (\Throwable $e) {
            // 연결이 끊겼으면 아래에서 새로 연다.
        }
    }

    try {
        $conn = new MallPooledConnection(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    } catch (\Throwable $e) {
        error_log('mall_get_db_connection error: ' . $e->getMessage());
        throw new Exception('데이터베이스 연결에 실패했습니다: ' . $e->getMessage());
    }
    if ($conn->connect_error) {
        error_log('mall_get_db_connection error: ' . $conn->connect_error);
        throw new Exception('데이터베이스 연결에 실패했습니다: ' . $conn->connect_error);
    }
    $conn->set_charset(DB_CHARSET);
    $conn->query("SET time_zone = '+08:00'");

    return $conn;
}

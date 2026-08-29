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

// mall/batch/recalc_tiers.php를 cron(HTTP)으로 호출할 때 사용하는 인증 키(X-Api-Key 헤더).
// CLI(php recalc_tiers.php) 실행 시에는 이 키가 필요 없다. 운영 전 반드시 강력한 값으로 교체할 것.
define('MALL_BATCH_API_KEY', 'mallbatch_change_this_to_a_strong_32char_secret');

// 장바구니 화면의 무료배송 진행바 표시 전용 상수(참고용 안내일 뿐, 실제 배송비 부과 로직은
// 체크아웃 재설계 범위에서 다룬다 — 이번 범위에는 포함되지 않음).
define('MALL_FREE_SHIPPING_THRESHOLD', 1200);
define('MALL_BASE_SHIPPING_FEE', 79);

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

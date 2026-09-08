<?php
/**
 * 신선식품(과일/채소/정육/수산) 전용 마진 관리 헬퍼.
 * lib/margin_helper.php(정가상품용, PDO 기반)와는 별도로 유지한다 — 신선식품은
 * categories 테이블이 아닌 fresh_category ENUM 고정값을 쓰고, 기존 admin/fresh_*.php
 * 화면들과 동일하게 MySQLi(get_db_connection() 계열) 연결을 그대로 사용한다.
 */

const FRESH_DEFAULT_MARGIN_RATE = 40.0;

/**
 * 신선식품 카테고리별 마진율(%)을 조회한다. 행이 없으면 기본값 40%를 반환한다.
 * @param string $freshCategory 'fruit'|'vegetable'|'meat'|'seafood'
 */
function get_fresh_margin_rate(string $freshCategory, mysqli $conn): float
{
    $stmt = $conn->prepare('SELECT margin_percentage FROM fresh_margin_rules WHERE fresh_category = ?');
    $stmt->bind_param('s', $freshCategory);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (float)$row['margin_percentage'] : FRESH_DEFAULT_MARGIN_RATE;
}

/**
 * 신선식품 4개 카테고리의 마진율을 한 번에 조회한다 (마진 관리 화면용).
 * 저장된 행이 없는 카테고리는 기본값 40%로 채워서 반환한다.
 * @return array<string, float> 예: ['fruit' => 40.0, 'vegetable' => 40.0, ...]
 */
function get_all_fresh_margin_rates(mysqli $conn): array
{
    $rates = [
        'fruit' => FRESH_DEFAULT_MARGIN_RATE,
        'vegetable' => FRESH_DEFAULT_MARGIN_RATE,
        'meat' => FRESH_DEFAULT_MARGIN_RATE,
        'seafood' => FRESH_DEFAULT_MARGIN_RATE,
    ];

    $result = $conn->query('SELECT fresh_category, margin_percentage FROM fresh_margin_rules');
    if ($result) {
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $rates[$row['fresh_category']] = (float)$row['margin_percentage'];
        }
    }

    return $rates;
}

/**
 * 신선식품 카테고리 마진율을 저장(있으면 갱신, 없으면 등록)한다.
 * @param float $marginRate 0 이상 500 이하만 허용
 */
function save_fresh_margin_rate(string $freshCategory, float $marginRate, ?int $updatedBy, mysqli $conn): void
{
    $stmt = $conn->prepare(
        'INSERT INTO fresh_margin_rules (fresh_category, margin_percentage, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE margin_percentage = VALUES(margin_percentage), updated_by = VALUES(updated_by)'
    );
    $stmt->bind_param('sdi', $freshCategory, $marginRate, $updatedBy);
    $stmt->execute();
    $stmt->close();
}

/**
 * 원가에 마진율을 적용해 판매가를 계산한다.
 * 프로젝트 컨벤션(원가는 소숫점 둘째자리 유지)과 달리, 신선식품 판매가는
 * 사용자 확정 정책에 따라 정수 원 단위로 올림(ceil) 처리한다.
 * @param float $costPrice 원가 (박스당 원가 또는 단위원가)
 * @param float $marginRate 마진율(%)
 * @return float 정수 원 단위로 올림된 판매가
 */
function calculate_fresh_sale_price(float $costPrice, float $marginRate): float
{
    if ($costPrice <= 0) {
        return 0.0;
    }

    return ceil($costPrice * (1 + $marginRate / 100));
}

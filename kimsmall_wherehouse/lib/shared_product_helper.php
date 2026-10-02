<?php
/**
 * shared_product_helper.php — admin 공용 상품(products)과 창고 상품(kw_products) 연결 공용 라이브러리
 *
 * 규칙
 *  - 상품 이름/바코드(sku)의 원본은 admin products. 창고는 kw_products.product_id 로 연결한다.
 *  - 창고 전용 값(unit, pieces_per_box, min_stock, requires_expiry, 박스/물류 바코드, 분류)은 kw_products 에 둔다.
 *  - 낱개 바코드(barcode_unit)가 곧 products.sku 이다. 박스/물류 코드는 sku 로 쓰지 않는다.
 *  - kw_products_v / product_id 는 sql/run_migration_v23.php 적용 후 사용 가능.
 */

/**
 * admin products 에서 sku 정확일치로 한 건 조회.
 *
 * @return array|null [id, sku, name_en, name_ko, is_active, linked_kw_id]
 */
function kw_find_shared_product_by_sku(mysqli $conn, string $sku): ?array
{
    $sku = trim($sku);
    if ($sku === '') {
        return null;
    }
    $st = $conn->prepare(
        "SELECT p.id, p.sku, p.name_en, p.name_ko, p.is_active, p.pieces_per_box,
                (SELECT kw.id FROM kw_products kw WHERE kw.product_id = p.id LIMIT 1) AS linked_kw_id
         FROM products p
         WHERE BINARY p.sku = BINARY ?
         LIMIT 1"
    );
    $st->bind_param('s', $sku);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row;
}

/**
 * admin products 검색 (sku 부분일치 / 영문·한글 이름 부분일치).
 * 이미 창고에 연결된 상품은 linked_kw_id 로 표시한다.
 *
 * @return array[] [id, sku, name_en, name_ko, linked_kw_id]
 */
function kw_search_shared_products(mysqli $conn, string $q, int $limit = 20): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $like  = '%' . $q . '%';
    $limit = max(1, min(50, $limit));
    $st = $conn->prepare(
        "SELECT p.id, p.sku, p.name_en, p.name_ko,
                (SELECT kw.id FROM kw_products kw WHERE kw.product_id = p.id LIMIT 1) AS linked_kw_id
         FROM products p
         WHERE p.is_active = 1
           AND (p.sku LIKE ? OR p.name_en LIKE ? OR p.name_ko LIKE ?)
         ORDER BY (p.sku = ?) DESC, p.name_ko ASC
         LIMIT {$limit}"
    );
    $st->bind_param('ssss', $like, $like, $like, $q);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/**
 * 창고 상품 등록 (공용 상품 연결 또는 신규 생성).
 *
 * - barcode_unit 이 비어 있으면 연결 없이 창고 전용 상품으로만 등록한다(product_id NULL).
 * - barcode_unit 이 있으면:
 *     · admin products 에 같은 sku 가 있으면 그 상품에 연결 (admin 데이터는 수정하지 않음)
 *     · 없으면 admin products 에 새로 INSERT 한 뒤 연결
 *     · 이미 다른 창고 상품이 그 상품에 연결되어 있으면 실패
 * - admin INSERT 와 kw INSERT 는 하나의 트랜잭션으로 처리한다.
 *
 * @param array $d 창고 상품 값:
 *   name_en, name_ko, capacity, brand_id, category_id, unit, pieces_per_box,
 *   barcode_unit, barcode_box, barcode_logistics, min_stock, requires_expiry, image_path
 * @return array ['id'=>kw id, 'product_id'=>admin id|null, 'created_shared'=>bool, 'linked_existing'=>bool,
 *                'name_en'=>저장된 영문명, 'name_ko'=>저장된 한글명]
 * @throws RuntimeException 사용자에게 보여줄 수 있는 검증 오류
 */
function kw_register_product(mysqli $conn, array $d, int $uid): array
{
    $barcode_unit = trim((string)($d['barcode_unit'] ?? '')) ?: null;
    $name_en = trim((string)($d['name_en'] ?? ''));
    $name_ko = trim((string)($d['name_ko'] ?? '')) ?: null;

    $product_id      = null;
    $created_shared  = false;
    $linked_existing = false;

    $conn->begin_transaction();
    try {
        if ($barcode_unit !== null) {
            $shared = kw_find_shared_product_by_sku($conn, $barcode_unit);
            if ($shared) {
                if (!empty($shared['linked_kw_id'])) {
                    throw new RuntimeException("This barcode is already registered in the warehouse (product #{$shared['linked_kw_id']}).");
                }
                $product_id      = (int)$shared['id'];
                $linked_existing = true;
                // 창고 NOT NULL 컬럼(name_en)을 만족시키기 위해 공용 상품 이름으로 보충
                if ($name_en === '') {
                    $name_en = trim((string)($shared['name_en'] ?? '')) ?: trim((string)($shared['name_ko'] ?? ''));
                }
                if ($name_ko === null && !empty($shared['name_ko'])) {
                    $name_ko = $shared['name_ko'];
                }
            } else {
                if ($name_en === '') {
                    throw new RuntimeException('Please enter the English product name.');
                }
                // products.name_ko 는 NOT NULL — 한글명이 없으면 영문명으로 대체
                $ko_for_shared = $name_ko ?? $name_en;
                $st = $conn->prepare(
                    "INSERT INTO products (sku, name_ko, name_en, is_active, pieces_per_box, is_vat_applicable, last_modified_by_user_id)
                     VALUES (?, ?, ?, 1, 1, 1, ?)"
                );
                $uid_param = $uid > 0 ? $uid : null;
                $st->bind_param('sssi', $barcode_unit, $ko_for_shared, $name_en, $uid_param);
                $st->execute();
                $product_id     = (int)$conn->insert_id;
                $created_shared = true;
                $st->close();
            }
        }

        if ($name_en === '') {
            throw new RuntimeException('Please enter the English product name.');
        }

        $capacity          = $d['capacity'] ?? null;
        $brand_id          = $d['brand_id'] ?? null;
        $category_id       = $d['category_id'] ?? null;
        $unit              = $d['unit'];
        $pieces_per_box    = (int)$d['pieces_per_box'];
        $barcode_box       = $d['barcode_box'] ?? null;
        $barcode_logistics = $d['barcode_logistics'] ?? null;
        $min_stock         = (int)($d['min_stock'] ?? 0);
        $requires_expiry   = (int)($d['requires_expiry'] ?? 0);
        $image_path        = $d['image_path'] ?? null;

        $st = $conn->prepare(
            "INSERT INTO kw_products
             (product_id, name_en, name_ko, capacity, brand_id, category_id, unit, pieces_per_box,
              barcode_unit, barcode_box, barcode_logistics, min_stock, requires_expiry, image_path, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->bind_param('isssiisisssiisi',
            $product_id, $name_en, $name_ko, $capacity, $brand_id, $category_id,
            $unit, $pieces_per_box,
            $barcode_unit, $barcode_box, $barcode_logistics,
            $min_stock, $requires_expiry, $image_path, $uid
        );
        $st->execute();
        $new_id = (int)$conn->insert_id;
        $st->close();

        $uname = $_SESSION['name'] ?? ($_SESSION['username'] ?? 'Unknown');
        $sh = $conn->prepare(
            "INSERT INTO kw_product_history (product_id, user_id, user_name, action) VALUES (?,?,?,'create')"
        );
        $sh->bind_param('iis', $new_id, $uid, $uname);
        $sh->execute();
        $sh->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'id'              => $new_id,
        'product_id'      => $product_id,
        'created_shared'  => $created_shared,
        'linked_existing' => $linked_existing,
        'name_en'         => $name_en,
        'name_ko'         => $name_ko,
    ];
}

/**
 * 스캔/입력한 바코드가 창고에는 없고 admin products 에만 있을 때, 창고 연결 행을 자동 생성한다.
 * (기본값: unit=PCS, pieces_per_box=1, 활성, 유통기한 불필요 — 이후 상품 수정 화면에서 조정)
 *
 * @return int|null 생성된 kw_products.id (연결 대상이 없거나 이미 연결돼 있으면 null)
 */
function kw_autolink_shared_by_barcode(mysqli $conn, string $barcode, int $uid): ?int
{
    $barcode = trim($barcode);
    if (strlen($barcode) < 8) {
        return null;
    }
    $shared = kw_find_shared_product_by_sku($conn, $barcode);
    if (!$shared || !empty($shared['linked_kw_id']) || (int)$shared['is_active'] !== 1) {
        return null;
    }
    $name_en = trim((string)($shared['name_en'] ?? '')) ?: trim((string)($shared['name_ko'] ?? ''));
    if ($name_en === '') {
        return null;
    }
    try {
        $res = kw_register_product($conn, [
            'name_en'        => $name_en,
            'name_ko'        => $shared['name_ko'] ?: null,
            'unit'           => 'PCS',
            'pieces_per_box' => max(1, (int)($shared['pieces_per_box'] ?? 1)), // admin 상품의 박스당 포장 수량(PKG)
            'barcode_unit'   => $shared['sku'],
        ], $uid);
        return $res['id'];
    } catch (Throwable $e) {
        error_log('kw_autolink_shared_by_barcode: ' . $e->getMessage());
        return null;
    }
}

<?php

/**
 * Shared read helpers for the fresh-product administration screens.
 * Every database operation in this feature uses a prepared statement.
 */

/**
 * 고정 분류(과일/채소/정육/수산) — categories 테이블과 무관한 고정값.
 * DB 컬럼: mall_fresh_products.fresh_category ENUM('fruit','vegetable','meat','seafood')
 */
function fresh_category_options(): array
{
    return [
        'fruit' => t('mall_fresh_products.category_fruit'),
        'vegetable' => t('mall_fresh_products.category_vegetable'),
        'meat' => t('mall_fresh_products.category_meat'),
        'seafood' => t('mall_fresh_products.category_seafood'),
    ];
}

function fresh_category_label(string $code): string
{
    return fresh_category_options()[$code] ?? $code;
}

function fresh_admin_flash(string $type, string $message): void
{
    $_SESSION['fresh_flash'] = ['type' => $type, 'message' => $message];
}

function fresh_admin_take_flash(): ?array
{
    $flash = $_SESSION['fresh_flash'] ?? null;
    unset($_SESSION['fresh_flash']);
    return is_array($flash) ? $flash : null;
}

function fresh_admin_redirect(string $location): void
{
    header('Location: ' . $location);
    exit;
}

/**
 * 신선상품 매입 등록(다건 일괄) 화면에서 저장 실패 시 입력값을 세션에 임시 보관한다.
 * 새로고침/리다이렉트 후에도 사용자가 입력하던 목록을 잃지 않도록 함.
 */
function fresh_save_purchase_draft(int $supplierId, string $purchaseDate, string $itemsJson): void
{
    $_SESSION['fresh_purchase_draft'] = [
        'supplier_id' => $supplierId,
        'purchase_date' => $purchaseDate,
        'items_json' => $itemsJson,
    ];
}

function fresh_take_purchase_draft(): ?array
{
    $draft = $_SESSION['fresh_purchase_draft'] ?? null;
    unset($_SESSION['fresh_purchase_draft']);
    return is_array($draft) ? $draft : null;
}

/**
 * fresh_purchase_items 한 행의 구성/단위원가 표시 문자열을 계산한다.
 * 저울(weight) 매입과 낱개(piece) 매입 중 어느 쪽이 채워져 있는지에 따라 자동 분기.
 * @param array $row weight_kg, pieces_per_box, unit_cost_per_100g, unit_cost_per_piece 키를 포함해야 함
 * @return array{composition: string, unit_cost: string}
 */
function fresh_purchase_row_display(array $row): array
{
    if ($row['pieces_per_box'] !== null) {
        return [
            'composition' => number_format((int)$row['pieces_per_box']) . ' ' . t('common.items'),
            'unit_cost' => number_format((float)$row['unit_cost_per_piece'], 2) . ' / ' . t('mall_fresh_products.unit_cost_per_piece_label'),
        ];
    }
    if ($row['weight_kg'] !== null) {
        return [
            'composition' => number_format((float)$row['weight_kg'], 3) . 'kg',
            'unit_cost' => number_format((float)$row['unit_cost_per_100g'], 2) . ' / 100g',
        ];
    }
    return ['composition' => '-', 'unit_cost' => '-'];
}

<?php

/**
 * Shared read helpers for the mall fresh-product administration screens.
 * Every database operation in this feature uses a prepared statement.
 */

function fresh_admin_categories(mysqli $conn): array
{
    $sql = "SELECT c.id, c.name, c.name_en, c.parent_id,
                   CASE WHEN c.parent_id IS NULL THEN 0 ELSE 1 END AS depth
            FROM categories c
            LEFT JOIN categories parent ON parent.id = c.parent_id
            WHERE (
                c.parent_id IS NULL
                AND (c.name = ? OR LOWER(COALESCE(c.name_en, '')) IN (?, ?))
            ) OR (
                c.parent_id IS NOT NULL
                AND (parent.name = ? OR LOWER(COALESCE(parent.name_en, '')) IN (?, ?))
                AND (c.name IN (?, ?, ?, ?) OR LOWER(COALESCE(c.name_en, '')) IN (?, ?, ?, ?))
            )
            ORDER BY depth, c.sort_order, c.name";
    $stmt = $conn->prepare($sql);
    $freshKo = '신선식품';
    $freshEn = 'fresh food';
    $freshFoodsEn = 'fresh foods';
    $fruitKo = '과일';
    $vegetableKo = '채소';
    $meatKo = '정육';
    $seafoodKo = '수산물';
    $fruitEn = 'fruit';
    $vegetableEn = 'vegetable';
    $meatEn = 'meat';
    $seafoodEn = 'seafood';
    $stmt->bind_param(
        'ssssssssssssss',
        $freshKo, $freshEn, $freshFoodsEn,
        $freshKo, $freshEn, $freshFoodsEn,
        $fruitKo, $vegetableKo, $meatKo, $seafoodKo,
        $fruitEn, $vegetableEn, $meatEn, $seafoodEn
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fresh_admin_category_ids(array $categories): array
{
    return array_map(static function ($row) {
        return (int)$row['id'];
    }, $categories);
}

function fresh_admin_valid_category(array $categories, int $categoryId): bool
{
    return $categoryId > 0 && in_array($categoryId, fresh_admin_category_ids($categories), true);
}

function fresh_admin_flash(string $type, string $message): void
{
    $_SESSION['mall_fresh_flash'] = ['type' => $type, 'message' => $message];
}

function fresh_admin_take_flash(): ?array
{
    $flash = $_SESSION['mall_fresh_flash'] ?? null;
    unset($_SESSION['mall_fresh_flash']);
    return is_array($flash) ? $flash : null;
}

function fresh_admin_redirect(string $location): void
{
    header('Location: ' . $location);
    exit;
}

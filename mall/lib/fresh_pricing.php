<?php
/** Price of one configured selling unit; purchase weight/piece mode stays unchanged. */
function mall_fresh_sale_unit_price(array $product): float
{
    if ($product['selling_price_override'] !== null) {
        return round((float)$product['selling_price_override'], 2);
    }
    $isWeight = $product['sale_type'] === 'weight';
    $reference = max(1, (int)($product['selling_weight_reference_g'] ?? ($isWeight ? 100 : 1)));
    return round((float)$product['price_per_100g'] * $reference / ($isWeight ? 1000 : 1), 2);
}

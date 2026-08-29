<?php
require_once __DIR__ . '/../lib/pricing.php';
require_once __DIR__ . '/../lib/auth.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();

$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT w.id AS wishlist_id, w.product_id, w.channel,
            COALESCE(mp.display_name, p.name_ko) AS display_name,
            (SELECT image_path FROM mall_product_images WHERE product_id = w.product_id ORDER BY sort_order LIMIT 1) AS image_path
     FROM mall_wishlist w
     INNER JOIN products p ON p.id = w.product_id
     LEFT JOIN mall_products mp ON mp.product_id = w.product_id
     WHERE w.member_id = ?
     ORDER BY w.created_at DESC"
);
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$page_title = '위시리스트';
$mall_show_back = true;
$show_bottom_nav = true;
$active_nav = 'my';
require_once __DIR__ . '/../partials/header.php';
?>

<?php if (empty($items)): ?>
    <p style="text-align:center;color:#9ca3af;padding:3rem 0;">위시리스트가 비어있습니다.</p>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;">
    <?php foreach ($items as $it):
        $price = mall_calculate_price($it['product_id'], $member, $it['channel'], 0.0);
        $card = ['product_id' => $it['product_id'], 'display_name' => $it['display_name'], 'image_path' => $it['image_path'], 'price' => $price, 'stock' => mall_get_stock_quantity($it['product_id'])];
    ?>
        <div>
            <?php include __DIR__ . '/../partials/product_card.php'; ?>
            <button class="wishlist-remove-btn" data-id="<?php echo (int)$it['wishlist_id']; ?>" style="width:100%;margin-top:0.35rem;background:#fff;border:1px solid #e5e7eb;border-radius:0.4rem;padding:0.35rem;font-size:0.75rem;color:#dc2626;cursor:pointer;">위시리스트에서 제거</button>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.wishlist-remove-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const params = new URLSearchParams();
        params.set('wishlist_id', btn.dataset.id);
        fetch('/mall/ajax/toggle_wishlist.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(() => window.location.reload());
    });
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

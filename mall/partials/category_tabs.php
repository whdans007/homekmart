<?php
/**
 * 상단 가로 카테고리 탭
 * Design Ref: mall-home-layout.design.md §5.3, §5.4 카테고리 탐색
 * 포함하는 쪽에서 $categories(최상위 카테고리 목록), $category_id(현재 선택, nullable), $search를 미리 정의해야 한다.
 */
?>
<div style="display:flex;gap:0.5rem;overflow-x:auto;padding-bottom:0.75rem;margin-bottom:1.25rem;border-bottom:1px solid #e5e7eb;">
    <a href="/mall/category.php<?php echo $search !== '' ? '?q=' . urlencode($search) : ''; ?>"
       style="flex-shrink:0;padding:0.5rem 1rem;border-radius:999px;font-size:0.82rem;font-weight:600;text-decoration:none;white-space:nowrap;
              <?php echo $category_id === null ? 'background:#111827;color:#fff;' : 'background:#f3f4f6;color:#374151;'; ?>">
        전체
    </a>
    <?php foreach ($categories as $cat): ?>
        <a href="/mall/category.php?id=<?php echo (int)$cat['id']; ?><?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?>"
           style="flex-shrink:0;padding:0.5rem 1rem;border-radius:999px;font-size:0.82rem;font-weight:600;text-decoration:none;white-space:nowrap;
                  <?php echo $category_id === (int)$cat['id'] ? 'background:#111827;color:#fff;' : 'background:#f3f4f6;color:#374151;'; ?>">
            <?php echo htmlspecialchars(($mall_lang === 'en' && !empty($cat['name_en'])) ? $cat['name_en'] : $cat['name']); ?>
        </a>
    <?php endforeach; ?>
</div>

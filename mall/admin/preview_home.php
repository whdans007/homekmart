<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../lib/home_layout.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$view = ($_GET['view'] ?? 'published') === 'draft' ? 'draft' : 'published';
// 발행본 미리보기는 실제 고객 화면과 똑같이(비노출 섹션 제외) 보여주고,
// 초안 미리보기는 관리자가 지금 만들고 있는 걸 그대로(숨김 섹션도 포함) 보여준다.
$is_customer_facing = ($view === 'published');
$sections = mall_get_active_home_sections($is_customer_facing, $view);

$mall_redesigned = true;
$page_title = t('mall_admin.preview_home.title_prefix') . ' (' . ($view === 'draft' ? t('mall_admin.preview_home.draft') : t('mall_admin.preview_home.published')) . ')';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="coming-soon-banner" style="border-style:solid;">
    <i class="fas fa-eye"></i>
    <span><?php echo $view === 'draft' ? t('mall_admin.preview_home.banner_draft') : t('mall_admin.preview_home.banner_published'); ?></span>
</div>

<?php
$show_section_placeholders = ($view === 'draft');
$is_admin_preview = true;
require __DIR__ . '/../partials/home_body.php';
?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

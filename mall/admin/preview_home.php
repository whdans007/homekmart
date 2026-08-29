<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
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
$mall_hide_topbar = true;
$page_title = '홈 미리보기 (' . ($view === 'draft' ? '초안' : '발행본') . ')';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="coming-soon-banner" style="border-style:solid;">
    <i class="fas fa-eye"></i>
    <span><?php echo $view === 'draft' ? '초안 미리보기 — 실제 고객에게는 보이지 않습니다.' : '현재 발행되어 고객에게 노출 중인 화면입니다.'; ?></span>
</div>

<?php
$show_section_placeholders = ($view === 'draft');
$is_admin_preview = true;
require __DIR__ . '/../partials/home_body.php';
?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

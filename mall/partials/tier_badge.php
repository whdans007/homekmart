<?php
/**
 * 회원 등급/도매 승인상태 뱃지
 * 포함하는 쪽에서 $member(mall_current_member() 반환값, null 가능)를 미리 정의해야 한다.
 */
if (!function_exists('mall_render_tier_badge')) {
    function mall_render_tier_badge($member) {
        if ($member === null) {
            return '';
        }
        if ($member['member_type'] === 'retail') {
            $labels = ['general' => '일반', 'good' => '우수', 'vip' => 'VIP', 'platinum' => '플래티넘'];
            $label = $labels[$member['retail_tier']] ?? $member['retail_tier'];
            return '<span class="tier-badge tier-retail">' . htmlspecialchars($label) . ' 회원</span>';
        }

        $status_labels = ['pending' => '승인대기', 'approved' => '승인됨', 'rejected' => '반려'];
        $label = $status_labels[$member['wholesale_status']] ?? $member['wholesale_status'];
        $cls = $member['wholesale_status'] === 'approved' ? 'tier-wholesale-approved' : 'tier-wholesale-pending';
        return '<span class="tier-badge ' . $cls . '">도매(' . htmlspecialchars($label) . ')</span>';
    }
}
?>

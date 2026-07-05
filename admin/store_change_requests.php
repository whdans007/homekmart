<?php
/**
 * 소속 지점 변경 요청 등록 & 승인 큐.
 * - 등록(대리 발령 요청): 점장 이상이 직원의 지점 변경을 대신 요청
 *     · 점장(level 50~59): 본인 소속 지점 직원만 발령 요청 가능
 *     · CEO 이상(level>=60): 모든 직원 발령 요청 가능
 * - 승인/반려: 도착(발령) 지점 점장 이상(또는 CEO 이상). 본인이 등록한 요청은 본인이 처리 불가(상호 견제).
 */
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '지점 변경 요청/승인 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/store_change_request_helper.php';

// 점장 이상만 접근 가능
if (current_user_level() < LEVEL_BRANCH_MANAGER) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: index.php');
    exit;
}

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$op_level = current_user_level();
$op_store_id = isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
$is_ceo_plus = ($op_level >= LEVEL_CEO);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_action = $_POST['form_action'] ?? 'decide';

    if ($form_action === 'create_request') {
        // 대리 발령 요청 등록
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        $to_store_id = $_POST['to_store_id'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        if ($target_user_id <= 0 || empty($to_store_id) || !filter_var($to_store_id, FILTER_VALIDATE_INT)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => '대상 직원과 발령 지점을 선택하세요.'];
        } elseif (!$is_ceo_plus && scr_get_user_store_id($target_user_id) !== $op_store_id) {
            // 점장은 본인 소속 직원만 발령 요청 가능
            $_SESSION['flash'] = ['type' => 'error', 'message' => '점장은 본인 소속 지점의 직원만 발령 요청할 수 있습니다.'];
        } else {
            $res = create_store_change_request($target_user_id, (int)$to_store_id, $reason, $_SESSION['user_id']);
            $_SESSION['flash'] = ($res === true)
                ? ['type' => 'success', 'message' => '발령 요청이 등록되었습니다. 도착 지점 점장(또는 CEO 이상)이 승인하면 반영됩니다.']
                : ['type' => 'error', 'message' => $res];
        }
    } else {
        // 승인 / 반려
        $request_id = (int)($_POST['request_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $note = trim($_POST['note'] ?? '');

        $req = get_store_change_request_by_id($request_id);
        if (!$req) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => '존재하지 않는 요청입니다.'];
        } elseif (!can_approve_store_change($req['to_store_id'])) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => '이 요청을 처리할 권한이 없습니다. (도착 지점의 점장 또는 CEO 이상만 가능)'];
        } elseif ((int)$req['requested_by'] === (int)$_SESSION['user_id']) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => '본인이 등록한 요청은 다른 승인자가 처리해야 합니다.'];
        } elseif (!in_array($action, ['approve', 'reject'], true)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => '잘못된 요청입니다.'];
        } else {
            $res = decide_store_change_request($request_id, $action === 'approve', $_SESSION['user_id'], $note);
            $_SESSION['flash'] = ($res === true)
                ? ['type' => 'success', 'message' => $action === 'approve' ? '요청을 승인하여 소속 지점이 변경되었습니다.' : '요청을 반려했습니다.']
                : ['type' => 'error', 'message' => $res];
        }
    }
    header('Location: store_change_requests.php');
    exit;
}

$requests = get_pending_store_change_requests();
$candidates = get_store_change_candidates($is_ceo_plus ? null : $op_store_id);
$all_stores = scr_get_all_stores();
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

<?php if ($flash): ?>
    <div class="mb-6 <?php echo $flash['type'] === 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?> rounded-md p-4">
        <p class="text-sm <?php echo $flash['type'] === 'success' ? 'text-green-800' : 'text-red-800'; ?>">
            <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-1"></i>
            <?php echo htmlspecialchars($flash['message']); ?>
        </p>
    </div>
<?php endif; ?>

<!-- 대리 발령 요청 등록 -->
<div class="bg-white shadow-lg rounded-lg ring-1 ring-gray-400 mb-6 p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-1"><i class="fas fa-user-plus mr-2 text-primary-600"></i>발령 요청 등록</h3>
    <p class="text-sm text-gray-500 mb-4">
        직원의 지점 변경(발령)을 대신 요청합니다.
        <?php echo $is_ceo_plus ? '모든 직원을 선택할 수 있습니다.' : '본인 소속 지점의 직원만 선택할 수 있습니다.'; ?>
        등록 후 도착 지점의 점장(또는 CEO 이상)이 승인하면 반영됩니다.
    </p>
    <form method="post" class="grid grid-cols-1 gap-4 sm:grid-cols-4 items-end">
        <input type="hidden" name="form_action" value="create_request">
        <div class="sm:col-span-1">
            <label class="block text-sm font-medium text-gray-700">대상 직원</label>
            <select name="target_user_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                <option value="">직원 선택</option>
                <?php foreach ($candidates as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>">
                        <?php echo htmlspecialchars(($c['full_name'] ?: $c['username']) . ' / ' . ($c['store_name'] ?? '미지정')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sm:col-span-1">
            <label class="block text-sm font-medium text-gray-700">발령 지점</label>
            <select name="to_store_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                <option value=""><?php echo t('store.select_store'); ?></option>
                <?php foreach ($all_stores as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sm:col-span-1">
            <label class="block text-sm font-medium text-gray-700">사유 <span class="text-gray-500">(선택)</span></label>
            <input type="text" name="reason" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" placeholder="예: 2026-07 인사발령">
        </div>
        <div class="sm:col-span-1">
            <button type="submit" class="w-full inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-paper-plane mr-2"></i>요청 등록
            </button>
        </div>
    </form>
</div>

<!-- 승인 대기 목록 -->
<div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
    <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
        <h3 class="text-lg leading-6 font-semibold text-gray-900">
            <i class="fas fa-people-arrows mr-2 text-primary-600"></i>지점 변경 요청 (발령 승인)
            <span class="text-sm font-normal text-gray-500">(대기 <?php echo count($requests); ?>건)</span>
        </h3>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">대상자</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">현재 지점</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">발령 지점</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">사유</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">요청자</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">요청일</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase">처리</th>
                </tr>
            </thead>
            <tbody class="bg-white">
                <?php foreach ($requests as $r): ?>
                <?php
                    $can_act = can_approve_store_change($r['to_store_id']);
                    $is_own = ((int)$r['requested_by'] === (int)$_SESSION['user_id']);
                ?>
                <tr class="border-b border-gray-100">
                    <td class="px-4 py-3 text-sm font-medium text-gray-900">
                        <?php echo htmlspecialchars($r['full_name'] ?? $r['username']); ?>
                        <span class="text-gray-400 text-xs">(<?php echo htmlspecialchars($r['username']); ?>)</span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($r['from_store_name'] ?? '미지정'); ?></td>
                    <td class="px-4 py-3 text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($r['to_store_name'] ?? '-'); ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($r['reason'] ?? '-'); ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($r['requested_by_name'] ?? '-'); ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo date('Y-m-d H:i', strtotime($r['created_at'])); ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($can_act && !$is_own): ?>
                        <form method="post" class="inline-flex items-center gap-1" onsubmit="return confirm('이 요청을 처리하시겠습니까?');">
                            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                            <input type="text" name="note" placeholder="메모(선택)" class="border border-gray-300 rounded px-2 py-1 text-xs w-28">
                            <button type="submit" name="action" value="approve" class="px-3 py-1 text-xs font-medium text-white bg-green-600 hover:bg-green-700 rounded">
                                <i class="fas fa-check mr-1"></i>승인
                            </button>
                            <button type="submit" name="action" value="reject" class="px-3 py-1 text-xs font-medium text-white bg-red-600 hover:bg-red-700 rounded">
                                <i class="fas fa-times mr-1"></i>반려
                            </button>
                        </form>
                        <?php elseif ($is_own): ?>
                        <span class="text-xs text-gray-400">본인 등록 (다른 승인자 처리 대기)</span>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">다른 지점 점장 승인 대기</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-inbox text-gray-300 text-4xl mb-3"></i>
                        <p>대기중인 지점 변경 요청이 없습니다.</p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

<?php
// Design Ref: homekmart-store-config §4.3 / §5.1
require_once __DIR__ . '/../lib/store_config_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '내 지점 정보 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 점장(branch_manager) 이상만 접근 가능 (지점 변경 승인과 동일한 등급 기준)
// Ref: lib/permission_helper.php - current_user_level(), LEVEL_BRANCH_MANAGER
if (current_user_level() < LEVEL_BRANCH_MANAGER) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$is_super_admin = ($_SESSION['role'] === 'super_admin');

// super_admin은 ?id= 로 임의 지점을 조회할 수 있지만, 그 외(점장/CEO 등)는
// 항상 본인 소속 지점(store_id)만 조회/수정 가능 (URL 조작으로 타 지점 접근 방지)
if ($is_super_admin && !empty($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $store_id = (int)$_GET['id'];
} else {
    $store_id = (int)($current_store_id ?? 0);
}

$errors = [];
$store = null;
$all_stores = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // super_admin은 모든 점포를 조회/수정할 수 있어야 하므로 점포 선택 메뉴용 전체 목록을 불러온다.
    // Design Ref: homekmart-store-config — super_admin 전 점포 접근성 보강
    if ($is_super_admin) {
        $all_stores = $pdo->query("SELECT id, name FROM stores ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        if ($store_id <= 0 && !empty($all_stores)) {
            $store_id = (int)$all_stores[0]['id']; // ?id= 없이 접근 시 첫 점포로 기본 이동
        }
    }

    if ($store_id <= 0) {
        $errors[] = '소속된 지점 정보가 없습니다. 관리자에게 문의해주세요.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM stores WHERE id = ?");
        $stmt->execute([$store_id]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$store) {
            $errors[] = '지점을 찾을 수 없습니다.';
        }
    }
} catch (PDOException $e) {
    $errors[] = "데이터베이스 연결에 실패했습니다: " . $e->getMessage();
}

// 대표직원 후보 목록 — 이 지점 소속 사용자 계정 (Design Ref: main_office 결제란 PREPARED 대표직원 지정 기능)
$store_users = [];
if ($store) {
    try {
        $u_stmt = $pdo->prepare("SELECT id, full_name, username FROM users WHERE store_id = ? ORDER BY full_name, username");
        $u_stmt->execute([$store_id]);
        $store_users = $u_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // 대표직원 목록 조회 실패는 페이지 전체를 막지 않음
        error_log('my_store.php store_users error: ' . $e->getMessage());
    }
}

$config_action = is_string($_POST['config_action'] ?? null) ? $_POST['config_action'] : '';

if ($store && $_SERVER['REQUEST_METHOD'] === 'POST' && $config_action === '') {
    $store['name'] = trim($_POST['name'] ?? '');
    $store['company_name'] = trim($_POST['company_name'] ?? '');
    $store['phone'] = trim($_POST['phone'] ?? '');
    $store['address'] = trim($_POST['address'] ?? '');
    $store['bank_account'] = trim($_POST['bank_account'] ?? '');

    // 대표직원(오피스 대표, 결제란 PREPARED에 사용) — 이 지점 소속 사용자 중에서만 선택 가능
    $rep_user_id_input = (int)($_POST['representative_user_id'] ?? 0);
    $store['representative_user_id'] = null;
    if ($rep_user_id_input > 0) {
        foreach ($store_users as $su) {
            if ((int)$su['id'] === $rep_user_id_input) {
                $store['representative_user_id'] = $rep_user_id_input;
                break;
            }
        }
    }

    if (empty($store['name'])) {
        $errors[] = "지점명을 입력해주세요.";
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM stores WHERE name = ? AND id != ?");
            $stmt->execute([$store['name'], $store_id]);
            if ($stmt->fetch()) {
                $errors[] = "이미 존재하는 지점명입니다.";
            } else {
                $update_stmt = $pdo->prepare("UPDATE stores SET name = ?, company_name = ?, phone = ?, address = ?, bank_account = ?, representative_user_id = ? WHERE id = ?");
                $update_stmt->execute([
                    $store['name'],
                    $store['company_name'] !== '' ? $store['company_name'] : null,
                    $store['phone'] !== '' ? $store['phone'] : null,
                    $store['address'] !== '' ? $store['address'] : null,
                    $store['bank_account'] !== '' ? $store['bank_account'] : null,
                    $store['representative_user_id'],
                    $store_id,
                ]);

                $_SESSION['flash'] = ['type' => 'success', 'message' => "지점 정보가 성공적으로 수정되었습니다."];
                header("Location: my_store.php" . ($is_super_admin ? "?id=$store_id" : ""));
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = "데이터베이스 오류가 발생했습니다: " . $e->getMessage();
        }
    }
}

// 설정은 기존 지점 정보 저장과 독립된 헬퍼 트랜잭션으로 저장한다.
$pos_count_input = $store ? (string)get_store_pos_count($store_id) : '';
$shift_inputs = [];
if ($store) {
    foreach (get_store_shifts($store_id) as $key => $shift_config) {
        $shift_inputs[$key] = [
            'label' => $shift_config['label'],
            'start' => substr($shift_config['start_time'], 0, 5),
            'end' => substr($shift_config['end_time'], 0, 5),
        ];
    }
}
if ($store && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($config_action, ['pos', 'shifts'], true)) {
    $updated_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    if ($config_action === 'pos') {
        $pos_count_input = is_string($_POST['pos_count'] ?? null) ? $_POST['pos_count'] : '';
        $validated_count = filter_var($pos_count_input, FILTER_VALIDATE_INT);
        $result = $validated_count === false
            ? ['ok' => false, 'error' => t('store_config.invalid_pos_count', ['max' => STORE_POS_COUNT_MAX])]
            : save_store_pos_count($store_id, $validated_count, $updated_by);
    } else {
        foreach (STORE_SHIFT_KEYS as $key) {
            foreach (['label', 'start', 'end'] as $field) {
                $value = $_POST["shift_{$key}_{$field}"] ?? '';
                $shift_inputs[$key][$field] = is_string($value) ? trim($value) : '';
            }
        }
        $result = save_store_shifts($store_id, $shift_inputs, $updated_by);
    }
    if (!$result['ok']) {
        $errors[] = $result['error'];
    } else {
        $_SESSION['flash'] = ['type' => 'success', 'message' => t('store_config.saved')];
        header("Location: my_store.php" . ($is_super_admin ? "?id=$store_id" : ""));
        exit;
    }
}

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">내 지점 정보</h1>
    <p class="mt-1 text-sm text-gray-500">소속 지점의 정보를 확인하고 수정할 수 있습니다.</p>
</div>

<?php if ($flash): ?>
    <div class="mb-6 rounded-md <?php echo $flash['type'] === 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?> p-4">
        <p class="text-sm <?php echo $flash['type'] === 'success' ? 'text-green-700' : 'text-red-700'; ?>"><?php echo htmlspecialchars($flash['message']); ?></p>
    </div>
<?php endif; ?>

<?php if ($is_super_admin && !empty($all_stores)): ?>
    <div class="mb-6 bg-white shadow sm:rounded-lg p-4 max-w-xl flex items-center gap-3">
        <label for="store_switcher" class="text-sm font-medium text-gray-700 whitespace-nowrap"><i class="fas fa-store mr-1 text-gray-400"></i>점포 선택 (Super Admin)</label>
        <select id="store_switcher" onchange="if(this.value) location.href='my_store.php?id='+this.value;" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            <?php foreach ($all_stores as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$s['id'] === $store_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
<?php endif; ?>

<?php if (!empty($errors) && !$store): ?>
    <div class="rounded-md bg-red-50 p-4 max-w-xl">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-times-circle text-red-400"></i>
            </div>
            <div class="ml-3">
                <ul role="list" class="list-disc pl-5 space-y-1 text-sm text-red-700">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
<?php elseif ($store): ?>
    <form action="my_store.php<?php echo $is_super_admin ? '?id=' . $store_id : ''; ?>" method="post" class="space-y-8 bg-white shadow sm:rounded-lg p-6 max-w-xl">
        <?php if (!empty($errors)): ?>
            <div class="rounded-md bg-red-50 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-times-circle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">문제가 발생했습니다.</h3>
                        <div class="mt-2 text-sm text-red-700">
                            <ul role="list" class="list-disc pl-5 space-y-1">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div>
            <h2 class="text-lg font-medium leading-6 text-gray-900">지점 정보</h2>
            <div class="mt-6">
                <label for="name" class="block text-sm font-medium text-gray-700">지점명</label>
                <input type="text" id="name" name="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['name']); ?>" required>
            </div>
            <div class="mt-6">
                <label for="company_name" class="block text-sm font-medium text-gray-700">실제 회사명 (상호)</label>
                <input type="text" id="company_name" name="company_name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['company_name'] ?? ''); ?>" placeholder="사업자등록상 상호 / 법인명">
            </div>
            <div class="mt-6">
                <label for="phone" class="block text-sm font-medium text-gray-700">전화번호</label>
                <input type="text" id="phone" name="phone" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['phone'] ?? ''); ?>" placeholder="지점 전화번호">
            </div>
            <div class="mt-6">
                <label for="address" class="block text-sm font-medium text-gray-700">주소</label>
                <input type="text" id="address" name="address" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['address'] ?? ''); ?>" placeholder="지점 주소">
            </div>
            <div class="mt-6">
                <label for="bank_account" class="block text-sm font-medium text-gray-700">계좌번호</label>
                <input type="text" id="bank_account" name="bank_account" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['bank_account'] ?? ''); ?>" placeholder="은행명 및 계좌번호">
            </div>
            <div class="mt-6">
                <!-- Design Ref: main_office 결제란 PREPARED 대표직원 지정 기능 -->
                <label for="representative_user_id" class="block text-sm font-medium text-gray-700">오피스 대표 직원</label>
                <select id="representative_user_id" name="representative_user_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                    <option value="">미지정</option>
                    <?php foreach ($store_users as $su): ?>
                    <option value="<?php echo (int)$su['id']; ?>" <?php echo ((int)($store['representative_user_id'] ?? 0) === (int)$su['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($su['full_name'] ?: $su['username']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-gray-500">메인 오피스에서 이 지점의 결제란(PREPARED)을 열람할 때 표시되는 담당자입니다.</p>
            </div>
        </div>

        <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-save mr-2"></i>
                정보 저장
            </button>
        </div>
    </form>

    <form action="my_store.php<?php echo $is_super_admin ? '?id=' . $store_id : ''; ?>" method="post" class="mt-8 space-y-6 bg-white shadow sm:rounded-lg p-6 max-w-xl">
        <input type="hidden" name="config_action" value="pos">
        <h2 class="text-lg font-medium text-gray-900"><?php echo t('store_config.pos_section_title'); ?></h2>
        <div>
            <label for="pos_count" class="block text-sm font-medium text-gray-700"><?php echo t('store_config.pos_count_label'); ?></label>
            <input type="number" id="pos_count" name="pos_count" min="1" max="<?php echo STORE_POS_COUNT_MAX; ?>" step="1" required value="<?php echo htmlspecialchars($pos_count_input); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            <p class="mt-2 text-sm text-gray-500"><?php echo t('store_config.pos_help'); ?></p>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="py-2 px-4 rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:ring-2 focus:ring-primary-500"><?php echo t('common.save'); ?></button>
        </div>
    </form>

    <form action="my_store.php<?php echo $is_super_admin ? '?id=' . $store_id : ''; ?>" method="post" class="mt-8 space-y-6 bg-white shadow sm:rounded-lg p-6 max-w-xl">
        <input type="hidden" name="config_action" value="shifts">
        <h2 class="text-lg font-medium text-gray-900"><?php echo t('store_config.shift_section_title'); ?></h2>
        <p class="text-sm text-gray-500"><?php echo t('store_config.shift_help'); ?></p>
        <?php foreach (STORE_SHIFT_KEYS as $key): ?>
        <fieldset class="border border-gray-200 rounded-md p-3">
            <legend class="px-1 text-sm font-medium text-gray-700"><?php echo t('store_config.shift_' . $key); ?></legend>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <?php foreach (['label', 'start', 'end'] as $field): $input_id = "shift_{$key}_{$field}"; ?>
                <div>
                    <label for="<?php echo $input_id; ?>" class="block text-sm font-medium text-gray-700"><?php echo t('store_config.' . $field . '_label'); ?></label>
                    <input type="<?php echo $field === 'label' ? 'text' : 'time'; ?>" id="<?php echo $input_id; ?>" name="<?php echo $input_id; ?>" <?php echo $field === 'label' ? 'maxlength="20"' : 'step="60"'; ?> required value="<?php echo htmlspecialchars($shift_inputs[$key][$field]); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                </div>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php endforeach; ?>
        <div class="flex justify-end">
            <button type="submit" class="py-2 px-4 rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:ring-2 focus:ring-primary-500"><?php echo t('common.save'); ?></button>
        </div>
    </form>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

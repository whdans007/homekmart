<?php
// Design Ref: supplier_management.php — 같은 회사가 이름을 다르게(오타 등) 중복 등록한 경우
// 여러 공급처를 하나로 통합하는 기능. purchases.supplier_id(ID 참조)와 office 모듈의
// office_receipts/office_product_purchases/office_equipment_purchases.supplier_name(텍스트 사본)을
// 모두 대표 공급처 기준으로 맞춘 뒤, 나머지 공급처 행을 삭제한다.
$page_title = "공급처 통합 - HOME K MART";
require_once __DIR__ . '/partials/header.php';

if (!has_permission('supplier_management') && $_SESSION['role'] !== 'super_admin') {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
$pdo = new PDO($dsn, DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$OFFICE_TEXT_TABLES = ['office_receipts', 'office_product_purchases', 'office_equipment_purchases'];

// ── 통합 실행 ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute') {
    $ids          = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
    $canonical_id = (int)($_POST['canonical_id'] ?? 0);

    if (count($ids) < 2 || !in_array($canonical_id, $ids, true)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '통합할 공급처를 다시 선택해주세요.'];
        header('Location: supplier_management.php');
        exit;
    }

    $other_ids = array_values(array_diff($ids, [$canonical_id]));

    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, name FROM suppliers WHERE id IN ({$ph})");
        $st->execute($ids);
        $names = $st->fetchAll(PDO::FETCH_KEY_PAIR); // id => name

        if (!isset($names[$canonical_id]) || count($names) !== count($ids)) {
            throw new Exception('선택한 공급처 정보를 다시 확인해주세요.');
        }
        $canonical_name = $names[$canonical_id];

        $pdo->beginTransaction();

        // 1) 메인 admin 매입(purchases.supplier_id) — ID 참조라 그대로 재배정
        $ph2 = implode(',', array_fill(0, count($other_ids), '?'));
        $pdo->prepare("UPDATE purchases SET supplier_id=? WHERE supplier_id IN ({$ph2})")
            ->execute(array_merge([$canonical_id], $other_ids));

        foreach ($other_ids as $oid) {
            $old_name = $names[$oid];

            if ($old_name !== $canonical_name) {
                // 2) office 모듈 — supplier_name 텍스트 사본 3개 테이블을 대표 이름으로 일괄 변경
                foreach ($OFFICE_TEXT_TABLES as $tbl) {
                    $pdo->prepare("UPDATE {$tbl} SET supplier_name=? WHERE supplier_name=?")
                        ->execute([$canonical_name, $old_name]);
                }

                // 3) cash_disbursement 공급처→섹션 매핑 — (store_id, supplier_name) UNIQUE라
                //    대표 이름 매핑이 이미 있으면 옛 이름 행은 삭제, 없으면 이름만 변경
                $chk = $pdo->query("SHOW TABLES LIKE 'cd_supplier_section_map'");
                if ($chk && $chk->rowCount() > 0) {
                    $sel = $pdo->prepare("SELECT store_id FROM cd_supplier_section_map WHERE supplier_name=?");
                    $sel->execute([$old_name]);
                    foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $store_id) {
                        $exists = $pdo->prepare("SELECT 1 FROM cd_supplier_section_map WHERE store_id=? AND supplier_name=?");
                        $exists->execute([$store_id, $canonical_name]);
                        if ($exists->fetch()) {
                            $pdo->prepare("DELETE FROM cd_supplier_section_map WHERE store_id=? AND supplier_name=?")
                                ->execute([$store_id, $old_name]);
                        } else {
                            $pdo->prepare("UPDATE cd_supplier_section_map SET supplier_name=? WHERE store_id=? AND supplier_name=?")
                                ->execute([$canonical_name, $store_id, $old_name]);
                        }
                    }
                }
            }

            // 4) 통합된 공급처 원본 행 삭제
            $pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([$oid]);
        }

        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'message' => count($other_ids) . '개 공급처를 "' . $canonical_name . '"(으)로 통합했습니다.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'message' => '통합 중 오류가 발생했습니다: ' . $e->getMessage()];
    }

    header('Location: supplier_management.php');
    exit;
}

// ── 미리보기 화면 ────────────────────────────────────────────
$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
if (count($ids) < 2) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '통합하려면 공급처를 2개 이상 선택해주세요.'];
    header('Location: supplier_management.php');
    exit;
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$st = $pdo->prepare("SELECT id, name, phone, memo, created_at FROM suppliers WHERE id IN ({$ph}) ORDER BY created_at ASC");
$st->execute($ids);
$candidates = $st->fetchAll(PDO::FETCH_ASSOC);

if (count($candidates) !== count($ids)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '선택한 공급처 중 일부를 찾을 수 없습니다.'];
    header('Location: supplier_management.php');
    exit;
}

// 공급처별 실제 사용 건수 (통합 영향 미리보기)
foreach ($candidates as &$c) {
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id=?");
    $cnt_stmt->execute([$c['id']]);
    $cnt = (int)$cnt_stmt->fetchColumn();

    foreach ($OFFICE_TEXT_TABLES as $tbl) {
        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tbl} WHERE supplier_name=?");
        $cnt_stmt->execute([$c['name']]);
        $cnt += (int)$cnt_stmt->fetchColumn();
    }
    $c['usage_count'] = $cnt;
}
unset($c);
?>

<div class="max-w-3xl mx-auto">
    <a href="supplier_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i>공급처 목록으로 돌아가기
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mb-1">공급처 통합</h1>
    <p class="text-sm text-gray-500 mb-6">대표로 남길 공급처를 하나 선택하세요. 나머지는 선택한 공급처로 합쳐지고 삭제됩니다.</p>

    <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4 mb-6 text-sm text-yellow-800">
        <i class="fas fa-triangle-exclamation mr-1"></i>
        통합하면 매입 내역(구매/영수증 등)의 공급처명이 모두 대표 이름으로 바뀌고, 나머지 공급처는 <strong>삭제</strong>되어 되돌릴 수 없습니다.
    </div>

    <form action="merge_suppliers.php" method="post" class="bg-white shadow sm:rounded-lg p-6"
          onsubmit="return confirm('선택한 공급처들을 통합하시겠습니까? 이 작업은 되돌릴 수 없습니다.');">
        <input type="hidden" name="action" value="execute">
        <?php foreach ($ids as $id): ?>
        <input type="hidden" name="ids[]" value="<?php echo (int)$id; ?>">
        <?php endforeach; ?>

        <div class="space-y-3 mb-6">
            <?php foreach ($candidates as $idx => $c): ?>
            <label class="merge-candidate-label flex items-start gap-3 border border-gray-200 rounded-md p-3 cursor-pointer hover:bg-gray-50">
                <input type="radio" name="canonical_id" value="<?php echo (int)$c['id']; ?>"
                       class="mt-1 merge-canonical-radio" <?php echo $idx === 0 ? 'checked' : ''; ?> required
                       onchange="updateCanonicalHighlight()">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($c['name']); ?></span>
                        <span class="text-xs text-gray-400">ID <?php echo (int)$c['id']; ?></span>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">사용 <?php echo (int)$c['usage_count']; ?>건</span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        <?php echo htmlspecialchars($c['phone'] ?: '전화번호 없음'); ?>
                        <?php if (!empty($c['memo'])): ?> · <?php echo nl2br(htmlspecialchars($c['memo'])); ?><?php endif; ?>
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5">등록일: <?php echo date('Y-m-d', strtotime($c['created_at'])); ?></div>
                </div>
            </label>
            <?php endforeach; ?>
        </div>

        <div class="flex justify-end gap-x-3 pt-4 border-t border-gray-200">
            <a href="supplier_management.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">취소</a>
            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                <i class="fas fa-code-merge mr-2"></i>선택한 공급처로 통합 실행
            </button>
        </div>
    </form>
</div>

<script>
function updateCanonicalHighlight() {
    document.querySelectorAll('.merge-candidate-label').forEach(label => {
        const radio = label.querySelector('.merge-canonical-radio');
        if (radio && radio.checked) {
            label.style.borderColor = '#6366f1';
            label.style.background = '#eef2ff';
        } else {
            label.style.borderColor = '';
            label.style.background = '';
        }
    });
}
updateCanonicalHighlight();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

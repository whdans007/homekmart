<?php
$page_title = 'Supplier Management - Logistics Center';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';

lc_require_staff();

$suppliers = [];
try {
    $conn = get_lc_db();
    $suppliers = $conn->query(
        "SELECT id, name, contact_person, phone, email, memo, created_at FROM lc_suppliers ORDER BY name ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $conn->close();
} catch (Exception $e) {
    lc_set_flash('error', 'DB Error: ' . $e->getMessage());
}
?>

<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-bold text-gray-900">Supplier Management</h2>
    <button onclick="openModal()" class="inline-flex items-center px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold rounded-lg transition-colors">
        <i class="fas fa-plus mr-2"></i> Add Supplier
    </button>
</div>

<!-- 검색 -->
<div class="mb-4">
    <div class="relative max-w-xs">
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
        <input type="text" id="searchInput" placeholder="Search suppliers..."
               oninput="filterTable(this.value)"
               class="pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm w-full focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
    </div>
</div>

<!-- 목록 -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Contact Person</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Phone</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Email</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Memo</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100" id="supplierTable">
            <?php foreach ($suppliers as $s): ?>
            <tr class="hover:bg-gray-50 supplier-row"
                data-search="<?php echo htmlspecialchars(strtolower($s['name'] . ' ' . $s['contact_person'] . ' ' . $s['phone'] . ' ' . $s['email']), ENT_QUOTES); ?>">
                <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($s['name']); ?></td>
                <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($s['contact_person'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($s['phone'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($s['email'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-sm text-gray-500 max-w-xs truncate"><?php echo htmlspecialchars($s['memo'] ?? ''); ?></td>
                <td class="px-4 py-3 text-right">
                    <div class="flex gap-2 justify-end">
                        <button onclick="openModal(<?php echo htmlspecialchars(json_encode($s), ENT_QUOTES); ?>)"
                                class="text-teal-600 hover:text-teal-800 text-sm font-medium">
                            <i class="fas fa-edit mr-1"></i>Edit
                        </button>
                        <button onclick="deleteSupplier(<?php echo $s['id']; ?>, '<?php echo addslashes(htmlspecialchars($s['name'])); ?>')"
                                class="text-red-500 hover:text-red-700 text-sm font-medium">
                            <i class="fas fa-trash mr-1"></i>Delete
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($suppliers)): ?>
            <tr id="emptyRow">
                <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-400">
                    <i class="fas fa-truck text-3xl text-gray-200 mb-3 block"></i>
                    No suppliers yet. Click "Add Supplier" to get started.
                </td>
            </tr>
            <?php endif; ?>
            <tr id="noResultRow" class="hidden">
                <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">No results found.</td>
            </tr>
        </tbody>
    </table>
</div>

<!-- 추가/편집 모달 -->
<div id="supplierModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
            <h3 id="modalTitle" class="text-base font-semibold text-gray-900">Add Supplier</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="supplierForm" class="px-6 py-5 space-y-4">
            <input type="hidden" id="supplierId" name="id" value="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" id="supplierName" name="name" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Contact Person</label>
                <input type="text" id="supplierContact" name="contact_person"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                <input type="text" id="supplierPhone" name="phone"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" id="supplierEmail" name="email"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Memo</label>
                <textarea id="supplierMemo" name="memo" rows="2"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500 resize-none"></textarea>
            </div>

            <div id="modalError" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal()"
                        class="flex-1 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-semibold transition-colors">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function filterTable(q) {
    q = q.toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.supplier-row').forEach(row => {
        const match = !q || row.dataset.search.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('noResultRow').classList.toggle('hidden', !q || visible > 0);
}

function openModal(data) {
    document.getElementById('modalTitle').textContent = data ? 'Edit Supplier' : 'Add Supplier';
    document.getElementById('supplierId').value        = data ? data.id : '';
    document.getElementById('supplierName').value      = data ? (data.name || '') : '';
    document.getElementById('supplierContact').value   = data ? (data.contact_person || '') : '';
    document.getElementById('supplierPhone').value     = data ? (data.phone || '') : '';
    document.getElementById('supplierEmail').value     = data ? (data.email || '') : '';
    document.getElementById('supplierMemo').value      = data ? (data.memo || '') : '';
    document.getElementById('modalError').classList.add('hidden');
    document.getElementById('supplierModal').classList.remove('hidden');
    document.getElementById('supplierName').focus();
}

function closeModal() {
    document.getElementById('supplierModal').classList.add('hidden');
}

document.getElementById('supplierForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const errEl = document.getElementById('modalError');
    errEl.classList.add('hidden');

    try {
        const res = await fetch('ajax/save_supplier.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            errEl.textContent = data.message || 'Save failed.';
            errEl.classList.remove('hidden');
        }
    } catch {
        errEl.textContent = 'Network error.';
        errEl.classList.remove('hidden');
    }
});

async function deleteSupplier(id, name) {
    if (!confirm('Delete supplier "' + name + '"?\nThis cannot be undone.')) return;

    const formData = new FormData();
    formData.append('id', id);
    formData.append('csrf_token', '<?php echo addslashes(lc_csrf_token()); ?>');

    try {
        const res = await fetch('ajax/delete_supplier.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Delete failed.');
        }
    } catch {
        alert('Network error.');
    }
}

// ESC 키로 모달 닫기
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

<?php
/**
 * رصيد - إدارة البنود (مدير النظام فقط)
 */
require __DIR__ . '/includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            if ($categoryId <= 0 || $name === '') {
                flash('danger', 'يرجى اختيار التصنيف وإدخال اسم البند.');
            } else {
                q('INSERT INTO items (category_id, name) VALUES (?, ?)', [$categoryId, $name]);
                flash('success', 'تمت إضافة البند بنجاح.');
            }
        } elseif ($action === 'edit') {
            $id         = (int)($_POST['id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            if ($id > 0 && $categoryId > 0 && $name !== '') {
                q('UPDATE items SET category_id = ?, name = ? WHERE id = ?', [$categoryId, $name, $id]);
                flash('success', 'تم تعديل البند بنجاح.');
            }
        } elseif ($action === 'toggle') {
            q('UPDATE items SET status = 1 - status WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            flash('success', 'تم تغيير حالة البند.');
        } elseif ($action === 'delete') {
            q('DELETE FROM items WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            flash('success', 'تم حذف البند بنجاح.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('danger', str_contains($e->getMessage(), 'Duplicate')
                ? 'يوجد بند بنفس الاسم في هذا التصنيف مسبقاً.'
                : 'لا يمكن حذف البند لوجود عمليات مرتبطة به.');
        } else {
            log_error('items: ' . $e->getMessage());
            flash('danger', 'حدث خطأ غير متوقع.');
        }
    }
    $back = !empty($_POST['filter_category']) ? '?category_id=' . (int)$_POST['filter_category'] : '';
    redirect(APP_URL . 'items.php' . $back);
}

$filterCategory = (int)($_GET['category_id'] ?? 0);
$categories = q('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$where = '';
$bind  = [];
if ($filterCategory > 0) {
    $where = 'WHERE i.category_id = ?';
    $bind  = [$filterCategory];
}

$items = q("SELECT i.*, c.name AS category_name,
                (SELECT COUNT(*) FROM transactions t WHERE t.item_id = i.id) AS tx_count
            FROM items i
            JOIN categories c ON c.id = i.category_id
            $where
            ORDER BY c.name, i.name", $bind)->fetchAll();

$pageTitle = 'البنود';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle"></i> إضافة بند</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="filter_category" value="<?= $filterCategory ?>">
                    <div class="mb-3">
                        <label class="form-label">التصنيف</label>
                        <select class="form-select" name="category_id" required>
                            <option value="">-- اختر التصنيف --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $filterCategory === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">اسم البند</label>
                        <input type="text" class="form-control" name="name" required maxlength="100"
                               placeholder="مثال: قهوة، وقود، دفعة مشروع...">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">إضافة</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>البنود (<?= count($items) ?>)</span>
                <form method="get" class="d-flex gap-2">
                    <select class="form-select form-select-sm" name="category_id" onchange="this.form.submit()">
                        <option value="">جميع التصنيفات</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $filterCategory === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-mobile align-middle">
                        <thead>
                            <tr>
                                <th>البند</th>
                                <th>التصنيف</th>
                                <th>العمليات</th>
                                <th>الحالة</th>
                                <th class="text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $i): ?>
                                <tr>
                                    <td data-label="البند" class="fw-bold"><?= e($i['name']) ?></td>
                                    <td data-label="التصنيف"><?= e($i['category_name']) ?></td>
                                    <td data-label="العمليات"><?= number_format((float)$i['tx_count']) ?></td>
                                    <td data-label="الحالة">
                                        <span class="badge text-bg-<?= $i['status'] ? 'success' : 'secondary' ?>">
                                            <?= $i['status'] ? 'مفعّل' : 'موقوف' ?>
                                        </span>
                                    </td>
                                    <td data-label="إجراءات" class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal" data-bs-target="#editModal"
                                                    data-id="<?= $i['id'] ?>" data-name="<?= e($i['name']) ?>"
                                                    data-category="<?= $i['category_id'] ?>" title="تعديل">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= $i['id'] ?>">
                                                <input type="hidden" name="filter_category" value="<?= $filterCategory ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="تفعيل / إيقاف">
                                                    <i class="bi bi-power"></i>
                                                </button>
                                            </form>
                                            <form method="post" data-confirm="هل أنت متأكد من حذف البند؟">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $i['id'] ?>">
                                                <input type="hidden" name="filter_category" value="<?= $filterCategory ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- نافذة التعديل -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <input type="hidden" name="filter_category" value="<?= $filterCategory ?>">
                <div class="modal-header">
                    <h5 class="modal-title">تعديل البند</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">التصنيف</label>
                        <select class="form-select" name="category_id" id="editCategory" required>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="form-label">اسم البند</label>
                    <input type="text" class="form-control" name="name" id="editName" required maxlength="100">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<'HTML'
<script>
document.getElementById('editModal').addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    document.getElementById('editId').value = btn.getAttribute('data-id');
    document.getElementById('editName').value = btn.getAttribute('data-name');
    document.getElementById('editCategory').value = btn.getAttribute('data-category');
});
</script>
HTML;
require BASE_PATH . '/includes/layout/footer.php';
?>

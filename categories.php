<?php
/**
 * رصيد - إدارة التصنيفات (مدير النظام فقط)
 */
require __DIR__ . '/includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                flash('danger', 'يرجى إدخال اسم التصنيف.');
            } else {
                q('INSERT INTO categories (name) VALUES (?)', [$name]);
                flash('success', 'تمت إضافة التصنيف بنجاح.');
            }
        } elseif ($action === 'edit') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($id > 0 && $name !== '') {
                q('UPDATE categories SET name = ? WHERE id = ?', [$name, $id]);
                flash('success', 'تم تعديل التصنيف بنجاح.');
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            q('UPDATE categories SET status = 1 - status WHERE id = ?', [$id]);
            flash('success', 'تم تغيير حالة التصنيف.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            q('DELETE FROM categories WHERE id = ?', [$id]);
            flash('success', 'تم حذف التصنيف بنجاح.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('danger', str_contains($e->getMessage(), 'Duplicate')
                ? 'يوجد تصنيف بنفس الاسم مسبقاً.'
                : 'لا يمكن حذف التصنيف لوجود بنود أو عمليات مرتبطة به.');
        } else {
            log_error('categories: ' . $e->getMessage());
            flash('danger', 'حدث خطأ غير متوقع.');
        }
    }
    redirect(APP_URL . 'categories.php');
}

$categories = q('SELECT c.*,
                    (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS items_count,
                    (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.id) AS tx_count
                 FROM categories c ORDER BY c.name')->fetchAll();

$pageTitle = 'التصنيفات';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle"></i> إضافة تصنيف</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">اسم التصنيف</label>
                        <input type="text" class="form-control" name="name" required maxlength="100"
                               placeholder="مثال: ضيافة، نقل، مشتريات...">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">إضافة</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">التصنيفات (<?= count($categories) ?>)</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-mobile align-middle">
                        <thead>
                            <tr>
                                <th>التصنيف</th>
                                <th>البنود</th>
                                <th>العمليات</th>
                                <th>الحالة</th>
                                <th class="text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $c): ?>
                                <tr>
                                    <td data-label="التصنيف" class="fw-bold"><?= e($c['name']) ?></td>
                                    <td data-label="البنود">
                                        <a href="<?= APP_URL ?>items.php?category_id=<?= $c['id'] ?>"><?= $c['items_count'] ?> بند</a>
                                    </td>
                                    <td data-label="العمليات"><?= number_format((float)$c['tx_count']) ?></td>
                                    <td data-label="الحالة">
                                        <span class="badge text-bg-<?= $c['status'] ? 'success' : 'secondary' ?>">
                                            <?= $c['status'] ? 'مفعّل' : 'موقوف' ?>
                                        </span>
                                    </td>
                                    <td data-label="إجراءات" class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal" data-bs-target="#editModal"
                                                    data-id="<?= $c['id'] ?>" data-name="<?= e($c['name']) ?>" title="تعديل">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="تفعيل / إيقاف">
                                                    <i class="bi bi-power"></i>
                                                </button>
                                            </form>
                                            <form method="post" data-confirm="هل أنت متأكد من حذف التصنيف؟">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
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
                <div class="modal-header">
                    <h5 class="modal-title">تعديل التصنيف</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">اسم التصنيف</label>
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
});
</script>
HTML;
require BASE_PATH . '/includes/layout/footer.php';
?>

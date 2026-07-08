<?php
/**
 * رصيد - إدارة التاقات (مدير النظام فقط)
 *
 * التاق وسم إضافي اختياري على العملية (مثل مشروع أو جهة أو نشاط)،
 * وتعرض هذه الصفحة لكل تاق إجمالي المبالغ المرتبطة به من إيرادات ومصروفات.
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
                flash('danger', 'يرجى إدخال اسم التاق.');
            } else {
                q('INSERT INTO tags (name) VALUES (?)', [$name]);
                flash('success', 'تمت إضافة التاق بنجاح.');
            }
        } elseif ($action === 'edit') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($id > 0 && $name !== '') {
                q('UPDATE tags SET name = ? WHERE id = ?', [$name, $id]);
                flash('success', 'تم تعديل التاق بنجاح.');
            }
        } elseif ($action === 'toggle') {
            q('UPDATE tags SET status = 1 - status WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            flash('success', 'تم تغيير حالة التاق.');
        } elseif ($action === 'delete') {
            // العمليات المرتبطة تبقى، ويصبح تاقها فارغاً (ON DELETE SET NULL)
            q('DELETE FROM tags WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            flash('success', 'تم حذف التاق. العمليات المرتبطة به بقيت بدون تاق.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate')) {
            flash('danger', 'يوجد تاق بنفس الاسم مسبقاً.');
        } else {
            log_error('tags: ' . $e->getMessage());
            flash('danger', 'حدث خطأ غير متوقع.');
        }
    }
    redirect(APP_URL . 'tags.php');
}

// كل تاق مع إجمالي المبالغ المرتبطة به من إيرادات ومصروفات وعدد عملياته
$tags = q("SELECT tg.*,
              COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0) AS income,
              COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS expense,
              COUNT(t.id) AS tx_count
           FROM tags tg
           LEFT JOIN transactions t ON t.tag_id = tg.id
           GROUP BY tg.id
           ORDER BY tg.name")->fetchAll();

$pageTitle = 'التاقات';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle"></i> إضافة تاق</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">اسم التاق</label>
                        <input type="text" class="form-control" name="name" required maxlength="100"
                               placeholder="مثال: مشروع الرياض، معرض 2026، عميل أحمد...">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">إضافة</button>
                </form>
                <p class="text-muted small mt-3 mb-0">
                    التاق وسم إضافي اختياري على العملية بجانب التصنيف والبند، يتيح تتبّع إيرادات ومصروفات نشاط أو مشروع معيّن.
                </p>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">التاقات (<?= count($tags) ?>)</div>
            <div class="card-body p-0">
                <?php if (!$tags): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-tag fs-1 d-block mb-2"></i>
                        لا توجد تاقات بعد
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-mobile align-middle">
                            <thead>
                                <tr>
                                    <th>التاق</th>
                                    <th>الإيرادات المرتبطة</th>
                                    <th>المصروفات المرتبطة</th>
                                    <th>العمليات</th>
                                    <th>الحالة</th>
                                    <th class="text-center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tags as $t): ?>
                                    <tr>
                                        <td data-label="التاق" class="fw-bold">
                                            <a href="<?= APP_URL ?>transactions.php?tag_id=<?= $t['id'] ?>"><?= e($t['name']) ?></a>
                                        </td>
                                        <td data-label="الإيرادات المرتبطة" class="amount-income"><?= format_amount($t['income']) ?></td>
                                        <td data-label="المصروفات المرتبطة" class="amount-expense"><?= format_amount($t['expense']) ?></td>
                                        <td data-label="العمليات"><?= number_format((float)$t['tx_count']) ?></td>
                                        <td data-label="الحالة">
                                            <span class="badge text-bg-<?= $t['status'] ? 'success' : 'secondary' ?>">
                                                <?= $t['status'] ? 'مفعّل' : 'موقوف' ?>
                                            </span>
                                        </td>
                                        <td data-label="إجراءات" class="text-center">
                                            <div class="d-inline-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                        data-bs-toggle="modal" data-bs-target="#editModal"
                                                        data-id="<?= $t['id'] ?>" data-name="<?= e($t['name']) ?>" title="تعديل">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="post">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="تفعيل / إيقاف">
                                                        <i class="bi bi-power"></i>
                                                    </button>
                                                </form>
                                                <form method="post" data-confirm="حذف التاق؟ العمليات المرتبطة به ستبقى بدون تاق.">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
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
                <?php endif; ?>
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
                    <h5 class="modal-title">تعديل التاق</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">اسم التاق</label>
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
$nonce = csp_nonce();
$pageScripts = <<<HTML
<script nonce="{$nonce}">
document.getElementById('editModal').addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    document.getElementById('editId').value = btn.getAttribute('data-id');
    document.getElementById('editName').value = btn.getAttribute('data-name');
});
</script>
HTML;
require BASE_PATH . '/includes/layout/footer.php';
?>

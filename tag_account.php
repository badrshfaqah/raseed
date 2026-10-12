<?php
/**
 * رصيد - حساب التاق ومشاريعه الخاصة (مدير النظام فقط)
 *
 * يعرض لكل تاق (شخص/جهة): الوارد له (إيرادات موسومة) + قيمة المشاريع الخاصة
 * التي نفّذها لي = إجمالي المستحق له، مقابل المصروف نيابةً عنه = المتبقّي له.
 *
 * المشاريع الخاصة لها تواريخ محددة وتُحتسب ضمن مستحقات هذا التاق فقط،
 * ولا تدخل إطلاقاً ضمن الحسابات العامة (الرصيد العام/الإحصائيات/كشف الحساب).
 */
require __DIR__ . '/includes/init.php';
require_admin();

$id  = (int)($_GET['id'] ?? 0);
$tag = q('SELECT * FROM tags WHERE id = ?', [$id])->fetch();
if (!$tag) {
    flash('danger', 'التاق غير موجود.');
    redirect(APP_URL . 'tags.php');
}

/* ---------------- حفظ مشاريع التاق ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add' || $action === 'edit') {
            $name   = trim($_POST['name'] ?? '');
            $value  = (float)str_replace(',', '', (string)($_POST['value'] ?? '0'));
            $start  = trim($_POST['start_date'] ?? '');
            $end    = trim($_POST['end_date'] ?? '');
            $notes  = trim($_POST['notes'] ?? '');
            $errors = [];

            if ($name === '' || mb_strlen($name) > 150) {
                $errors[] = 'اسم المشروع مطلوب (وبحد أقصى 150 حرفاً).';
            }
            if ($value <= 0) {
                $errors[] = 'قيمة المشروع يجب أن تكون أكبر من صفر.';
            } elseif ($value > 999999999999.99) {
                $errors[] = 'قيمة المشروع تتجاوز الحد الأقصى.';
            }
            if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                $errors[] = 'تاريخ البداية غير صحيح.';
            }
            if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                $errors[] = 'تاريخ النهاية غير صحيح.';
            }
            if ($start !== '' && $end !== '' && $end < $start) {
                $errors[] = 'تاريخ النهاية يجب ألا يسبق تاريخ البداية.';
            }
            if (mb_strlen($notes) > 500) {
                $errors[] = 'الملاحظات طويلة جداً (بحد أقصى 500 حرف).';
            }

            if ($errors) {
                foreach ($errors as $e) { flash('danger', $e); }
            } elseif ($action === 'add') {
                q('INSERT INTO tag_projects (tag_id, name, value, start_date, end_date, notes)
                   VALUES (?, ?, ?, ?, ?, ?)',
                  [$id, $name, $value, $start ?: null, $end ?: null, $notes ?: null]);
                flash('success', 'تمت إضافة المشروع بنجاح.');
            } else {
                $pid = (int)($_POST['project_id'] ?? 0);
                q('UPDATE tag_projects SET name = ?, value = ?, start_date = ?, end_date = ?, notes = ?
                   WHERE id = ? AND tag_id = ?',
                  [$name, $value, $start ?: null, $end ?: null, $notes ?: null, $pid, $id]);
                flash('success', 'تم تعديل المشروع بنجاح.');
            }
        } elseif ($action === 'toggle') {
            q('UPDATE tag_projects SET status = 1 - status WHERE id = ? AND tag_id = ?',
              [(int)($_POST['project_id'] ?? 0), $id]);
            flash('success', 'تم تغيير حالة المشروع (المؤرشف لا يُحتسب ضمن المستحقات).');
        } elseif ($action === 'delete') {
            q('DELETE FROM tag_projects WHERE id = ? AND tag_id = ?',
              [(int)($_POST['project_id'] ?? 0), $id]);
            flash('success', 'تم حذف المشروع.');
        }
    } catch (Throwable $e) {
        log_error('tag_account: ' . $e->getMessage());
        flash('danger', 'حدث خطأ غير متوقع.');
    }
    redirect(APP_URL . 'tag_account.php?id=' . $id);
}

/* ---------------- حسابات التاق ---------------- */

// الوارد له والمصروف نيابةً عنه من العمليات الموسومة بهذا التاق
$tx = q(
    "SELECT
        COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0) AS income,
        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS expense,
        COUNT(*) AS tx_count
     FROM transaction_tags jt
     JOIN transactions t ON t.id = jt.transaction_id
     WHERE jt.tag_id = ?",
    [$id]
)->fetch();
$income  = (float)$tx['income'];
$expense = (float)$tx['expense'];

$projects = q('SELECT * FROM tag_projects WHERE tag_id = ?
               ORDER BY COALESCE(start_date, DATE(created_at)) DESC, id DESC', [$id])->fetchAll();

// قيمة المشاريع النشطة فقط تُحتسب ضمن المستحقات
$projectsTotal = 0.0;
foreach ($projects as $p) {
    if ((int)$p['status'] === 1) {
        $projectsTotal += (float)$p['value'];
    }
}

$owed      = $income + $projectsTotal;   // إجمالي المستحق له
$remaining = $owed - $expense;           // المتبقّي له (أو عليه إن كان سالباً)

$pageTitle = 'حساب التاق: ' . $tag['name'];
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h5 class="mb-0"><i class="bi bi-person-badge"></i> حساب: <?= e($tag['name']) ?></h5>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>transactions.php?tag_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-journal-text"></i> عمليات التاق
        </a>
        <a href="<?= APP_URL ?>tags.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-right"></i> كل التاقات
        </a>
    </div>
</div>

<!-- ملخص الحساب -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon income"><i class="bi bi-arrow-down-circle"></i></div>
            <div><div class="stat-label">الوارد له (إيرادات)</div><div class="stat-value"><?= format_amount($income) ?></div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon balance"><i class="bi bi-kanban"></i></div>
            <div><div class="stat-label">قيمة المشاريع المنفَّذة له</div><div class="stat-value"><?= format_amount($projectsTotal) ?></div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon expense"><i class="bi bi-arrow-up-circle"></i></div>
            <div><div class="stat-label">المصروف نيابةً عنه</div><div class="stat-value"><?= format_amount($expense) ?></div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon <?= $remaining >= 0 ? 'income' : 'expense' ?>"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="stat-label">المتبقّي</div>
                <div class="stat-value <?= $remaining >= 0 ? 'positive' : 'negative' ?>">
                    <?php if (abs($remaining) < 0.005): ?>
                        مسدّد
                    <?php else: ?>
                        <?= ($remaining > 0 ? 'له ' : 'عليه ') . format_amount(abs($remaining)) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info d-flex align-items-center gap-2 py-2">
    <i class="bi bi-info-circle"></i>
    <div class="small">
        المعادلة: <strong>المتبقّي له = (الوارد له + قيمة المشاريع) − المصروف نيابةً عنه</strong>.
        قيمة المشاريع تُحتسب ضمن مستحقات هذا التاق فقط، <strong>ولا تدخل ضمن الرصيد العام أو الإحصائيات أو كشف الحساب</strong>.
    </div>
</div>

<div class="row g-4">
    <!-- إضافة مشروع -->
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle"></i> إضافة مشروع خاص</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">اسم المشروع <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" maxlength="150" required
                               placeholder="مثال: مشروع الآبل">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">قيمة المشروع (<?= e(setting('currency', 'ر.س')) ?>) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="value" step="0.01" min="0.01" dir="ltr" required placeholder="0.00">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">من تاريخ</label>
                            <input type="date" class="form-control" name="start_date">
                        </div>
                        <div class="col-6">
                            <label class="form-label">إلى تاريخ</label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="2" maxlength="500"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-circle"></i> إضافة المشروع</button>
                </form>
            </div>
        </div>
    </div>

    <!-- قائمة المشاريع -->
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-kanban"></i> المشاريع الخاصة (<?= count($projects) ?>)</span>
                <span class="small text-muted">إجمالي النشِط: <?= format_amount($projectsTotal) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (!$projects): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-kanban fs-1 d-block mb-2"></i>
                        لا توجد مشاريع خاصة لهذا التاق بعد
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-grid align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>المشروع</th>
                                    <th>التاريخ</th>
                                    <th>القيمة</th>
                                    <th>الحالة</th>
                                    <th>ملاحظات</th>
                                    <th class="text-center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projects as $p): ?>
                                    <tr class="<?= (int)$p['status'] === 1 ? '' : 'text-muted' ?>">
                                        <td class="fw-bold"><?= e($p['name']) ?></td>
                                        <td>
                                            <?php
                                            $s = $p['start_date'] ? format_date($p['start_date']) : '—';
                                            echo e($s);
                                            if ($p['end_date']) { echo ' ← ' . e(format_date($p['end_date'])); }
                                            ?>
                                        </td>
                                        <td class="amount-income"><?= format_amount($p['value']) ?></td>
                                        <td>
                                            <span class="badge text-bg-<?= (int)$p['status'] === 1 ? 'success' : 'secondary' ?>">
                                                <?= (int)$p['status'] === 1 ? 'نشِط' : 'مؤرشف' ?>
                                            </span>
                                        </td>
                                        <td class="cell-wrap"><?= e($p['notes']) ?: '-' ?></td>
                                        <td class="text-center">
                                            <div class="d-inline-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                        data-bs-toggle="modal" data-bs-target="#editModal"
                                                        data-id="<?= $p['id'] ?>"
                                                        data-name="<?= e($p['name']) ?>"
                                                        data-value="<?= e((string)$p['value']) ?>"
                                                        data-start="<?= e((string)$p['start_date']) ?>"
                                                        data-end="<?= e((string)$p['end_date']) ?>"
                                                        data-notes="<?= e((string)$p['notes']) ?>" title="تعديل">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="post">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="أرشفة / تفعيل">
                                                        <i class="bi bi-archive"></i>
                                                    </button>
                                                </form>
                                                <form method="post" data-confirm="حذف المشروع نهائياً؟">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
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

<!-- نافذة تعديل المشروع -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="project_id" id="editProjectId">
                <div class="modal-header">
                    <h5 class="modal-title">تعديل المشروع</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المشروع</label>
                        <input type="text" class="form-control" name="name" id="editName" maxlength="150" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">القيمة (<?= e(setting('currency', 'ر.س')) ?>)</label>
                        <input type="number" class="form-control" name="value" id="editValue" step="0.01" min="0.01" dir="ltr" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">من تاريخ</label>
                            <input type="date" class="form-control" name="start_date" id="editStart">
                        </div>
                        <div class="col-6">
                            <label class="form-label">إلى تاريخ</label>
                            <input type="date" class="form-control" name="end_date" id="editEnd">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" id="editNotes" rows="2" maxlength="500"></textarea>
                    </div>
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
    const b = event.relatedTarget;
    document.getElementById('editProjectId').value = b.getAttribute('data-id');
    document.getElementById('editName').value  = b.getAttribute('data-name');
    document.getElementById('editValue').value = b.getAttribute('data-value');
    document.getElementById('editStart').value = b.getAttribute('data-start');
    document.getElementById('editEnd').value   = b.getAttribute('data-end');
    document.getElementById('editNotes').value = b.getAttribute('data-notes');
});
</script>
HTML;
require BASE_PATH . '/includes/layout/footer.php';
?>

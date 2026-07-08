<?php
/**
 * رصيد - إدارة المستخدمين (مدير النظام فقط)
 */
require __DIR__ . '/includes/init.php';
require_admin();

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    try {
        if ($action === 'toggle') {
            if ($id === (int)$me['id']) {
                flash('danger', 'لا يمكنك إيقاف حسابك الحالي.');
            } else {
                q('UPDATE users SET status = 1 - status WHERE id = ?', [$id]);
                flash('success', 'تم تغيير حالة المستخدم.');
            }
        } elseif ($action === 'delete') {
            if ($id === (int)$me['id']) {
                flash('danger', 'لا يمكنك حذف حسابك الحالي.');
            } else {
                q('DELETE FROM users WHERE id = ?', [$id]);
                flash('success', 'تم حذف المستخدم بنجاح.');
            }
        } elseif ($action === 'reset_password') {
            $password = (string)($_POST['password'] ?? '');
            $confirm  = (string)($_POST['confirm'] ?? '');
            if (mb_strlen($password) < 6) {
                flash('danger', 'كلمة المرور يجب ألا تقل عن 6 أحرف.');
            } elseif ($password !== $confirm) {
                flash('danger', 'كلمة المرور وتأكيدها غير متطابقين.');
            } else {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                flash('success', 'تم إعادة تعيين كلمة المرور بنجاح.');
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('danger', 'لا يمكن حذف المستخدم لوجود عمليات مسجلة باسمه. يمكنك إيقاف الحساب بدلاً من حذفه.');
        } else {
            log_error('users: ' . $e->getMessage());
            flash('danger', 'حدث خطأ غير متوقع.');
        }
    }
    redirect(APP_URL . 'users.php');
}

$users = q('SELECT * FROM users ORDER BY role = "admin" DESC, name')->fetchAll();

$pageTitle = 'المستخدمون';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted"><?= count($users) ?> مستخدم</span>
    <a href="<?= APP_URL ?>user_form.php" class="btn btn-primary">
        <i class="bi bi-person-plus"></i> إضافة مستخدم
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-mobile align-middle">
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>اسم المستخدم</th>
                        <th>النوع</th>
                        <th>الصلاحية</th>
                        <th>الحالة</th>
                        <th>آخر دخول</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td data-label="الاسم" class="fw-bold">
                                <?= e($u['name']) ?>
                                <?php if ((int)$u['id'] === (int)$me['id']): ?>
                                    <span class="badge text-bg-info">أنت</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="اسم المستخدم" dir="ltr"><?= e($u['username']) ?></td>
                            <td data-label="النوع">
                                <span class="badge text-bg-<?= $u['role'] === 'admin' ? 'primary' : 'secondary' ?>">
                                    <?= $u['role'] === 'admin' ? 'مدير النظام' : 'مستخدم' ?>
                                </span>
                            </td>
                            <td data-label="الصلاحية">
                                <?php if ($u['role'] === 'admin'): ?>
                                    -
                                <?php else: ?>
                                    <span class="badge text-bg-<?= $u['permission'] === 'entry' ? 'success' : 'light text-dark border' ?>">
                                        <?= $u['permission'] === 'entry' ? 'إدخال' : 'مشاهدة فقط' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-label="الحالة">
                                <span class="badge text-bg-<?= $u['status'] ? 'success' : 'danger' ?>">
                                    <?= $u['status'] ? 'مفعّل' : 'موقوف' ?>
                                </span>
                            </td>
                            <td data-label="آخر دخول"><?= format_datetime($u['last_login']) ?></td>
                            <td data-label="إجراءات" class="text-center">
                                <div class="d-inline-flex gap-1 flex-wrap justify-content-center">
                                    <a href="<?= APP_URL ?>user_form.php?id=<?= $u['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" title="تعديل">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal" data-bs-target="#resetModal"
                                            data-id="<?= $u['id'] ?>" data-name="<?= e($u['name']) ?>"
                                            title="إعادة تعيين كلمة المرور">
                                        <i class="bi bi-key"></i>
                                    </button>
                                    <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="تفعيل / إيقاف">
                                                <i class="bi bi-power"></i>
                                            </button>
                                        </form>
                                        <form method="post" data-confirm="هل أنت متأكد من حذف هذا المستخدم؟">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- نافذة إعادة تعيين كلمة المرور -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" id="resetId">
                <div class="modal-header">
                    <h5 class="modal-title">إعادة تعيين كلمة المرور: <span id="resetName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">كلمة المرور الجديدة</label>
                        <input type="password" class="form-control" name="password" required minlength="6">
                    </div>
                    <label class="form-label">تأكيد كلمة المرور</label>
                    <input type="password" class="form-control" name="confirm" required minlength="6">
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
document.getElementById('resetModal').addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    document.getElementById('resetId').value = btn.getAttribute('data-id');
    document.getElementById('resetName').textContent = btn.getAttribute('data-name');
});
</script>
HTML;
require BASE_PATH . '/includes/layout/footer.php';
?>

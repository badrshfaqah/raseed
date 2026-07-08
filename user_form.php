<?php
/**
 * رصيد - إضافة / تعديل مستخدم (مدير النظام فقط)
 */
require __DIR__ . '/includes/init.php';
require_admin();

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];

$user = [
    'name' => '', 'username' => '', 'email' => '', 'phone' => '',
    'role' => 'user', 'permission' => 'view', 'status' => 1,
];

if ($isEdit) {
    $found = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    if (!$found) {
        flash('danger', 'المستخدم غير موجود.');
        redirect(APP_URL . 'users.php');
    }
    $user = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $user = array_merge($user, [
        'name'       => trim($_POST['name'] ?? ''),
        'username'   => trim($_POST['username'] ?? ''),
        'email'      => trim($_POST['email'] ?? ''),
        'phone'      => trim($_POST['phone'] ?? ''),
        'role'       => ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
        'permission' => ($_POST['permission'] ?? 'view') === 'entry' ? 'entry' : 'view',
        'status'     => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
    ]);
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    if ($user['name'] === '') {
        $errors[] = 'الاسم مطلوب.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $user['username'])) {
        $errors[] = 'اسم المستخدم يجب أن يكون بأحرف إنجليزية وأرقام (3-50 حرفاً).';
    }
    if ($user['email'] !== '' && !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني غير صالح.';
    }
    if (!$isEdit || $password !== '') {
        if (mb_strlen($password) < 6) {
            $errors[] = 'كلمة المرور يجب ألا تقل عن 6 أحرف.';
        }
        if ($password !== $confirm) {
            $errors[] = 'كلمة المرور وتأكيدها غير متطابقين.';
        }
    }
    // منع المدير من إزالة صلاحية الإدارة عن نفسه
    if ($isEdit && $id === (int)current_user()['id'] && $user['role'] !== 'admin') {
        $errors[] = 'لا يمكنك إزالة صلاحية مدير النظام عن حسابك الحالي.';
        $user['role'] = 'admin';
    }

    if (!$errors) {
        try {
            if ($isEdit) {
                q('UPDATE users SET name = ?, username = ?, email = ?, phone = ?, role = ?, permission = ?, status = ?
                   WHERE id = ?',
                  [$user['name'], $user['username'], $user['email'] ?: null, $user['phone'] ?: null,
                   $user['role'], $user['permission'], $user['status'], $id]);
                if ($password !== '') {
                    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                }
                flash('success', 'تم تحديث بيانات المستخدم بنجاح.');
            } else {
                q('INSERT INTO users (name, username, password_hash, email, phone, role, permission, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                  [$user['name'], $user['username'], password_hash($password, PASSWORD_DEFAULT),
                   $user['email'] ?: null, $user['phone'] ?: null, $user['role'], $user['permission'], $user['status']]);
                flash('success', 'تم إنشاء المستخدم بنجاح.');
            }
            redirect(APP_URL . 'users.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'اسم المستخدم مستخدم مسبقاً.';
            } else {
                log_error('user_form: ' . $e->getMessage());
                $errors[] = 'حدث خطأ غير متوقع.';
            }
        }
    }
}

$pageTitle = $isEdit ? 'تعديل مستخدم' : 'إضافة مستخدم';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-<?= $isEdit ? 'gear' : 'plus' ?>"></i> <?= $pageTitle ?></div>
            <div class="card-body">
                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger py-2"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">الاسم <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="<?= e($user['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">اسم المستخدم <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" value="<?= e($user['username']) ?>" required dir="ltr">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">كلمة المرور <?= $isEdit ? '<small class="text-muted">(اتركها فارغة للإبقاء عليها)</small>' : '<span class="text-danger">*</span>' ?></label>
                            <input type="password" class="form-control" name="password" <?= $isEdit ? '' : 'required' ?> minlength="6">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">تأكيد كلمة المرور</label>
                            <input type="password" class="form-control" name="confirm" <?= $isEdit ? '' : 'required' ?> minlength="6">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">البريد الإلكتروني (اختياري)</label>
                            <input type="email" class="form-control" name="email" value="<?= e($user['email'] ?? '') ?>" dir="ltr">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الجوال (اختياري)</label>
                            <input type="text" class="form-control" name="phone" value="<?= e($user['phone'] ?? '') ?>" dir="ltr">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">نوع الحساب</label>
                            <select class="form-select" name="role" id="roleSelect">
                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>مستخدم</option>
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>مدير النظام</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">صلاحية المستخدم</label>
                            <select class="form-select" name="permission" id="permissionSelect">
                                <option value="entry" <?= $user['permission'] === 'entry' ? 'selected' : '' ?>>إدخال</option>
                                <option value="view" <?= $user['permission'] === 'view' ? 'selected' : '' ?>>مشاهدة فقط</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">الحالة</label>
                            <select class="form-select" name="status">
                                <option value="1" <?= (int)$user['status'] === 1 ? 'selected' : '' ?>>مفعّل</option>
                                <option value="0" <?= (int)$user['status'] === 0 ? 'selected' : '' ?>>موقوف</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-check-circle"></i> حفظ
                        </button>
                        <a href="<?= APP_URL ?>users.php" class="btn btn-outline-secondary">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$nonce = csp_nonce();
$pageScripts = <<<HTML
<script nonce="{$nonce}">
// صلاحية المستخدم تنطبق على حسابات "مستخدم" فقط
const roleSelect = document.getElementById('roleSelect');
const permissionSelect = document.getElementById('permissionSelect');
function syncPermission() {
    permissionSelect.disabled = roleSelect.value === 'admin';
}
roleSelect.addEventListener('change', syncPermission);
syncPermission();
</script>
HTML;
require BASE_PATH . '/includes/layout/footer.php';
?>

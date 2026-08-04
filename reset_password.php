<?php
/**
 * رصيد - تعيين كلمة مرور جديدة عبر رمز الاستعادة
 *
 * يتحقق من الرمز (موجود، غير مستخدم، غير منتهٍ) عبر بصمته المخزّنة،
 * ثم يسمح بتعيين كلمة مرور جديدة. يُبطَل الرمز بعد الاستخدام وتُلغى بقية
 * رموز المستخدم، منعاً لإعادة الاستخدام.
 */
require __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(APP_URL . 'dashboard.php');
}

const RESET_MIN_PASSWORD = 8;

/** استرجاع سجل الرمز الصالح أو null */
function reset_lookup(string $token): ?array
{
    if ($token === '' || !ctype_xdigit($token)) {
        return null;
    }
    // تنظيف المنتهي قبل الفحص
    q('DELETE FROM password_resets WHERE expires_at < NOW() OR used = 1');
    $row = q(
        'SELECT * FROM password_resets
         WHERE token_hash = ? AND used = 0 AND expires_at > NOW()
         LIMIT 1',
        [hash('sha256', $token)]
    )->fetch();
    return $row ?: null;
}

$token = (string)($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
$reset = reset_lookup($token);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if (!$reset) {
        $error = 'رابط إعادة التعيين غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً.';
    } elseif (strlen($password) < RESET_MIN_PASSWORD) {
        $error = 'كلمة المرور يجب أن تكون ' . RESET_MIN_PASSWORD . ' أحرف على الأقل.';
    } elseif ($password !== $confirm) {
        $error = 'كلمتا المرور غير متطابقتين.';
    } else {
        try {
            db()->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            q('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $reset['user_id']]);
            // إبطال الرمز الحالي وبقية رموز المستخدم
            q('UPDATE password_resets SET used = 1 WHERE user_id = ?', [$reset['user_id']]);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            log_error('reset_password: ' . $e->getMessage());
            $error = 'تعذّر حفظ كلمة المرور الجديدة، يرجى المحاولة مجدداً.';
        }

        if ($error === '') {
            flash('success', 'تم تعيين كلمة المرور الجديدة بنجاح. يمكنك تسجيل الدخول الآن.');
            redirect(APP_URL . 'login.php');
        }
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تعيين كلمة مرور جديدة - <?= e(setting('system_name', 'رصيد')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>assets/css/style.css?v=<?= RASEED_VERSION ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <i class="bi bi-shield-lock"></i>
            <h1>كلمة مرور جديدة</h1>
            <p class="text-muted small mb-0"><?= e(setting('system_name', 'رصيد')) ?></p>
        </div>

        <?php if (!$reset && $error === ''): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-octagon"></i>
                رابط إعادة التعيين غير صالح أو انتهت صلاحيته أو سبق استخدامه.
            </div>
            <a href="<?= APP_URL ?>forgot_password.php" class="btn btn-primary btn-lg w-100">
                <i class="bi bi-arrow-repeat"></i> طلب رابط جديد
            </a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($reset): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="mb-3">
                        <label class="form-label">كلمة المرور الجديدة</label>
                        <input type="password" class="form-control form-control-lg" name="password"
                               minlength="<?= RESET_MIN_PASSWORD ?>" required autofocus>
                        <div class="form-text"><?= RESET_MIN_PASSWORD ?> أحرف على الأقل.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">تأكيد كلمة المرور</label>
                        <input type="password" class="form-control form-control-lg" name="password_confirm"
                               minlength="<?= RESET_MIN_PASSWORD ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-check-circle"></i> حفظ كلمة المرور
                    </button>
                </form>
            <?php else: ?>
                <a href="<?= APP_URL ?>forgot_password.php" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-arrow-repeat"></i> طلب رابط جديد
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <div class="auth-footer">
            تطوير <a href="https://almgrat.com" target="_blank" rel="noopener">برمجة المجرات</a>
        </div>
    </div>
</div>
</body>
</html>

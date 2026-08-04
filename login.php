<?php
/**
 * رصيد - تسجيل الدخول
 */
require __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(APP_URL . 'dashboard.php');
}

$error = '';

// حماية من هجمات التخمين: قفل مؤقت بعد 5 محاولات فاشلة خلال 15 دقيقة
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MIN   = 15;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $ip       = substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);

    if ($username === '' || $password === '') {
        $error = 'يرجى إدخال اسم المستخدم وكلمة المرور.';
    } else {
        // تنظيف السجلات القديمة ثم فحص القفل المؤقت
        q('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
        $failures = (int)q(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE)
               AND (username = ? OR ip = ?)',
            [$username, $ip]
        )->fetchColumn();

        if ($failures >= LOGIN_MAX_ATTEMPTS) {
            $error = 'تم إيقاف محاولات الدخول مؤقتاً بسبب تكرار المحاولات الفاشلة. حاول مجدداً بعد ' . LOGIN_WINDOW_MIN . ' دقيقة.';
        } else {
            $user = q('SELECT * FROM users WHERE username = ?', [$username])->fetch();

            // مقارنة بوقت ثابت حتى عند عدم وجود المستخدم (منع كشف أسماء المستخدمين عبر فارق التوقيت)
            $dummyHash = '$2y$12$Ujo9IDMUHMixc0snyhULMeR/iUbTYSAUh..wAowFvdseFdlOAcMHa';
            $validPassword = password_verify($password, $user['password_hash'] ?? $dummyHash) && $user;

            if ($validPassword) {
                if ((int)$user['status'] !== 1) {
                    $error = 'هذا الحساب موقوف، يرجى مراجعة مدير النظام.';
                } else {
                    q('INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, 1)', [$username, $ip]);
                    q('DELETE FROM login_attempts WHERE username = ? AND success = 0', [$username]);
                    session_regenerate_id(true);
                    $_SESSION['user_id']        = (int)$user['id'];
                    $_SESSION['regenerated_at'] = time();
                    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
                    redirect(APP_URL . 'dashboard.php');
                }
            } else {
                q('INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, 0)', [$username, $ip]);
                sleep(1); // إبطاء إضافي لكل محاولة فاشلة
                $error = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول - <?= e(setting('system_name', 'رصيد')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>assets/css/style.css?v=<?= RASEED_VERSION ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <i class="bi bi-wallet2"></i>
            <h1><?= e(setting('system_name', 'رصيد')) ?></h1>
            <?php if (setting('company_name')): ?>
                <p class="text-muted small mb-0"><?= e(setting('company_name')) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">اسم المستخدم</label>
                <input type="text" class="form-control form-control-lg" name="username"
                       value="<?= e($_POST['username'] ?? '') ?>" required autofocus dir="ltr">
            </div>
            <div class="mb-4">
                <label class="form-label">كلمة المرور</label>
                <input type="password" class="form-control form-control-lg" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100">
                <i class="bi bi-box-arrow-in-left"></i> تسجيل الدخول
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="<?= APP_URL ?>forgot_password.php" class="text-decoration-none small">
                <i class="bi bi-key"></i> نسيت كلمة المرور؟
            </a>
        </div>

        <div class="auth-footer">
            تطوير <a href="https://almgrat.com" target="_blank" rel="noopener">برمجة المجرات</a>
        </div>
    </div>
</div>
</body>
</html>

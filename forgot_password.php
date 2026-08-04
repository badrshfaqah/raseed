<?php
/**
 * رصيد - طلب إعادة تعيين كلمة المرور عبر البريد
 *
 * يُدخل المستخدم اسم المستخدم أو البريد. عند التطابق مع حساب فعّال له بريد
 * مسجّل، نولّد رمزاً عشوائياً ونخزّن بصمته (SHA-256) مع صلاحية ساعة واحدة،
 * ثم نرسل رابط إعادة التعيين. الرد دائماً عام (لا يكشف وجود الحساب من عدمه).
 */
require __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect(APP_URL . 'dashboard.php');
}

require BASE_PATH . '/includes/Mailer.php';

const RESET_TTL_MINUTES  = 60;  // مدة صلاحية الرابط بالدقائق
const RESET_MAX_PER_USER = 3;   // أقصى عدد طلبات فعّالة لكل مستخدم خلال نافذة القفل
const RESET_WINDOW_MIN   = 15;  // نافذة احتساب الطلبات المتكررة بالدقائق

$done  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier === '') {
        $error = 'يرجى إدخال اسم المستخدم أو البريد الإلكتروني.';
    } else {
        // تنظيف الرموز المنتهية/المستخدمة قديماً
        q('DELETE FROM password_resets WHERE expires_at < NOW() OR used = 1');

        $user = q(
            'SELECT * FROM users
             WHERE status = 1 AND (username = ? OR email = ?)
             LIMIT 1',
            [$identifier, $identifier]
        )->fetch();

        if ($user && !empty($user['email'])) {
            // كبح إساءة الاستخدام: عدد الطلبات الأخيرة لهذا المستخدم
            $recent = (int)q(
                'SELECT COUNT(*) FROM password_resets
                 WHERE user_id = ? AND created_at > (NOW() - INTERVAL ' . RESET_WINDOW_MIN . ' MINUTE)',
                [$user['id']]
            )->fetchColumn();

            if ($recent < RESET_MAX_PER_USER) {
                $token     = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                q(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at)
                     VALUES (?, ?, (NOW() + INTERVAL ' . RESET_TTL_MINUTES . ' MINUTE))',
                    [$user['id'], $tokenHash]
                );

                $link = app_base_url() . 'reset_password.php?token=' . $token;
                $msg  = Mailer::passwordResetMessage($user['name'], $link, RESET_TTL_MINUTES);
                $sent = Mailer::send($user['email'], $msg['subject'], $msg['html'], $msg['text']);
                if (!$sent) {
                    log_error('password reset: تعذّر إرسال البريد إلى المستخدم #' . $user['id']);
                }
            }
        }

        // رد عام موحّد مهما كانت النتيجة (منع كشف وجود الحسابات)
        $done = true;
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>استعادة كلمة المرور - <?= e(setting('system_name', 'رصيد')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>assets/css/style.css?v=<?= RASEED_VERSION ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <i class="bi bi-key"></i>
            <h1>استعادة كلمة المرور</h1>
            <p class="text-muted small mb-0"><?= e(setting('system_name', 'رصيد')) ?></p>
        </div>

        <?php if ($done): ?>
            <div class="alert alert-success">
                <i class="bi bi-envelope-check"></i>
                إذا كان الحساب موجوداً وله بريد إلكتروني مسجّل، فقد أرسلنا إليه رابطاً لإعادة تعيين كلمة المرور.
                تحقّق من بريدك (وصندوق الرسائل غير المرغوبة). الرابط صالح لمدة <?= RESET_TTL_MINUTES ?> دقيقة.
            </div>
            <a href="<?= APP_URL ?>login.php" class="btn btn-primary btn-lg w-100">
                <i class="bi bi-box-arrow-in-left"></i> العودة لتسجيل الدخول
            </a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>
            <p class="text-muted small">
                أدخل اسم المستخدم أو البريد الإلكتروني المرتبط بحسابك، وسنرسل لك رابطاً لإعادة تعيين كلمة المرور.
            </p>
            <form method="post">
                <?= csrf_field() ?>
                <div class="mb-4">
                    <label class="form-label">اسم المستخدم أو البريد الإلكتروني</label>
                    <input type="text" class="form-control form-control-lg" name="identifier"
                           value="<?= e($_POST['identifier'] ?? '') ?>" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-envelope-arrow-up"></i> إرسال رابط الاستعادة
                </button>
            </form>
            <div class="text-center mt-3">
                <a href="<?= APP_URL ?>login.php" class="text-decoration-none small">
                    <i class="bi bi-arrow-right"></i> العودة لتسجيل الدخول
                </a>
            </div>
        <?php endif; ?>

        <div class="auth-footer">
            تطوير <a href="https://almgrat.com" target="_blank" rel="noopener">برمجة المجرات</a>
        </div>
    </div>
</div>
</body>
</html>

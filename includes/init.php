<?php
/**
 * رصيد - نواة تشغيل النظام
 * يتم تضمين هذا الملف في أعلى كل صفحة.
 */
declare(strict_types=1);

define('RASEED', true);
define('BASE_PATH', dirname(__DIR__));
define('RASEED_VERSION', '1.0.3');

// رابط جذر التطبيق (يدعم التشغيل من مجلد فرعي مثل domain.com/raseed)
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$appUrl = preg_replace('#/(api|install)$#', '', $scriptDir);
define('APP_URL', ($appUrl === '' ? '' : $appUrl) . '/');

// التحويل إلى معالج التثبيت إذا لم يكن النظام مثبتاً
if (!is_file(BASE_PATH . '/config.php')) {
    header('Location: ' . APP_URL . 'install/');
    exit;
}

require BASE_PATH . '/config.php';

if (!defined('RASEED_INSTALLED') || RASEED_INSTALLED !== true) {
    header('Location: ' . APP_URL . 'install/');
    exit;
}

// إعدادات الجلسة الآمنة
ini_set('session.use_strict_mode', '1');
session_name('raseed_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => APP_URL,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

require BASE_PATH . '/includes/db.php';
require BASE_PATH . '/includes/functions.php';
require BASE_PATH . '/includes/auth.php';
require BASE_PATH . '/includes/migrations.php';

send_security_headers();

// تحصين الجلسة: ربطها ببصمة المتصفح لمنع سرقة معرف الجلسة
$fingerprint = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
if (isset($_SESSION['fingerprint']) && !hash_equals($_SESSION['fingerprint'], $fingerprint)) {
    session_unset();
    session_regenerate_id(true);
}
$_SESSION['fingerprint'] = $fingerprint;

// إنهاء الجلسة تلقائياً بعد 30 دقيقة من الخمول
if (!empty($_SESSION['user_id'])) {
    if (time() - ($_SESSION['last_activity'] ?? time()) > 1800) {
        session_unset();
        session_regenerate_id(true);
    } elseif (time() - ($_SESSION['regenerated_at'] ?? 0) > 1800) {
        // تجديد معرف الجلسة دورياً أثناء الاستخدام الطويل
        session_regenerate_id(true);
        $_SESSION['regenerated_at'] = time();
    }
}
$_SESSION['last_activity'] = time();

// تسجيل الأخطاء في ملف Log محمي بدل عرضها
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', log_file());

set_exception_handler(function (Throwable $e) {
    log_error('Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><body style="font-family:sans-serif;text-align:center;padding:60px">'
        . '<h3>حدث خطأ غير متوقع</h3><p>تم تسجيل الخطأ، يرجى المحاولة لاحقاً.</p></body></html>';
    exit;
});

// تحميل إعدادات النظام وضبط المنطقة الزمنية
load_settings();
date_default_timezone_set(setting('timezone', 'Asia/Riyadh'));

// بوابة الترقية: إذا كانت بنية القاعدة أقدم من الكود (بعد رفع نسخة جديدة)،
// نمنع تشغيل بقية الصفحات ببنية ناقصة. المدير يُوجَّه لصفحة الترقية،
// وغير المدير يرى رسالة صيانة. لا يُطبَّق على صفحة الترقية وتسجيل الخروج.
if (needs_upgrade() && current_user()) {
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!in_array($currentScript, ['upgrade.php', 'logout.php'], true)) {
        if (is_admin()) {
            redirect(APP_URL . 'upgrade.php');
        }
        http_response_code(503);
        echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">'
            . '<body style="font-family:sans-serif;text-align:center;padding:60px">'
            . '<h3>النظام تحت التحديث</h3><p>يرجى المحاولة بعد قليل. إذا استمرت الرسالة، راجع مدير النظام.</p></body></html>';
        exit;
    }
}

// النسخ الاحتياطي اليومي التلقائي (خطة احتياطية لو لم يُضبط Cron):
// عند أول استخدام في اليوم من مستخدم مسجّل، تُنشأ نسخة اليوم مرة واحدة.
// نضبط تاريخ اليوم أولاً (قفل تفاؤلي) لمنع تكرار التشغيل مع الطلبات المتزامنة،
// وأي فشل يُسجَّل ولا يوقف الصفحة.
if (current_user()
    && setting('auto_backup_enabled', '1') === '1'
    && setting('last_auto_backup') !== date('Y-m-d')
    && !needs_upgrade()) {
    set_setting('last_auto_backup', date('Y-m-d'));
    try {
        require_once BASE_PATH . '/includes/Xlsx.php';
        require_once BASE_PATH . '/includes/Backup.php';
        Backup::runDaily();
    } catch (Throwable $e) {
        log_error('auto-backup: ' . $e->getMessage());
    }
}

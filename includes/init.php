<?php
/**
 * رصيد - نواة تشغيل النظام
 * يتم تضمين هذا الملف في أعلى كل صفحة.
 */
declare(strict_types=1);

define('RASEED', true);
define('BASE_PATH', dirname(__DIR__));
define('RASEED_VERSION', '1.0.0');

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

// تسجيل الأخطاء في ملف Log بدل عرضها
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/logs/error.log');

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

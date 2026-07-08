<?php
/**
 * رصيد - نقطة تشغيل النسخ الاحتياطي اليومي
 *
 * تُستدعى بإحدى طريقتين:
 *  1) عبر Cron على الاستضافة (الموصى بها):
 *       - أمر PHP:  php /path/to/raseed/backup_cron.php
 *       - أو رابط:  wget -q -O - "https://domain.com/raseed/backup_cron.php?token=TOKEN"
 *  2) تلقائياً كخطة احتياطية عند أول استخدام للنظام في اليوم (عبر init.php).
 *
 * عند الاستدعاء عبر الويب يجب تمرير token المطابق لإعداد backup_token
 * (يظهر في صفحة النسخ الاحتياطي) لمنع أي تشغيل غير مصرّح به.
 */
define('RASEED', true);

$basePath = __DIR__;
if (!is_file($basePath . '/config.php')) {
    http_response_code(503);
    exit('النظام غير مثبّت.');
}

require $basePath . '/config.php';
require $basePath . '/includes/db.php';
require $basePath . '/includes/functions.php';
require $basePath . '/includes/Xlsx.php';
require $basePath . '/includes/Backup.php';

define('BASE_PATH', $basePath);

$isCli = PHP_SAPI === 'cli';

load_settings();
date_default_timezone_set(setting('timezone', 'Asia/Riyadh'));

// عبر الويب: التحقق من التوكن. عبر سطر الأوامر (Cron): مسموح مباشرة.
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    $expected = setting('backup_token');
    if ($expected === '' || !hash_equals($expected, (string) $token)) {
        http_response_code(403);
        exit('رمز غير صالح.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$result = Backup::runDaily();

echo ($result['ok'] ? '[OK] ' : '[FAILED] ') . $result['message'] . "\n";
if ($result['ok']) {
    echo 'SQL: ' . $result['sql'] . "\n";
    echo 'XLSX: ' . ($result['xlsx'] ?? '(غير متوفر - ZipArchive مفقود)') . "\n";
}
exit($result['ok'] ? 0 : 1);

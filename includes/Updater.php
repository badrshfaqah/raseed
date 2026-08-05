<?php
/**
 * رصيد - محرّك التحديث الذاتي من GitHub
 *
 * يفحص آخر إصدار على مستودع GitHub العام، ينزّله كحزمة ZIP، ويطبّقه
 * فوق ملفات البرنامج مع الحفاظ التام على ملفات العميل (config.php والسجلات
 * والنسخ الاحتياطية) حسب قائمة preserve في almgrat.json. يأخذ نسخة احتياطية
 * تلقائية قبل التطبيق، ثم يترك تطبيق ترقيات قاعدة البيانات لصفحة upgrade.php.
 */
defined('RASEED') || exit;

class Updater
{
    /** إعدادات المصدر (المستودع والفرع) مع القيم الافتراضية */
    public static function repo(): string
    {
        return setting('update_repo', 'badrshfaqah/raseed') ?: 'badrshfaqah/raseed';
    }

    public static function branch(): string
    {
        return setting('update_branch', 'main') ?: 'main';
    }

    /** إصدار البرنامج المثبَّت حالياً (من almgrat.json المحلي) */
    public static function localVersion(): string
    {
        return self::readVersion(@file_get_contents(BASE_PATH . '/almgrat.json'));
    }

    /** إصدار آخر نسخة على GitHub (من almgrat.json البعيد) */
    public static function remoteVersion(): string
    {
        $url = 'https://raw.githubusercontent.com/' . self::repo() . '/' . rawurlencode(self::branch()) . '/almgrat.json';
        return self::readVersion(self::httpGet($url));
    }

    /** هل يتوفّر تحديث؟ (الإصدار البعيد أحدث من المحلي) */
    public static function hasUpdate(): bool
    {
        $remote = self::remoteVersion();
        return $remote !== '' && version_compare($remote, self::localVersion(), '>');
    }

    /** استخراج قيمة version من نص almgrat.json */
    private static function readVersion(?string $json): string
    {
        if (!$json) {
            return '';
        }
        $data = json_decode($json, true);
        return is_array($data) && !empty($data['version']) ? (string) $data['version'] : '';
    }

    /** قائمة الملفات/المجلدات التي لا يلمسها التحديث (من almgrat.json) */
    public static function preserveList(): array
    {
        $data = json_decode(@file_get_contents(BASE_PATH . '/almgrat.json'), true);
        $preserve = is_array($data) && !empty($data['preserve']) ? $data['preserve'] : [];
        // ثوابت لا تُلمس أبداً مهما كان الإعداد
        return array_values(array_unique(array_merge($preserve, ['config.php', 'logs', 'backups', '.git'])));
    }

    /**
     * تنفيذ التحديث بالكامل.
     *
     * @param bool $force عند التفعيل يتجاوز فحص رقم الإصدار ويعيد سحب آخر ملفات
     *                    الفرع وتطبيقها مهما كان الرقم (مفيد لو نُسي رفع الإصدار).
     * @return array{ok:bool, message:string, from?:string, to?:string}
     */
    public static function run(bool $force = false): array
    {
        $localVer  = self::localVersion();
        $remoteVer = self::remoteVersion();

        // في الوضع العادي نتحقق من توفّر إصدار أحدث؛ في وضع الفرض نتجاوز ذلك
        if (!$force) {
            if ($remoteVer === '') {
                return ['ok' => false, 'message' => 'تعذّر قراءة إصدار النسخة الأحدث من GitHub. تحقّق من إعدادات المستودع والفرع والاتصال.'];
            }
            if (version_compare($remoteVer, $localVer, '<=')) {
                return ['ok' => false, 'message' => 'النظام محدَّث بالفعل (الإصدار ' . $localVer . ').'];
            }
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'امتداد ZipArchive غير متوفر على السيرفر، ولا يمكن تطبيق التحديث تلقائياً.'];
        }
        if (!is_writable(BASE_PATH)) {
            return ['ok' => false, 'message' => 'مجلد النظام غير قابل للكتابة، فلا يمكن تطبيق التحديث. عدّل الصلاحيات ثم أعد المحاولة.'];
        }

        $tmpDir = BASE_PATH . '/logs/_update_' . date('YmdHis');
        $zipPath = $tmpDir . '.zip';

        try {
            // 1) نسخة احتياطية وقائية قبل أي تعديل
            require_once BASE_PATH . '/includes/Xlsx.php';
            require_once BASE_PATH . '/includes/Backup.php';
            Backup::runDaily();

            // 2) تنزيل حزمة الفرع من GitHub
            $zipUrl = 'https://codeload.github.com/' . self::repo() . '/zip/refs/heads/' . rawurlencode(self::branch());
            $data = self::httpGet($zipUrl, true);
            if ($data === null || strlen($data) < 1000) {
                throw new RuntimeException('فشل تنزيل حزمة التحديث من GitHub.');
            }
            if (file_put_contents($zipPath, $data) === false) {
                throw new RuntimeException('تعذّرت كتابة ملف التحديث المؤقت.');
            }

            // 3) فك الضغط إلى مجلد مؤقت
            @mkdir($tmpDir, 0755, true);
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('تعذّر فتح حزمة التحديث.');
            }
            $zip->extractTo($tmpDir);
            $zip->close();

            // 4) تحديد المجلد الجذر داخل الحزمة (repo-branch/)
            $roots = glob($tmpDir . '/*', GLOB_ONLYDIR);
            if (!$roots) {
                throw new RuntimeException('حزمة التحديث فارغة أو غير صالحة.');
            }
            $srcRoot = $roots[0];

            // 5) نسخ الملفات فوق النظام مع تجاوز قائمة preserve
            $preserve = self::preserveList();
            self::copyTree($srcRoot, BASE_PATH, $preserve);

            // 6) تنظيف الملفات المؤقتة
            self::deleteTree($tmpDir);
            @unlink($zipPath);

            $toLabel = $remoteVer !== '' ? $remoteVer : 'الأحدث';
            return [
                'ok' => true,
                'from' => $localVer,
                'to' => $toLabel,
                'message' => $force
                    ? 'تمت إعادة رفع ملفات البرنامج من الفرع ' . self::branch() . ' (الإصدار ' . $toLabel . ') بنجاح.'
                    : 'تم تحديث ملفات البرنامج من الإصدار ' . $localVer . ' إلى ' . $toLabel . ' بنجاح.',
            ];
        } catch (Throwable $e) {
            self::deleteTree($tmpDir);
            @unlink($zipPath);
            log_error('Updater: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'فشل التحديث: ' . $e->getMessage() . ' (بياناتك سليمة، ولديك نسخة احتياطية.)'];
        }
    }

    /* ---------------- أدوات مساعدة ---------------- */

    /** نسخ شجرة ملفات مع تجاوز عناصر preserve على المستوى الأعلى */
    private static function copyTree(string $src, string $dst, array $skipTop): void
    {
        $items = scandir($src);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            // تجاوز عناصر preserve والملفات الحساسة على مستوى الجذر
            if (in_array($item, $skipTop, true)) {
                continue;
            }
            $from = $src . '/' . $item;
            $to   = $dst . '/' . $item;
            if (is_dir($from)) {
                if (!is_dir($to)) {
                    @mkdir($to, 0755, true);
                }
                self::copyTree($from, $to, []); // preserve يُطبَّق على الجذر فقط
            } else {
                @copy($from, $to);
            }
        }
    }

    /** حذف شجرة ملفات بالكامل */
    private static function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::deleteTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** طلب HTTP GET عبر cURL (يعيد المحتوى أو null) */
    private static function httpGet(string $url, bool $binary = false): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $binary ? 60 : 20,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'Raseed-Updater/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($res !== false && $code >= 200 && $code < 300) ? $res : null;
    }
}

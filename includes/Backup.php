<?php
/**
 * رصيد - محرّك النسخ الاحتياطي
 *
 * يولّد نسختين من البيانات ويحفظهما في مجلد backups المحمي:
 *   1) نسخة SQL كاملة (كل الجداول - للاستعادة).
 *   2) ملف Excel (XLSX) بكل العمليات - للقراءة السريعة.
 *
 * تُشغَّل يومياً تلقائياً (عبر Cron أو احتياطياً عند أول استخدام في اليوم)،
 * مع الاحتفاظ بعدد أيام محدد وحذف الأقدم. الهدف: بقاء البيانات آمنة
 * في ملفات مستقلة حتى لو تعطّلت قاعدة البيانات.
 */
defined('RASEED') || exit;

class Backup
{
    /** ترتيب الجداول في نسخة SQL (المرجعية قبل المعتمِدة عليها) */
    public const TABLES = [
        'users', 'categories', 'items', 'tags',
        'transactions', 'google_sheet_sync_logs', 'login_attempts', 'settings',
    ];

    /** مسار مجلد النسخ الاحتياطي مع ضمان وجوده وحمايته */
    public static function dir(): string
    {
        $dir = BASE_PATH . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // حماية من الوصول المباشر عبر الويب (Apache + احتياطي)
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', "<!-- ممنوع الوصول المباشر -->\n");
        }
        return $dir;
    }

    /**
     * سرّ يُدرَج في أسماء ملفات النسخ ليجعلها غير قابلة للتخمين،
     * كطبقة حماية إضافية على الاستضافات التي لا تدعم .htaccess (nginx).
     */
    private static function fileSecret(): string
    {
        $secret = setting('backup_file_secret');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(8));
            set_setting('backup_file_secret', $secret);
        }
        return $secret;
    }

    /* ---------------- توليد نسخة SQL ---------------- */

    /** نسخة SQL كاملة (هيكل + بيانات) كنص */
    public static function sqlDump(): string
    {
        $out  = "-- رصيد - نسخة احتياطية\n";
        $out .= '-- التاريخ: ' . date('Y-m-d H:i:s') . "\n";
        $out .= '-- قاعدة البيانات: ' . DB_NAME . "\n\n";
        $out .= "SET NAMES utf8mb4;\n";
        $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach (self::TABLES as $table) {
            // تخطّي أي جدول غير موجود (توافق مع إصدارات أقدم)
            $exists = q('SELECT 1 FROM information_schema.tables
                         WHERE table_schema = DATABASE() AND table_name = ?', [$table])->fetch();
            if (!$exists) {
                continue;
            }
            $create = q("SHOW CREATE TABLE `$table`")->fetch();
            $out .= "DROP TABLE IF EXISTS `$table`;\n";
            $out .= ($create['Create Table'] ?? '') . ";\n\n";

            $st = db()->query("SELECT * FROM `$table`");
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $cols = '`' . implode('`, `', array_keys($row)) . '`';
                $vals = implode(', ', array_map(
                    fn($v) => $v === null ? 'NULL' : db()->quote((string)$v),
                    array_values($row)
                ));
                $out .= "INSERT INTO `$table` ($cols) VALUES ($vals);\n";
            }
            $out .= "\n";
        }

        $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return $out;
    }

    /* ---------------- توليد نسخة Excel للعمليات ---------------- */

    /** رؤوس وصفوف كل العمليات (لملف Excel) */
    public static function transactionRows(): array
    {
        $headers = ['#', 'التاريخ', 'النوع', 'التصنيف', 'البند', 'التاق', 'المبلغ', 'الملاحظات', 'المستخدم', 'وقت التسجيل'];
        $rows = [];
        $data = q('SELECT t.id, t.trans_date, t.type, c.name AS category_name, i.name AS item_name,
                          tg.name AS tag_name, t.amount, t.notes, u.name AS user_name, t.created_at
                   ' . tx_base_query() . '
                   ORDER BY t.trans_date DESC, t.id DESC')->fetchAll();
        foreach ($data as $r) {
            $rows[] = [
                (int)$r['id'], $r['trans_date'], type_label($r['type']),
                $r['category_name'], $r['item_name'], (string)$r['tag_name'],
                (float)$r['amount'], (string)$r['notes'], $r['user_name'], $r['created_at'],
            ];
        }
        return [$headers, $rows];
    }

    /* ---------------- التشغيل اليومي ---------------- */

    /**
     * توليد نسختي اليوم (SQL + XLSX) وتطبيق سياسة الاحتفاظ.
     * @return array{ok:bool, sql:?string, xlsx:?string, message:string}
     */
    public static function runDaily(): array
    {
        $dir    = self::dir();
        $date   = date('Y-m-d');
        $prefix = 'raseed-' . $date . '-' . self::fileSecret();

        try {
            // نسخة SQL
            $sqlFile = $dir . '/' . $prefix . '.sql';
            if (file_put_contents($sqlFile, self::sqlDump(), LOCK_EX) === false) {
                throw new RuntimeException('تعذّرت كتابة نسخة SQL - تحقق من صلاحيات مجلد backups.');
            }

            // نسخة Excel (اختيارية - تتطلب ZipArchive)
            $xlsxFile = null;
            if (class_exists('ZipArchive')) {
                [$headers, $rows] = self::transactionRows();
                $xlsxFile = $dir . '/' . $prefix . '.xlsx';
                Xlsx::writeFile($xlsxFile, $headers, $rows);
            }

            self::applyRetention($dir);

            set_setting('last_auto_backup', $date);
            set_setting('last_backup_at', date('Y-m-d H:i:s'));

            return [
                'ok'      => true,
                'sql'     => basename($sqlFile),
                'xlsx'    => $xlsxFile ? basename($xlsxFile) : null,
                'message' => 'تم إنشاء النسخة الاحتياطية اليومية بنجاح.',
            ];
        } catch (Throwable $e) {
            log_error('Backup::runDaily: ' . $e->getMessage());
            return ['ok' => false, 'sql' => null, 'xlsx' => null, 'message' => $e->getMessage()];
        }
    }

    /** حذف النسخ الأقدم من عدد أيام الاحتفاظ */
    private static function applyRetention(string $dir): void
    {
        $days = max(1, (int) setting('backup_retention_days', '14'));
        $cutoff = strtotime('-' . $days . ' days');

        foreach (self::listBackups() as $b) {
            $fileDate = strtotime($b['date']);
            if ($fileDate && $fileDate < $cutoff) {
                @unlink($dir . '/' . $b['file']);
            }
        }
    }

    /* ---------------- قائمة النسخ الموجودة ---------------- */

    /**
     * النسخ الموجودة مجمّعة حسب التاريخ.
     * @return array<int, array{date:string, file:string, type:string, size:int}>
     */
    public static function listBackups(): array
    {
        $dir = self::dir();
        $list = [];
        foreach (glob($dir . '/raseed-*.{sql,xlsx}', GLOB_BRACE) ?: [] as $path) {
            $file = basename($path);
            if (preg_match('/^raseed-(\d{4}-\d{2}-\d{2})-[a-f0-9]+\.(sql|xlsx)$/', $file, $m)) {
                $list[] = [
                    'date' => $m[1],
                    'file' => $file,
                    'type' => $m[2],
                    'size' => (int) @filesize($path),
                ];
            }
        }
        // الأحدث أولاً
        usort($list, fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['type'], $b['type']));
        return $list;
    }

    /** التحقق من صلاحية اسم ملف نسخة (لمنع اجتياز المسارات) وإرجاع مساره أو null */
    public static function safePath(string $file): ?string
    {
        if (!preg_match('/^raseed-\d{4}-\d{2}-\d{2}-[a-f0-9]+\.(sql|xlsx)$/', $file)) {
            return null;
        }
        $path = self::dir() . '/' . $file;
        return is_file($path) ? $path : null;
    }
}

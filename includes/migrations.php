<?php
/**
 * رصيد - نظام إصدارات وترقية بنية قاعدة البيانات
 *
 * الفكرة: رقم إصدار بنية القاعدة يُحفظ في جدول settings (المفتاح db_version).
 * عند إضافة أي تغيير جدولي مستقبلاً (عمود/جدول جديد) نرفع RASEED_DB_VERSION
 * ونضيف جمل SQL الخاصة به في raseed_migrations()، ثم يكفي فتح upgrade.php
 * ليطبّق الناقص فقط دون لمس أي ملف PHP آخر ولا أي بيانات موجودة.
 */
defined('RASEED') || defined('RASEED_INSTALLER') || exit;

/** إصدار بنية قاعدة البيانات المطلوب لهذا الكود */
const RASEED_DB_VERSION = 2;

/**
 * سجل الترقيات: المفتاح = رقم الإصدار، القيمة = مصفوفة جمل SQL
 * تُنفَّذ للوصول إلى ذلك الإصدار من الإصدار الذي قبله مباشرة.
 *
 * الإصدار 1 هو الأساس (ما ينشئه install/schema.php)، فلا ترقية له.
 * أي تغيير جديد يبدأ من الإصدار 2 فصاعداً.
 *
 * مثال لإضافة مستقبلية (لا تفعّله الآن، للتوضيح فقط):
 *   2 => [
 *       "ALTER TABLE transactions ADD COLUMN attachment VARCHAR(255) NULL",
 *   ],
 *   3 => [
 *       "CREATE TABLE audit_log ( ... ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 *   ],
 */
function raseed_migrations(): array
{
    return [
        // الإصدار 2: ميزة التاق (وسم إضافي للعمليات لتتبّع إيراداتها ومصروفاتها)
        2 => [
            "CREATE TABLE IF NOT EXISTS tags (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tag_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE transactions
                ADD COLUMN tag_id INT UNSIGNED DEFAULT NULL AFTER item_id,
                ADD KEY idx_tx_tag (tag_id),
                ADD CONSTRAINT fk_tx_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE SET NULL",
        ],
    ];
}

/** إصدار القاعدة الحالي المخزَّن (0 إن لم يُسجَّل بعد) */
function db_version(): int
{
    return (int) setting('db_version', '0');
}

/**
 * الترقيات المعلّقة (الأحدث من الإصدار الحالي) مرتبة تصاعدياً.
 * ملاحظة: التركيبات القديمة التي لا تحمل db_version تُعامل كإصدار 0،
 * وبما أن أول ترقية فعلية تبدأ من 2 فما فوق، فإن أي تركيب على الأساس
 * (كامل الجداول) لن يُعيد تنفيذ شيء موجود مسبقاً.
 */
function pending_migrations(): array
{
    $current = db_version();
    $pending = [];
    foreach (raseed_migrations() as $version => $statements) {
        if ((int) $version > $current) {
            $pending[(int) $version] = $statements;
        }
    }
    ksort($pending);
    return $pending;
}

/** هل تحتاج القاعدة إلى ترقية؟ */
function needs_upgrade(): bool
{
    return db_version() < RASEED_DB_VERSION;
}

/**
 * تطبيق كل الترقيات المعلّقة.
 * تُنفَّذ جمل كل إصدار بالترتيب، ويُحدَّث رقم الإصدار بعد نجاح كل إصدار
 * حتى يمكن استئناف الترقية من حيث توقفت لو فشل إصدار لاحق.
 *
 * @return array{ok:bool, applied:int[], message:string}
 */
function run_upgrade(): array
{
    $applied = [];
    try {
        foreach (pending_migrations() as $version => $statements) {
            foreach ($statements as $sql) {
                db()->exec($sql);
            }
            set_setting('db_version', (string) $version);
            $applied[] = $version;
        }
        // مزامنة رقم الإصدار مع الهدف حتى لو لم تكن هناك جمل (أساس بلا ترقيات)
        if (db_version() < RASEED_DB_VERSION) {
            set_setting('db_version', (string) RASEED_DB_VERSION);
        }
        return ['ok' => true, 'applied' => $applied, 'message' => 'تمت الترقية بنجاح.'];
    } catch (Throwable $e) {
        log_error('run_upgrade @v' . (end($applied) ?: db_version()) . ': ' . $e->getMessage());
        return ['ok' => false, 'applied' => $applied, 'message' => $e->getMessage()];
    }
}

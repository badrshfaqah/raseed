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
const RASEED_DB_VERSION = 5;

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

        // الإصدار 3: علامة استلام الإيصال/الفاتورة على العملية + صلاحية تحديدها لعضوية المشاهد
        3 => [
            "ALTER TABLE transactions ADD COLUMN receipt_status TINYINT(1) NOT NULL DEFAULT 0 AFTER notes",
            "ALTER TABLE users ADD COLUMN can_toggle_receipt TINYINT(1) NOT NULL DEFAULT 0 AFTER permission",
        ],

        // الإصدار 4: استعادة كلمة المرور عبر البريد (رموز إعادة تعيين مؤقتة)
        4 => [
            "CREATE TABLE IF NOT EXISTS password_resets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pr_token (token_hash),
                KEY idx_pr_user (user_id),
                CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        // الإصدار 5: تاق متعدد لكل عملية (جدول ربط) + تمييز المصروف كأصل
        5 => [
            // جدول الربط بين العمليات والتاقات (علاقة متعدّد-إلى-متعدّد)
            "CREATE TABLE IF NOT EXISTS transaction_tags (
                transaction_id INT UNSIGNED NOT NULL,
                tag_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (transaction_id, tag_id),
                KEY idx_tt_tag (tag_id),
                CONSTRAINT fk_tt_tx  FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
                CONSTRAINT fk_tt_tag FOREIGN KEY (tag_id)         REFERENCES tags (id)         ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // ترحيل التاقات الحالية (عمود tag_id المفرد) إلى جدول الربط
            "INSERT IGNORE INTO transaction_tags (transaction_id, tag_id)
                SELECT id, tag_id FROM transactions WHERE tag_id IS NOT NULL",
            // إسقاط العمود المفرد بعد الترحيل (يُسقط قيده ومفتاحه)
            "ALTER TABLE transactions DROP FOREIGN KEY fk_tx_tag",
            "ALTER TABLE transactions DROP COLUMN tag_id",
            // تمييز المصروف كأصل مع حقل اختياري لبيانات الأصل
            "ALTER TABLE transactions ADD COLUMN is_asset TINYINT(1) NOT NULL DEFAULT 0 AFTER amount",
            "ALTER TABLE transactions ADD COLUMN asset_name VARCHAR(150) DEFAULT NULL AFTER is_asset",
            "ALTER TABLE transactions ADD KEY idx_tx_asset (is_asset)",
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

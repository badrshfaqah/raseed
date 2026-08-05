<?php
/**
 * رصيد - مخطط قاعدة البيانات
 */
defined('RASEED_INSTALLER') || exit;

/** جمل إنشاء الجداول */
function raseed_schema(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            username VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(150) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            role ENUM('admin','user') NOT NULL DEFAULT 'user',
            permission ENUM('entry','view') NOT NULL DEFAULT 'view',
            can_toggle_receipt TINYINT(1) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_category_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item (category_id, name),
            KEY idx_item_category (category_id),
            CONSTRAINT fk_items_category FOREIGN KEY (category_id)
                REFERENCES categories (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tags (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tag_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS transactions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            type ENUM('income','expense') NOT NULL,
            trans_date DATE NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            is_asset TINYINT(1) NOT NULL DEFAULT 0,
            asset_name VARCHAR(150) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            receipt_status TINYINT(1) NOT NULL DEFAULT 0,
            user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_tx_date (trans_date),
            KEY idx_tx_type (type),
            KEY idx_tx_category (category_id),
            KEY idx_tx_item (item_id),
            KEY idx_tx_asset (is_asset),
            KEY idx_tx_user (user_id),
            KEY idx_tx_created (created_at),
            CONSTRAINT fk_tx_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
            CONSTRAINT fk_tx_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE RESTRICT,
            CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS transaction_tags (
            transaction_id INT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (transaction_id, tag_id),
            KEY idx_tt_tag (tag_id),
            CONSTRAINT fk_tt_tx  FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
            CONSTRAINT fk_tt_tag FOREIGN KEY (tag_id)         REFERENCES tags (id)         ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS google_sheet_sync_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_id INT UNSIGNED NOT NULL,
            action ENUM('add','update','delete') NOT NULL DEFAULT 'add',
            status ENUM('success','failed') NOT NULL,
            message VARCHAR(1000) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sync_tx (transaction_id),
            KEY idx_sync_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_attempts_user (username, attempted_at),
            KEY idx_attempts_ip (ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT DEFAULT NULL,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

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
    ];
}

/** التصنيفات والبنود الافتراضية */
function raseed_default_data(): array
{
    return [
        'ضيافة'    => ['قهوة', 'وجبات', 'مياه'],
        'نقل'      => ['وقود', 'سيارات', 'أجور'],
        'مشتريات'  => [],
        'صيانة'    => [],
        'إيرادات'  => ['دفعة وزارة', 'دفعة مشروع', 'تحويل'],
        'أخرى'     => [],
    ];
}

<?php
/**
 * رصيد - دوال مساعدة عامة
 */
defined('RASEED') || exit;

/**
 * قيمة Nonce عشوائية لكل طلب: السكربتات الداخلية التي تحملها فقط
 * هي المسموح بتنفيذها، فأي سكربت يُحقن عبر ثغرة XSS محتملة لن يعمل.
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/**
 * ترويسات أمنية تُرسل مع كل صفحة:
 * منع التضمين داخل إطارات، منع تخمين نوع المحتوى،
 * وسياسة أمان محتوى (CSP) بنظام Nonce تحصر السكربتات
 * في ملفات النظام و CDN المكتبات والسكربتات الداخلية الموقعة فقط.
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "object-src 'none'");
    // فرض HTTPS لمدة سنة عندما يكون الموقع مخدوماً عبره
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** تهريب HTML */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/* ---------------- حماية CSRF ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('انتهت صلاحية الجلسة، يرجى إعادة تحميل الصفحة والمحاولة مجدداً.');
    }
}

/* ---------------- رسائل Flash ---------------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- إعدادات النظام ---------------- */

function load_settings(): void
{
    $GLOBALS['raseed_settings'] = [];
    try {
        foreach (q('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
            $GLOBALS['raseed_settings'][$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        log_error('load_settings: ' . $e->getMessage());
    }
}

function setting(string $key, string $default = ''): string
{
    return $GLOBALS['raseed_settings'][$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    $GLOBALS['raseed_settings'][$key] = $value;
}

/* ---------------- تنسيق المبالغ والتواريخ ---------------- */

function format_amount(float|string $amount, bool $withCurrency = true): string
{
    $formatted = number_format((float)$amount, 2);
    return $withCurrency ? $formatted . ' ' . setting('currency', 'ر.س') : $formatted;
}

function format_date(?string $date): string
{
    if (!$date) {
        return '-';
    }
    return date('Y-m-d', strtotime($date));
}

function format_datetime(?string $dt): string
{
    if (!$dt) {
        return '-';
    }
    return date('Y-m-d H:i', strtotime($dt));
}

/* ---------------- سجل الأخطاء ---------------- */

function log_error(string $message): void
{
    $dir = BASE_PATH . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents(
        $dir . '/error.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- بناء استعلام كشف الحساب (مشترك بين الكشف والتصدير) ---------------- */

/**
 * يبني شرط WHERE وقيم الربط من فلاتر الطلب.
 * الفلاتر المدعومة: from, to, type, category_id, item_id, search
 */
function build_tx_filters(array $in): array
{
    $where = [];
    $bind  = [];

    if (!empty($in['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['from'])) {
        $where[] = 't.trans_date >= ?';
        $bind[]  = $in['from'];
    }
    if (!empty($in['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['to'])) {
        $where[] = 't.trans_date <= ?';
        $bind[]  = $in['to'];
    }
    if (!empty($in['type']) && in_array($in['type'], ['income', 'expense'], true)) {
        $where[] = 't.type = ?';
        $bind[]  = $in['type'];
    }
    if (!empty($in['category_id']) && ctype_digit((string)$in['category_id'])) {
        $where[] = 't.category_id = ?';
        $bind[]  = (int)$in['category_id'];
    }
    if (!empty($in['item_id']) && ctype_digit((string)$in['item_id'])) {
        $where[] = 't.item_id = ?';
        $bind[]  = (int)$in['item_id'];
    }
    if (!empty($in['search'])) {
        $where[] = '(t.notes LIKE ? OR i.name LIKE ? OR c.name LIKE ?)';
        $like    = '%' . $in['search'] . '%';
        array_push($bind, $like, $like, $like);
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $bind];
}

/** جملة SELECT الأساسية لكشف الحساب */
function tx_base_query(): string
{
    return 'FROM transactions t
            JOIN categories c ON c.id = t.category_id
            JOIN items i      ON i.id = t.item_id
            JOIN users u      ON u.id = t.user_id';
}

/** إجماليات (إيرادات، مصروفات، رصيد) وفق الفلاتر */
function tx_totals(string $whereSql, array $bind): array
{
    $row = q(
        "SELECT
            COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0) AS income,
            COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS expense
         " . tx_base_query() . " $whereSql",
        $bind
    )->fetch();

    return [
        'income'  => (float)$row['income'],
        'expense' => (float)$row['expense'],
        'balance' => (float)$row['income'] - (float)$row['expense'],
    ];
}

/** ترجمة نوع العملية */
function type_label(string $type): string
{
    return $type === 'income' ? 'إيراد' : 'مصروف';
}

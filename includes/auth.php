<?php
/**
 * رصيد - المصادقة والصلاحيات
 */
defined('RASEED') || exit;

/** المستخدم الحالي أو null */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $found = q('SELECT * FROM users WHERE id = ? AND status = 1', [(int)$_SESSION['user_id']])->fetch();
            $user = $found ?: null;
            if ($user === null) {
                // المستخدم حُذف أو أُوقف أثناء الجلسة
                unset($_SESSION['user_id']);
            }
        }
    }
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/** هل يستطيع المستخدم إضافة عمليات؟ (مدير أو صلاحية إدخال) */
function can_add(): bool
{
    $u = current_user();
    return $u && ($u['role'] === 'admin' || $u['permission'] === 'entry');
}

/** التعديل والحذف لمدير النظام فقط */
function can_edit(): bool
{
    return is_admin();
}

/**
 * هل يستطيع المستخدم تحديد حالة استلام الإيصال/الفاتورة على العملية؟
 * مدير النظام وصلاحية الإدخال يملكونها دائماً، وعضوية المشاهدة تحتاج
 * منحاً صريحاً من المدير عبر عمود can_toggle_receipt.
 */
function can_toggle_receipt(): bool
{
    $u = current_user();
    return $u && ($u['role'] === 'admin' || $u['permission'] === 'entry' || !empty($u['can_toggle_receipt']));
}

/** إلزام تسجيل الدخول */
function require_login(): void
{
    if (!current_user()) {
        redirect(APP_URL . 'login.php');
    }
}

/** إلزام صلاحية المدير */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        flash('danger', 'ليس لديك صلاحية للوصول إلى هذه الصفحة.');
        redirect(APP_URL . 'dashboard.php');
    }
}

/** إلزام صلاحية الإدخال */
function require_can_add(): void
{
    require_login();
    if (!can_add()) {
        http_response_code(403);
        flash('danger', 'صلاحيتك مشاهدة فقط، لا يمكنك إضافة عمليات.');
        redirect(APP_URL . 'dashboard.php');
    }
}

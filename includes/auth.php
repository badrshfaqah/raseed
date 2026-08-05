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

/* ---------------- تثبيت الدخول على الجهاز (تذكّرني / PWA) ---------------- */

const REMEMBER_COOKIE = 'raseed_remember';
const REMEMBER_DAYS   = 30;

/** معطيات كوكي التذكّر (المسار وحالة HTTPS) */
function remember_cookie_meta(): array
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return [APP_URL, $secure];
}

/** إصدار رمز تذكّر جديد وتخزين بصمته وإرسال الكوكي (صلاحية REMEMBER_DAYS يوماً) */
function issue_remember_token(int $userId): void
{
    try {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $exp   = date('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400);
        q('INSERT INTO auth_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)', [$userId, $hash, $exp]);
        [$path, $secure] = remember_cookie_meta();
        setcookie(REMEMBER_COOKIE, $token, [
            'expires'  => time() + REMEMBER_DAYS * 86400,
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[REMEMBER_COOKIE] = $token;
    } catch (Throwable $e) {
        log_error('issue_remember_token: ' . $e->getMessage());
    }
}

/** إلغاء رمز التذكّر الحالي (حذفه من القاعدة وإزالة الكوكي) */
function clear_remember_token(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie !== '' && ctype_xdigit($cookie)) {
        try {
            q('DELETE FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $cookie)]);
        } catch (Throwable $e) {
            log_error('clear_remember_token: ' . $e->getMessage());
        }
    }
    [$path, $secure] = remember_cookie_meta();
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 42000,
        'path'     => $path,
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/**
 * محاولة استعادة الجلسة من كوكي التذكّر (يُستدعى في نواة التشغيل).
 * يُدوِّر الرمز بعد كل استعادة ناجحة (حذف القديم وإصدار جديد) لتقليل خطر السرقة.
 * محاط بحماية شاملة حتى لا يُعطّل الموقع لو لم يوجد الجدول بعد (قبل الترقية).
 */
function try_remember_login(): void
{
    if (!empty($_SESSION['user_id']) || empty($_COOKIE[REMEMBER_COOKIE])) {
        return;
    }
    $token = (string) $_COOKIE[REMEMBER_COOKIE];
    if (!ctype_xdigit($token)) {
        clear_remember_token();
        return;
    }
    try {
        q('DELETE FROM auth_tokens WHERE expires_at < NOW()');
        $row = q(
            'SELECT at.id, at.user_id FROM auth_tokens at
             JOIN users u ON u.id = at.user_id
             WHERE at.token_hash = ? AND at.expires_at > NOW() AND u.status = 1
             LIMIT 1',
            [hash('sha256', $token)]
        )->fetch();
    } catch (Throwable $e) {
        // الجدول غير موجود بعد (قبل الترقية) أو خطأ عابر: تجاهل بصمت
        return;
    }
    if (!$row) {
        clear_remember_token();
        return;
    }
    // تثبيت الجلسة
    session_regenerate_id(true);
    $_SESSION['user_id']        = (int) $row['user_id'];
    $_SESSION['regenerated_at'] = time();
    $_SESSION['last_activity']  = time();
    // تدوير الرمز
    try {
        q('DELETE FROM auth_tokens WHERE id = ?', [(int) $row['id']]);
    } catch (Throwable $e) {
        log_error('try_remember_login rotate: ' . $e->getMessage());
    }
    issue_remember_token((int) $row['user_id']);
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

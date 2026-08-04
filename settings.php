<?php
/**
 * رصيد - إعدادات النظام (مدير النظام فقط)
 */
require __DIR__ . '/includes/init.php';
require_admin();
require BASE_PATH . '/includes/GoogleSheets.php';

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'general') {
        $timezone = trim($_POST['timezone'] ?? 'Asia/Riyadh');
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'Asia/Riyadh';
        }
        set_setting('system_name', trim($_POST['system_name'] ?? '') ?: 'رصيد');
        set_setting('company_name', trim($_POST['company_name'] ?? ''));
        set_setting('timezone', $timezone);
        set_setting('currency', trim($_POST['currency'] ?? '') ?: 'ر.س');
        flash('success', 'تم حفظ الإعدادات العامة بنجاح.');
        redirect(APP_URL . 'settings.php');
    }

    if ($action === 'mail') {
        $siteUrl = trim($_POST['site_url'] ?? '');
        if ($siteUrl !== '' && !preg_match('#^https?://#i', $siteUrl)) {
            $siteUrl = 'https://' . $siteUrl;
        }
        if ($siteUrl !== '' && !filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            flash('danger', 'رابط الموقع غير صحيح.');
            redirect(APP_URL . 'settings.php#mail');
        }
        $mailFrom = trim($_POST['mail_from'] ?? '');
        if ($mailFrom !== '' && !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'بريد المُرسِل غير صحيح.');
            redirect(APP_URL . 'settings.php#mail');
        }
        set_setting('site_url', $siteUrl);
        set_setting('mail_from', $mailFrom);
        set_setting('mail_from_name', trim($_POST['mail_from_name'] ?? ''));
        flash('success', 'تم حفظ إعدادات البريد بنجاح.');
        redirect(APP_URL . 'settings.php#mail');
    }

    if ($action === 'sheets') {
        $serviceAccount = trim($_POST['google_service_account'] ?? '');
        if ($serviceAccount !== '' && json_decode($serviceAccount, true) === null) {
            flash('danger', 'بيانات Service Account يجب أن تكون بصيغة JSON صالحة.');
            redirect(APP_URL . 'settings.php#sheets');
        }
        set_setting('google_sheet_id', trim($_POST['google_sheet_id'] ?? ''));
        set_setting('google_sheet_name', trim($_POST['google_sheet_name'] ?? '') ?: 'Transactions');
        set_setting('google_service_account', $serviceAccount);
        // إبطال التوكن المخزّن عند تغيير بيانات الربط
        set_setting('google_access_token', '');
        set_setting('google_token_expires', '0');
        flash('success', 'تم حفظ إعدادات Google Sheets بنجاح.');
        redirect(APP_URL . 'settings.php#sheets');
    }

    if ($action === 'test_sheets') {
        $testResult = GoogleSheets::enabled()
            ? GoogleSheets::testConnection()
            : ['ok' => false, 'message' => 'يرجى حفظ Google Sheet ID وبيانات Service Account أولاً.'];
    }
}

$timezones = ['Asia/Riyadh', 'Asia/Dubai', 'Asia/Kuwait', 'Asia/Qatar', 'Asia/Bahrain', 'Asia/Amman', 'Asia/Baghdad', 'Africa/Cairo', 'Asia/Beirut', 'Europe/Istanbul', 'UTC'];
$currentTz = setting('timezone', 'Asia/Riyadh');
if (!in_array($currentTz, $timezones, true)) {
    $timezones[] = $currentTz;
}

$pageTitle = 'إعدادات النظام';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row g-4">
    <!-- الإعدادات العامة -->
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-gear"></i> الإعدادات العامة</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="general">
                    <div class="mb-3">
                        <label class="form-label">اسم النظام</label>
                        <input type="text" class="form-control" name="system_name" value="<?= e(setting('system_name', 'رصيد')) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">اسم الشركة / الجهة</label>
                        <input type="text" class="form-control" name="company_name" value="<?= e(setting('company_name')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المنطقة الزمنية</label>
                        <select class="form-select" name="timezone">
                            <?php foreach ($timezones as $tz): ?>
                                <option value="<?= e($tz) ?>" <?= $tz === $currentTz ? 'selected' : '' ?>><?= e($tz) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">العملة</label>
                        <input type="text" class="form-control" name="currency" value="<?= e(setting('currency', 'ر.س')) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-circle"></i> حفظ الإعدادات العامة
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- إعدادات Google Sheets -->
    <div class="col-12 col-lg-6" id="sheets">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table"></i> إعدادات Google Sheets</span>
                <span class="badge text-bg-<?= GoogleSheets::enabled() ? 'success' : 'secondary' ?>">
                    <?= GoogleSheets::enabled() ? 'مفعّلة' : 'غير مفعّلة' ?>
                </span>
            </div>
            <div class="card-body">
                <?php if ($testResult !== null): ?>
                    <div class="alert alert-<?= $testResult['ok'] ? 'success' : 'danger' ?> py-2">
                        <?= e($testResult['message']) ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="sheets">
                    <div class="mb-3">
                        <label class="form-label">Google Sheet ID</label>
                        <input type="text" class="form-control" name="google_sheet_id"
                               value="<?= e(setting('google_sheet_id')) ?>" dir="ltr"
                               placeholder="من رابط الملف: docs.google.com/spreadsheets/d/SHEET_ID/edit">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">اسم ورقة العمل</label>
                        <input type="text" class="form-control" name="google_sheet_name"
                               value="<?= e(setting('google_sheet_name', 'Transactions')) ?>" dir="ltr">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">بيانات Service Account (JSON)</label>
                        <textarea class="form-control font-monospace" name="google_service_account" rows="6"
                                  dir="ltr" placeholder='{"type":"service_account", ...}'><?= e(setting('google_service_account')) ?></textarea>
                        <div class="form-text">
                            أنشئ Service Account من Google Cloud Console وفعّل Sheets API،
                            ثم شارك ملف الشيت مع بريد الحساب (client_email) بصلاحية تحرير.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-check-circle"></i> حفظ إعدادات Google Sheets
                    </button>
                </form>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test_sheets">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="bi bi-plug"></i> اختبار الاتصال وتجهيز ورقة العمل
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- إعدادات البريد واستعادة كلمة المرور -->
    <div class="col-12 col-lg-6" id="mail">
        <div class="card">
            <div class="card-header"><i class="bi bi-envelope-at"></i> إعدادات البريد (استعادة كلمة المرور)</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mail">
                    <div class="mb-3">
                        <label class="form-label">رابط الموقع (Site URL)</label>
                        <input type="text" class="form-control" name="site_url" dir="ltr"
                               value="<?= e(setting('site_url')) ?>"
                               placeholder="https://domain.com/raseed">
                        <div class="form-text">
                            يُستخدم لبناء رابط إعادة التعيين في البريد. ضبطه يحمي من تزوير ترويسة المضيف
                            (Host Header). اتركه فارغاً ليُبنى تلقائياً من عنوان الطلب.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">بريد المُرسِل (From)</label>
                        <input type="email" class="form-control" name="mail_from" dir="ltr"
                               value="<?= e(setting('mail_from')) ?>" placeholder="no-reply@domain.com">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">اسم المُرسِل الظاهر</label>
                        <input type="text" class="form-control" name="mail_from_name"
                               value="<?= e(setting('mail_from_name')) ?>"
                               placeholder="<?= e(setting('system_name', 'رصيد')) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-circle"></i> حفظ إعدادات البريد
                    </button>
                </form>
                <p class="text-muted small mt-3 mb-0">
                    يعتمد الإرسال على دالة البريد في PHP، وهي مفعّلة على أغلب استضافات الويب.
                    إن لم تصل الرسائل، تأكّد من إعداد البريد لدى مزوّد الاستضافة.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>

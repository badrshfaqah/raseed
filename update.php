<?php
/**
 * رصيد - تحديث البرنامج من GitHub (مدير النظام فقط)
 *
 * يفحص آخر إصدار على المستودع، وينزّله ويطبّقه مع الحفاظ على ملفات العميل،
 * ثم يوجّه إلى صفحة الترقية لتطبيق أي تعديلات على قاعدة البيانات.
 */
require __DIR__ . '/includes/init.php';
require_admin();
require BASE_PATH . '/includes/Updater.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_source') {
        $repo   = trim($_POST['update_repo'] ?? '');
        $branch = trim($_POST['update_branch'] ?? '');
        // صيغة owner/repo فقط
        if (!preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
            flash('danger', 'صيغة المستودع غير صحيحة. استخدم owner/repo.');
        } else {
            set_setting('update_repo', $repo);
            set_setting('update_branch', $branch ?: 'main');
            flash('success', 'تم حفظ إعدادات مصدر التحديث.');
        }
        redirect(APP_URL . 'update.php');
    }

    if ($action === 'update' || $action === 'force_update') {
        $result = Updater::run($action === 'force_update');
        if ($result['ok']) {
            // تحديث الإصدار المخزَّن في الجلسة غير مطلوب؛ الملفات حُدّثت.
            flash('success', $result['message'] . ' سيتم الآن فحص ترقية قاعدة البيانات.');
            redirect(APP_URL . 'upgrade.php');
        }
        flash('danger', $result['message']);
        redirect(APP_URL . 'update.php');
    }
}

// فحص الإصدارات (قد يتطلب اتصالاً بالإنترنت)
$localVersion  = Updater::localVersion();
$remoteVersion = '';
$checkError    = '';
try {
    $remoteVersion = Updater::remoteVersion();
    if ($remoteVersion === '') {
        $checkError = 'تعذّر قراءة الإصدار الأحدث من GitHub. تحقّق من المستودع والفرع واتصال السيرفر بالإنترنت.';
    }
} catch (Throwable $e) {
    $checkError = 'تعذّر الاتصال بـ GitHub: ' . $e->getMessage();
}

$hasUpdate = $remoteVersion !== '' && version_compare($remoteVersion, $localVersion, '>');

$pageTitle = 'تحديث البرنامج';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row g-4 justify-content-center">
    <div class="col-12 col-lg-8">

        <!-- حالة الإصدار -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-cloud-arrow-down"></i> تحديث البرنامج من GitHub</div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <div class="stat-card">
                            <div class="stat-icon balance"><i class="bi bi-box-seam"></i></div>
                            <div>
                                <div class="stat-label">الإصدار المثبَّت</div>
                                <div class="stat-value"><?= e($localVersion ?: '—') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card">
                            <div class="stat-icon <?= $hasUpdate ? 'expense' : 'income' ?>">
                                <i class="bi bi-<?= $hasUpdate ? 'arrow-up-circle' : 'check2-circle' ?>"></i>
                            </div>
                            <div>
                                <div class="stat-label">الإصدار الأحدث على GitHub</div>
                                <div class="stat-value"><?= e($remoteVersion ?: '—') ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($checkError): ?>
                    <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <?= e($checkError) ?></div>
                <?php elseif ($hasUpdate): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-stars"></i>
                        يتوفّر إصدار جديد (<strong><?= e($remoteVersion) ?></strong>). سيأخذ النظام نسخة احتياطية تلقائية،
                        ثم يحدّث الملفات مع الحفاظ التام على إعداداتك وبياناتك ونسخك الاحتياطية.
                    </div>
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-shield-exclamation"></i>
                        لا تُغلق الصفحة أثناء التحديث. بعد اكتماله سيتم توجيهك لتطبيق أي تعديلات على قاعدة البيانات.
                    </div>
                    <form method="post" data-confirm="سيتم تنزيل الإصدار الجديد وتطبيقه فوق ملفات البرنامج (مع أخذ نسخة احتياطية أولاً). متابعة؟">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-download"></i> تحديث الآن إلى الإصدار <?= e($remoteVersion) ?>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i>
                        أنت على أحدث إصدار (<strong><?= e($localVersion) ?></strong>). لا حاجة لأي تحديث.
                    </div>
                    <a href="<?= APP_URL ?>update.php" class="btn btn-outline-primary w-100">
                        <i class="bi bi-arrow-clockwise"></i> إعادة الفحص
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- إعادة رفع الملفات (فرض) -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-arrow-repeat"></i> إعادة رفع الملفات (فرض التحديث)</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    يسحب هذا الخيار آخر ملفات الفرع من GitHub ويطبّقها <strong>مهما كان رقم الإصدار</strong> —
                    حتى لو ظهر أن النظام محدَّث. استخدمه إذا نُسي رفع رقم الإصدار مع تعديل جديد، أو للتأكد من
                    تطابق الملفات مع المستودع. تُؤخذ نسخة احتياطية تلقائية أولاً، وتبقى إعداداتك وبياناتك
                    ونسخك الاحتياطية سليمة تماماً.
                </p>
                <form method="post" data-confirm="سيتم سحب آخر الملفات من GitHub وتطبيقها فوق ملفات البرنامج (مع نسخة احتياطية أولاً)، بصرف النظر عن رقم الإصدار. متابعة؟">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="force_update">
                    <button type="submit" class="btn btn-outline-warning w-100">
                        <i class="bi bi-cloud-arrow-down"></i> إعادة رفع الملفات من الفرع «<?= e(Updater::branch()) ?>» الآن
                    </button>
                </form>
            </div>
        </div>

        <!-- مصدر التحديث -->
        <div class="card">
            <div class="card-header"><i class="bi bi-github"></i> مصدر التحديث</div>
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_source">
                    <div class="col-md-7">
                        <label class="form-label">المستودع (owner/repo)</label>
                        <input type="text" class="form-control" name="update_repo" dir="ltr"
                               value="<?= e(Updater::repo()) ?>" placeholder="badrshfaqah/raseed">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">الفرع</label>
                        <input type="text" class="form-control" name="update_branch" dir="ltr"
                               value="<?= e(Updater::branch()) ?>" placeholder="main">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary w-100">حفظ</button>
                    </div>
                </form>
                <p class="text-muted small mt-3 mb-0">
                    يجب أن يكون المستودع <strong>عامّاً (Public)</strong> على GitHub. يقرأ النظام رقم الإصدار من
                    <code>almgrat.json</code>، وينزّل حزمة الفرع المحدّد. لا تُلمس أبداً ملفاتك الخاصة
                    (<code>config.php</code>، <code>logs</code>، <code>backups</code>) عند التحديث.
                </p>
            </div>
        </div>

    </div>
</div>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>

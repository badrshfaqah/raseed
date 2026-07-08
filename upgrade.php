<?php
/**
 * رصيد - ترقية بنية قاعدة البيانات (مدير النظام فقط)
 *
 * يُفتح بعد رفع نسخة جديدة من الملفات. يقارن إصدار القاعدة المخزَّن
 * بإصدار الكود الحالي، ويطبّق جمل الترقية الناقصة فقط. لا يلمس أي ملف
 * PHP ولا أي بيانات موجودة (عمليات، مستخدمون، إعدادات).
 */
require __DIR__ . '/includes/init.php';
require_admin();

$result  = null;
$pending = pending_migrations();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upgrade') {
    verify_csrf();
    if (needs_upgrade()) {
        $result  = run_upgrade();
        $pending = pending_migrations(); // إعادة الحساب بعد التطبيق
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
    } else {
        flash('info', 'قاعدة البيانات محدَّثة بالفعل.');
    }
    redirect(APP_URL . 'upgrade.php');
}

$current = db_version();
$target  = RASEED_DB_VERSION;
$upToDate = !needs_upgrade();

$pageTitle = 'ترقية النظام';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-arrow-up-circle"></i> ترقية بنية قاعدة البيانات</div>
            <div class="card-body">

                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="stat-card">
                            <div class="stat-icon balance"><i class="bi bi-database"></i></div>
                            <div>
                                <div class="stat-label">إصدار القاعدة الحالي</div>
                                <div class="stat-value"><?= (int) $current ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card">
                            <div class="stat-icon <?= $upToDate ? 'income' : 'expense' ?>">
                                <i class="bi bi-<?= $upToDate ? 'check2-circle' : 'exclamation-circle' ?>"></i>
                            </div>
                            <div>
                                <div class="stat-label">إصدار الكود المطلوب</div>
                                <div class="stat-value"><?= (int) $target ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($upToDate): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i>
                        قاعدة البيانات محدَّثة بالكامل ومتوافقة مع إصدار الكود الحالي. لا حاجة لأي إجراء.
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        توجد <strong><?= count($pending) ?></strong> ترقية معلّقة يجب تطبيقها لتتوافق القاعدة مع الكود الجديد.
                    </div>

                    <p class="fw-bold mb-2">الترقيات التي ستُطبَّق:</p>
                    <ul class="check-list mb-4">
                        <?php foreach ($pending as $version => $statements): ?>
                            <li>
                                <span>الإصدار <?= (int) $version ?></span>
                                <span class="badge text-bg-light border"><?= count($statements) ?> جملة SQL</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="alert alert-info py-2 small">
                        <i class="bi bi-shield-check"></i>
                        الترقية تعدّل بنية الجداول فقط ولا تحذف أي بيانات. يُنصح بأخذ
                        <a href="<?= APP_URL ?>backup.php">نسخة احتياطية</a> قبل المتابعة.
                    </div>

                    <form method="post" data-confirm="سيتم تطبيق تحديثات بنية قاعدة البيانات. هل تريد المتابعة؟">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upgrade">
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-arrow-up-circle"></i> تطبيق التحديثات
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($result && $result['applied']): ?>
                    <div class="mt-3 small text-muted">
                        تم تطبيق الإصدارات: <?= implode('، ', array_map('intval', $result['applied'])) ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>

<?php
/**
 * رصيد - النسخ الاحتياطي والاستعادة (مدير النظام فقط)
 *
 * التنزيل: توليد ملف SQL كامل (هيكل + بيانات) عبر PHP مباشرة
 * دون الحاجة إلى mysqldump (غير متاح غالباً على الاستضافات المشتركة).
 */
require __DIR__ . '/includes/init.php';
require_admin();
require BASE_PATH . '/includes/Xlsx.php';
require BASE_PATH . '/includes/Backup.php';

$tables = Backup::TABLES;

/* ---------------- تنزيل نسخة SQL فورية ---------------- */

if (($_GET['action'] ?? '') === 'download') {
    $filename = 'raseed-backup-' . date('Y-m-d-His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo Backup::sqlDump();
    exit;
}

/* ---------------- تنزيل ملف نسخة يومية محفوظة (محمي بالمصادقة) ---------------- */

if (!empty($_GET['file'])) {
    $path = Backup::safePath((string) $_GET['file']);
    if ($path === null) {
        http_response_code(404);
        flash('danger', 'الملف غير موجود.');
        redirect(APP_URL . 'backup.php');
    }
    $isXlsx = str_ends_with($path, '.xlsx');
    header('Content-Type: ' . ($isXlsx
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/sql; charset=utf-8'));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ---------------- تشغيل نسخة يومية الآن ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_now') {
    verify_csrf();
    $result = Backup::runDaily();
    flash($result['ok'] ? 'success' : 'danger', $result['message']);
    redirect(APP_URL . 'backup.php');
}

/* ---------------- حفظ إعدادات النسخ التلقائي ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    verify_csrf();
    set_setting('auto_backup_enabled', ($_POST['auto_backup_enabled'] ?? '') === '1' ? '1' : '0');
    $retention = (int)($_POST['backup_retention_days'] ?? 14);
    set_setting('backup_retention_days', (string) max(1, min(365, $retention)));
    flash('success', 'تم حفظ إعدادات النسخ الاحتياطي التلقائي.');
    redirect(APP_URL . 'backup.php');
}

/* ---------------- استعادة نسخة احتياطية ---------------- */

$restoreResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    verify_csrf();

    if (empty($_FILES['backup_file']['tmp_name']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        flash('danger', 'يرجى اختيار ملف نسخة احتياطية صالح.');
        redirect(APP_URL . 'backup.php');
    }

    $name = $_FILES['backup_file']['name'] ?? '';
    if (!preg_match('/\.sql$/i', $name)) {
        flash('danger', 'يجب أن يكون الملف بصيغة SQL.');
        redirect(APP_URL . 'backup.php');
    }

    $sql = file_get_contents($_FILES['backup_file']['tmp_name']);
    if ($sql === false || trim($sql) === '') {
        flash('danger', 'الملف فارغ أو تعذّرت قراءته.');
        redirect(APP_URL . 'backup.php');
    }

    try {
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        // تقسيم الملف إلى جمل SQL مع مراعاة النصوص المقتبسة
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inString) {
                $current .= $ch;
                if ($ch === '\\') {
                    // تخطي المحرف المهرّب
                    if ($i + 1 < $len) {
                        $current .= $sql[++$i];
                    }
                } elseif ($ch === $stringChar) {
                    $inString = false;
                }
            } elseif ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                $current .= $ch;
            } elseif ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '' && !str_starts_with($trimmed, '--')) {
                    $statements[] = $trimmed;
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        $trimmed = trim($current);
        if ($trimmed !== '' && !str_starts_with($trimmed, '--')) {
            $statements[] = $trimmed;
        }

        $executed = 0;
        foreach ($statements as $statement) {
            // تجاهل أسطر التعليقات داخل الجمل
            $clean = implode("\n", array_filter(
                explode("\n", $statement),
                fn($line) => !str_starts_with(trim($line), '--')
            ));
            if (trim($clean) === '') {
                continue;
            }
            $pdo->exec($clean);
            $executed++;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        load_settings();
        flash('success', "تمت استعادة النسخة الاحتياطية بنجاح ($executed جملة SQL).");
    } catch (Throwable $e) {
        log_error('restore: ' . $e->getMessage());
        flash('danger', 'فشلت الاستعادة: ' . $e->getMessage());
    }
    redirect(APP_URL . 'backup.php');
}

// توليد رمز تشغيل Cron إن لم يوجد
if (setting('backup_token') === '') {
    set_setting('backup_token', bin2hex(random_bytes(16)));
}

// إحصائيات سريعة
$stats = [];
foreach ($tables as $table) {
    $stats[$table] = (int)q("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

$dailyBackups = Backup::listBackups();
$cronUrl = rtrim((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'domain.com'), '/') . APP_URL
    . 'backup_cron.php?token=' . setting('backup_token');

function human_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' م.ب';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' ك.ب';
    }
    return $bytes . ' بايت';
}

$tableLabels = [
    'users' => 'المستخدمون',
    'categories' => 'التصنيفات',
    'items' => 'البنود',
    'tags' => 'التاقات',
    'transactions' => 'العمليات',
    'google_sheet_sync_logs' => 'سجل المزامنة',
    'login_attempts' => 'محاولات الدخول',
    'settings' => 'الإعدادات',
];

$pageTitle = 'النسخ الاحتياطي';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-download"></i> تنزيل نسخة احتياطية</div>
            <div class="card-body">
                <p class="text-muted">تنزيل نسخة كاملة من قاعدة البيانات (الهيكل والبيانات) بصيغة SQL بضغطة واحدة.</p>
                <ul class="check-list mb-4">
                    <?php foreach ($stats as $table => $count): ?>
                        <li>
                            <span><?= $tableLabels[$table] ?></span>
                            <span class="badge text-bg-light border"><?= number_format($count) ?> سجل</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a href="?action=download" class="btn btn-primary w-100 btn-lg">
                    <i class="bi bi-database-down"></i> تنزيل النسخة الاحتياطية
                </a>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-upload"></i> استعادة نسخة احتياطية</div>
            <div class="card-body">
                <div class="alert alert-warning py-2 small">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>تحذير:</strong> الاستعادة تستبدل جميع البيانات الحالية بمحتوى النسخة الاحتياطية.
                    لا يمكن التراجع عن هذه العملية. يُنصح بتنزيل نسخة احتياطية حديثة أولاً.
                </div>
                <form method="post" enctype="multipart/form-data"
                      data-confirm="هل أنت متأكد؟ سيتم استبدال جميع البيانات الحالية بمحتوى النسخة الاحتياطية.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <div class="mb-3">
                        <label class="form-label">ملف النسخة الاحتياطية (SQL)</label>
                        <input type="file" class="form-control" name="backup_file" accept=".sql" required>
                    </div>
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="bi bi-database-up"></i> استعادة النسخة الاحتياطية
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- النسخ الاحتياطي اليومي التلقائي -->
<div class="row g-4 mt-1">
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-calendar-check"></i> النسخ اليومي التلقائي</div>
            <div class="card-body">
                <p class="text-muted small">
                    يحفظ النظام يومياً نسختين (SQL + Excel) في مجلد <code>backups</code> المحمي على السيرفر،
                    فتبقى بياناتك آمنة في ملفات مستقلة حتى لو تعطّلت قاعدة البيانات.
                </p>

                <form method="post" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_settings">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="autoBackup"
                               name="auto_backup_enabled" value="1"
                               <?= setting('auto_backup_enabled', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="autoBackup">تفعيل النسخ اليومي التلقائي</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">مدة الاحتفاظ بالنسخ (بالأيام)</label>
                        <input type="number" class="form-control form-control-sm" name="backup_retention_days"
                               min="1" max="365" value="<?= e(setting('backup_retention_days', '14')) ?>">
                        <div class="form-text">النسخ الأقدم من هذه المدة تُحذف تلقائياً.</div>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">حفظ الإعدادات</button>
                </form>

                <form method="post" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="run_now">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-play-circle"></i> إنشاء نسخة اليوم الآن
                    </button>
                </form>

                <?php if (setting('last_backup_at')): ?>
                    <p class="text-muted small mb-0">
                        <i class="bi bi-clock-history"></i>
                        آخر نسخة تلقائية: <?= e(format_datetime(setting('last_backup_at'))) ?>
                    </p>
                <?php endif; ?>

                <hr>
                <p class="fw-bold small mb-1">تشغيل تلقائي مضمون عبر Cron (موصى به):</p>
                <p class="text-muted small mb-1">أضف في لوحة الاستضافة مهمة Cron يومية بهذا الرابط:</p>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control font-monospace" dir="ltr"
                           value="<?= e($cronUrl) ?>" readonly onclick="this.select()">
                </div>
                <p class="text-muted small mt-1 mb-0">
                    بدون Cron، تُنشأ النسخة تلقائياً عند أول دخول للنظام كل يوم.
                </p>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-archive"></i> النسخ اليومية المحفوظة</span>
                <span class="badge text-bg-light border"><?= count($dailyBackups) ?> ملف</span>
            </div>
            <div class="card-body p-0">
                <?php if (!$dailyBackups): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-folder2-open fs-1 d-block mb-2"></i>
                        لا توجد نسخ يومية بعد. اضغط «إنشاء نسخة اليوم الآن».
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-mobile align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>النوع</th>
                                    <th>الحجم</th>
                                    <th class="text-center">تنزيل</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dailyBackups as $b): ?>
                                    <tr>
                                        <td data-label="التاريخ"><?= e($b['date']) ?></td>
                                        <td data-label="النوع">
                                            <?php if ($b['type'] === 'sql'): ?>
                                                <span class="badge text-bg-primary">قاعدة بيانات SQL</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-success">Excel</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="الحجم"><?= human_size($b['size']) ?></td>
                                        <td data-label="تنزيل" class="text-center">
                                            <a href="?file=<?= urlencode($b['file']) ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>

<?php
/**
 * رصيد - النسخ الاحتياطي والاستعادة (مدير النظام فقط)
 *
 * التنزيل: توليد ملف SQL كامل (هيكل + بيانات) عبر PHP مباشرة
 * دون الحاجة إلى mysqldump (غير متاح غالباً على الاستضافات المشتركة).
 */
require __DIR__ . '/includes/init.php';
require_admin();

$tables = ['users', 'categories', 'items', 'tags', 'transactions', 'google_sheet_sync_logs', 'login_attempts', 'settings'];

/* ---------------- تنزيل نسخة احتياطية ---------------- */

if (($_GET['action'] ?? '') === 'download') {
    $filename = 'raseed-backup-' . date('Y-m-d-His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "-- رصيد - نسخة احتياطية\n";
    echo "-- التاريخ: " . date('Y-m-d H:i:s') . "\n";
    echo "-- قاعدة البيانات: " . DB_NAME . "\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $create = q("SHOW CREATE TABLE `$table`")->fetch();
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo ($create['Create Table'] ?? '') . ";\n\n";

        $st = db()->query("SELECT * FROM `$table`");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols = '`' . implode('`, `', array_keys($row)) . '`';
            $vals = implode(', ', array_map(
                fn($v) => $v === null ? 'NULL' : db()->quote((string)$v),
                array_values($row)
            ));
            echo "INSERT INTO `$table` ($cols) VALUES ($vals);\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
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

// إحصائيات سريعة
$stats = [];
foreach ($tables as $table) {
    $stats[$table] = (int)q("SELECT COUNT(*) FROM `$table`")->fetchColumn();
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

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>

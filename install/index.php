<?php
/**
 * رصيد - معالج التثبيت
 *
 * يعمل تلقائياً عند أول تشغيل، ويُعطَّل نهائياً بعد اكتمال التثبيت
 * (لا يعمل مجدداً إلا بعد حذف ملف config.php يدوياً).
 */
declare(strict_types=1);

define('RASEED_INSTALLER', true);

$basePath   = dirname(__DIR__);
$configFile = $basePath . '/config.php';

// تعطيل صفحة التثبيت إذا كان النظام مثبتاً
if (is_file($configFile)) {
    require $configFile;
    if (defined('RASEED_INSTALLED') && RASEED_INSTALLED === true) {
        http_response_code(403);
        echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><body style="font-family:sans-serif;text-align:center;padding:60px">'
            . '<h3>النظام مثبت مسبقاً</h3><p>لإعادة التثبيت يجب حذف ملف config.php يدوياً من السيرفر.</p>'
            . '<p><a href="../login.php">الانتقال لتسجيل الدخول</a></p></body></html>';
        exit;
    }
}

require __DIR__ . '/schema.php';
require dirname(__DIR__) . '/includes/migrations.php'; // لثابت RASEED_DB_VERSION

// ترويسات أمنية (المثبّت لا يمر عبر init.php)
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

session_name('raseed_install');
session_start();

mb_internal_encoding('UTF-8');

$step   = max(1, min(5, (int)($_GET['step'] ?? 1)));
$errors = [];

/* ---------------- فحوصات السيرفر ---------------- */

function server_checks(string $basePath): array
{
    return [
        ['إصدار PHP (8.0 أو أحدث)', PHP_VERSION_ID >= 80000, 'الحالي: ' . PHP_VERSION, true],
        ['امتداد PDO', extension_loaded('pdo'), '', true],
        ['امتداد PDO MySQL', extension_loaded('pdo_mysql'), '', true],
        ['امتداد OpenSSL (لمزامنة Google Sheets)', extension_loaded('openssl'), '', true],
        ['امتداد cURL (لمزامنة Google Sheets)', extension_loaded('curl'), '', true],
        ['امتداد JSON', function_exists('json_encode'), '', true],
        ['امتداد mbstring', extension_loaded('mbstring'), '', true],
        ['امتداد ZipArchive (لتصدير Excel)', class_exists('ZipArchive'), 'اختياري - يمكن استخدام CSV بدونه', false],
        ['صلاحية الكتابة على مجلد النظام', is_writable($basePath), 'مطلوبة لإنشاء ملف الإعدادات', true],
    ];
}

function checks_passed(string $basePath): bool
{
    foreach (server_checks($basePath) as [$label, $ok, $note, $required]) {
        if ($required && !$ok) {
            return false;
        }
    }
    return true;
}

/* ---------------- اختبار اتصال قاعدة البيانات ---------------- */

function try_db_connect(array $db): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['name']);
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

/* ---------------- معالجة الخطوات (POST) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // اختبار الاتصال عبر AJAX
    if ($action === 'test_db') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            try_db_connect([
                'host' => trim($_POST['db_host'] ?? ''),
                'name' => trim($_POST['db_name'] ?? ''),
                'user' => trim($_POST['db_user'] ?? ''),
                'pass' => (string)($_POST['db_pass'] ?? ''),
            ]);
            echo json_encode(['ok' => true, 'message' => 'تم الاتصال بقاعدة البيانات بنجاح.'], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'message' => 'فشل الاتصال: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'step2') {
        $db = [
            'host' => trim($_POST['db_host'] ?? ''),
            'name' => trim($_POST['db_name'] ?? ''),
            'user' => trim($_POST['db_user'] ?? ''),
            'pass' => (string)($_POST['db_pass'] ?? ''),
        ];
        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
            $errors[] = 'يرجى تعبئة بيانات الاتصال بقاعدة البيانات.';
        } else {
            try {
                try_db_connect($db);
                $_SESSION['install_db'] = $db;
                header('Location: ?step=3');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage();
            }
        }
        $step = 2;
    }

    if ($action === 'step3') {
        $sys = [
            'system_name'  => trim($_POST['system_name'] ?? '') ?: 'رصيد',
            'company_name' => trim($_POST['company_name'] ?? ''),
            'timezone'     => trim($_POST['timezone'] ?? 'Asia/Riyadh'),
            'currency'     => trim($_POST['currency'] ?? '') ?: 'ر.س',
        ];
        if (!in_array($sys['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $sys['timezone'] = 'Asia/Riyadh';
        }
        $_SESSION['install_sys'] = $sys;
        header('Location: ?step=4');
        exit;
    }

    if ($action === 'step4') {
        $admin = [
            'name'     => trim($_POST['admin_name'] ?? ''),
            'username' => trim($_POST['admin_username'] ?? ''),
            'password' => (string)($_POST['admin_password'] ?? ''),
            'confirm'  => (string)($_POST['admin_confirm'] ?? ''),
        ];
        if ($admin['name'] === '' || $admin['username'] === '') {
            $errors[] = 'الاسم واسم المستخدم مطلوبان.';
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $admin['username'])) {
            $errors[] = 'اسم المستخدم يجب أن يكون بأحرف إنجليزية وأرقام (3-50 حرفاً).';
        }
        if (mb_strlen($admin['password']) < 6) {
            $errors[] = 'كلمة المرور يجب ألا تقل عن 6 أحرف.';
        }
        if ($admin['password'] !== $admin['confirm']) {
            $errors[] = 'كلمة المرور وتأكيدها غير متطابقين.';
        }
        if (!$errors) {
            $_SESSION['install_admin'] = $admin;
            header('Location: ?step=5');
            exit;
        }
        $step = 4;
    }

    if ($action === 'install') {
        $db    = $_SESSION['install_db'] ?? null;
        $sys   = $_SESSION['install_sys'] ?? null;
        $admin = $_SESSION['install_admin'] ?? null;

        if (!$db || !$sys || !$admin) {
            $errors[] = 'بيانات التثبيت غير مكتملة، يرجى البدء من جديد.';
            $step = 1;
        } else {
            try {
                $pdo = try_db_connect($db);
                $pdo->exec("SET NAMES utf8mb4");

                // إنشاء الجداول
                foreach (raseed_schema() as $sql) {
                    $pdo->exec($sql);
                }

                $pdo->beginTransaction();

                // حساب المدير
                $st = $pdo->prepare('INSERT INTO users (name, username, password_hash, role, permission, status)
                                     VALUES (?, ?, ?, "admin", "entry", 1)');
                $st->execute([
                    $admin['name'],
                    $admin['username'],
                    password_hash($admin['password'], PASSWORD_DEFAULT),
                ]);

                // إعدادات النظام
                $st = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                foreach ($sys as $k => $v) {
                    $st->execute([$k, $v]);
                }
                // ختم إصدار بنية القاعدة حتى لا يطلب النظام ترقية بعد تثبيت جديد
                $st->execute(['db_version', (string) RASEED_DB_VERSION]);

                // إعدادات النسخ الاحتياطي التلقائي
                $st->execute(['auto_backup_enabled', '1']);
                $st->execute(['backup_retention_days', '14']);
                $st->execute(['backup_token', bin2hex(random_bytes(16))]);

                // التصنيفات والبنود الافتراضية
                $catSt  = $pdo->prepare('INSERT IGNORE INTO categories (name) VALUES (?)');
                $itemSt = $pdo->prepare('INSERT IGNORE INTO items (category_id, name) VALUES (?, ?)');
                foreach (raseed_default_data() as $catName => $items) {
                    $catSt->execute([$catName]);
                    $catId = (int)$pdo->lastInsertId();
                    if ($catId === 0) {
                        $find = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
                        $find->execute([$catName]);
                        $catId = (int)$find->fetchColumn();
                    }
                    foreach ($items as $itemName) {
                        $itemSt->execute([$catId, $itemName]);
                    }
                }

                $pdo->commit();

                // كتابة ملف الإعدادات (يُعلِّم النظام كمثبّت ويعطّل هذه الصفحة)
                $config = "<?php\n"
                    . "/**\n * رصيد - ملف الإعدادات\n * تم إنشاؤه بواسطة معالج التثبيت في " . date('Y-m-d H:i') . "\n * حذف هذا الملف يعيد تفعيل معالج التثبيت.\n */\n"
                    . "defined('RASEED') || defined('RASEED_INSTALLER') || exit;\n\n"
                    . "define('DB_HOST', " . var_export($db['host'], true) . ");\n"
                    . "define('DB_NAME', " . var_export($db['name'], true) . ");\n"
                    . "define('DB_USER', " . var_export($db['user'], true) . ");\n"
                    . "define('DB_PASS', " . var_export($db['pass'], true) . ");\n\n"
                    . "define('RASEED_INSTALLED', true);\n";

                if (file_put_contents($configFile, $config, LOCK_EX) === false) {
                    throw new RuntimeException('تعذّرت كتابة ملف config.php - تحقق من صلاحيات الكتابة.');
                }
                @chmod($configFile, 0644);

                // إنشاء مجلد السجلات مع ملف سجل محمي بسطر حارس PHP
                @mkdir($basePath . '/logs', 0755, true);
                @file_put_contents(
                    $basePath . '/logs/error.log.php',
                    "<?php http_response_code(403); die('Forbidden'); ?>\n",
                    LOCK_EX
                );

                // إنشاء مجلد النسخ الاحتياطي المحمي
                @mkdir($basePath . '/backups', 0755, true);
                @file_put_contents($basePath . '/backups/.htaccess', "Require all denied\nDeny from all\n");
                @file_put_contents($basePath . '/backups/index.html', "<!-- ممنوع الوصول المباشر -->\n");

                session_destroy();
                $step = 5;
                $installed = true;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'فشل التثبيت: ' . $e->getMessage();
                $step = 5;
            }
        }
    }
}

// منع القفز لخطوة متقدمة بدون إكمال السابقة
if ($step >= 3 && empty($_SESSION['install_db'])) {
    $step = min($step, 2);
}
if ($step >= 4 && empty($_SESSION['install_sys'])) {
    $step = min($step, 3);
}
if ($step >= 5 && empty($_SESSION['install_admin']) && empty($installed) && !$errors) {
    $step = 4;
}

$stepTitles = ['فحص السيرفر', 'قاعدة البيانات', 'إعدادات النظام', 'حساب المدير', 'التثبيت'];

function old(string $key, string $default = ''): string
{
    return htmlspecialchars((string)($_POST[$key] ?? $default), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تثبيت نظام رصيد</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="auth-card install-card">
        <div class="auth-logo">
            <i class="bi bi-wallet2"></i>
            <h1>تثبيت نظام رصيد</h1>
            <p class="text-muted small mb-0">نظام إدارة الإيرادات والمصروفات</p>
        </div>

        <div class="install-steps">
            <?php foreach ($stepTitles as $i => $title): $n = $i + 1; ?>
                <div class="install-step <?= $n === $step ? 'active' : ($n < $step ? 'done' : '') ?>">
                    <div class="num"><?= $n < $step ? '<i class="bi bi-check"></i>' : $n ?></div>
                    <div><?= $title ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if ($step === 1): ?>
            <!-- الخطوة 1: فحص السيرفر -->
            <ul class="check-list mb-4">
                <?php foreach (server_checks($basePath) as [$label, $ok, $note, $required]): ?>
                    <li>
                        <span>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($note): ?><br><small class="text-muted"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                        </span>
                        <?php if ($ok): ?>
                            <span class="badge text-bg-success"><i class="bi bi-check-lg"></i> متوفر</span>
                        <?php elseif ($required): ?>
                            <span class="badge text-bg-danger"><i class="bi bi-x-lg"></i> غير متوفر</span>
                        <?php else: ?>
                            <span class="badge text-bg-warning">غير متوفر</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (checks_passed($basePath)): ?>
                <a href="?step=2" class="btn btn-primary w-100">متابعة <i class="bi bi-arrow-left"></i></a>
            <?php else: ?>
                <div class="alert alert-warning py-2 small">يوجد متطلبات أساسية غير متوفرة. يرجى معالجتها ثم إعادة الفحص.</div>
                <a href="?step=1" class="btn btn-outline-primary w-100">إعادة الفحص</a>
            <?php endif; ?>

        <?php elseif ($step === 2): ?>
            <!-- الخطوة 2: قاعدة البيانات -->
            <form method="post" id="dbForm">
                <input type="hidden" name="action" value="step2">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">الخادم (Server)</label>
                        <input type="text" class="form-control" name="db_host" value="<?= old('db_host', $_SESSION['install_db']['host'] ?? 'localhost') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">اسم قاعدة البيانات</label>
                        <input type="text" class="form-control" name="db_name" value="<?= old('db_name', $_SESSION['install_db']['name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">اسم المستخدم</label>
                        <input type="text" class="form-control" name="db_user" value="<?= old('db_user', $_SESSION['install_db']['user'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">كلمة المرور</label>
                        <input type="password" class="form-control" name="db_pass" value="<?= old('db_pass', $_SESSION['install_db']['pass'] ?? '') ?>">
                    </div>
                </div>
                <div id="testResult" class="mt-3"></div>
                <div class="d-flex gap-2 mt-3">
                    <button type="button" class="btn btn-outline-primary" id="testBtn">
                        <i class="bi bi-plug"></i> اختبار الاتصال
                    </button>
                    <button type="submit" class="btn btn-primary flex-grow-1">متابعة <i class="bi bi-arrow-left"></i></button>
                </div>
            </form>

        <?php elseif ($step === 3): ?>
            <!-- الخطوة 3: إعدادات النظام -->
            <form method="post">
                <input type="hidden" name="action" value="step3">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">اسم النظام</label>
                        <input type="text" class="form-control" name="system_name" value="<?= old('system_name', $_SESSION['install_sys']['system_name'] ?? 'رصيد') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">اسم الشركة / الجهة</label>
                        <input type="text" class="form-control" name="company_name" value="<?= old('company_name', $_SESSION['install_sys']['company_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">المنطقة الزمنية</label>
                        <select class="form-select" name="timezone">
                            <?php
                            $current = $_SESSION['install_sys']['timezone'] ?? 'Asia/Riyadh';
                            $zones = ['Asia/Riyadh', 'Asia/Dubai', 'Asia/Kuwait', 'Asia/Qatar', 'Asia/Bahrain', 'Asia/Amman', 'Asia/Baghdad', 'Africa/Cairo', 'Asia/Beirut', 'Europe/Istanbul', 'UTC'];
                            foreach ($zones as $tz): ?>
                                <option value="<?= $tz ?>" <?= $tz === $current ? 'selected' : '' ?>><?= $tz ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">العملة</label>
                        <input type="text" class="form-control" name="currency" value="<?= old('currency', $_SESSION['install_sys']['currency'] ?? 'ر.س') ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-4">متابعة <i class="bi bi-arrow-left"></i></button>
            </form>

        <?php elseif ($step === 4): ?>
            <!-- الخطوة 4: حساب المدير -->
            <form method="post">
                <input type="hidden" name="action" value="step4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">الاسم</label>
                        <input type="text" class="form-control" name="admin_name" value="<?= old('admin_name') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">اسم المستخدم</label>
                        <input type="text" class="form-control" name="admin_username" value="<?= old('admin_username') ?>" required dir="ltr">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">كلمة المرور</label>
                        <input type="password" class="form-control" name="admin_password" required minlength="6">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">تأكيد كلمة المرور</label>
                        <input type="password" class="form-control" name="admin_confirm" required minlength="6">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-4">متابعة <i class="bi bi-arrow-left"></i></button>
            </form>

        <?php elseif ($step === 5): ?>
            <!-- الخطوة 5: التثبيت -->
            <?php if (!empty($installed)): ?>
                <div class="text-center py-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:3.5rem"></i>
                    <h4 class="mt-3">تم تثبيت النظام بنجاح</h4>
                    <p class="text-muted">تم إنشاء الجداول وملف الإعدادات وحساب المدير.<br>تم تعطيل صفحة التثبيت تلقائياً.</p>
                    <div class="alert alert-info py-2 small text-start">
                        <i class="bi bi-shield-check"></i>
                        لحماية إضافية يُنصح بحذف مجلد <code>install</code> من السيرفر الآن بعد اكتمال التثبيت.
                    </div>
                    <a href="../login.php" class="btn btn-primary px-5 mt-2">تسجيل الدخول <i class="bi bi-box-arrow-in-left"></i></a>
                </div>
            <?php elseif ($errors): ?>
                <a href="?step=1" class="btn btn-outline-primary w-100">البدء من جديد</a>
            <?php else: ?>
                <div class="text-center py-2">
                    <p>سيقوم المعالج الآن بتنفيذ الخطوات التالية تلقائياً:</p>
                    <ul class="check-list text-start mb-4">
                        <li><span>إنشاء جميع جداول قاعدة البيانات</span><i class="bi bi-gear text-muted"></i></li>
                        <li><span>حفظ إعدادات النظام</span><i class="bi bi-gear text-muted"></i></li>
                        <li><span>إنشاء حساب مدير النظام</span><i class="bi bi-gear text-muted"></i></li>
                        <li><span>إضافة التصنيفات والبنود الافتراضية</span><i class="bi bi-gear text-muted"></i></li>
                        <li><span>إنشاء ملف الإعدادات وتعطيل صفحة التثبيت</span><i class="bi bi-gear text-muted"></i></li>
                    </ul>
                    <form method="post">
                        <input type="hidden" name="action" value="install">
                        <button type="submit" class="btn btn-primary w-100 btn-lg">
                            <i class="bi bi-rocket-takeoff"></i> بدء التثبيت
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="auth-footer">
            تطوير <a href="https://almgrat.com" target="_blank" rel="noopener">برمجة المجرات</a>
        </div>
    </div>
</div>

<script>
// اختبار اتصال قاعدة البيانات (خطوة 2)
const testBtn = document.getElementById('testBtn');
if (testBtn) {
    testBtn.addEventListener('click', function () {
        const form = document.getElementById('dbForm');
        const data = new FormData(form);
        data.set('action', 'test_db');
        const box = document.getElementById('testResult');
        box.innerHTML = '<div class="alert alert-info py-2 mb-0">جاري الاختبار...</div>';
        fetch('', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                box.innerHTML = '<div class="alert alert-' + (res.ok ? 'success' : 'danger') + ' py-2 mb-0">' + res.message + '</div>';
            })
            .catch(() => {
                box.innerHTML = '<div class="alert alert-danger py-2 mb-0">تعذّر تنفيذ الاختبار.</div>';
            });
    });
}
</script>
</body>
</html>

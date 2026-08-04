<?php
/**
 * رصيد - رأس الصفحة والقائمة الجانبية
 * متغيرات متوقعة: $pageTitle (اختياري)
 */
defined('RASEED') || exit;

$pageTitle   = $pageTitle ?? 'رصيد';
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$user        = current_user();

/** رابط قائمة جانبية مع حالة التفعيل */
function nav_link(string $href, string $icon, string $label, string $current): string
{
    $active = $current === $href ? ' active' : '';
    return '<a class="nav-item-link' . $active . '" href="' . APP_URL . $href . '">'
        . '<i class="bi ' . $icon . '"></i><span>' . $label . '</span></a>';
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e(setting('system_name', 'رصيد')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>assets/css/style.css?v=<?= RASEED_VERSION ?>" rel="stylesheet">
</head>
<body data-app-url="<?= APP_URL ?>" data-csrf="<?= csrf_token() ?>">

<div class="layout">

    <!-- القائمة الجانبية -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <i class="bi bi-wallet2"></i>
            <div>
                <div class="brand-name"><?= e(setting('system_name', 'رصيد')) ?></div>
                <div class="brand-company"><?= e(setting('company_name', '')) ?></div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?= nav_link('dashboard.php', 'bi-speedometer2', 'لوحة التحكم', $currentPage) ?>
            <?php if (can_add()):
                $txType = $_GET['type'] ?? '';
                $incomeActive  = ($currentPage === 'transaction_add.php' && $txType === 'income') ? ' active' : '';
                $expenseActive = ($currentPage === 'transaction_add.php' && $txType === 'expense') ? ' active' : '';
            ?>
                <a class="nav-item-link<?= $incomeActive ?>" href="<?= APP_URL ?>transaction_add.php?type=income"><i class="bi bi-arrow-down-circle"></i><span>إضافة إيراد</span></a>
                <a class="nav-item-link<?= $expenseActive ?>" href="<?= APP_URL ?>transaction_add.php?type=expense"><i class="bi bi-arrow-up-circle"></i><span>إضافة مصروف</span></a>
            <?php endif; ?>
            <?= nav_link('transactions.php', 'bi-journal-text', 'كشف الحساب', $currentPage) ?>
            <?= nav_link('statistics.php', 'bi-bar-chart-line', 'الإحصائيات', $currentPage) ?>

            <?php if (is_admin()): ?>
                <div class="nav-section">الإدارة</div>
                <?= nav_link('quick_edit.php', 'bi-pencil-square', 'التحرير السريع', $currentPage) ?>
                <?= nav_link('import.php', 'bi-file-earmark-arrow-up', 'استيراد من Excel', $currentPage) ?>
                <?= nav_link('categories.php', 'bi-tags', 'التصنيفات', $currentPage) ?>
                <?= nav_link('items.php', 'bi-list-ul', 'البنود', $currentPage) ?>
                <?= nav_link('tags.php', 'bi-tag', 'التاقات', $currentPage) ?>
                <?= nav_link('users.php', 'bi-people', 'المستخدمون', $currentPage) ?>
                <?= nav_link('sync_log.php', 'bi-arrow-repeat', 'سجل المزامنة', $currentPage) ?>
                <?= nav_link('backup.php', 'bi-database-down', 'النسخ الاحتياطي', $currentPage) ?>
                <?= nav_link('settings.php', 'bi-gear', 'الإعدادات', $currentPage) ?>
                <?= nav_link('update.php', 'bi-cloud-arrow-down', 'تحديث البرنامج', $currentPage) ?>
                <?php if (function_exists('needs_upgrade') && needs_upgrade()): ?>
                    <?= nav_link('upgrade.php', 'bi-arrow-up-circle', 'ترقية النظام', $currentPage) ?>
                <?php endif; ?>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-chip">
                <i class="bi bi-person-circle"></i>
                <div>
                    <div class="user-name"><?= e($user['name'] ?? '') ?></div>
                    <div class="user-role">
                        <?= is_admin() ? 'مدير النظام' : (($user['permission'] ?? '') === 'entry' ? 'مستخدم - إدخال' : 'مستخدم - مشاهدة') ?>
                    </div>
                </div>
            </div>
            <a href="<?= APP_URL ?>logout.php" class="btn btn-sm btn-outline-light w-100 mt-2">
                <i class="bi bi-box-arrow-left"></i> تسجيل الخروج
            </a>
        </div>
    </aside>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- المحتوى -->
    <main class="content">
        <header class="topbar">
            <button class="btn btn-light d-lg-none" id="sidebarToggle" aria-label="القائمة">
                <i class="bi bi-list fs-4"></i>
            </button>
            <h1 class="page-title"><?= e($pageTitle) ?></h1>
        </header>

        <div class="page-body">
            <?php foreach (get_flashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($f['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>

            <?php if (is_admin() && $currentPage !== 'upgrade.php' && function_exists('needs_upgrade') && needs_upgrade()): ?>
                <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span>
                        <i class="bi bi-exclamation-triangle"></i>
                        تم رفع إصدار جديد من الملفات، وقاعدة البيانات تحتاج إلى ترقية لتتوافق معه.
                    </span>
                    <a href="<?= APP_URL ?>upgrade.php" class="btn btn-sm btn-warning">
                        <i class="bi bi-arrow-up-circle"></i> ترقية الآن
                    </a>
                </div>
            <?php endif; ?>

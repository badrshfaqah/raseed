<?php
/**
 * رصيد - عرض العمليات للطباعة
 *
 * نسخة مبسّطة مخصّصة للطباعة تعرض العمليات (وفق الفلاتر الحالية) كجدول
 * نظيف مع ترويسة وإجماليات، وتفتح مربّع الطباعة تلقائياً. تحترم نفس فلاتر
 * كشف الحساب/الأصول الممرَّرة في الرابط.
 */
require __DIR__ . '/includes/init.php';
require_login();

[$whereSql, $bind] = build_tx_filters($_GET);

$rows = q('SELECT t.*, c.name AS category_name, i.name AS item_name, u.name AS user_name,
           ' . tx_tags_subquery() . ' AS tag_names
           ' . tx_base_query() . "
           $whereSql
           ORDER BY t.trans_date DESC, t.id DESC", $bind)->fetchAll();

$totals = tx_totals($whereSql, $bind);

// ملخّص الفلاتر المطبَّقة للعرض في الترويسة
$typeLabels = ['income' => 'الإيرادات فقط', 'expense' => 'المصروفات فقط'];
$filterBits = [];
if (!empty($_GET['from']))    { $filterBits[] = 'من ' . e($_GET['from']); }
if (!empty($_GET['to']))      { $filterBits[] = 'إلى ' . e($_GET['to']); }
if (!empty($_GET['type']) && isset($typeLabels[$_GET['type']])) { $filterBits[] = $typeLabels[$_GET['type']]; }
if (!empty($_GET['is_asset'])) { $filterBits[] = 'الأصول فقط'; }
if (!empty($_GET['tag_id']) && ctype_digit((string)$_GET['tag_id'])) {
    $tg = q('SELECT name FROM tags WHERE id = ?', [(int)$_GET['tag_id']])->fetchColumn();
    if ($tg) { $filterBits[] = 'التاق: ' . e($tg); }
}
if (!empty($_GET['search'])) { $filterBits[] = 'بحث: ' . e($_GET['search']); }

$reportTitle = ($_GET['type'] ?? '') === 'expense' ? 'كشف المصروفات'
    : ((($_GET['type'] ?? '') === 'income') ? 'كشف الإيرادات'
    : (!empty($_GET['is_asset']) ? 'كشف الأصول' : 'كشف الحساب'));

$currency = setting('currency', 'ر.س');
$nonce    = csp_nonce();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($reportTitle) ?> - <?= e(setting('system_name', 'رصيد')) ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Tajawal', 'Segoe UI', Tahoma, sans-serif; color: #1e293b; margin: 24px; background: #fff; }
    .print-bar { text-align: left; margin-bottom: 16px; }
    .print-bar button, .print-bar a {
        font: inherit; padding: 8px 18px; border-radius: 6px; cursor: pointer; text-decoration: none;
        border: 1px solid #0f766e; background: #0f766e; color: #fff; margin-inline-start: 6px; display: inline-block;
    }
    .print-bar a { background: #fff; color: #0f766e; }
    .report-head { border-bottom: 2px solid #0f766e; padding-bottom: 12px; margin-bottom: 16px; }
    .report-head h1 { margin: 0 0 4px; font-size: 22px; color: #0f766e; }
    .report-head .company { font-size: 15px; color: #475569; }
    .report-meta { font-size: 13px; color: #64748b; margin-top: 8px; line-height: 1.8; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { border: 1px solid #cbd5e1; padding: 7px 8px; text-align: right; vertical-align: top; }
    thead th { background: #f1f5f9; font-weight: 700; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    .num { text-align: left; direction: ltr; white-space: nowrap; }
    .type-income { color: #15803d; font-weight: 600; }
    .type-expense { color: #b91c1c; font-weight: 600; }
    .asset-tag { display: inline-block; background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; border-radius: 4px; padding: 0 5px; font-size: 11px; }
    .tag-chip { display: inline-block; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px; padding: 0 5px; font-size: 11px; margin: 1px; }
    tfoot td { font-weight: 700; background: #f1f5f9; }
    .totals { margin-top: 18px; width: auto; }
    .totals td { border: none; padding: 4px 14px; font-size: 15px; }
    .totals .label { color: #475569; }
    .totals .value { text-align: left; direction: ltr; font-weight: 700; }
    .empty { text-align: center; color: #64748b; padding: 40px; }
    .foot-note { margin-top: 26px; text-align: center; font-size: 12px; color: #94a3b8; }
    @media print {
        body { margin: 0; }
        .print-bar { display: none; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="print-bar">
    <button type="button" id="printBtn"><i></i> طباعة</button>
    <a href="<?= APP_URL ?><?= !empty($_GET['is_asset']) ? 'assets.php' : 'transactions.php' ?>">رجوع</a>
</div>

<div class="report-head">
    <h1><?= e($reportTitle) ?></h1>
    <div class="company">
        <?= e(setting('system_name', 'رصيد')) ?><?= setting('company_name') ? ' — ' . e(setting('company_name')) : '' ?>
    </div>
    <div class="report-meta">
        <?php if ($filterBits): ?>
            <div>الفلاتر: <?= implode(' • ', $filterBits) ?></div>
        <?php endif; ?>
        <div>عدد العمليات: <?= number_format(count($rows)) ?> — تاريخ الطباعة: <?= date('Y-m-d H:i') ?></div>
    </div>
</div>

<?php if (!$rows): ?>
    <div class="empty">لا توجد عمليات مطابقة للطباعة.</div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>التاريخ</th>
                <th>النوع</th>
                <th>التصنيف</th>
                <th>البند</th>
                <th>التاق</th>
                <th>الملاحظات</th>
                <th>المبلغ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td><?= (int)$t['id'] ?></td>
                    <td><?= e(format_date($t['trans_date'])) ?></td>
                    <td class="type-<?= $t['type'] ?>"><?= type_label($t['type']) ?></td>
                    <td><?= e($t['category_name']) ?></td>
                    <td>
                        <?= e($t['item_name']) ?>
                        <?php if (!empty($t['is_asset'])): ?>
                            <span class="asset-tag">أصل<?= !empty($t['asset_name']) ? ': ' . e($t['asset_name']) : '' ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($t['tag_names'])): ?>
                            <?php foreach (explode('، ', $t['tag_names']) as $tgName): ?>
                                <span class="tag-chip"><?= e($tgName) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><?= e($t['notes']) ?: '-' ?></td>
                    <td class="num"><?= ($t['type'] === 'expense' ? '-' : '+') . ' ' . format_amount($t['amount'], false) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">إجمالي الإيرادات:</td>
            <td class="value type-income"><?= format_amount($totals['income']) ?></td>
        </tr>
        <tr>
            <td class="label">إجمالي المصروفات:</td>
            <td class="value type-expense"><?= format_amount($totals['expense']) ?></td>
        </tr>
        <tr>
            <td class="label">الرصيد:</td>
            <td class="value"><?= format_amount($totals['balance']) ?></td>
        </tr>
    </table>
<?php endif; ?>

<div class="foot-note">تطوير برمجة المجرات — <?= e(setting('system_name', 'رصيد')) ?></div>

<script nonce="<?= $nonce ?>">
    document.getElementById('printBtn').addEventListener('click', function () { window.print(); });
    window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>

<?php
/**
 * رصيد - التصدير (Excel XLSX / CSV)
 *
 * يدعم تصدير: جميع العمليات، الإيرادات فقط، المصروفات فقط،
 * أو نتائج البحث/الفلترة الحالية (تمرر الفلاتر في الرابط).
 */
require __DIR__ . '/includes/init.php';
require_login();
require BASE_PATH . '/includes/Xlsx.php';

$format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

[$whereSql, $bind] = build_tx_filters($_GET);

$rows = q('SELECT t.id, t.trans_date, t.type, c.name AS category_name, i.name AS item_name,
                  ' . tx_tags_subquery() . ' AS tag_names,
                  t.amount, t.is_asset, t.asset_name, t.notes, u.name AS user_name, t.created_at
           ' . tx_base_query() . "
           $whereSql
           ORDER BY t.trans_date ASC, t.id ASC", $bind)->fetchAll();

$headers = ['#', 'التاريخ', 'النوع', 'التصنيف', 'البند', 'التاق', 'المبلغ', 'حركة الرصيد', 'أصل', 'اسم الأصل', 'الملاحظات', 'المستخدم', 'وقت التسجيل'];

$prev = previous_balance($_GET);

$data = [];
$running = $prev['show'] ? $prev['balance'] : 0.0;
$seq = 0;
// صف الرصيد السابق المُرحّل (عند تفعيل الخيار مع تاريخ «من»)
if ($prev['show']) {
    $data[] = ['', '', '', '', '', 'الرصيد السابق (قبل ' . $prev['from'] . ')', '', $running, '', '', '', '', ''];
}
foreach ($rows as $r) {
    $seq++;
    $running += ($r['type'] === 'income' ? (float)$r['amount'] : -(float)$r['amount']);
    $data[] = [
        $seq,
        $r['trans_date'],
        type_label($r['type']),
        $r['category_name'],
        $r['item_name'],
        (string)$r['tag_names'],
        (float)$r['amount'],
        $running,
        (int)$r['is_asset'] === 1 ? 'نعم' : '',
        (string)$r['asset_name'],
        (string)$r['notes'],
        $r['user_name'],
        $r['created_at'],
    ];
}

// صف الإجماليات في نهاية الملف (التسمية تحت عمود التاق، والقيمة تحت المبلغ)
$totals = tx_totals($whereSql, $bind);
$data[] = ['', '', '', '', '', 'إجمالي الإيرادات', $totals['income'], '', '', '', '', '', ''];
$data[] = ['', '', '', '', '', 'إجمالي المصروفات', $totals['expense'], '', '', '', '', '', ''];
$data[] = ['', '', '', '', '', 'الرصيد', $totals['balance'], '', '', '', '', '', ''];

$typeSuffix = match ($_GET['type'] ?? '') {
    'income'  => '-الإيرادات',
    'expense' => '-المصروفات',
    default   => '',
};
$filename = 'raseed' . $typeSuffix . '-' . date('Y-m-d');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '.csv"');
    $out = fopen('php://output', 'w');
    // BOM ليتعرف Excel على الترميز العربي
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($data as $row) {
        // حماية من حقن معادلات CSV: أي خلية نصية تبدأ بـ = + - @ أو Tab
        // قد ينفذها Excel كمعادلة، لذا تُسبق بفاصلة عليا تجعلها نصاً صرفاً
        $safe = array_map(function ($cell) {
            if (is_string($cell) && $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false) {
                return "'" . $cell;
            }
            return $cell;
        }, $row);
        fputcsv($out, $safe);
    }
    fclose($out);
    exit;
}

Xlsx::download($filename, $headers, $data);

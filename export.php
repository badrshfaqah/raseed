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
                  tg.name AS tag_name, t.amount, t.notes, u.name AS user_name, t.created_at
           ' . tx_base_query() . "
           $whereSql
           ORDER BY t.trans_date DESC, t.id DESC", $bind)->fetchAll();

$headers = ['#', 'التاريخ', 'النوع', 'التصنيف', 'البند', 'التاق', 'المبلغ', 'الملاحظات', 'المستخدم', 'وقت التسجيل'];

$data = [];
foreach ($rows as $r) {
    $data[] = [
        (int)$r['id'],
        $r['trans_date'],
        type_label($r['type']),
        $r['category_name'],
        $r['item_name'],
        (string)$r['tag_name'],
        (float)$r['amount'],
        (string)$r['notes'],
        $r['user_name'],
        $r['created_at'],
    ];
}

// صف الإجماليات في نهاية الملف
$totals = tx_totals($whereSql, $bind);
$data[] = ['', '', '', '', '', 'إجمالي الإيرادات', $totals['income'], '', '', ''];
$data[] = ['', '', '', '', '', 'إجمالي المصروفات', $totals['expense'], '', '', ''];
$data[] = ['', '', '', '', '', 'الرصيد', $totals['balance'], '', '', ''];

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

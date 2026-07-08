<?php
/**
 * رصيد - الإحصائيات
 */
require __DIR__ . '/includes/init.php';
require_login();

// الفترة الافتراضية: السنة الحالية
$from = (!empty($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : date('Y-01-01');
$to   = (!empty($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : date('Y-m-d');

$bind   = [$from, $to];
$where  = 'WHERE t.trans_date BETWEEN ? AND ?';
$totals = tx_totals($where, $bind);

// أكثر التصنيفات صرفاً
$topCategories = q("SELECT c.name, SUM(t.amount) AS total
                    FROM transactions t JOIN categories c ON c.id = t.category_id
                    WHERE t.type = 'expense' AND t.trans_date BETWEEN ? AND ?
                    GROUP BY t.category_id, c.name
                    ORDER BY total DESC LIMIT 10", $bind)->fetchAll();

// أكثر البنود صرفاً
$topItems = q("SELECT i.name, c.name AS category_name, SUM(t.amount) AS total
               FROM transactions t
               JOIN items i ON i.id = t.item_id
               JOIN categories c ON c.id = t.category_id
               WHERE t.type = 'expense' AND t.trans_date BETWEEN ? AND ?
               GROUP BY t.item_id, i.name, c.name
               ORDER BY total DESC LIMIT 10", $bind)->fetchAll();

// الإيرادات والمصروفات شهرياً
$monthly = q("SELECT DATE_FORMAT(t.trans_date, '%Y-%m') AS month,
                     SUM(CASE WHEN t.type = 'income'  THEN t.amount ELSE 0 END) AS income,
                     SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) AS expense
              FROM transactions t
              WHERE t.trans_date BETWEEN ? AND ?
              GROUP BY month ORDER BY month", $bind)->fetchAll();

$chartMonths  = array_column($monthly, 'month');
$chartIncome  = array_map('floatval', array_column($monthly, 'income'));
$chartExpense = array_map('floatval', array_column($monthly, 'expense'));
$pieLabels    = array_column($topCategories, 'name');
$pieValues    = array_map('floatval', array_column($topCategories, 'total'));

$pageTitle = 'الإحصائيات';
require BASE_PATH . '/includes/layout/header.php';
?>

<!-- اختيار الفترة -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small">من تاريخ</label>
                <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small">إلى تاريخ</label>
                <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
            </div>
            <div class="col-12 col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-bar-chart-line"></i> عرض</button>
            </div>
        </form>
    </div>
</div>

<!-- الإجماليات -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-icon income"><i class="bi bi-arrow-down-circle"></i></div>
            <div>
                <div class="stat-label">إجمالي الإيرادات</div>
                <div class="stat-value"><?= format_amount($totals['income']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-icon expense"><i class="bi bi-arrow-up-circle"></i></div>
            <div>
                <div class="stat-label">إجمالي المصروفات</div>
                <div class="stat-value"><?= format_amount($totals['expense']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-icon balance"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="stat-label">الرصيد</div>
                <div class="stat-value <?= $totals['balance'] >= 0 ? 'positive' : 'negative' ?>">
                    <?= format_amount($totals['balance']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- الرسوم البيانية -->
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header">الإيرادات والمصروفات شهرياً</div>
            <div class="card-body">
                <canvas id="monthlyChart" height="240"></canvas>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header">توزيع المصروفات حسب التصنيف</div>
            <div class="card-body">
                <canvas id="pieChart" height="240"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- أكثر التصنيفات والبنود صرفاً -->
<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header">أكثر التصنيفات صرفاً</div>
            <div class="card-body p-0">
                <?php if (!$topCategories): ?>
                    <div class="text-center text-muted py-4">لا توجد مصروفات في هذه الفترة</div>
                <?php else: ?>
                    <table class="table align-middle">
                        <thead><tr><th>التصنيف</th><th class="text-start">الإجمالي</th></tr></thead>
                        <tbody>
                            <?php foreach ($topCategories as $r): ?>
                                <tr>
                                    <td><?= e($r['name']) ?></td>
                                    <td class="text-start amount-expense"><?= format_amount($r['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header">أكثر البنود صرفاً</div>
            <div class="card-body p-0">
                <?php if (!$topItems): ?>
                    <div class="text-center text-muted py-4">لا توجد مصروفات في هذه الفترة</div>
                <?php else: ?>
                    <table class="table align-middle">
                        <thead><tr><th>البند</th><th>التصنيف</th><th class="text-start">الإجمالي</th></tr></thead>
                        <tbody>
                            <?php foreach ($topItems as $r): ?>
                                <tr>
                                    <td><?= e($r['name']) ?></td>
                                    <td class="text-muted"><?= e($r['category_name']) ?></td>
                                    <td class="text-start amount-expense"><?= format_amount($r['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$chartData = json_encode([
    'months'    => $chartMonths,
    'income'    => $chartIncome,
    'expense'   => $chartExpense,
    'pieLabels' => $pieLabels,
    'pieValues' => $pieValues,
], JSON_UNESCAPED_UNICODE);

$nonce = csp_nonce();
$pageScripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script nonce="{$nonce}">
const data = {$chartData};
Chart.defaults.font.family = 'Tajawal, sans-serif';

new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: data.months,
        datasets: [
            { label: 'الإيرادات', data: data.income, backgroundColor: '#198754', borderRadius: 4 },
            { label: 'المصروفات', data: data.expense, backgroundColor: '#dc3545', borderRadius: 4 }
        ]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: true } },
        plugins: { legend: { position: 'bottom', rtl: true } }
    }
});

new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: {
        labels: data.pieLabels,
        datasets: [{
            data: data.pieValues,
            backgroundColor: ['#1e6f5c','#dc3545','#f0a13a','#2563eb','#7c3aed','#0891b2','#be185d','#65a30d','#a16207','#475569']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', rtl: true } }
    }
});
</script>
HTML;
require BASE_PATH . '/includes/layout/footer.php';
?>

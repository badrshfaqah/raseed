<?php
/**
 * رصيد - قسم الأصول
 *
 * يعرض كل العمليات التي أُشِّرت كأصل (مصروفات اقتناء أصول) لفرزها بسهولة،
 * مع اسم/تفاصيل كل أصل وإجمالي قيمتها. يدعم الفلترة بالتاريخ والتاق والبحث.
 */
require __DIR__ . '/includes/init.php';
require_login();

// الفلاتر مع فرض عرض الأصول فقط
$filters = $_GET;
$filters['is_asset'] = '1';
[$whereSql, $bind] = build_tx_filters($filters);

// ترقيم الصفحات
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$count   = (int)q('SELECT COUNT(*) ' . tx_base_query() . " $whereSql", $bind)->fetchColumn();
$pages   = max(1, (int)ceil($count / $perPage));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

$totals  = tx_totals($whereSql, $bind);
$totalValue = $totals['expense'] + $totals['income']; // الأصول مصروفات، لكن نجمع احتياطاً

$rows = q('SELECT t.*, c.name AS category_name, i.name AS item_name, u.name AS user_name,
           ' . tx_tags_subquery() . ' AS tag_names
           ' . tx_base_query() . "
           $whereSql
           ORDER BY t.trans_date DESC, t.id DESC
           LIMIT $perPage OFFSET $offset", $bind)->fetchAll();

$tags = q('SELECT id, name FROM tags ORDER BY name')->fetchAll();

$pageTitle = 'الأصول';
require BASE_PATH . '/includes/layout/header.php';
?>

<!-- الفلاتر -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small">من تاريخ</label>
                <input type="date" class="form-control form-control-sm" name="from" value="<?= e($_GET['from'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small">إلى تاريخ</label>
                <input type="date" class="form-control form-control-sm" name="to" value="<?= e($_GET['to'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small">التاق</label>
                <select class="form-select form-select-sm" name="tag_id">
                    <option value="">الكل</option>
                    <?php foreach ($tags as $tg): ?>
                        <option value="<?= $tg['id'] ?>" <?= (int)($_GET['tag_id'] ?? 0) === (int)$tg['id'] ? 'selected' : '' ?>>
                            <?= e($tg['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small">بحث</label>
                <input type="text" class="form-control form-control-sm" name="search"
                       value="<?= e($_GET['search'] ?? '') ?>" placeholder="اسم الأصل، البند، الملاحظات...">
            </div>
            <?php $assetPrintParams = http_build_query(array_filter([
                'is_asset' => '1',
                'from'     => $_GET['from'] ?? '',
                'to'       => $_GET['to'] ?? '',
                'tag_id'   => $_GET['tag_id'] ?? '',
                'search'   => $_GET['search'] ?? '',
            ])); ?>
            <div class="col-12 d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> تطبيق الفلترة</button>
                <a href="<?= APP_URL ?>assets.php" class="btn btn-sm btn-outline-secondary">إعادة تعيين</a>
                <a href="<?= APP_URL ?>print.php?<?= $assetPrintParams ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-dark ms-auto">
                    <i class="bi bi-printer"></i> طباعة
                </a>
            </div>
        </form>
    </div>
</div>

<!-- إجماليات -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="stat-card">
            <div class="stat-icon balance"><i class="bi bi-box-seam"></i></div>
            <div>
                <div class="stat-label">عدد الأصول</div>
                <div class="stat-value"><?= number_format($count) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="stat-card">
            <div class="stat-icon expense"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="stat-label">إجمالي قيمة الأصول</div>
                <div class="stat-value"><?= format_amount($totalValue) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- الجدول -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box-seam"></i> الأصول (<?= number_format($count) ?>)</span>
    </div>
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-box fs-1 d-block mb-2"></i>
                لا توجد أصول بعد. عند إضافة مصروف، فعّل خيار «تسجيل كأصل» ليظهر هنا.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-mobile align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>التاريخ</th>
                            <th>اسم / تفاصيل الأصل</th>
                            <th>التصنيف</th>
                            <th>البند</th>
                            <th>التاق</th>
                            <th>القيمة</th>
                            <th>الملاحظات</th>
                            <th>المستخدم</th>
                            <?php if (can_edit()): ?><th class="text-center">إجراءات</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $t): ?>
                            <tr>
                                <td data-label="#"><?= $t['id'] ?></td>
                                <td data-label="التاريخ"><?= format_date($t['trans_date']) ?></td>
                                <td data-label="اسم الأصل" class="fw-bold">
                                    <?= e($t['asset_name'] ?: '—') ?>
                                </td>
                                <td data-label="التصنيف"><?= e($t['category_name']) ?></td>
                                <td data-label="البند"><?= e($t['item_name']) ?></td>
                                <td data-label="التاق">
                                    <?php if (!empty($t['tag_names'])): ?>
                                        <?php foreach (explode('، ', $t['tag_names']) as $tgName): ?>
                                            <span class="badge text-bg-light border mb-1"><?= e($tgName) ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td data-label="القيمة" class="amount-expense"><?= format_amount($t['amount']) ?></td>
                                <td data-label="الملاحظات"><?= e($t['notes']) ?: '-' ?></td>
                                <td data-label="المستخدم"><?= e($t['user_name']) ?></td>
                                <?php if (can_edit()): ?>
                                    <td data-label="إجراءات" class="text-center">
                                        <a href="<?= APP_URL ?>transaction_edit.php?id=<?= $t['id'] ?>"
                                           class="btn btn-sm btn-outline-primary" title="تعديل">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php
                    $query = $_GET;
                    for ($p = 1; $p <= $pages; $p++):
                        $query['page'] = $p;
                    ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query($query) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>

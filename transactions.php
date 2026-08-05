<?php
/**
 * رصيد - كشف الحساب
 */
require __DIR__ . '/includes/init.php';
require_login();

[$whereSql, $bind] = build_tx_filters($_GET);
$totals = tx_totals($whereSql, $bind);

// ترقيم الصفحات
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$count   = (int)q('SELECT COUNT(*) ' . tx_base_query() . " $whereSql", $bind)->fetchColumn();
$pages   = max(1, (int)ceil($count / $perPage));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

$rows = q('SELECT t.*, c.name AS category_name, i.name AS item_name, u.name AS user_name,
           ' . tx_tags_subquery() . ' AS tag_names
           ' . tx_base_query() . "
           $whereSql
           ORDER BY t.trans_date DESC, t.id DESC
           LIMIT $perPage OFFSET $offset", $bind)->fetchAll();

$categories = q('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$tags       = q('SELECT id, name FROM tags ORDER BY name')->fetchAll();

// بنود التصنيف المختار (للفلتر)
$filterItems = [];
if (!empty($_GET['category_id']) && ctype_digit((string)$_GET['category_id'])) {
    $filterItems = q('SELECT id, name FROM items WHERE category_id = ? ORDER BY name', [(int)$_GET['category_id']])->fetchAll();
}

// رابط التصدير محتفظاً بالفلاتر الحالية
$exportParams = http_build_query(array_filter([
    'from'        => $_GET['from'] ?? '',
    'to'          => $_GET['to'] ?? '',
    'type'        => $_GET['type'] ?? '',
    'category_id' => $_GET['category_id'] ?? '',
    'item_id'     => $_GET['item_id'] ?? '',
    'tag_id'      => $_GET['tag_id'] ?? '',
    'is_asset'    => !empty($_GET['is_asset']) ? '1' : '',
    'search'      => $_GET['search'] ?? '',
]));

$pageTitle = 'كشف الحساب';
require BASE_PATH . '/includes/layout/header.php';
?>

<!-- الفلاتر -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small">من تاريخ</label>
                <input type="date" class="form-control form-control-sm" name="from" value="<?= e($_GET['from'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">إلى تاريخ</label>
                <input type="date" class="form-control form-control-sm" name="to" value="<?= e($_GET['to'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">النوع</label>
                <select class="form-select form-select-sm" name="type">
                    <option value="">الكل</option>
                    <option value="income" <?= ($_GET['type'] ?? '') === 'income' ? 'selected' : '' ?>>إيرادات</option>
                    <option value="expense" <?= ($_GET['type'] ?? '') === 'expense' ? 'selected' : '' ?>>مصروفات</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">التصنيف</label>
                <select class="form-select form-select-sm" name="category_id" data-autosubmit>
                    <option value="">الكل</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (int)($_GET['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">البند</label>
                <select class="form-select form-select-sm" name="item_id" <?= $filterItems ? '' : 'disabled' ?>>
                    <option value="">الكل</option>
                    <?php foreach ($filterItems as $i): ?>
                        <option value="<?= $i['id'] ?>" <?= (int)($_GET['item_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>>
                            <?= e($i['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
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
            <div class="col-6 col-md-2">
                <label class="form-label small">بحث</label>
                <input type="text" class="form-control form-control-sm" name="search"
                       value="<?= e($_GET['search'] ?? '') ?>" placeholder="في الملاحظات والبنود والتاق...">
            </div>
            <div class="col-6 col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="is_asset" id="assetFilter" value="1"
                           <?= !empty($_GET['is_asset']) ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="assetFilter">الأصول فقط</label>
                </div>
            </div>
            <div class="col-12 d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> تطبيق الفلترة</button>
                <a href="<?= APP_URL ?>transactions.php" class="btn btn-sm btn-outline-secondary">إعادة تعيين</a>
                <?php if (is_admin()): ?>
                    <a href="<?= APP_URL ?>quick_edit.php?<?= $exportParams ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil-square"></i> تحرير سريع
                    </a>
                <?php endif; ?>
                <div class="btn-group ms-auto">
                    <a href="<?= APP_URL ?>export.php?format=xlsx&<?= $exportParams ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-file-earmark-excel"></i> تصدير Excel
                    </a>
                    <a href="<?= APP_URL ?>export.php?format=csv&<?= $exportParams ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-filetype-csv"></i> CSV
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- إجماليات النتائج -->
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

<!-- الجدول -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>العمليات (<?= number_format($count) ?>)</span>
    </div>
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-search fs-1 d-block mb-2"></i>
                لا توجد عمليات مطابقة
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-mobile align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>التاريخ</th>
                            <th>النوع</th>
                            <th>التصنيف</th>
                            <th>البند</th>
                            <th>التاق</th>
                            <th>المبلغ</th>
                            <th>الملاحظات</th>
                            <th>المستخدم</th>
                            <th>الإيصال</th>
                            <?php if (can_edit()): ?><th class="text-center">إجراءات</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $t): ?>
                            <tr>
                                <td data-label="#"><?= $t['id'] ?></td>
                                <td data-label="التاريخ"><?= format_date($t['trans_date']) ?></td>
                                <td data-label="النوع">
                                    <span class="badge badge-<?= $t['type'] ?>"><?= type_label($t['type']) ?></span>
                                </td>
                                <td data-label="التصنيف"><?= e($t['category_name']) ?></td>
                                <td data-label="البند">
                                    <?= e($t['item_name']) ?>
                                    <?php if (!empty($t['is_asset'])): ?>
                                        <span class="badge text-bg-warning" title="<?= e((string)($t['asset_name'] ?? '')) ?>">
                                            <i class="bi bi-box-seam"></i> أصل<?= !empty($t['asset_name']) ? ': ' . e($t['asset_name']) : '' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="التاق">
                                    <?php if (!empty($t['tag_names'])): ?>
                                        <?php foreach (explode('، ', $t['tag_names']) as $tgName): ?>
                                            <span class="badge text-bg-light border mb-1"><?= e($tgName) ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td data-label="المبلغ" class="amount-<?= $t['type'] ?>">
                                    <?= ($t['type'] === 'expense' ? '-' : '+') . ' ' . format_amount($t['amount']) ?>
                                </td>
                                <td data-label="الملاحظات"><?= e($t['notes']) ?: '-' ?></td>
                                <td data-label="المستخدم"><?= e($t['user_name']) ?></td>
                                <td data-label="الإيصال">
                                    <?php
                                    $received = (int)$t['receipt_status'] === 1;
                                    $badgeClass = 'badge-receipt ' . ($received ? 'badge-receipt-received' : 'badge-receipt-missing');
                                    $icon  = $received ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';
                                    $label = $received ? 'تم الاستلام' : 'لم يستلم';
                                    ?>
                                    <?php if (can_toggle_receipt()): ?>
                                        <button type="button" class="<?= $badgeClass ?>"
                                                data-receipt-toggle data-tx-id="<?= $t['id'] ?>">
                                            <i class="bi receipt-icon <?= $icon ?>"></i>
                                            <span class="receipt-label"><?= $label ?></span>
                                        </button>
                                    <?php else: ?>
                                        <span class="<?= $badgeClass ?>">
                                            <i class="bi receipt-icon <?= $icon ?>"></i>
                                            <span class="receipt-label"><?= $label ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php if (can_edit()): ?>
                                    <td data-label="إجراءات" class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?= APP_URL ?>transaction_edit.php?id=<?= $t['id'] ?>"
                                               class="btn btn-sm btn-outline-primary" title="تعديل">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <form method="post" action="<?= APP_URL ?>transaction_delete.php"
                                                  data-confirm="هل أنت متأكد من حذف هذه العملية؟ لا يمكن التراجع.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
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

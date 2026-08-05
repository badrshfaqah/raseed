<?php
/**
 * رصيد - التحرير السريع (مدير النظام فقط)
 *
 * جدول قابل للتحرير المباشر لعدّة عمليات دفعة واحدة: تعديل التاريخ
 * والنوع والتصنيف والبند والتاق والمبلغ والملاحظات لأي صف، أو حذفه،
 * ثم حفظ الكل بضغطة واحدة. يُحدَّث ويُزامَن فقط ما تغيّر فعلاً.
 */
require __DIR__ . '/includes/init.php';
require_admin();
require BASE_PATH . '/includes/GoogleSheets.php';

const QE_MAX_ROWS = 200;

/* ---------------- حفظ التعديلات ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verify_csrf();

    $rows = $_POST['rows'] ?? [];
    $updated = $deleted = $unchanged = 0;
    $errors  = [];

    // خرائط التحقق: بنود كل تصنيف، والتاقات
    $catItems = [];
    foreach (q('SELECT id, category_id FROM items')->fetchAll() as $r) {
        $catItems[(int)$r['id']] = (int)$r['category_id'];
    }
    $validTagIds = array_map('intval', q('SELECT id FROM tags')->fetchAll(PDO::FETCH_COLUMN));

    $syncUpdate = [];
    $syncDelete = [];

    try {
        db()->beginTransaction();

        foreach ($rows as $id => $in) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            $current = q('SELECT * FROM transactions WHERE id = ?', [$id])->fetch();
            if (!$current) {
                continue;
            }

            // حذف الصف المؤشَّر
            if (!empty($in['delete'])) {
                q('DELETE FROM transactions WHERE id = ?', [$id]);
                $syncDelete[] = $id;
                $deleted++;
                continue;
            }

            $type       = ($in['type'] ?? '') === 'income' ? 'income' : 'expense';
            $transDate  = trim($in['trans_date'] ?? '');
            $categoryId = (int)($in['category_id'] ?? 0);
            $itemId     = (int)($in['item_id'] ?? 0);
            $tagIds     = array_map('intval', (array)($in['tag_ids'] ?? []));
            $amount     = (float)str_replace(',', '', (string)($in['amount'] ?? '0'));
            $notes      = trim($in['notes'] ?? '');

            // تحقّق الصف
            $rowErr = [];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transDate) || !strtotime($transDate)) {
                $rowErr[] = 'تاريخ غير صحيح';
            }
            if ($amount <= 0) {
                $rowErr[] = 'المبلغ يجب أن يكون أكبر من صفر';
            } elseif ($amount > 999999999999.99) {
                $rowErr[] = 'المبلغ يتجاوز الحد';
            }
            if (mb_strlen($notes) > 1000) {
                $rowErr[] = 'الملاحظات طويلة';
            }
            if ($itemId <= 0 || !isset($catItems[$itemId]) || $catItems[$itemId] !== $categoryId) {
                $rowErr[] = 'التصنيف والبند غير متطابقين';
            }
            foreach ($tagIds as $tid) {
                if ($tid > 0 && !in_array($tid, $validTagIds, true)) {
                    $rowErr[] = 'أحد التاقات غير صالح';
                    break;
                }
            }
            if ($rowErr) {
                $errors[] = 'العملية #' . $id . ': ' . implode('، ', $rowErr);
                continue;
            }

            // هل تغيّرت الحقول العادية؟
            $fieldsChanged = $type !== $current['type']
                || $transDate !== $current['trans_date']
                || $categoryId !== (int)$current['category_id']
                || $itemId !== (int)$current['item_id']
                || (float)$amount !== (float)$current['amount']
                || $notes !== (string)$current['notes'];

            if ($fieldsChanged) {
                q('UPDATE transactions SET type = ?, trans_date = ?, category_id = ?, item_id = ?, amount = ?, notes = ?
                   WHERE id = ?',
                  [$type, $transDate, $categoryId, $itemId, $amount, $notes ?: null, $id]);
            }
            // مزامنة التاقات (تعيد true إذا تغيّرت المجموعة فعلاً)
            $tagsChanged = set_tx_tags($id, $tagIds, $validTagIds);

            if (!$fieldsChanged && !$tagsChanged) {
                $unchanged++;
                continue;
            }
            $syncUpdate[] = $id;
            $updated++;
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        log_error('quick_edit: ' . $e->getMessage());
        flash('danger', 'حدث خطأ أثناء الحفظ، لم تُطبَّق التعديلات.');
        redirect(APP_URL . 'quick_edit.php');
    }

    // المزامنة مع Google Sheets بعد نجاح الحفظ (أفضل جهد)
    $syncFailed = 0;
    if (GoogleSheets::enabled()) {
        foreach ($syncUpdate as $id) {
            if (!GoogleSheets::sync($id, 'update')) { $syncFailed++; }
        }
        foreach ($syncDelete as $id) {
            if (!GoogleSheets::sync($id, 'delete')) { $syncFailed++; }
        }
    }

    $parts = [];
    if ($updated)   { $parts[] = "تم تعديل $updated عملية"; }
    if ($deleted)   { $parts[] = "حذف $deleted"; }
    if ($unchanged) { $parts[] = "بلا تغيير $unchanged"; }
    $msg = $parts ? implode('، ', $parts) . '.' : 'لم تُجرَ أي تعديلات.';
    if ($syncFailed) { $msg .= " (تعذّرت مزامنة $syncFailed مع Google Sheets)"; }
    if ($errors)     { $msg .= ' — تجاوز ' . count($errors) . ' صفاً بأخطاء.'; }

    flash($errors ? 'warning' : 'success', $msg);
    foreach ($errors as $e) { flash('danger', $e); }

    // الاحتفاظ بالفلاتر الحالية بعد الحفظ
    redirect(APP_URL . 'quick_edit.php' . ($_POST['filters'] ?? '' ? '?' . $_POST['filters'] : ''));
}

/* ---------------- عرض الجدول ---------------- */

[$whereSql, $bind] = build_tx_filters($_GET);

$total = (int)q('SELECT COUNT(*) ' . tx_base_query() . " $whereSql", $bind)->fetchColumn();

$rows = q('SELECT t.*, c.name AS category_name, i.name AS item_name
           ' . tx_base_query() . "
           $whereSql
           ORDER BY t.trans_date DESC, t.id DESC
           LIMIT " . QE_MAX_ROWS, $bind)->fetchAll();

// تاقات كل عملية معروضة (استعلام واحد) → خريطة: معرّف العملية => [معرّفات التاقات]
$rowTagMap = [];
$rowIds    = array_map(static fn($r) => (int)$r['id'], $rows);
if ($rowIds) {
    $ph = implode(',', array_fill(0, count($rowIds), '?'));
    foreach (q("SELECT transaction_id, tag_id FROM transaction_tags WHERE transaction_id IN ($ph)", $rowIds)->fetchAll() as $tt) {
        $rowTagMap[(int)$tt['transaction_id']][] = (int)$tt['tag_id'];
    }
}

$categories = q('SELECT id, name FROM categories WHERE status = 1 ORDER BY name')->fetchAll();
$allItems   = q('SELECT id, category_id, name FROM items WHERE status = 1 ORDER BY name')->fetchAll();
$tags       = q('SELECT id, name FROM tags WHERE status = 1 ORDER BY name')->fetchAll();

// بنود مبوّبة حسب التصنيف لـ JavaScript
$itemsByCat = [];
foreach ($allItems as $it) {
    $itemsByCat[(int)$it['category_id']][] = ['id' => (int)$it['id'], 'name' => $it['name']];
}

// سلسلة الفلاتر الحالية (للحفاظ عليها بعد الحفظ)
$filterQuery = http_build_query(array_filter([
    'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'type' => $_GET['type'] ?? '', 'category_id' => $_GET['category_id'] ?? '',
    'tag_id' => $_GET['tag_id'] ?? '', 'search' => $_GET['search'] ?? '',
]));

$pageTitle = 'التحرير السريع';
require BASE_PATH . '/includes/layout/header.php';
?>

<!-- الفلاتر -->
<div class="card mb-3">
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
                <select class="form-select form-select-sm" name="category_id">
                    <option value="">الكل</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (int)($_GET['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">التاق</label>
                <select class="form-select form-select-sm" name="tag_id">
                    <option value="">الكل</option>
                    <?php foreach ($tags as $tg): ?>
                        <option value="<?= $tg['id'] ?>" <?= (int)($_GET['tag_id'] ?? 0) === (int)$tg['id'] ? 'selected' : '' ?>><?= e($tg['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">بحث</label>
                <input type="text" class="form-control form-control-sm" name="search" value="<?= e($_GET['search'] ?? '') ?>" placeholder="بحث...">
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> تطبيق الفلترة</button>
                <a href="<?= APP_URL ?>quick_edit.php" class="btn btn-sm btn-outline-secondary">إعادة تعيين</a>
                <a href="<?= APP_URL ?>transactions.php?<?= $filterQuery ?>" class="btn btn-sm btn-outline-secondary ms-auto">
                    <i class="bi bi-list-ul"></i> كشف الحساب
                </a>
            </div>
        </form>
    </div>
</div>

<?php if ($total > QE_MAX_ROWS): ?>
    <div class="alert alert-info py-2 small">
        <i class="bi bi-info-circle"></i>
        يعرض التحرير السريع أحدث <?= QE_MAX_ROWS ?> عملية فقط من أصل <?= number_format($total) ?>. استخدم الفلاتر أعلاه لتضييق النتائج.
    </div>
<?php endif; ?>

<form method="post" id="qeForm" data-confirm="حفظ كل التعديلات على العمليات المعروضة؟">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="filters" value="<?= e($filterQuery) ?>">

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-pencil-square"></i> تحرير سريع (<?= count($rows) ?> عملية)</span>
            <button type="submit" class="btn btn-sm btn-success">
                <i class="bi bi-check-circle"></i> حفظ كل التعديلات
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (!$rows): ?>
                <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i> لا توجد عمليات مطابقة</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="min-width:940px;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th style="min-width:140px;">التاريخ</th>
                                <th style="min-width:110px;">النوع</th>
                                <th style="min-width:150px;">التصنيف</th>
                                <th style="min-width:150px;">البند</th>
                                <th style="min-width:140px;">التاق</th>
                                <th style="min-width:130px;">المبلغ</th>
                                <th style="min-width:180px;">الملاحظات</th>
                                <th class="text-center" title="حذف">🗑</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $t): $id = (int)$t['id']; ?>
                                <tr data-row="<?= $id ?>">
                                    <td class="text-muted"><?= $id ?></td>
                                    <td><input type="date" class="form-control form-control-sm" name="rows[<?= $id ?>][trans_date]" value="<?= e($t['trans_date']) ?>"></td>
                                    <td>
                                        <select class="form-select form-select-sm" name="rows[<?= $id ?>][type]">
                                            <option value="income" <?= $t['type'] === 'income' ? 'selected' : '' ?>>إيراد</option>
                                            <option value="expense" <?= $t['type'] === 'expense' ? 'selected' : '' ?>>مصروف</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm qe-cat" name="rows[<?= $id ?>][category_id]" data-row="<?= $id ?>">
                                            <?php foreach ($categories as $c): ?>
                                                <option value="<?= $c['id'] ?>" <?= (int)$t['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm qe-item" name="rows[<?= $id ?>][item_id]" data-current="<?= (int)$t['item_id'] ?>">
                                            <!-- تُملأ عبر JavaScript حسب التصنيف -->
                                        </select>
                                    </td>
                                    <td>
                                        <?php $rowTags = $rowTagMap[$id] ?? []; ?>
                                        <select class="form-select form-select-sm" name="rows[<?= $id ?>][tag_ids][]" multiple size="3" title="Ctrl/Cmd لاختيار عدة تاقات">
                                            <?php foreach ($tags as $tg): ?>
                                                <option value="<?= $tg['id'] ?>" <?= in_array((int)$tg['id'], $rowTags, true) ? 'selected' : '' ?>><?= e($tg['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" min="0.01" dir="ltr" class="form-control form-control-sm" name="rows[<?= $id ?>][amount]" value="<?= e((string)$t['amount']) ?>"></td>
                                    <td><input type="text" maxlength="1000" class="form-control form-control-sm" name="rows[<?= $id ?>][notes]" value="<?= e($t['notes']) ?>"></td>
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input qe-del" name="rows[<?= $id ?>][delete]" value="1" title="حذف هذه العملية">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($rows): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="text-muted small"><i class="bi bi-info-circle"></i> عدّل أي حقل مباشرة ثم اضغط حفظ. يُطبَّق التغيير على ما تعدّله فقط.</span>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle"></i> حفظ كل التعديلات
            </button>
        </div>
        <?php endif; ?>
    </div>
</form>

<?php
$itemsJson = json_encode($itemsByCat, JSON_UNESCAPED_UNICODE);
$nonce = csp_nonce();
$pageScripts = <<<HTML
<script nonce="{$nonce}">
(function () {
    var itemsByCat = {$itemsJson};

    function fillItems(catSelect) {
        var row = catSelect.getAttribute('data-row');
        var tr = catSelect.closest('tr');
        var itemSelect = tr.querySelector('.qe-item');
        var current = itemSelect.getAttribute('data-current');
        var catId = catSelect.value;
        var list = itemsByCat[catId] || [];
        itemSelect.innerHTML = '';
        if (!list.length) {
            var opt = document.createElement('option');
            opt.value = ''; opt.textContent = '— لا بنود —';
            itemSelect.appendChild(opt);
            return;
        }
        list.forEach(function (it) {
            var opt = document.createElement('option');
            opt.value = it.id; opt.textContent = it.name;
            if (String(it.id) === String(current)) opt.selected = true;
            itemSelect.appendChild(opt);
        });
    }

    // تعبئة أولية لكل الصفوف
    document.querySelectorAll('.qe-cat').forEach(function (sel) {
        fillItems(sel);
        sel.addEventListener('change', function () {
            // عند تغيير التصنيف، أفرغ البند المحدد سابقاً ثم أعد التعبئة
            sel.closest('tr').querySelector('.qe-item').setAttribute('data-current', '');
            fillItems(sel);
        });
    });

    // تمييز صف الحذف بصرياً
    document.querySelectorAll('.qe-del').forEach(function (chk) {
        chk.addEventListener('change', function () {
            chk.closest('tr').style.opacity = chk.checked ? '0.5' : '1';
        });
    });
})();
</script>
HTML;
require BASE_PATH . '/includes/layout/footer.php';
?>

<?php
/**
 * رصيد - استيراد العمليات من ملف Excel / CSV (مدير النظام فقط)
 *
 * التدفّق:
 *  1) المدير ينزّل القالب الجاهز، يعبّئه، ويرفعه.
 *  2) النظام يقرأ الملف ويتحقق من كل صف ويعرض معاينة، مع تحديد
 *     التصنيفات والبنود الجديدة التي ستُنشأ تلقائياً.
 *  3) عند التأكيد تُستورد كل الصفوف الصحيحة داخل Transaction واحدة،
 *     وتُنشأ التصنيفات/البنود الناقصة، ثم تُزامَن مع Google Sheets.
 */
require __DIR__ . '/includes/init.php';
require_admin();
require BASE_PATH . '/includes/Xlsx.php';
require BASE_PATH . '/includes/XlsxReader.php';
require BASE_PATH . '/includes/GoogleSheets.php';

const IMPORT_MAX_ROWS = 5000;
const IMPORT_COLUMNS  = ['التاريخ', 'النوع', 'التصنيف', 'البند', 'المبلغ', 'الملاحظات', 'التاق'];

/* ---------------- تنزيل القالب ---------------- */

if (($_GET['action'] ?? '') === 'template') {
    $example = [
        ['2026-01-15', 'إيراد', 'إيرادات', 'دفعة مشروع', 5000, 'دفعة أولى', 'مشروع الرياض'],
        ['2026-01-16', 'مصروف', 'ضيافة', 'قهوة', 120, 'اجتماع', 'مشروع الرياض، الموظف أحمد'],
        ['2026-01-16', 'مصروف', 'نقل', 'وقود', 200, '', ''],
    ];
    Xlsx::download('raseed-import-template', IMPORT_COLUMNS, $example);
}

/* ---------------- أدوات مساعدة ---------------- */

/** هل الصف يشبه صف العناوين؟ */
function is_header_row(array $row): bool
{
    $first = mb_strtolower(trim($row[0] ?? ''));
    return in_array($first, ['التاريخ', 'date', 'تاريخ'], true);
}

/** هل الصف فارغ تماماً؟ */
function is_empty_row(array $row): bool
{
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }
    return true;
}

/** تفكيك خلية التاقات إلى أسماء (يفصلها ، أو ؛ أو , أو ;) مع إزالة التكرار */
function parse_tag_names(string $cell): array
{
    $parts = preg_split('/[،؛,;]+/u', $cell) ?: [];
    $out   = [];
    foreach ($parts as $p) {
        $name = trim($p);
        if ($name === '') {
            continue;
        }
        $out[mb_strtolower($name)] = $name; // إزالة التكرار مع الإبقاء على أول صياغة
    }
    return array_values($out);
}

/**
 * تحويل صفوف الملف الخام إلى معاينة مُتحقَّق منها.
 * @return array{preview:array, valid:array, errors:int, newCats:array, newItems:array}
 */
function build_preview(array $rows): array
{
    // فهارس التصنيفات والبنود الموجودة (بأحرف صغيرة للمطابقة)
    $existingCats = [];
    foreach (q('SELECT name FROM categories')->fetchAll() as $r) {
        $existingCats[mb_strtolower($r['name'])] = true;
    }
    $existingItems = [];
    foreach (q('SELECT i.name AS item, c.name AS cat FROM items i JOIN categories c ON c.id = i.category_id')->fetchAll() as $r) {
        $existingItems[mb_strtolower($r['cat']) . '|' . mb_strtolower($r['item'])] = true;
    }
    $existingTags = [];
    foreach (q('SELECT name FROM tags')->fetchAll() as $r) {
        $existingTags[mb_strtolower($r['name'])] = true;
    }

    $preview  = [];
    $valid    = [];
    $errors   = 0;
    $newCats  = [];
    $newItems = [];
    $newTags  = [];
    $rowNum   = 0;

    foreach ($rows as $raw) {
        $rowNum++;
        if ($rowNum === 1 && is_header_row($raw)) {
            continue; // تخطّي صف العناوين
        }
        if (is_empty_row($raw)) {
            continue;
        }

        $dateRaw = trim($raw[0] ?? '');
        $typeRaw = trim($raw[1] ?? '');
        $catName = trim($raw[2] ?? '');
        $itemName = trim($raw[3] ?? '');
        $amountRaw = str_replace([',', ' '], '', trim($raw[4] ?? ''));
        $notes   = trim($raw[5] ?? '');
        $tagCell = trim($raw[6] ?? '');
        $tagNames = parse_tag_names($tagCell);

        $rowErrors = [];
        $date = XlsxReader::normalizeDate($dateRaw);
        if ($date === null) {
            $rowErrors[] = 'تاريخ غير صحيح';
        }
        $type = XlsxReader::normalizeType($typeRaw);
        if ($type === null) {
            $rowErrors[] = 'النوع يجب أن يكون «إيراد» أو «مصروف»';
        }
        if ($catName === '') {
            $rowErrors[] = 'التصنيف مطلوب';
        }
        if ($itemName === '') {
            $rowErrors[] = 'البند مطلوب';
        }
        $amount = is_numeric($amountRaw) ? (float) $amountRaw : -1;
        if (!is_numeric($amountRaw) || $amount <= 0) {
            $rowErrors[] = 'المبلغ يجب أن يكون رقماً أكبر من صفر';
        } elseif ($amount > 999999999999.99) {
            $rowErrors[] = 'المبلغ يتجاوز الحد الأقصى';
        }
        if (mb_strlen($notes) > 1000) {
            $rowErrors[] = 'الملاحظات طويلة جداً';
        }
        foreach ($tagNames as $tn) {
            if (mb_strlen($tn) > 100) {
                $rowErrors[] = 'أحد أسماء التاقات طويل جداً';
                break;
            }
        }

        $ok = !$rowErrors;

        // رصد التصنيفات والبنود والتاقات الجديدة (للصفوف الصحيحة فقط)
        if ($ok) {
            $catKey = mb_strtolower($catName);
            if (!isset($existingCats[$catKey]) && !isset($newCats[$catKey])) {
                $newCats[$catKey] = $catName;
            }
            $itemKey = $catKey . '|' . mb_strtolower($itemName);
            if (!isset($existingItems[$itemKey]) && !isset($newItems[$itemKey])) {
                $newItems[$itemKey] = $catName . ' ← ' . $itemName;
            }
            foreach ($tagNames as $tn) {
                $tagKey = mb_strtolower($tn);
                if (!isset($existingTags[$tagKey]) && !isset($newTags[$tagKey])) {
                    $newTags[$tagKey] = $tn;
                }
            }
            $valid[] = [
                'date' => $date, 'type' => $type, 'category' => $catName,
                'item' => $itemName, 'amount' => $amount, 'notes' => $notes, 'tags' => $tagNames,
            ];
        } else {
            $errors++;
        }

        $preview[] = [
            'row' => $rowNum, 'date' => $dateRaw, 'type' => $typeRaw,
            'category' => $catName, 'item' => $itemName, 'amount' => $amountRaw,
            'notes' => $notes, 'tag' => implode('، ', $tagNames), 'ok' => $ok, 'error' => implode('، ', $rowErrors),
        ];
    }

    return [
        'preview'  => $preview,
        'valid'    => $valid,
        'errors'   => $errors,
        'newCats'  => array_values($newCats),
        'newItems' => array_values($newItems),
        'newTags'  => array_values($newTags),
    ];
}

/* ---------------- تنفيذ الاستيراد (التأكيد) ---------------- */

function run_import(array $validRows): array
{
    $catCache  = [];
    $itemCache = [];
    $tagCache  = [];
    $insertedIds = [];

    db()->beginTransaction();
    try {
        $insertTx = db()->prepare(
            'INSERT INTO transactions (type, trans_date, category_id, item_id, amount, notes, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $insertTag = db()->prepare('INSERT IGNORE INTO transaction_tags (transaction_id, tag_id) VALUES (?, ?)');
        foreach ($validRows as $r) {
            $catId  = resolve_category($r['category'], $catCache);
            $itemId = resolve_item($catId, $r['item'], $itemCache);
            $insertTx->execute([
                $r['type'], $r['date'], $catId, $itemId,
                $r['amount'], $r['notes'] !== '' ? $r['notes'] : null,
                current_user()['id'],
            ]);
            $txId = (int) db()->lastInsertId();
            $insertedIds[] = $txId;
            foreach (($r['tags'] ?? []) as $tagName) {
                $insertTag->execute([$txId, resolve_tag($tagName, $tagCache)]);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        log_error('import: ' . $e->getMessage());
        return ['ok' => false, 'count' => 0, 'message' => 'حدث خطأ أثناء الاستيراد، لم تُحفظ أي عملية.'];
    }

    // المزامنة مع Google Sheets بعد نجاح الحفظ (أفضل جهد - لا توقف الاستيراد)
    $syncFailed = 0;
    if (GoogleSheets::enabled()) {
        foreach ($insertedIds as $id) {
            if (!GoogleSheets::sync($id, 'add')) {
                $syncFailed++;
            }
        }
    }

    $msg = 'تم استيراد ' . count($insertedIds) . ' عملية بنجاح.';
    if ($syncFailed > 0) {
        $msg .= " (تعذّرت مزامنة $syncFailed منها مع Google Sheets - راجع سجل المزامنة)";
    }
    return ['ok' => true, 'count' => count($insertedIds), 'message' => $msg];
}

/** إيجاد تصنيف بالاسم أو إنشاؤه */
function resolve_category(string $name, array &$cache): int
{
    $key = mb_strtolower($name);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $id = q('SELECT id FROM categories WHERE name = ?', [$name])->fetchColumn();
    if (!$id) {
        q('INSERT INTO categories (name) VALUES (?)', [$name]);
        $id = db()->lastInsertId();
    }
    return $cache[$key] = (int) $id;
}

/** إيجاد بند ضمن تصنيف أو إنشاؤه */
function resolve_item(int $categoryId, string $name, array &$cache): int
{
    $key = $categoryId . '|' . mb_strtolower($name);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $id = q('SELECT id FROM items WHERE category_id = ? AND name = ?', [$categoryId, $name])->fetchColumn();
    if (!$id) {
        q('INSERT INTO items (category_id, name) VALUES (?, ?)', [$categoryId, $name]);
        $id = db()->lastInsertId();
    }
    return $cache[$key] = (int) $id;
}

/** إيجاد تاق بالاسم أو إنشاؤه */
function resolve_tag(string $name, array &$cache): int
{
    $key = mb_strtolower($name);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $id = q('SELECT id FROM tags WHERE name = ?', [$name])->fetchColumn();
    if (!$id) {
        q('INSERT INTO tags (name) VALUES (?)', [$name]);
        $id = db()->lastInsertId();
    }
    return $cache[$key] = (int) $id;
}

/* ---------------- معالجة الطلبات ---------------- */

$data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            flash('danger', 'يرجى اختيار ملف صالح للرفع.');
            redirect(APP_URL . 'import.php');
        }
        $name = $_FILES['file']['name'] ?? '';
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        try {
            if ($ext === 'xlsx') {
                $rows = XlsxReader::read($_FILES['file']['tmp_name']);
            } elseif ($ext === 'csv') {
                $rows = read_csv_rows($_FILES['file']['tmp_name']);
            } else {
                flash('danger', 'الصيغة غير مدعومة. استخدم XLSX أو CSV.');
                redirect(APP_URL . 'import.php');
            }
        } catch (Throwable $e) {
            flash('danger', 'تعذّرت قراءة الملف: ' . $e->getMessage());
            redirect(APP_URL . 'import.php');
        }

        if (count($rows) > IMPORT_MAX_ROWS + 1) {
            flash('danger', 'الملف يتجاوز الحد الأقصى (' . IMPORT_MAX_ROWS . ' صف). قسّمه إلى ملفات أصغر.');
            redirect(APP_URL . 'import.php');
        }

        $data = build_preview($rows);
        // حفظ الصفوف الصحيحة في الجلسة للتأكيد (لا نعيد رفع الملف)
        $_SESSION['import_valid'] = $data['valid'];
    }

    if ($action === 'confirm') {
        $validRows = $_SESSION['import_valid'] ?? [];
        unset($_SESSION['import_valid']);
        if (!$validRows) {
            flash('warning', 'لا توجد بيانات للاستيراد. يرجى رفع الملف من جديد.');
            redirect(APP_URL . 'import.php');
        }
        $result = run_import($validRows);
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
        redirect(APP_URL . ($result['ok'] ? 'transactions.php' : 'import.php'));
    }
}

/** قراءة صفوف CSV مع دعم الترميز العربي (BOM) */
function read_csv_rows(string $path): array
{
    $rows = [];
    if (($h = fopen($path, 'r')) !== false) {
        $first = true;
        while (($row = fgetcsv($h)) !== false) {
            if ($first) {
                // إزالة BOM من أول خلية إن وُجد
                if (isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
                }
                $first = false;
            }
            $rows[] = $row;
        }
        fclose($h);
    }
    return $rows;
}

$pageTitle = 'استيراد من Excel';
require BASE_PATH . '/includes/layout/header.php';
?>

<?php if ($data === null): ?>
    <!-- الخطوة 1: التعليمات والرفع -->
    <div class="row g-4">
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-file-earmark-arrow-down"></i> 1. نزّل القالب</div>
                <div class="card-body">
                    <p class="text-muted">نزّل القالب الجاهز، وعبّئ عملياتك (إيرادات ومصروفات) في نفس الأعمدة، ثم ارفعه.</p>
                    <a href="<?= APP_URL ?>import.php?action=template" class="btn btn-outline-primary w-100 mb-3">
                        <i class="bi bi-file-earmark-excel"></i> تنزيل قالب Excel
                    </a>
                    <p class="fw-bold mb-2">الأعمدة:</p>
                    <ul class="check-list small">
                        <li><span>التاريخ</span><span class="text-muted">مثل 2026-01-15</span></li>
                        <li><span>النوع</span><span class="text-muted">إيراد أو مصروف</span></li>
                        <li><span>التصنيف</span><span class="text-muted">يُنشأ تلقائياً إن كان جديداً</span></li>
                        <li><span>البند</span><span class="text-muted">يُنشأ تلقائياً إن كان جديداً</span></li>
                        <li><span>المبلغ</span><span class="text-muted">رقم أكبر من صفر</span></li>
                        <li><span>الملاحظات</span><span class="text-muted">اختياري</span></li>
                        <li><span>التاق</span><span class="text-muted">اختياري - عدة تاقات تُفصل بفاصلة ،</span></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-upload"></i> 2. ارفع الملف</div>
                <div class="card-body">
                    <div class="alert alert-info py-2 small">
                        <i class="bi bi-info-circle"></i>
                        التصنيفات والبنود والتاقات غير الموجودة ستُنشأ تلقائياً أثناء الاستيراد. سترى معاينة كاملة قبل الحفظ النهائي.
                    </div>
                    <form method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload">
                        <div class="mb-3">
                            <label class="form-label">ملف Excel (XLSX) أو CSV</label>
                            <input type="file" class="form-control" name="file" accept=".xlsx,.csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-eye"></i> رفع ومعاينة
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- الخطوة 2: المعاينة والتأكيد -->
    <?php $validCount = count($data['valid']); ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2 col-xl">
            <div class="stat-card">
                <div class="stat-icon income"><i class="bi bi-check2-circle"></i></div>
                <div><div class="stat-label">صفوف صحيحة</div><div class="stat-value"><?= $validCount ?></div></div>
            </div>
        </div>
        <div class="col-6 col-md-2 col-xl">
            <div class="stat-card">
                <div class="stat-icon expense"><i class="bi bi-x-circle"></i></div>
                <div><div class="stat-label">أخطاء</div><div class="stat-value"><?= (int) $data['errors'] ?></div></div>
            </div>
        </div>
        <div class="col-6 col-md-2 col-xl">
            <div class="stat-card">
                <div class="stat-icon balance"><i class="bi bi-tags"></i></div>
                <div><div class="stat-label">تصنيفات جديدة</div><div class="stat-value"><?= count($data['newCats']) ?></div></div>
            </div>
        </div>
        <div class="col-6 col-md-2 col-xl">
            <div class="stat-card">
                <div class="stat-icon balance"><i class="bi bi-list-ul"></i></div>
                <div><div class="stat-label">بنود جديدة</div><div class="stat-value"><?= count($data['newItems']) ?></div></div>
            </div>
        </div>
        <div class="col-6 col-md-2 col-xl">
            <div class="stat-card">
                <div class="stat-icon balance"><i class="bi bi-tag"></i></div>
                <div><div class="stat-label">تاقات جديدة</div><div class="stat-value"><?= count($data['newTags']) ?></div></div>
            </div>
        </div>
    </div>

    <?php if ($data['newCats'] || $data['newItems'] || $data['newTags']): ?>
        <div class="alert alert-info">
            <?php if ($data['newCats']): ?>
                <div><strong>تصنيفات ستُنشأ:</strong> <?= e(implode('، ', $data['newCats'])) ?></div>
            <?php endif; ?>
            <?php if ($data['newItems']): ?>
                <div class="mt-1"><strong>بنود ستُنشأ:</strong> <?= e(implode('، ', $data['newItems'])) ?></div>
            <?php endif; ?>
            <?php if ($data['newTags']): ?>
                <div class="mt-1"><strong>تاقات ستُنشأ:</strong> <?= e(implode('، ', $data['newTags'])) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">معاينة الصفوف</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-mobile align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th><th>التاريخ</th><th>النوع</th><th>التصنيف</th>
                            <th>البند</th><th>التاق</th><th>المبلغ</th><th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['preview'] as $p): ?>
                            <tr class="<?= $p['ok'] ? '' : 'table-danger' ?>">
                                <td data-label="#"><?= (int) $p['row'] ?></td>
                                <td data-label="التاريخ"><?= e($p['date']) ?></td>
                                <td data-label="النوع"><?= e($p['type']) ?></td>
                                <td data-label="التصنيف"><?= e($p['category']) ?></td>
                                <td data-label="البند"><?= e($p['item']) ?></td>
                                <td data-label="التاق"><?= e($p['tag']) ?: '-' ?></td>
                                <td data-label="المبلغ"><?= e($p['amount']) ?></td>
                                <td data-label="الحالة">
                                    <?php if ($p['ok']): ?>
                                        <span class="badge text-bg-success">صحيح</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-danger" title="<?= e($p['error']) ?>"><?= e($p['error']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <?php if ($validCount > 0): ?>
            <form method="post" class="flex-grow-1"
                  data-confirm="سيتم استيراد <?= $validCount ?> عملية<?= $data['errors'] ? ' وتخطّي ' . (int) $data['errors'] . ' صفاً بها أخطاء' : '' ?>. متابعة؟">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-success btn-lg w-100">
                    <i class="bi bi-check-circle"></i>
                    تأكيد واستيراد <?= $validCount ?> عملية<?= $data['errors'] ? ' (وتخطّي الأخطاء)' : '' ?>
                </button>
            </form>
        <?php else: ?>
            <div class="alert alert-warning flex-grow-1 mb-0">
                لا توجد صفوف صحيحة للاستيراد. صحّح الأخطاء في الملف وأعد رفعه.
            </div>
        <?php endif; ?>
        <a href="<?= APP_URL ?>import.php" class="btn btn-outline-secondary btn-lg">رفع ملف آخر</a>
    </div>
<?php endif; ?>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>

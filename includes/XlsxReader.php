<?php
/**
 * رصيد - قارئ XLSX خفيف
 *
 * يقرأ ملفات Excel (XLSX) باستخدام ZipArchive و SimpleXML فقط،
 * دون أي مكتبات خارجية — مكمّل للكاتب في Xlsx.php.
 * يعيد صفوف الورقة الأولى كمصفوفات مفهرسة بالأعمدة.
 */
defined('RASEED') || exit;

class XlsxReader
{
    /**
     * قراءة أول ورقة عمل من ملف XLSX.
     * @return array<int, array<int, string>> صفوف، كل صف مصفوفة خلايا نصية
     */
    public static function read(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('امتداد ZipArchive غير متوفر على السيرفر.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('تعذّر فتح ملف Excel - تأكد أنه بصيغة XLSX صحيحة.');
        }

        // جدول النصوص المشتركة
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $sst = @simplexml_load_string($ssXml);
            if ($sst !== false) {
                foreach ($sst->si as $si) {
                    $shared[] = self::nodeText($si);
                }
            }
        }

        $sheetData = $zip->getFromName(self::firstSheetPath($zip));
        $zip->close();

        if ($sheetData === false) {
            throw new RuntimeException('لا توجد ورقة عمل في الملف.');
        }
        $sheet = @simplexml_load_string($sheetData);
        if ($sheet === false) {
            throw new RuntimeException('تعذّرت قراءة محتوى ورقة العمل.');
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells  = [];
            $maxCol = -1;
            foreach ($row->c as $c) {
                $col  = self::colIndex((string) $c['r']);
                $type = (string) $c['t'];
                if ($type === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = self::nodeText($c->is);
                } else {
                    $val = (string) $c->v;
                }
                $cells[$col] = trim($val);
                $maxCol = max($maxCol, $col);
            }
            // تسوية الصف إلى مصفوفة متسلسلة (الخلايا الفارغة تُملأ بفراغ)
            $normalized = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $normalized[$i] = $cells[$i] ?? '';
            }
            $rows[] = $normalized;
        }
        return $rows;
    }

    /** نص عنصر <si> أو <is> بما فيه أجزاء النص المنسّق <r> */
    private static function nodeText(SimpleXMLElement $node): string
    {
        $text = '';
        if (isset($node->t)) {
            $text .= (string) $node->t;
        }
        if (isset($node->r)) {
            foreach ($node->r as $r) {
                $text .= (string) $r->t;
            }
        }
        return $text;
    }

    /** تحويل مرجع خلية (مثل "AB12") إلى فهرس عمود صفري */
    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/[0-9]/', '', $ref);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    /** مسار أول ورقة عمل من خلال workbook.xml وعلاقاته */
    private static function firstSheetPath(ZipArchive $zip): string
    {
        $wb   = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $wbXml = @simplexml_load_string($wb);
            $relsXml = @simplexml_load_string($rels);
            if ($wbXml !== false && $relsXml !== false && isset($wbXml->sheets->sheet[0])) {
                $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
                $rid = (string) $wbXml->sheets->sheet[0]->attributes($rNs)->id;
                foreach ($relsXml->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = (string) $rel['Target'];
                        return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * تحويل قيمة تاريخ إلى صيغة Y-m-d.
     * يدعم: نص Y-m-d، رقم Excel التسلسلي، وصيغ يفهمها strtotime.
     * يعيد null إن تعذّر.
     */
    public static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value)) {
            $ts = strtotime($value);
            return $ts ? date('Y-m-d', $ts) : null;
        }
        // رقم Excel التسلسلي (أيام منذ 1899-12-30)
        if (is_numeric($value)) {
            $serial = (int) $value;
            if ($serial > 0 && $serial < 100000) {
                $base = new DateTime('1899-12-30');
                $base->modify('+' . $serial . ' days');
                return $base->format('Y-m-d');
            }
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * تحويل نص النوع إلى income / expense (متسامح مع مرادفات عربية وإنجليزية).
     * يعيد null إن لم يُعرف.
     */
    public static function normalizeType(string $value): ?string
    {
        $v = mb_strtolower(trim($value));
        $income  = ['إيراد', 'ايراد', 'إيرادات', 'ايرادات', 'دخل', 'وارد', 'income', 'in', 'credit'];
        $expense = ['مصروف', 'مصروفات', 'صرف', 'منصرف', 'expense', 'out', 'debit'];
        if (in_array($v, $income, true)) {
            return 'income';
        }
        if (in_array($v, $expense, true)) {
            return 'expense';
        }
        return null;
    }
}

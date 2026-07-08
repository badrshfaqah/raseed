<?php
/**
 * رصيد - مصدّر XLSX خفيف
 *
 * كاتب ملفات Excel (XLSX) أصلي باستخدام ZipArchive بدون أي مكتبات خارجية،
 * ليبقى النظام صغيراً وقابلاً للتركيب على أي استضافة بدون Composer.
 * يدعم النصوص العربية (RTL) والأرقام.
 */
defined('RASEED') || exit;

class Xlsx
{
    /**
     * توليد ملف XLSX وإرساله للمتصفح.
     *
     * @param string $filename اسم الملف (بدون امتداد)
     * @param array  $headers  رؤوس الأعمدة
     * @param array  $rows     مصفوفة صفوف، كل صف مصفوفة خلايا
     */
    public static function download(string $filename, array $headers, array $rows): never
    {
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            exit('امتداد ZipArchive غير متوفر على السيرفر. استخدم تصدير CSV بدلاً من Excel.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView rightToLeft="1"/></bookViews>'
            . '<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');

        // نمطان: 0 عادي، 1 عريض للرؤوس، 2 أرقام بفواصل عشرية
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="1" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" applyFont="1" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '</styleSheet>');

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>'
            . '<sheetData>';

        $sheet .= self::rowXml($headers, 1, true);
        $rowNum = 2;
        foreach ($rows as $row) {
            $sheet .= self::rowXml($row, $rowNum++, false);
        }
        $sheet .= '</sheetData></worksheet>';

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private static function rowXml(array $cells, int $rowNum, bool $bold): string
    {
        $xml = '<row r="' . $rowNum . '">';
        $col = 0;
        foreach ($cells as $value) {
            $ref = self::colLetter($col++) . $rowNum;
            if (is_int($value) || is_float($value)) {
                $style = $bold ? 1 : 2;
                $xml  .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
            } else {
                $style = $bold ? 1 : 0;
                $xml  .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                    . htmlspecialchars((string)$value, ENT_XML1, 'UTF-8')
                    . '</t></is></c>';
            }
        }
        return $xml . '</row>';
    }

    private static function colLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index  = intdiv($index, 26) - 1;
        }
        return $letter;
    }
}

<?php
/**
 * رصيد - المزامنة مع Google Sheets
 *
 * عميل خفيف بدون مكتبات خارجية: مصادقة Service Account عبر JWT (RS256)
 * ثم استدعاء Google Sheets API v4 عبر cURL.
 *
 * قاعدة أساسية: فشل المزامنة لا يوقف الحفظ في MySQL أبداً،
 * وكل محاولة تُسجَّل في جدول google_sheet_sync_logs.
 */
defined('RASEED') || exit;

class GoogleSheets
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE  = 'https://sheets.googleapis.com/v4/spreadsheets/';
    private const SCOPE     = 'https://www.googleapis.com/auth/spreadsheets';

    /** رؤوس الأعمدة في الشيت */
    public const HEADERS = ['ID', 'التاريخ', 'النوع', 'التصنيف', 'البند', 'المبلغ', 'الملاحظات', 'المستخدم', 'وقت التسجيل'];

    /** هل المزامنة مفعّلة (توجد بيانات ربط)؟ */
    public static function enabled(): bool
    {
        return setting('google_sheet_id') !== '' && setting('google_service_account') !== '';
    }

    /**
     * مزامنة عملية (إضافة / تعديل / حذف) مع تسجيل النتيجة في سجل المزامنة.
     * لا ترمي استثناءات أبداً.
     */
    public static function sync(int $transactionId, string $action): bool
    {
        if (!self::enabled()) {
            return true; // المزامنة غير مفعّلة، لا شيء نفعله
        }
        try {
            match ($action) {
                'add'    => self::pushRow($transactionId, false),
                'update' => self::pushRow($transactionId, true),
                'delete' => self::removeRow($transactionId),
                default  => throw new RuntimeException('إجراء غير معروف: ' . $action),
            };
            self::logSync($transactionId, $action, 'success', 'تمت المزامنة بنجاح');
            return true;
        } catch (Throwable $e) {
            self::logSync($transactionId, $action, 'failed', $e->getMessage());
            log_error('GoogleSheets sync #' . $transactionId . ' (' . $action . '): ' . $e->getMessage());
            return false;
        }
    }

    /** اختبار الاتصال وكتابة صف الرؤوس - تُستدعى من صفحة الإعدادات */
    public static function testConnection(): array
    {
        try {
            $token = self::accessToken();
            $sheet = self::sheetName();
            $first = self::request('GET', self::valuesUrl($sheet . '!A1:A1'), null, $token);
            if (empty($first['values'])) {
                self::request('PUT', self::valuesUrl($sheet . '!A1') . '?valueInputOption=RAW',
                    ['values' => [self::HEADERS]], $token);
            }
            return ['ok' => true, 'message' => 'تم الاتصال بنجاح وتجهيز ورقة العمل.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /* ---------------- التنفيذ الداخلي ---------------- */

    private static function txRow(int $id): ?array
    {
        $t = q('SELECT t.*, c.name AS category_name, i.name AS item_name, u.name AS user_name
                ' . tx_base_query() . ' WHERE t.id = ?', [$id])->fetch();
        if (!$t) {
            return null;
        }
        return [
            (string)$t['id'],
            $t['trans_date'],
            type_label($t['type']),
            $t['category_name'],
            $t['item_name'],
            (float)$t['amount'],
            (string)$t['notes'],
            $t['user_name'],
            $t['created_at'],
        ];
    }

    /** إضافة أو تحديث صف العملية في الشيت */
    private static function pushRow(int $id, bool $isUpdate): void
    {
        $row = self::txRow($id);
        if (!$row) {
            throw new RuntimeException('العملية غير موجودة في قاعدة البيانات');
        }
        $token = self::accessToken();
        $sheet = self::sheetName();

        $existingRow = $isUpdate ? self::findRowNumber($id, $token) : null;
        if ($existingRow !== null) {
            self::request('PUT',
                self::valuesUrl($sheet . '!A' . $existingRow) . '?valueInputOption=USER_ENTERED',
                ['values' => [$row]], $token);
        } else {
            self::request('POST',
                self::valuesUrl($sheet . '!A:I') . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
                ['values' => [$row]], $token);
        }
    }

    /** حذف صف العملية من الشيت */
    private static function removeRow(int $id): void
    {
        $token  = self::accessToken();
        $rowNum = self::findRowNumber($id, $token);
        if ($rowNum === null) {
            return; // غير موجود في الشيت أصلاً
        }
        $sheetId = self::gridSheetId($token);
        self::request('POST', self::API_BASE . rawurlencode(setting('google_sheet_id')) . ':batchUpdate', [
            'requests' => [[
                'deleteDimension' => [
                    'range' => [
                        'sheetId'    => $sheetId,
                        'dimension'  => 'ROWS',
                        'startIndex' => $rowNum - 1,
                        'endIndex'   => $rowNum,
                    ],
                ],
            ]],
        ], $token);
    }

    /** البحث عن رقم الصف الذي يحمل معرّف العملية في العمود A */
    private static function findRowNumber(int $id, string $token): ?int
    {
        $sheet = self::sheetName();
        $res   = self::request('GET', self::valuesUrl($sheet . '!A:A'), null, $token);
        foreach ($res['values'] ?? [] as $index => $cells) {
            if (isset($cells[0]) && (string)$cells[0] === (string)$id) {
                return $index + 1; // الصفوف تبدأ من 1
            }
        }
        return null;
    }

    /** جلب sheetId الرقمي لورقة العمل (مطلوب لحذف الصفوف) */
    private static function gridSheetId(string $token): int
    {
        $meta = self::request('GET',
            self::API_BASE . rawurlencode(setting('google_sheet_id')) . '?fields=sheets.properties',
            null, $token);
        $wanted = self::sheetName();
        foreach ($meta['sheets'] ?? [] as $s) {
            if (($s['properties']['title'] ?? '') === $wanted) {
                return (int)$s['properties']['sheetId'];
            }
        }
        throw new RuntimeException('ورقة العمل "' . $wanted . '" غير موجودة في الملف');
    }

    private static function sheetName(): string
    {
        return setting('google_sheet_name', 'Transactions') ?: 'Transactions';
    }

    private static function valuesUrl(string $range): string
    {
        return self::API_BASE . rawurlencode(setting('google_sheet_id')) . '/values/' . rawurlencode($range);
    }

    /* ---------------- المصادقة (Service Account JWT) ---------------- */

    private static function accessToken(): string
    {
        // استخدام التوكن المخزّن إذا كان صالحاً (مع هامش دقيقة)
        $cached  = setting('google_access_token');
        $expires = (int)setting('google_token_expires', '0');
        if ($cached !== '' && $expires > time() + 60) {
            return $cached;
        }

        $sa = json_decode(setting('google_service_account'), true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new RuntimeException('بيانات Service Account غير صالحة (JSON)');
        }

        $now    = time();
        $header = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signature = '';
        if (!openssl_sign($header . '.' . $claims, $signature, $sa['private_key'], 'sha256WithRSAEncryption')) {
            throw new RuntimeException('فشل توقيع JWT - تحقق من المفتاح الخاص');
        }
        $jwt = $header . '.' . $claims . '.' . self::b64($signature);

        $res = self::httpRequest('POST', self::TOKEN_URL, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]), ['Content-Type: application/x-www-form-urlencoded']);

        if (empty($res['access_token'])) {
            throw new RuntimeException('فشل الحصول على Access Token: ' . ($res['error_description'] ?? $res['error'] ?? 'استجابة غير متوقعة'));
        }

        set_setting('google_access_token', $res['access_token']);
        set_setting('google_token_expires', (string)($now + (int)($res['expires_in'] ?? 3600)));
        return $res['access_token'];
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /* ---------------- طبقة HTTP ---------------- */

    private static function request(string $method, string $url, ?array $body, string $token): array
    {
        return self::httpRequest($method, $url,
            $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
    }

    private static function httpRequest(string $method, string $url, ?string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('امتداد cURL غير متوفر على السيرفر');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('خطأ اتصال: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode($raw, true) ?? [];
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? $data['error_description'] ?? $data['error'] ?? ('HTTP ' . $code);
            throw new RuntimeException(is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE));
        }
        return $data;
    }

    /* ---------------- سجل المزامنة ---------------- */

    private static function logSync(int $transactionId, string $action, string $status, string $message): void
    {
        try {
            q('INSERT INTO google_sheet_sync_logs (transaction_id, action, status, message, created_at)
               VALUES (?, ?, ?, ?, NOW())',
              [$transactionId, $action, $status, mb_substr($message, 0, 1000)]);
        } catch (Throwable $e) {
            log_error('logSync: ' . $e->getMessage());
        }
    }
}

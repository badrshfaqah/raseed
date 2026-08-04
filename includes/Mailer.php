<?php
/**
 * رصيد - مُرسِل البريد المبسّط (يعتمد على دالة mail() المتوفرة على أغلب الاستضافات)
 *
 * يرسل رسالة HTML مع بديل نصّي (multipart/alternative) وترميز UTF-8 صحيح
 * للعناوين العربية. لا يعتمد على أي مكتبة خارجية أو Composer.
 */
defined('RASEED') || exit;

class Mailer
{
    /** عنوان المُرسِل (from) المضبوط، أو بريد افتراضي مبني على المضيف */
    public static function fromAddress(): string
    {
        $from = trim(setting('mail_from', ''));
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $from;
        }
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = $host !== '' ? $host : 'localhost';
        return 'no-reply@' . preg_replace('/^www\./', '', $host);
    }

    /** اسم المُرسِل الظاهر */
    public static function fromName(): string
    {
        return trim(setting('mail_from_name', '')) ?: setting('system_name', 'رصيد');
    }

    /** ترميز عنوان/اسم يحتوي أحرفاً غير ASCII وفق MIME (RFC 2047) */
    private static function encodeHeader(string $text): string
    {
        if (preg_match('/[^\x20-\x7E]/', $text)) {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }
        return $text;
    }

    /**
     * إرسال رسالة HTML (مع بديل نصّي اختياري).
     * يعيد true عند نجاح تسليم الرسالة إلى نظام البريد، false خلاف ذلك.
     */
    public static function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
            return false;
        }
        if ($textBody === '') {
            $textBody = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES, 'UTF-8'));
        }

        $fromAddr = self::fromAddress();
        $fromName = self::encodeHeader(self::fromName());
        // منع حقن الترويسات: لا نسمح بأسطر جديدة في العنوان
        $subject  = str_replace(["\r", "\n"], ' ', $subject);

        $boundary = 'b_' . bin2hex(random_bytes(12));

        $headers   = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'From: ' . $fromName . ' <' . $fromAddr . '>';
        $headers[] = 'Reply-To: ' . $fromAddr;
        $headers[] = 'X-Mailer: Raseed';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body  = '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($textBody)) . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        try {
            return @mail(
                $to,
                self::encodeHeader($subject),
                $body,
                implode("\r\n", $headers),
                '-f' . $fromAddr
            );
        } catch (Throwable $e) {
            log_error('Mailer: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * قالب بريد إعادة تعيين كلمة المرور.
     * @return array{subject:string, html:string, text:string}
     */
    public static function passwordResetMessage(string $name, string $link, int $minutes): array
    {
        $system  = e(setting('system_name', 'رصيد'));
        $safeName = e($name);
        $safeLink = e($link);

        $subject = 'إعادة تعيين كلمة المرور - ' . setting('system_name', 'رصيد');

        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;background:#f5f7fa;padding:24px">'
            . '<div style="max-width:520px;margin:auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e9f0">'
            . '<h2 style="color:#0f766e;margin:0 0 16px">' . $system . '</h2>'
            . '<p style="color:#334155;font-size:15px;line-height:1.9">مرحباً ' . $safeName . '،</p>'
            . '<p style="color:#334155;font-size:15px;line-height:1.9">'
            . 'وصلنا طلب لإعادة تعيين كلمة المرور الخاصة بحسابك. اضغط الزر التالي لتعيين كلمة مرور جديدة:</p>'
            . '<p style="text-align:center;margin:26px 0">'
            . '<a href="' . $safeLink . '" style="background:#0f766e;color:#fff;text-decoration:none;'
            . 'padding:12px 28px;border-radius:8px;font-size:15px;display:inline-block">إعادة تعيين كلمة المرور</a></p>'
            . '<p style="color:#64748b;font-size:13px;line-height:1.9">أو انسخ الرابط التالي والصقه في المتصفح:<br>'
            . '<span style="direction:ltr;display:inline-block;word-break:break-all;color:#0f766e">' . $safeLink . '</span></p>'
            . '<p style="color:#64748b;font-size:13px;line-height:1.9">الرابط صالح لمدة ' . $minutes . ' دقيقة ولمرة واحدة فقط.</p>'
            . '<hr style="border:none;border-top:1px solid #e5e9f0;margin:20px 0">'
            . '<p style="color:#94a3b8;font-size:12px;line-height:1.8">'
            . 'إذا لم تطلب إعادة التعيين، تجاهل هذه الرسالة ولن يطرأ أي تغيير على حسابك.</p>'
            . '</div></div>';

        $text = "مرحباً {$name}،\n\n"
            . "وصلنا طلب لإعادة تعيين كلمة المرور الخاصة بحسابك.\n"
            . "افتح الرابط التالي لتعيين كلمة مرور جديدة (صالح لمدة {$minutes} دقيقة ولمرة واحدة):\n\n"
            . "{$link}\n\n"
            . "إذا لم تطلب إعادة التعيين، تجاهل هذه الرسالة.";

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
}

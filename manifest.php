<?php
/**
 * رصيد - بيان تطبيق الويب (PWA)
 *
 * يُخرج البيان بمسارات نسبية تُحلّ مقابل موقع هذا الملف (جذر التطبيق)،
 * فيعمل تلقائياً حتى عند التشغيل من مجلد فرعي (domain.com/raseed) دون
 * الحاجة لمعرفة APP_URL. خفيف الوزن ولا يحمّل نواة النظام.
 */
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

echo json_encode([
    'name'             => 'رصيد - الإدارة المالية',
    'short_name'       => 'رصيد',
    'description'      => 'نظام رصيد لإدارة الإيرادات والمصروفات والرصيد المباشر.',
    'lang'             => 'ar',
    'dir'              => 'rtl',
    'start_url'        => 'dashboard.php',
    'scope'           => './',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#f1f5f9',
    'theme_color'      => '#0f766e',
    'icons'            => [
        [
            'src'     => 'assets/img/icon-192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => 'assets/img/icon-512.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

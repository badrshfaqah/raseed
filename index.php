<?php
/**
 * رصيد - نقطة الدخول الرئيسية
 */
require __DIR__ . '/includes/init.php';

redirect(current_user() ? APP_URL . 'dashboard.php' : APP_URL . 'login.php');

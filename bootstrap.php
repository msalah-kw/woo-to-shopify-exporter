<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

if (class_exists(\NSB\WooToShopify\Plugin::class)) {
    \NSB\WooToShopify\Plugin::init();
}

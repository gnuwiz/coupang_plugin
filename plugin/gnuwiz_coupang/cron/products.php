<?php
/**
 * === products.php ===
 * 경로: /plugin/coupang/cron/products.php
 */

// 플러그인 경로 설정
define('COUPANG_PLUGIN_PATH', dirname(dirname(__FILE__)));

// main_cron.php를 products 타입으로 실행
$_SERVER['argv'] = array('products.php', 'products');
$_SERVER['argc'] = 2;
$argv = $_SERVER['argv'];

include_once(COUPANG_PLUGIN_PATH . '/cron/main_cron.php');
?>
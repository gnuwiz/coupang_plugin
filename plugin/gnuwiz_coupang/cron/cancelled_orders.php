<?php
/**
 * === cancelled_orders.php ===
 * 경로: /plugin/coupang/cron/cancelled_orders.php
 */

// 플러그인 경로 설정
define('COUPANG_PLUGIN_PATH', dirname(dirname(__FILE__)));

// main_cron.php를 orders 타입으로 실행
$_SERVER['argv'] = array('cancelled_orders.php', 'cancelled_orders');
$_SERVER['argc'] = 2;
$argv = $_SERVER['argv'];

include_once(COUPANG_PLUGIN_PATH . '/cron/main_cron.php');
?>
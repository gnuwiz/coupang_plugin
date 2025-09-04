<?php
/**
 * === product_status.php ===
 * 경로: /plugin/coupang/cron/product_status.php
 */

// 플러그인 및 영카트 경로 설정
define('COUPANG_PLUGIN_PATH', dirname(dirname(__FILE__)));
define('YOUNGCART_ROOT', dirname(COUPANG_PLUGIN_PATH));

include_once(YOUNGCART_ROOT . '/_common.php');
include_once(COUPANG_PLUGIN_PATH . '/_common.php');

exit(CoupangAPI::runCron('product_status'));
?>

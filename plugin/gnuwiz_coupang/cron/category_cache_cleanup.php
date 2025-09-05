<?php
/**
 * === category_cache_cleanup.php ===
 * 카테고리 캐시 정리
 * 경로: /plugin/coupang/cron/category_cache_cleanup.php
 * 용도: 오래된 카테고리 추천 캐시 삭제
 * 실행 주기: 하루 1회 (새벽 3시)
 */

// 플러그인 경로 설정
define('COUPANG_PLUGIN_PATH', dirname(dirname(__FILE__)));

// main_cron.php를 category_cache_cleanup 타입으로 실행
$_SERVER['argv'] = array('category_cache_cleanup.php', 'category_cache_cleanup');
$_SERVER['argc'] = 2;
$argv = $_SERVER['argv'];

include_once(COUPANG_PLUGIN_PATH . '/cron/main_cron.php');
?>

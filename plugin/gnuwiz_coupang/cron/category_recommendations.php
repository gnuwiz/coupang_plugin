<?php
/**
 * === category_recommendations.php ===
 * 카테고리 추천 배치 처리
 * 경로: /plugin/coupang/cron/category_recommendations.php
 * 용도: 등록된 영카트 상품들의 카테고리를 자동 추천
 * 실행 주기: 하루 1회 (새벽 2시)
 */

// 플러그인 경로 설정
define('COUPANG_PLUGIN_PATH', dirname(dirname(__FILE__)));

// main_cron.php를 category_recommendations 타입으로 실행
$_SERVER['argv'] = array('category_recommendations.php', 'category_recommendations');
$_SERVER['argc'] = 2;
$argv = $_SERVER['argv'];

include_once(COUPANG_PLUGIN_PATH . '/cron/main_cron.php');
?>
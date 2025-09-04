<?php
// Youngcart 공용 초기화
$yc_root = dirname(__DIR__, 2);
include_once($yc_root . '/common.php');

// 플러그인 경로 상수 정의
if (!defined('COUPANG_PLUGIN_PATH')) {
    define('COUPANG_PLUGIN_PATH', G5_PLUGIN_PATH . '/gnuwiz_coupang');
}

// 쿠팡 설정 및 API 클래스 로드
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_config.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_api_class.php');

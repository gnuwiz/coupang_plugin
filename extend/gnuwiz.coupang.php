<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가
/*
|--------------------------------------------------------------------------
| 쿠팡 연동 플러그인 (Coupang Integration Plugin)
|--------------------------------------------------------------------------
|
| Author: 그누위즈 (gnuwiz@example.com)
|
| Copyright: Copyright (C) 2025 by 그누위즈
|
| 쿠팡 쇼핑몰 연동 플러그인
| - 통합 관리 대시보드
|
*/

// 쿠팡 플러그인 기본 설정
define('COUPANG_PLUGIN_ACTIVE', true);
define('COUPANG_PLUGIN_VERSION', '2.0.0');
define('COUPANG_PLUGIN_PATH', G5_PLUGIN_PATH . '/gnuwiz_coupang');

// 플러그인 공통 초기화 로드
if (file_exists(COUPANG_PLUGIN_PATH . '/_common.php')) {
    include_once(COUPANG_PLUGIN_PATH . '/_common.php');
} else {
    define('COUPANG_PLUGIN_ACTIVE', false);
    return;
}

// 쿠팡 API 인스턴스 전역 생성
if (COUPANG_PLUGIN_ACTIVE) {
    global $coupang_api;
    try {
        $coupang_api = new CoupangAPI(array(
            'access_key' => COUPANG_ACCESS_KEY,
            'secret_key' => COUPANG_SECRET_KEY,
            'vendor_id'  => COUPANG_VENDOR_ID
        ));
    } catch (Exception $e) {
        CoupangAPI::log('ERROR', 'API 인스턴스 생성 실패', array('error' => $e->getMessage(), 'log_file' => 'general.log'));
        $coupang_api = null;
    }
}


// === 관리자 메뉴 추가 ===
if (defined('G5_IS_ADMIN') && G5_IS_ADMIN) {
    add_event('admin_menu', function() {
        if (!COUPANG_PLUGIN_ACTIVE) return;

        return array(
            'menu_id' => 'coupang',
            'menu_name' => '쿠팡 연동',
            'menu_order' => 200,
            'sub_menus' => array(
                array(
                    'url' => G5_ADMIN_URL . '/plugin.php?plugin=gnuwiz_coupang&page=dashboard',
                    'name' => '대시보드',
                    'icon' => 'fa-tachometer-alt'
                ),
                array(
                    'url' => G5_ADMIN_URL . '/plugin.php?plugin=gnuwiz_coupang&page=manual_sync',
                    'name' => '수동 동기화',
                    'icon' => 'fa-sync'
                ),
                array(
                    'url' => G5_ADMIN_URL . '/plugin.php?plugin=gnuwiz_coupang&page=settings',
                    'name' => '설정',
                    'icon' => 'fa-cog'
                ),
                array(
                    'url' => G5_ADMIN_URL . '/plugin.php?plugin=gnuwiz_coupang&page=api_test',
                    'name' => 'API 테스트',
                    'icon' => 'fa-flask'
                )
            )
        );
    });
}
?>

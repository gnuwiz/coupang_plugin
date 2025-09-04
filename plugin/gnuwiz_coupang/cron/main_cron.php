<?php
/**
 * 쿠팡 연동 통합 크론 스크립트
 * 경로: /plugin/coupang/cron/main_cron.php
 * 실행: php main_cron.php [sync_type]
 */

// 플러그인 및 영카트 경로 설정
define('COUPANG_PLUGIN_PATH', dirname(__DIR__));
define('YOUNGCART_ROOT', dirname(COUPANG_PLUGIN_PATH));

// Youngcart 및 플러그인 공통 초기화 로드
include_once(YOUNGCART_ROOT . '/_common.php');
include_once(COUPANG_PLUGIN_PATH . '/_common.php');

// CLI 인자 처리
$sync_type = isset($argv[1]) ? $argv[1] : '';
$valid_types = array('orders', 'cancelled_orders', 'order_status', 'products', 'product_status', 'stock');

if (empty($sync_type) || !in_array($sync_type, $valid_types)) {
    echo "사용법: php main_cron.php [sync_type]\n";
    echo "동기화 타입:\n";
    echo "  orders          - 쿠팡 → 영카트 주문 동기화 (매분 실행)\n";
    echo "  cancelled_orders - 쿠팡 취소 주문 → 영카트 반영 (매분 실행)\n";
    echo "  order_status    - 영카트 주문 상태 → 쿠팡 반영 (매분 실행)\n";
    echo "  products        - 영카트 상품 → 쿠팡 등록/업데이트 (하루 2번)\n";
    echo "  product_status  - 영카트 상품 상태 → 쿠팡 반영 (하루 2번)\n";
    echo "  stock          - 영카트 재고/가격 → 쿠팡 동기화 (하루 2번)\n";
    exit(1);
}

exit(CoupangAPI::runCron($sync_type));
?>

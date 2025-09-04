<?php
/**
 * === orders.php ===
 * 쿠팡 → 영카트 신규 주문 동기화
 * 경로: /plugin/coupang/cron/orders.php
 * 용도: 쿠팡에서 발생한 새로운 주문을 영카트 DB로 가져오기
 * 처리내용:
 *   - 지난 1시간 동안의 쿠팡 신규 주문 조회
 *   - 영카트 주문/카트 테이블에 저장
 *   - 재고 자동 차감
 *   - 중복 주문 방지
 */

// 플러그인 경로 설정
define('COUPANG_PLUGIN_PATH', dirname(dirname(__FILE__)));

// main_cron.php를 orders 타입으로 실행
$_SERVER['argv'] = array('orders.php', 'orders');
$_SERVER['argc'] = 2;
$argv = $_SERVER['argv'];

include_once(COUPANG_PLUGIN_PATH . '/cron/main_cron.php');
?>
<?php
/**
 * === stock.php ===
 * 영카트 → 쿠팡 재고/가격 동기화 크론 스크립트
 * 경로: /plugin/gnuwiz_coupang/cron/stock.php
 * 용도: 영카트 상품의 재고 수량과 가격을 쿠팡에 동기화
 * 실행주기: 하루 2번 실행 권장 (30 10,22 * * *)
 */

// CLI 환경에서만 실행
if (php_sapi_name() !== 'cli') {
    die('CLI 환경에서만 실행 가능합니다.');
}

// 쿠팡 플러그인 초기화
$plugin_path = dirname(__FILE__) . '/..';
if (!file_exists($plugin_path . '/_common.php')) {
    die('쿠팡 플러그인이 설치되지 않았습니다.');
}

include_once($plugin_path . '/_common.php');

// 실행 시작 로그
$cron_start_time = microtime(true);
coupang_log('INFO', '재고/가격 동기화 크론 시작');

try {
    // API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    // API 설정 검증
    $config_validation = validate_coupang_config();
    if (!$config_validation['valid']) {
        throw new Exception('쿠팡 API 설정 오류: ' . implode(', ', $config_validation['errors']));
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 영카트 → 쿠팡 재고/가격 동기화 시작\n";
    
    // 재고/가격 동기화 실행
    $sync_result = $coupang_api->syncStockToCoupang();
    
    if ($sync_result['success']) {
        $message = "재고/가격 동기화 완료 - 처리: {$sync_result['processed']}건, " .
                  "재고 업데이트: {$sync_result['stock_updated']}건, " . 
                  "가격 업데이트: {$sync_result['price_updated']}건, " .
                  "오류: " . count($sync_result['errors']) . "건";
        
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        
        // 재고 부족 상품들 경고
        if (isset($sync_result['low_stock_items']) && !empty($sync_result['low_stock_items'])) {
            echo "[" . date('Y-m-d H:i:s') . "] ⚠️ 재고 부족 상품들 (10개 이하):\n";
            foreach ($sync_result['low_stock_items'] as $item) {
                echo "  - 상품 ID: {$item['item_id']}, 상품명: {$item['item_name']}, 재고: {$item['stock_qty']}개\n";
            }
        }
        
        // 품절 상품들 확인
        if (isset($sync_result['out_of_stock_items']) && !empty($sync_result['out_of_stock_items'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 🚫 품절 상품들:\n";
            foreach ($sync_result['out_of_stock_items'] as $item) {
                echo "  - 상품 ID: {$item['item_id']}, 상품명: {$item['item_name']}\n";
            }
        }
        
        // 가격 변경된 상품들 출력
        if (isset($sync_result['price_changes']) && !empty($sync_result['price_changes'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 💰 가격 변경된 상품들:\n";
            foreach ($sync_result['price_changes'] as $change) {
                echo "  - 상품 ID: {$change['item_id']}, 상품명: {$change['item_name']}, ";
                echo "가격: " . number_format($change['old_price']) . "원 → " . number_format($change['new_price']) . "원\n";
            }
        }
        
        // 오류가 있으면 상세 출력
        if (!empty($sync_result['errors'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 오류 상세:\n";
            foreach ($sync_result['errors'] as $error) {
                echo "  - 상품 ID: {$error['item_id']}, 오류: {$error['error']}\n";
            }
        }
        
        // 전체 재고 통계 출력
        $sql = "SELECT 
                    COUNT(*) as total_items,
                    SUM(CASE WHEN it_stock_qty = 0 THEN 1 ELSE 0 END) as out_of_stock,
                    SUM(CASE WHEN it_stock_qty > 0 AND it_stock_qty <= 10 THEN 1 ELSE 0 END) as low_stock,
                    SUM(it_stock_qty) as total_stock
                FROM " . G5_TABLE_PREFIX . "g5_shop_item si
                INNER JOIN " . G5_TABLE_PREFIX . "coupang_item_map cim ON si.it_id = cim.youngcart_item_id
                WHERE cim.coupang_product_id IS NOT NULL";
        $stats = sql_fetch($sql);
        
        echo "[" . date('Y-m-d H:i:s') . "] 📊 전체 재고 현황: ";
        echo "총 상품 {$stats['total_items']}개, ";
        echo "품절 {$stats['out_of_stock']}개, ";
        echo "재고부족 {$stats['low_stock']}개, ";
        echo "총 재고량 " . number_format($stats['total_stock']) . "개\n";
        
        // 크론 실행 로그 기록
        monitor_cron_execution('stock', 'SUCCESS', $message, $sync_result['execution_time']);
        
        // 성공 통계 업데이트
        update_sync_statistics('stock', $sync_result['processed'], true);
        
    } else {
        throw new Exception($sync_result['error']);
    }
    
} catch (Exception $e) {
    $error_message = '재고/가격 동기화 실패: ' . $e->getMessage();
    $execution_time = microtime(true) - $cron_start_time;
    
    echo "[" . date('Y-m-d H:i:s') . "] {$error_message}\n";
    
    // 에러 로그 기록
    coupang_log('ERROR', $error_message, array(
        'execution_time' => round($execution_time, 2) . 's',
        'trace' => $e->getTraceAsString()
    ));
    
    // 크론 실행 로그 기록
    monitor_cron_execution('stock', 'FAIL', $error_message, $execution_time);
    
    // 실패 통계 업데이트
    update_sync_statistics('stock', 0, false);
    
    // CLI에서는 종료 코드 반환
    exit(1);
}

// 전체 실행 시간 계산
$total_execution_time = microtime(true) - $cron_start_time;
echo "[" . date('Y-m-d H:i:s') . "] 재고/가격 동기화 완료 - 총 실행시간: " . round($total_execution_time, 2) . "초\n";

coupang_log('INFO', '재고/가격 동기화 크론 완료', array(
    'total_execution_time' => round($total_execution_time, 2) . 's'
));

/**
 * 동기화 통계 업데이트
 */
function update_sync_statistics($sync_type, $count, $success) {
    global $g5;
    
    $date = date('Y-m-d');
    $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_sync_stats 
            (sync_type, sync_date, success_count, fail_count, last_execution_time) 
            VALUES ('{$sync_type}', '{$date}', " . ($success ? $count : 0) . ", " . ($success ? 0 : 1) . ", NOW())
            ON DUPLICATE KEY UPDATE 
            success_count = success_count + " . ($success ? $count : 0) . ",
            fail_count = fail_count + " . ($success ? 0 : 1) . ",
            last_execution_time = NOW()";
    
    sql_query($sql);
}

exit(0);
?>
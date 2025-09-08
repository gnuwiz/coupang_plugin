<?php
/**
 * === products.php ===
 * 영카트 → 쿠팡 상품 동기화 크론 스크립트
 * 경로: /plugin/gnuwiz_coupang/cron/products.php
 * 용도: 영카트 상품을 쿠팡에 신규 등록 또는 정보 업데이트
 * 실행주기: 하루 2번 실행 권장 (0 9,21 * * *)
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
coupang_log('INFO', '상품 동기화 크론 시작');

try {
    // API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    // API 설정 검증
    $config_validation = validate_coupang_config();
    if (!$config_validation['valid']) {
        throw new Exception('쿠팡 API 설정 오류: ' . implode(', ', $config_validation['errors']));
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 영카트 → 쿠팡 상품 동기화 시작\n";
    
    // 상품 동기화 실행
    $sync_result = $coupang_api->syncProductsToCoupang();
    
    if ($sync_result['success']) {
        $message = "상품 동기화 완료 - 처리: {$sync_result['processed']}건, " .
                  "신규: {$sync_result['new_products']}건, " . 
                  "수정: {$sync_result['updated_products']}건, " .
                  "오류: " . count($sync_result['errors']) . "건";
        
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        
        // 신규 등록된 상품들 출력
        if ($sync_result['new_products'] > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 신규 등록된 상품들:\n";
            $sql = "SELECT it_id, it_name, coupang_product_id 
                    FROM " . G5_TABLE_PREFIX . "g5_shop_item si
                    INNER JOIN " . G5_TABLE_PREFIX . "coupang_item_map cim ON si.it_id = cim.youngcart_item_id
                    WHERE cim.created_date >= NOW() - INTERVAL 1 HOUR
                    ORDER BY cim.created_date DESC 
                    LIMIT 10";
            $result = sql_query($sql);
            while ($row = sql_fetch_array($result)) {
                echo "  - 영카트 ID: {$row['it_id']}, 상품명: {$row['it_name']}, 쿠팡 ID: {$row['coupang_product_id']}\n";
            }
        }
        
        // 오류가 있으면 상세 출력
        if (!empty($sync_result['errors'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 오류 상세:\n";
            foreach ($sync_result['errors'] as $error) {
                echo "  - 상품 ID: {$error['item_id']}, 오류: {$error['error']}\n";
            }
        }
        
        // 카테고리 추천이 필요한 상품들 확인
        $sql = "SELECT COUNT(*) as cnt FROM " . G5_TABLE_PREFIX . "g5_shop_item 
                WHERE it_id NOT IN (
                    SELECT youngcart_item_id FROM " . G5_TABLE_PREFIX . "coupang_category_cache 
                    WHERE created_date >= NOW() - INTERVAL 7 DAY
                )";
        $result = sql_fetch($sql);
        if ($result['cnt'] > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 카테고리 추천이 필요한 상품: {$result['cnt']}개\n";
            echo "[" . date('Y-m-d H:i:s') . "] 카테고리 추천 크론을 실행하세요: php category_recommendations.php\n";
        }
        
        // 크론 실행 로그 기록
        monitor_cron_execution('products', 'SUCCESS', $message, $sync_result['execution_time']);
        
        // 성공 통계 업데이트
        update_sync_statistics('products', $sync_result['processed'], true);
        
    } else {
        throw new Exception($sync_result['error']);
    }
    
} catch (Exception $e) {
    $error_message = '상품 동기화 실패: ' . $e->getMessage();
    $execution_time = microtime(true) - $cron_start_time;
    
    echo "[" . date('Y-m-d H:i:s') . "] {$error_message}\n";
    
    // 에러 로그 기록
    coupang_log('ERROR', $error_message, array(
        'execution_time' => round($execution_time, 2) . 's',
        'trace' => $e->getTraceAsString()
    ));
    
    // 크론 실행 로그 기록
    monitor_cron_execution('products', 'FAIL', $error_message, $execution_time);
    
    // 실패 통계 업데이트
    update_sync_statistics('products', 0, false);
    
    // CLI에서는 종료 코드 반환
    exit(1);
}

// 전체 실행 시간 계산
$total_execution_time = microtime(true) - $cron_start_time;
echo "[" . date('Y-m-d H:i:s') . "] 상품 동기화 완료 - 총 실행시간: " . round($total_execution_time, 2) . "초\n";

coupang_log('INFO', '상품 동기화 크론 완료', array(
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
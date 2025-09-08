<?php
/**
 * === category_cache_cleanup.php ===
 * 쿠팡 카테고리 캐시 정리 크론 스크립트
 * 경로: /plugin/gnuwiz_coupang/cron/category_cache_cleanup.php
 * 용도: 오래된 카테고리 추천 캐시 데이터 삭제 및 정리
 * 실행주기: 하루 1회 실행 권장 (0 3 * * *)
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
coupang_log('INFO', '카테고리 캐시 정리 크론 시작');

try {
    // API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    echo "[" . date('Y-m-d H:i:s') . "] 🧹 쿠팡 카테고리 캐시 정리 시작\n";
    
    // 캐시 정리 실행 (7일 이상된 캐시 삭제)
    $cleanup_days = 7;
    $deleted_count = $coupang_api->cleanupCategoryCache($cleanup_days);
    
    $message = "카테고리 캐시 정리 완료 - 삭제된 캐시: {$deleted_count}개 ({$cleanup_days}일 이상된 데이터)";
    
    echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
    
    // 현재 캐시 상태 통계 출력
    $sql = "SELECT 
                COUNT(*) as total_cache,
                COUNT(CASE WHEN created_date >= CURDATE() THEN 1 END) as today_cache,
                COUNT(CASE WHEN created_date >= CURDATE() - INTERVAL 7 DAY THEN 1 END) as week_cache,
                MIN(created_date) as oldest_cache,
                MAX(created_date) as newest_cache
            FROM " . G5_TABLE_PREFIX . "coupang_category_cache";
    $stats = sql_fetch($sql);
    
    echo "[" . date('Y-m-d H:i:s') . "] 📊 캐시 현황:\n";
    echo "  - 전체 캐시 항목: " . number_format($stats['total_cache']) . "개\n";
    echo "  - 오늘 생성된 캐시: " . number_format($stats['today_cache']) . "개\n";
    echo "  - 최근 7일 캐시: " . number_format($stats['week_cache']) . "개\n";
    echo "  - 가장 오래된 캐시: {$stats['oldest_cache']}\n";
    echo "  - 가장 최신 캐시: {$stats['newest_cache']}\n";
    
    // 카테고리별 캐시 분포 출력
    $sql = "SELECT 
                coupang_category_name,
                COUNT(*) as cache_count,
                AVG(confidence) as avg_confidence
            FROM " . G5_TABLE_PREFIX . "coupang_category_cache 
            WHERE created_date >= CURDATE() - INTERVAL 7 DAY
            GROUP BY coupang_category_id, coupang_category_name 
            ORDER BY cache_count DESC 
            LIMIT 10";
    $category_stats = sql_query($sql);
    
    if (sql_num_rows($category_stats) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] 🏷️ 최근 7일 카테고리별 캐시 분포 (상위 10개):\n";
        while ($row = sql_fetch_array($category_stats)) {
            $avg_conf = number_format($row['avg_confidence'] * 100, 1);
            echo "  - {$row['coupang_category_name']}: {$row['cache_count']}개 (평균 신뢰도: {$avg_conf}%)\n";
        }
    }
    
    // 신뢰도 분포 통계
    $sql = "SELECT 
                CASE 
                    WHEN confidence >= 0.9 THEN '매우높음(90%+)'
                    WHEN confidence >= 0.7 THEN '높음(70-89%)'
                    WHEN confidence >= 0.5 THEN '보통(50-69%)'
                    ELSE '낮음(50%미만)'
                END as confidence_level,
                COUNT(*) as count
            FROM " . G5_TABLE_PREFIX . "coupang_category_cache 
            WHERE created_date >= CURDATE() - INTERVAL 7 DAY
            GROUP BY 
                CASE 
                    WHEN confidence >= 0.9 THEN '매우높음(90%+)'
                    WHEN confidence >= 0.7 THEN '높음(70-89%)'
                    WHEN confidence >= 0.5 THEN '보통(50-69%)'
                    ELSE '낮음(50%미만)'
                END
            ORDER BY count DESC";
    $confidence_stats = sql_query($sql);
    
    if (sql_num_rows($confidence_stats) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] 📈 신뢰도 분포 (최근 7일):\n";
        while ($row = sql_fetch_array($confidence_stats)) {
            echo "  - {$row['confidence_level']}: {$row['count']}개\n";
        }
    }
    
    // 삭제된 상품에 대한 캐시 정리
    $sql = "DELETE ccc FROM " . G5_TABLE_PREFIX . "coupang_category_cache ccc
            LEFT JOIN " . G5_TABLE_PREFIX . "g5_shop_item si ON ccc.youngcart_item_id = si.it_id
            WHERE si.it_id IS NULL";
    $orphan_result = sql_query($sql);
    $orphan_deleted = sql_affected_rows();
    
    if ($orphan_deleted > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] 🗑️ 삭제된 상품의 고아 캐시 정리: {$orphan_deleted}개\n";
    }
    
    // 중복 캐시 정리 (같은 상품에 대한 최신 캐시만 유지)
    $sql = "DELETE ccc1 FROM " . G5_TABLE_PREFIX . "coupang_category_cache ccc1
            INNER JOIN " . G5_TABLE_PREFIX . "coupang_category_cache ccc2 
            WHERE ccc1.youngcart_item_id = ccc2.youngcart_item_id
            AND ccc1.created_date < ccc2.created_date";
    $duplicate_result = sql_query($sql);
    $duplicate_deleted = sql_affected_rows();
    
    if ($duplicate_deleted > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] 🔄 중복 캐시 정리: {$duplicate_deleted}개\n";
    }
    
    // 최종 결과
    $total_cleaned = $deleted_count + $orphan_deleted + $duplicate_deleted;
    $final_message = "전체 캐시 정리 완료 - 총 {$total_cleaned}개 항목 정리됨 " .
                    "(만료: {$deleted_count}, 고아: {$orphan_deleted}, 중복: {$duplicate_deleted})";
    
    echo "[" . date('Y-m-d H:i:s') . "] ✅ {$final_message}\n";
    
    // 크론 실행 로그 기록
    monitor_cron_execution('category_cache_cleanup', 'SUCCESS', $final_message, microtime(true) - $cron_start_time);
    
    // 성공 통계 업데이트
    update_sync_statistics('category_cache_cleanup', $total_cleaned, true);
    
} catch (Exception $e) {
    $error_message = '카테고리 캐시 정리 실패: ' . $e->getMessage();
    $execution_time = microtime(true) - $cron_start_time;
    
    echo "[" . date('Y-m-d H:i:s') . "] {$error_message}\n";
    
    // 에러 로그 기록
    coupang_log('ERROR', $error_message, array(
        'execution_time' => round($execution_time, 2) . 's',
        'trace' => $e->getTraceAsString()
    ));
    
    // 크론 실행 로그 기록
    monitor_cron_execution('category_cache_cleanup', 'FAIL', $error_message, $execution_time);
    
    // 실패 통계 업데이트
    update_sync_statistics('category_cache_cleanup', 0, false);
    
    // CLI에서는 종료 코드 반환
    exit(1);
}

// 전체 실행 시간 계산
$total_execution_time = microtime(true) - $cron_start_time;
echo "[" . date('Y-m-d H:i:s') . "] 카테고리 캐시 정리 완료 - 총 실행시간: " . round($total_execution_time, 2) . "초\n";

coupang_log('INFO', '카테고리 캐시 정리 크론 완료', array(
    'total_execution_time' => round($total_execution_time, 2) . 's',
    'total_cleaned' => $total_cleaned ?? 0
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
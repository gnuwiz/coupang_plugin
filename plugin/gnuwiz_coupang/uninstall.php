<?php
/**
 * 쿠팡 연동 플러그인 제거 스크립트
 * 경로: /plugin/coupang/uninstall.php
 * 실행: CLI 또는 웹브라우저에서 실행
 */

include_once('./_common.php');

// 웹 접근시 관리자 권한 체크
if (isset($_SERVER['REQUEST_METHOD'])) {
    if (!$is_admin) {
        die('관리자만 접근할 수 있습니다.');
    }
    echo "<!DOCTYPE html>
<html>
<head>
    <title>쿠팡 연동 플러그인 제거</title>
    <meta charset='utf-8'>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        .confirm-box { background: #f8f9fa; padding: 20px; border: 2px solid #dc3545; border-radius: 5px; margin: 20px 0; }
        .btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 3px; cursor: pointer; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
    </style>
</head>
<body>";
}

echo "<h1>🗑️ 쿠팡 연동 플러그인 제거</h1>\n";

// 확인 단계
$confirm = isset($_POST['confirm']) ? $_POST['confirm'] : '';

if (!$confirm) {
    ?>
    <div class="confirm-box">
        <h2>⚠️ 경고</h2>
        <p>쿠팡 연동 플러그인을 제거하면 다음과 같은 데이터가 삭제됩니다:</p>
        <ul>
            <li>모든 쿠팡 관련 테이블 (매핑 정보, 로그 등)</li>
            <li>주문 테이블의 쿠팡 구분 필드들</li>
            <li>모든 로그 파일들</li>
            <li>플러그인 파일들</li>
        </ul>
        <p><strong>주의:</strong> 이 작업은 되돌릴 수 없습니다!</p>
        
        <form method="post">
            <p>정말로 제거하시겠습니까?</p>
            <input type="hidden" name="confirm" value="yes">
            <button type="submit" class="btn btn-danger">예, 제거합니다</button>
            <a href="admin/manual_sync.php" class="btn btn-secondary">취소</a>
        </form>
    </div>
    <?php
} else {
    try {
        global $g5;
        
        echo "<p>제거를 시작합니다...</p>\n";
        
        // === 1단계: 데이터베이스 백업 생성 ===
        echo "<h2>📦 데이터 백업</h2>\n";
        
        $backup_dir = COUPANG_PLUGIN_PATH . '/backup';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $backup_file = $backup_dir . '/uninstall_backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // 쿠팡 관련 테이블 백업
        $tables_to_backup = array(
            G5_TABLE_PREFIX . 'coupang_category_map',
            G5_TABLE_PREFIX . 'coupang_item_map',
            G5_TABLE_PREFIX . 'coupang_order_log',
            G5_TABLE_PREFIX . 'coupang_cron_log'
        );
        
        $backup_content = "-- 쿠팡 연동 플러그인 제거 전 백업\n";
        $backup_content .= "-- 생성 일시: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($tables_to_backup as $table) {
            $result = sql_query("SHOW TABLES LIKE '{$table}'", false);
            if ($result && sql_num_rows($result) > 0) {
                // 테이블 구조 백업
                $create_result = sql_query("SHOW CREATE TABLE `{$table}`");
                if ($create_result) {
                    $create_row = sql_fetch_array($create_result);
                    $backup_content .= "DROP TABLE IF EXISTS `{$table}`;\n";
                    $backup_content .= $create_row[1] . ";\n\n";
                }
                
                // 데이터 백업
                $data_result = sql_query("SELECT * FROM `{$table}`");
                if ($data_result && sql_num_rows($data_result) > 0) {
                    while ($row = sql_fetch_array($data_result)) {
                        $columns = array_keys($row);
                        $columns = array_filter($columns, 'is_string'); // 숫자 키 제거
                        
                        $values = array();
                        foreach ($columns as $col) {
                            $values[] = "'" . addslashes($row[$col]) . "'";
                        }
                        
                        $backup_content .= "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $backup_content .= "\n";
                }
            }
        }
        
        // 쿠팡 주문 데이터 백업
        $coupang_orders_result = sql_query("SELECT * FROM {$g5['g5_shop_order_table']} WHERE od_coupang_yn = 'Y'");
        if ($coupang_orders_result && sql_num_rows($coupang_orders_result) > 0) {
            $backup_content .= "-- 쿠팡 주문 데이터 백업\n";
            while ($order_row = sql_fetch_array($coupang_orders_result)) {
                $backup_content .= "-- 주문 ID: {$order_row['od_id']}, 주문자: {$order_row['od_name']}, 주문일: {$order_row['od_time']}\n";
            }
            $backup_content .= "\n";
        }
        
        if (file_put_contents($backup_file, $backup_content)) {
            echo "<span class='success'>✅ 백업 파일 생성: {$backup_file}</span><br>\n";
        } else {
            echo "<span class='warning'>⚠️ 백업 파일 생성 실패</span><br>\n";
        }
        
        // === 2단계: 쿠팡 관련 테이블 삭제 ===
        echo "<h2>🗄️ 테이블 삭제</h2>\n";
        
        foreach ($tables_to_backup as $table) {
            $result = sql_query("DROP TABLE IF EXISTS `{$table}`", false);
            if ($result) {
                echo "<span class='success'>✅ 테이블 삭제: {$table}</span><br>\n";
            } else {
                echo "<span class='warning'>⚠️ 테이블 삭제 실패 또는 존재하지 않음: {$table}</span><br>\n";
            }
        }
        
        // === 3단계: 주문 테이블에서 쿠팡 필드 제거 ===
        echo "<h2>📋 주문 테이블 정리</h2>\n";
        
        $fields_to_drop = array('od_coupang_yn', 'od_coupang_order_id', 'od_coupang_vendor_order_id');
        
        foreach ($fields_to_drop as $field) {
            $sql = "ALTER TABLE `{$g5['g5_shop_order_table']}` DROP COLUMN `{$field}`";
            $result = sql_query($sql, false);
            if ($result) {
                echo "<span class='success'>✅ 필드 삭제: {$field}</span><br>\n";
            } else {
                echo "<span class='warning'>⚠️ 필드 삭제 실패 또는 존재하지 않음: {$field}</span><br>\n";
            }
        }
        
        // 인덱스 제거
        $indexes_to_drop = array('idx_coupang_yn', 'idx_coupang_order_id');
        
        foreach ($indexes_to_drop as $index) {
            $sql = "ALTER TABLE `{$g5['g5_shop_order_table']}` DROP INDEX `{$index}`";
            $result = sql_query($sql, false);
            if ($result) {
                echo "<span class='success'>✅ 인덱스 삭제: {$index}</span><br>\n";
            } else {
                echo "<span class='warning'>⚠️ 인덱스 삭제 실패 또는 존재하지 않음: {$index}</span><br>\n";
            }
        }
        
        // === 4단계: 로그 파일 정리 ===
        echo "<h2>📝 로그 파일 정리</h2>\n";
        
        $log_dir = COUPANG_PLUGIN_PATH . '/logs';
        if (is_dir($log_dir)) {
            $log_files = glob($log_dir . '/*.log');
            foreach ($log_files as $log_file) {
                if (unlink($log_file)) {
                    echo "<span class='success'>✅ 로그 파일 삭제: " . basename($log_file) . "</span><br>\n";
                } else {
                    echo "<span class='warning'>⚠️ 로그 파일 삭제 실패: " . basename($log_file) . "</span><br>\n";
                }
            }
        }
        
        // === 5단계: 제거 완료 메시지 ===
        echo "<h2>🎉 제거 완료</h2>\n";
        echo "<div class='confirm-box' style='border-color: #28a745;'>\n";
        echo "<h3>제거가 완료되었습니다!</h3>\n";
        echo "<p><strong>백업 파일:</strong> {$backup_file}</p>\n";
        echo "<p><strong>남은 작업:</strong></p>\n";
        echo "<ul>\n";
        echo "<li>크론탭에서 쿠팡 관련 작업 제거</li>\n";
        echo "<li>필요시 플러그인 디렉터리 수동 삭제: " . COUPANG_PLUGIN_PATH . "</li>\n";
        echo "</ul>\n";
        
        // 크론탭 제거 가이드
        echo "<h3>크론탭 제거 명령어</h3>\n";
        echo "<pre style='background:#f8f9fa;padding:10px;border-radius:3px;'>\n";
        echo "crontab -e\n\n";
        echo "# 다음 라인들을 찾아서 삭제하세요:\n";
        echo "*/1 * * * * /usr/bin/php " . COUPANG_PLUGIN_PATH . "/cron/orders.php\n";
        echo "*/1 * * * * /usr/bin/php " . COUPANG_PLUGIN_PATH . "/cron/cancelled_orders.php\n";
        echo "*/1 * * * * /usr/bin/php " . COUPANG_PLUGIN_PATH . "/cron/order_status.php\n";
        echo "0 9,21 * * * /usr/bin/php " . COUPANG_PLUGIN_PATH . "/cron/products.php\n";
        echo "15 9,21 * * * /usr/bin/php " . COUPANG_PLUGIN_PATH . "/cron/product_status.php\n";
        echo "30 10,22 * * * /usr/bin/php " . COUPANG_PLUGIN_PATH . "/cron/stock.php\n";
        echo "</pre>\n";
        echo "</div>\n";
        
        // 제거 로그 생성
        $uninstall_log = COUPANG_PLUGIN_PATH . '/uninstall_' . date('Y-m-d_H-i-s') . '.log';
        $log_content = "쿠팡 연동 플러그인 제거 완료\n";
        $log_content .= "제거 일시: " . date('Y-m-d H:i:s') . "\n";
        $log_content .= "백업 파일: {$backup_file}\n";
        $log_content .= "제거된 테이블: " . implode(', ', $tables_to_backup) . "\n";
        file_put_contents($uninstall_log, $log_content);
        
    } catch (Exception $e) {
        echo "<h2>❌ 제거 중 오류 발생</h2>\n";
        echo "<p class='error'>오류 메시지: " . $e->getMessage() . "</p>\n";
        error_log("쿠팡 플러그인 제거 오류: " . $e->getMessage());
    }
}

// 웹 접근시 HTML 종료
if (isset($_SERVER['REQUEST_METHOD'])) {
    echo "</body></html>";
}

// CLI 실행시 텍스트 정리
if (php_sapi_name() === 'cli') {
    $output = ob_get_contents();
    ob_clean();
    echo strip_tags(str_replace(array('<br>', '<h1>', '</h1>', '<h2>', '</h2>', '<p>', '</p>', '<pre>', '</pre>'), array("\n", "\n=== ", " ===\n", "\n--- ", " ---\n", "\n", "\n", "\n", "\n"), $output));
}

?>
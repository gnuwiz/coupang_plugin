<?php
/**
 * 쿠팡 연동 플러그인 통합 설치 스크립트
 * 경로: /plugin/coupang/setup.php
 * 실행: CLI 또는 웹브라우저에서 실행
 * 용도: 모든 DB 테이블/필드 생성, 기본 데이터 설정, 디렉터리 구조 생성
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
    <title>쿠팡 연동 플러그인 설치</title>
    <meta charset='utf-8'>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #007cba; background: #f9f9f9; }
        .info { background: #e7f3ff; padding: 10px; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>";
}

echo "<h1>🚀 쿠팡 연동 플러그인 설치 (v2.0 통합)</h1>\n";
echo "<p>모든 DB 구조를 통합 설치합니다...</p>\n";

try {
    global $g5;
    $install_log = array();

    // === 1단계: 디렉터리 구조 생성 ===
    echo "<div class='step'>\n";
    echo "<h2>📁 디렉터리 구조 생성</h2>\n";

    $directories = array(
        COUPANG_PLUGIN_PATH . '/logs',
        COUPANG_PLUGIN_PATH . '/backup'
    );

    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (mkdir($dir, 0755, true)) {
                echo "<span class='success'>✅ 디렉터리 생성: " . basename($dir) . "</span><br>\n";
                $install_log[] = "디렉터리 생성: $dir";
            } else {
                echo "<span class='error'>❌ 디렉터리 생성 실패: " . basename($dir) . "</span><br>\n";
            }
        } else {
            echo "<span class='success'>✅ 디렉터리 존재: " . basename($dir) . "</span><br>\n";
        }
    }

    // 기본 로그 파일 생성
    $log_files = array(
        'orders.log',
        'cancelled.log',
        'status.log',
        'products.log',
        'product_status.log',
        'stock.log',
        'general.log'
    );
    foreach ($log_files as $log) {
        $path = COUPANG_PLUGIN_PATH . '/logs/' . $log;
        if (!file_exists($path)) {
            @touch($path);
            echo "<span class='success'>✅ 로그 파일 생성: {$log}</span><br>\n";
        } else {
            echo "<span class='success'>✅ 로그 파일 존재: {$log}</span><br>\n";
        }
    }

    echo "</div>\n";

    // === 2단계: 기존 주문 테이블 필드 추가 ===
    echo "<div class='step'>\n";
    echo "<h2>🛒 주문 테이블 필드 추가</h2>\n";

    // 먼저 기존 필드 확인
    $existing_fields = array();
    $desc_result = sql_query("DESCRIBE {$g5['g5_shop_order_table']}", false);
    if ($desc_result) {
        while ($row = sql_fetch_array($desc_result)) {
            $existing_fields[] = $row['Field'];
        }
    }

    // 추가할 필드 정의
    $order_fields = array(
        'od_coupang_yn' => array(
            'definition' => "ADD `od_coupang_yn` ENUM('Y','N') DEFAULT 'N' COMMENT '쿠팡주문여부'",
            'description' => '쿠팡 주문 구분'
        ),
        'od_coupang_order_id' => array(
            'definition' => "ADD `od_coupang_order_id` VARCHAR(50) DEFAULT '' COMMENT '쿠팡원본주문번호'",
            'description' => '쿠팡 원본 주문 ID'
        ),
        'od_coupang_vendor_order_id' => array(
            'definition' => "ADD `od_coupang_vendor_order_id` VARCHAR(50) DEFAULT '' COMMENT '쿠팡벤더주문번호'",
            'description' => '쿠팡 벤더 주문 ID'
        )
    );

    foreach ($order_fields as $field_name => $field_info) {
        if (!in_array($field_name, $existing_fields)) {
            $sql = "ALTER TABLE `{$g5['g5_shop_order_table']}` " . $field_info['definition'];
            $result = sql_query($sql, false);
            if ($result) {
                echo "<span class='success'>✅ 필드 추가: {$field_name} ({$field_info['description']})</span><br>\n";
                $install_log[] = "주문 테이블 필드 추가: $field_name";
            } else {
                echo "<span class='error'>❌ 필드 추가 실패: {$field_name} - " . sql_error() . "</span><br>\n";
            }
        } else {
            echo "<span class='warning'>⚠️ 필드 이미 존재: {$field_name}</span><br>\n";
        }
    }

    // 인덱스 추가
    $order_indexes = array(
        'idx_coupang_yn' => "ADD INDEX `idx_coupang_yn` (`od_coupang_yn`)",
        'idx_coupang_order_id' => "ADD INDEX `idx_coupang_order_id` (`od_coupang_order_id`)"
    );

    foreach ($order_indexes as $index_name => $index_sql) {
        $check_index = sql_query("SHOW INDEX FROM {$g5['g5_shop_order_table']} WHERE Key_name = '$index_name'", false);
        if (!$check_index || sql_num_rows($check_index) == 0) {
            $result = sql_query("ALTER TABLE `{$g5['g5_shop_order_table']}` $index_sql", false);
            if ($result) {
                echo "<span class='success'>✅ 인덱스 추가: {$index_name}</span><br>\n";
            } else {
                echo "<span class='warning'>⚠️ 인덱스 추가 시도: {$index_name} (이미 존재할 수 있음)</span><br>\n";
            }
        } else {
            echo "<span class='warning'>⚠️ 인덱스 이미 존재: {$index_name}</span><br>\n";
        }
    }
    echo "</div>\n";

    // === 3단계: 쿠팡 전용 테이블 생성 ===
    echo "<div class='step'>\n";
    echo "<h2>📊 쿠팡 전용 테이블 생성</h2>\n";

    // 카테고리 매핑 테이블
    $category_table_sql = "CREATE TABLE IF NOT EXISTS `" . G5_TABLE_PREFIX . "coupang_category_map` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `youngcart_ca_id` varchar(10) NOT NULL COMMENT '영카트 카테고리 ID',
        `coupang_category_id` varchar(20) NOT NULL COMMENT '쿠팡 카테고리 ID',
        `coupang_category_name` varchar(255) DEFAULT '' COMMENT '쿠팡 카테고리명',
        `sync_date` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '매핑 등록일',
        PRIMARY KEY (`id`),
        UNIQUE KEY `youngcart_ca_id` (`youngcart_ca_id`),
        KEY `coupang_category_id` (`coupang_category_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='쿠팡-영카트 카테고리 매핑'";

    if (sql_query($category_table_sql)) {
        echo "<span class='success'>✅ 카테고리 매핑 테이블 생성</span><br>\n";
        $install_log[] = "테이블 생성: coupang_category_map";
    } else {
        echo "<span class='error'>❌ 카테고리 매핑 테이블 생성 실패: " . sql_error() . "</span><br>\n";
    }

    // 상품 매핑 테이블
    $item_table_sql = "CREATE TABLE IF NOT EXISTS `" . G5_TABLE_PREFIX . "coupang_item_map` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `youngcart_it_id` varchar(20) NOT NULL COMMENT '영카트 상품 ID',
        `coupang_item_id` varchar(50) NOT NULL COMMENT '쿠팡 상품 ID',
        `coupang_product_id` varchar(50) DEFAULT '' COMMENT '쿠팡 프로덕트 ID',
        `sync_date` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '최초 동기화일',
        `last_sync_date` datetime DEFAULT NULL COMMENT '마지막 동기화일',
        `sync_status` enum('active','inactive','error') DEFAULT 'active' COMMENT '동기화 상태',
        `error_message` text COMMENT '오류 메시지',
        PRIMARY KEY (`id`),
        UNIQUE KEY `youngcart_it_id` (`youngcart_it_id`),
        KEY `coupang_item_id` (`coupang_item_id`),
        KEY `sync_status` (`sync_status`),
        KEY `last_sync_date` (`last_sync_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='쿠팡-영카트 상품 매핑'";

    if (sql_query($item_table_sql)) {
        echo "<span class='success'>✅ 상품 매핑 테이블 생성</span><br>\n";
        $install_log[] = "테이블 생성: coupang_item_map";
    } else {
        echo "<span class='error'>❌ 상품 매핑 테이블 생성 실패: " . sql_error() . "</span><br>\n";
    }

    // 주문 로그 테이블
    $order_log_sql = "CREATE TABLE IF NOT EXISTS `" . G5_TABLE_PREFIX . "coupang_order_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `od_id` varchar(20) NOT NULL COMMENT '주문 ID',
        `coupang_order_id` varchar(50) NOT NULL COMMENT '쿠팡 원본 주문 ID',
        `action_type` varchar(20) NOT NULL COMMENT '액션 타입 (order_import, status_update, cancel_from_coupang 등)',
        `action_data` text COMMENT '액션 데이터 (JSON)',
        `response_data` text COMMENT '응답 데이터 (JSON)',
        `created_date` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '로그 생성일',
        PRIMARY KEY (`id`),
        KEY `od_id` (`od_id`),
        KEY `coupang_order_id` (`coupang_order_id`),
        KEY `action_type` (`action_type`),
        KEY `created_date` (`created_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='쿠팡 주문 처리 로그'";

    if (sql_query($order_log_sql)) {
        echo "<span class='success'>✅ 주문 로그 테이블 생성</span><br>\n";
        $install_log[] = "테이블 생성: coupang_order_log";
    } else {
        echo "<span class='error'>❌ 주문 로그 테이블 생성 실패: " . sql_error() . "</span><br>\n";
    }

    // 크론 실행 로그 테이블
    $cron_log_sql = "CREATE TABLE IF NOT EXISTS `" . G5_TABLE_PREFIX . "coupang_cron_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `cron_type` varchar(50) NOT NULL COMMENT '크론 타입 (orders, products, stock, cancelled_orders, order_status, product_status)',
        `execution_time` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '실행 시간',
        `status` enum('start','success','error') NOT NULL COMMENT '실행 상태',
        `message` text COMMENT '실행 결과 메시지',
        `execution_duration` decimal(10,2) DEFAULT NULL COMMENT '실행 소요 시간 (초)',
        PRIMARY KEY (`id`),
        KEY `cron_type` (`cron_type`),
        KEY `execution_time` (`execution_time`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='쿠팡 크론 실행 모니터링'";

    if (sql_query($cron_log_sql)) {
        echo "<span class='success'>✅ 크론 로그 테이블 생성</span><br>\n";
        $install_log[] = "테이블 생성: coupang_cron_log";
    } else {
        echo "<span class='error'>❌ 크론 로그 테이블 생성 실패: " . sql_error() . "</span><br>\n";
    }
    echo "</div>\n";

    // === 4단계: 기본 데이터 삽입 ===
    echo "<div class='step'>\n";
    echo "<h2>📋 기본 데이터 설정</h2>\n";

    $default_mappings = array(
        array('10', '1001', '생활용품'),
        array('20', '1002', '의류/액세서리'),
        array('30', '1003', '식품'),
        array('40', '1004', '전자제품'),
        array('50', '1005', '도서/음반'),
        array('60', '1006', '화장품/미용'),
        array('70', '1007', '스포츠/레저'),
        array('80', '1008', '자동차용품'),
        array('90', '1009', '완구/취미'),
        array('99', '1010', '기타')
    );

    foreach ($default_mappings as $mapping) {
        $sql = "INSERT IGNORE INTO " . G5_TABLE_PREFIX . "coupang_category_map 
                (youngcart_ca_id, coupang_category_id, coupang_category_name) 
                VALUES ('{$mapping[0]}', '{$mapping[1]}', '{$mapping[2]}')";
        if (sql_query($sql)) {
            $check_sql = "SELECT COUNT(*) as cnt FROM ".G5_TABLE_PREFIX."coupang_category_map 
             WHERE youngcart_ca_id = '{$mapping[0]}'";
            $check_result = sql_fetch($check_sql);

            if ($check_result && $check_result['cnt'] > 0) {
                echo "<span class='success'>✅ 카테고리 매핑: {$mapping[2]} ({$mapping[0]} → {$mapping[1]})</span><br>\n";
            } else {
                echo "<span class='warning'>⚠️ 카테고리 매핑 이미 존재: {$mapping[2]}</span><br>\n";
            }
        }
    }
    echo "</div>\n";

    // === 5단계: 설정 파일 확인 ===
    echo "<div class='step'>\n";
    echo "<h2>⚙️ 설정 파일 확인</h2>\n";

    $config_file = COUPANG_PLUGIN_PATH . '/lib/coupang_config.php';
    if (file_exists($config_file)) {
        echo "<span class='success'>✅ 설정 파일 존재: coupang_config.php</span><br>\n";

        // 설정값 확인 (API 키가 기본값인지 체크)
        include_once($config_file);
        if (defined('COUPANG_ACCESS_KEY') && COUPANG_ACCESS_KEY === 'YOUR_ACCESS_KEY_HERE') {
            echo "<span class='warning'>⚠️ API 키가 설정되지 않았습니다!</span><br>\n";
        } else {
            echo "<span class='success'>✅ API 키 설정됨</span><br>\n";
        }
    } else {
        echo "<span class='error'>❌ 설정 파일이 없습니다: {$config_file}</span><br>\n";
        echo "<div class='info'>lib/coupang_config.php 파일을 먼저 설정하세요.</div>\n";
    }
    echo "</div>\n";

    // === 6단계: 버전 정보 생성 ===
    echo "<div class='step'>\n";
    echo "<h2>📄 버전 정보 생성</h2>\n";

    $version_info = array(
        'version' => '2.0.0',
        'install_date' => date('Y-m-d H:i:s'),
        'install_type' => 'unified_setup',
        'youngcart_version' => defined('G5_VERSION') ? G5_VERSION : 'Unknown',
        'php_version' => PHP_VERSION,
        'mysql_version' => sql_fetch("SELECT VERSION() as version")['version'],
        'server_os' => php_uname('s'),
        'timezone' => date_default_timezone_get(),
        'features' => array(
            'integrated_api_class' => true,
            'unified_cron_system' => true,
            'improved_db_structure' => true,
            'enhanced_error_handling' => true
        ),
        'install_log' => $install_log
    );

    if (file_put_contents(COUPANG_PLUGIN_PATH . '/version.json', json_encode($version_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        echo "<span class='success'>✅ 버전 정보 파일 생성 (version.json)</span><br>\n";
    } else {
        echo "<span class='error'>❌ 버전 정보 파일 생성 실패</span><br>\n";
    }
    echo "</div>\n";

    // === 7단계: 파일 구조 확인 ===
    echo "<div class='step'>\n";
    echo "<h2>📁 파일 구조 확인</h2>\n";

    $required_files = array(
        'lib/coupang_config.php' => '설정 파일',
        'lib/coupang_api_class.php' => 'API 클래스',
        'cron/main_cron.php' => '통합 크론',
        'cron/orders.php' => '주문 동기화',
        'cron/products.php' => '상품 동기화',
        'cron/stock.php' => '재고 동기화',
        'admin/manual_sync.php' => '관리 페이지',
        'admin/settings.php' => '설정 관리'
    );

    $missing_files = array();
    foreach ($required_files as $file => $desc) {
        $filepath = COUPANG_PLUGIN_PATH . '/' . $file;
        if (file_exists($filepath)) {
            echo "<span class='success'>✅ {$desc}: {$file}</span><br>\n";
        } else {
            echo "<span class='warning'>⚠️ 파일 없음: {$file} ({$desc})</span><br>\n";
            $missing_files[] = $file;
        }
    }

    if (!empty($missing_files)) {
        echo "<div class='info'><strong>누락된 파일들을 업로드하세요:</strong><br>";
        foreach ($missing_files as $file) {
            echo "- {$file}<br>";
        }
        echo "</div>\n";
    }
    echo "</div>\n";

    // === 8단계: 권한 설정 안내 ===
    echo "<div class='step'>\n";
    echo "<h2>🔐 권한 설정 안내</h2>\n";
    echo "<p>다음 명령으로 적절한 권한을 설정하세요:</p>\n";
    echo "<pre>";
    echo "# 플러그인 전체 디렉터리 권한\n";
    echo "chmod -R 755 " . COUPANG_PLUGIN_PATH . "/\n\n";
    echo "# PHP 파일 권한\n";
    echo "chmod 644 " . COUPANG_PLUGIN_PATH . "/lib/*.php\n";
    echo "chmod 755 " . COUPANG_PLUGIN_PATH . "/cron/*.php\n";
    echo "chmod 644 " . COUPANG_PLUGIN_PATH . "/admin/*.php\n\n";
    echo "# 로그 디렉터리 쓰기 권한\n";
    echo "chmod 755 " . COUPANG_PLUGIN_PATH . "/logs/\n";
    echo "</pre>\n";
    echo "</div>\n";

    // === 9단계: 크론탭 설정 가이드 ===
    echo "<div class='step'>\n";
    echo "<h2>⏰ 크론탭 설정 가이드</h2>\n";
    echo "<p>터미널에서 <code>crontab -e</code> 명령을 실행하고 다음 내용을 추가하세요:</p>\n";
    echo "<pre>";

    $plugin_path = COUPANG_PLUGIN_PATH;
    echo "# 쿠팡 주문 관리 (매분 실행)\n";
    echo "*/1 * * * * /usr/bin/php {$plugin_path}/cron/orders.php >> {$plugin_path}/logs/orders.log 2>&1\n";
    echo "*/1 * * * * /usr/bin/php {$plugin_path}/cron/cancelled_orders.php >> {$plugin_path}/logs/cancelled.log 2>&1\n";
    echo "*/1 * * * * /usr/bin/php {$plugin_path}/cron/order_status.php >> {$plugin_path}/logs/order_status.log 2>&1\n\n";

    echo "# 쿠팡 상품 관리 (하루 2번 실행)\n";
    echo "0 9,21 * * * /usr/bin/php {$plugin_path}/cron/products.php >> {$plugin_path}/logs/products.log 2>&1\n";
    echo "15 9,21 * * * /usr/bin/php {$plugin_path}/cron/product_status.php >> {$plugin_path}/logs/product_status.log 2>&1\n";
    echo "30 10,22 * * * /usr/bin/php {$plugin_path}/cron/stock.php >> {$plugin_path}/logs/stock.log 2>&1\n";
    echo "</pre>\n";
    echo "</div>\n";

    // === 10단계: 다음 단계 안내 ===
    echo "<div class='step'>\n";
    echo "<h2>🎯 다음 단계</h2>\n";
    echo "<ol>\n";
    echo "<li><strong>API 키 설정:</strong> <code>lib/coupang_config.php</code> 파일에서 쿠팡 API 키 입력</li>\n";
    echo "<li><strong>크론탭 등록:</strong> 위의 크론탭 설정 가이드 따라 실행</li>\n";
    echo "<li><strong>관리 페이지 접속:</strong> <a href='admin/manual_sync.php' target='_blank'>수동 동기화 페이지</a>에서 API 연결 테스트</li>\n";
    echo "<li><strong>카테고리 매핑:</strong> <a href='admin/settings.php' target='_blank'>설정 페이지</a>에서 카테고리 매핑 확인/수정</li>\n";
    echo "<li><strong>테스트 실행:</strong> 소량의 상품으로 동기화 테스트</li>\n";
    echo "</ol>\n";
    echo "</div>\n";

    // === 11단계: 설치 완료 ===
    echo "<div class='step'>\n";
    echo "<h2>🎉 설치 완료!</h2>\n";
    echo "<div class='info'>\n";
    echo "<strong>✅ 통합 설치가 성공적으로 완료되었습니다!</strong><br><br>\n";
    echo "<strong>설치된 구성요소:</strong><br>\n";
    echo "- 쿠팡 주문 테이블 필드 추가 완료<br>\n";
    echo "- 쿠팡 전용 테이블 4개 생성 완료<br>\n";
    echo "- 기본 카테고리 매핑 10개 설정 완료<br>\n";
    echo "- 통합 API 클래스 및 크론 시스템 준비 완료<br>\n";
    echo "- 로그 및 백업 디렉터리 생성 완료<br><br>\n";
    echo "<strong>버전:</strong> 2.0.0 (통합 설치)<br>\n";
    echo "<strong>설치 시간:</strong> " . date('Y-m-d H:i:s') . "<br>\n";
    echo "</div>\n";
    echo "</div>\n";

    // 설치 성공 로그
    CoupangAPI::log('INFO', '쿠팡 플러그인 통합 설치 완료', array(
        'version' => '2.0.0',
        'install_date' => date('Y-m-d H:i:s'),
        'install_log' => $install_log,
        'log_file' => 'general.log'
    ));

} catch (Exception $e) {
    echo "<div class='step'>\n";
    echo "<h2>❌ 설치 중 오류 발생</h2>\n";
    echo "<p class='error'>오류 메시지: " . $e->getMessage() . "</p>\n";
    echo "</div>\n";

    CoupangAPI::log('ERROR', '쿠팡 플러그인 설치 오류', array(
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'log_file' => 'general.log'
    ));
}

// 웹 접근시 HTML 종료
if (isset($_SERVER['REQUEST_METHOD'])) {
    echo "</body></html>";
}

// CLI 실행시 텍스트 정리
if (php_sapi_name() === 'cli') {
    $output = ob_get_contents();
    if ($output) {
        ob_clean();
        echo strip_tags(str_replace(
            array('<br>', '<h1>', '</h1>', '<h2>', '</h2>', '<p>', '</p>', '<pre>', '</pre>', '<code>', '</code>', '<div class="step">', '</div>', '<span class="success">', '<span class="error">', '<span class="warning">', '</span>'),
            array("\n", "\n=== ", " ===\n", "\n--- ", " ---\n", "\n", "\n", "\n", "\n", "", "", "\n", "\n", "[성공] ", "[오류] ", "[경고] ", ""),
            $output
        ));
    }
}

?>
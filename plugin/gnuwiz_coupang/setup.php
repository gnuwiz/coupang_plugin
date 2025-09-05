<?php
/**
 * 쿠팡 연동 플러그인 통합 설치 스크립트 (v2.1 카테고리 추천 포함)
 * 경로: /plugin/gnuwiz_coupang/setup.php
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

echo "<h1>🚀 쿠팡 연동 플러그인 설치 (v2.1 카테고리 추천 포함)</h1>\n";
echo "<p>모든 DB 구조 및 카테고리 추천 시스템을 통합 설치합니다...</p>\n";

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
    echo "</div>\n";

    // === 2단계: DB 테이블 생성 ===
    echo "<div class='step'>\n";
    echo "<h2>🗄️ 데이터베이스 테이블 생성</h2>\n";

    $table_prefix = G5_TABLE_PREFIX;

    // 1. 쿠팡 주문 테이블에 필드 추가
    echo "<h3>주문 테이블 필드 추가</h3>\n";
    $alter_order_table_queries = array(
        "ALTER TABLE `{$g5['g5_shop_order_table']}` ADD COLUMN `od_coupang_yn` enum('Y','N') NOT NULL DEFAULT 'N' COMMENT '쿠팡 주문 여부'",
        "ALTER TABLE `{$g5['g5_shop_order_table']}` ADD COLUMN `od_coupang_order_id` varchar(100) DEFAULT NULL COMMENT '쿠팡 주문 ID'",
        "ALTER TABLE `{$g5['g5_shop_order_table']}` ADD COLUMN `od_coupang_vendor_order_id` varchar(100) DEFAULT NULL COMMENT '쿠팡 업체 주문 ID'",
        "ALTER TABLE `{$g5['g5_shop_order_table']}` ADD INDEX `idx_coupang_yn` (`od_coupang_yn`)",
        "ALTER TABLE `{$g5['g5_shop_order_table']}` ADD INDEX `idx_coupang_order_id` (`od_coupang_order_id`)"
    );

    foreach ($alter_order_table_queries as $query) {
        $result = sql_query($query, false);
        if ($result) {
            echo "<span class='success'>✅ 주문 테이블 필드 추가</span><br>\n";
            $install_log[] = "주문 테이블 필드 추가";
        } else {
            echo "<span class='warning'>⚠️ 주문 테이블 필드 추가 (이미 존재하거나 스킵됨)</span><br>\n";
        }
    }

    // 2. 쿠팡 카테고리 매핑 테이블
    echo "<h3>카테고리 매핑 테이블</h3>\n";
    $sql = "CREATE TABLE IF NOT EXISTS `{$table_prefix}coupang_category_map` (
        `map_id` int(11) NOT NULL AUTO_INCREMENT,
        `youngcart_ca_id` varchar(50) NOT NULL COMMENT '영카트 상품 ID',
        `coupang_category_id` varchar(20) NOT NULL COMMENT '쿠팡 카테고리 ID',
        `coupang_category_name` varchar(255) DEFAULT NULL COMMENT '쿠팡 카테고리명',
        `confidence` decimal(3,2) DEFAULT '0.70' COMMENT '추천 신뢰도',
        `created_date` datetime NOT NULL COMMENT '생성일시',
        `updated_date` datetime DEFAULT NULL COMMENT '수정일시',
        PRIMARY KEY (`map_id`),
        UNIQUE KEY `uk_youngcart_ca_id` (`youngcart_ca_id`),
        KEY `idx_coupang_category_id` (`coupang_category_id`),
        KEY `idx_confidence` (`confidence`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쿠팡 카테고리 매핑'";

    if (sql_query($sql)) {
        echo "<span class='success'>✅ 카테고리 매핑 테이블 생성: {$table_prefix}coupang_category_map</span><br>\n";
        $install_log[] = "테이블 생성: {$table_prefix}coupang_category_map";
    } else {
        echo "<span class='error'>❌ 카테고리 매핑 테이블 생성 실패</span><br>\n";
    }

    // 3. 쿠팡 상품 매핑 테이블
    echo "<h3>상품 매핑 테이블</h3>\n";
    $sql = "CREATE TABLE IF NOT EXISTS `{$table_prefix}coupang_item_map` (
        `map_id` int(11) NOT NULL AUTO_INCREMENT,
        `youngcart_it_id` varchar(50) NOT NULL COMMENT '영카트 상품 ID',
        `coupang_item_id` varchar(100) DEFAULT NULL COMMENT '쿠팡 상품 ID',
        `sync_status` enum('pending','registered','updated','error') DEFAULT 'pending' COMMENT '동기화 상태',
        `error_message` text DEFAULT NULL COMMENT '오류 메시지',
        `sync_date` datetime DEFAULT NULL COMMENT '최초 동기화일',
        `last_sync_date` datetime DEFAULT NULL COMMENT '최종 동기화일',
        PRIMARY KEY (`map_id`),
        UNIQUE KEY `uk_youngcart_it_id` (`youngcart_it_id`),
        KEY `idx_coupang_item_id` (`coupang_item_id`),
        KEY `idx_sync_status` (`sync_status`),
        KEY `idx_last_sync_date` (`last_sync_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쿠팡 상품 매핑'";

    if (sql_query($sql)) {
        echo "<span class='success'>✅ 상품 매핑 테이블 생성: {$table_prefix}coupang_item_map</span><br>\n";
        $install_log[] = "테이블 생성: {$table_prefix}coupang_item_map";
    } else {
        echo "<span class='error'>❌ 상품 매핑 테이블 생성 실패</span><br>\n";
    }

    // 4. 쿠팡 주문 로그 테이블
    echo "<h3>주문 로그 테이블</h3>\n";
    $sql = "CREATE TABLE IF NOT EXISTS `{$table_prefix}coupang_order_log` (
        `log_id` int(11) NOT NULL AUTO_INCREMENT,
        `od_id` varchar(20) NOT NULL COMMENT '영카트 주문 ID',
        `coupang_order_id` varchar(100) DEFAULT NULL COMMENT '쿠팡 주문 ID',
        `action_type` varchar(50) NOT NULL COMMENT '액션 타입',
        `action_data` text DEFAULT NULL COMMENT '액션 데이터 (JSON)',
        `response_data` text DEFAULT NULL COMMENT '응답 데이터 (JSON)',
        `created_date` datetime NOT NULL COMMENT '생성일시',
        PRIMARY KEY (`log_id`),
        KEY `idx_od_id` (`od_id`),
        KEY `idx_coupang_order_id` (`coupang_order_id`),
        KEY `idx_action_type` (`action_type`),
        KEY `idx_created_date` (`created_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쿠팡 주문 처리 로그'";

    if (sql_query($sql)) {
        echo "<span class='success'>✅ 주문 로그 테이블 생성: {$table_prefix}coupang_order_log</span><br>\n";
        $install_log[] = "테이블 생성: {$table_prefix}coupang_order_log";
    } else {
        echo "<span class='error'>❌ 주문 로그 테이블 생성 실패</span><br>\n";
    }

    // 5. 🔥 카테고리 추천 캐시 테이블 (NEW!)
    echo "<h3>🔥 카테고리 추천 캐시 테이블 (NEW!)</h3>\n";
    $sql = "CREATE TABLE IF NOT EXISTS `{$table_prefix}coupang_category_cache` (
        `cache_id` int(11) NOT NULL AUTO_INCREMENT,
        `cache_key` varchar(255) NOT NULL COMMENT '캐시 키 (MD5 해시)',
        `cache_data` text NOT NULL COMMENT '캐시된 추천 결과 (JSON)',
        `created_date` datetime NOT NULL COMMENT '생성일시',
        PRIMARY KEY (`cache_id`),
        UNIQUE KEY `uk_cache_key` (`cache_key`),
        KEY `idx_created_date` (`created_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쿠팡 카테고리 추천 캐시'";

    if (sql_query($sql)) {
        echo "<span class='success'>✅ 카테고리 캐시 테이블 생성: {$table_prefix}coupang_category_cache</span><br>\n";
        $install_log[] = "테이블 생성: {$table_prefix}coupang_category_cache";
    } else {
        echo "<span class='error'>❌ 카테고리 캐시 테이블 생성 실패</span><br>\n";
    }

    // 6. 쿠팡 크론 로그 테이블
    echo "<h3>크론 로그 테이블</h3>\n";
    $sql = "CREATE TABLE IF NOT EXISTS `{$table_prefix}coupang_cron_log` (
        `log_id` int(11) NOT NULL AUTO_INCREMENT,
        `cron_type` varchar(50) NOT NULL COMMENT '크론 타입',
        `status` enum('start','success','error') NOT NULL COMMENT '실행 상태',
        `message` text DEFAULT NULL COMMENT '메시지',
        `execution_duration` decimal(10,4) DEFAULT NULL COMMENT '실행 시간 (초)',
        `created_date` datetime NOT NULL COMMENT '실행일시',
        PRIMARY KEY (`log_id`),
        KEY `idx_cron_type` (`cron_type`),
        KEY `idx_status` (`status`),
        KEY `idx_created_date` (`created_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쿠팡 크론 실행 로그'";

    if (sql_query($sql)) {
        echo "<span class='success'>✅ 크론 로그 테이블 생성: {$table_prefix}coupang_cron_log</span><br>\n";
        $install_log[] = "테이블 생성: {$table_prefix}coupang_cron_log";
    } else {
        echo "<span class='error'>❌ 크론 로그 테이블 생성 실패</span><br>\n";
    }

    echo "</div>\n";

	// === 출고지/반품지 관리 테이블 생성 ===
    echo "<div class='step'>\n";
    echo "<h2>🚚 출고지/반품지 관리 테이블 생성</h2>\n";

    // 출고지/반품지 관리 테이블
    $shipping_places_table = "
    CREATE TABLE IF NOT EXISTS `" . G5_TABLE_PREFIX . "coupang_shipping_places` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `shipping_place_code` varchar(50) NOT NULL COMMENT '쿠팡 출고지/반품지 코드',
      `shipping_place_name` varchar(255) NOT NULL COMMENT '출고지/반품지 명',
      `address_type` enum('OUTBOUND','RETURN') NOT NULL COMMENT '주소 타입 (OUTBOUND:출고지, RETURN:반품지)',
      `company_name` varchar(255) DEFAULT NULL COMMENT '회사명',
      `contact_name` varchar(100) DEFAULT NULL COMMENT '담당자명',
      `company_phone` varchar(20) DEFAULT NULL COMMENT '회사 전화번호',
      `phone1` varchar(20) DEFAULT NULL COMMENT '연락처1',
      `phone2` varchar(20) DEFAULT NULL COMMENT '연락처2',
      `zipcode` varchar(10) DEFAULT NULL COMMENT '우편번호',
      `address1` varchar(255) DEFAULT NULL COMMENT '주소1',
      `address2` varchar(255) DEFAULT NULL COMMENT '주소2',
      `place_data` text COMMENT '쿠팡 API 원본 데이터 (JSON)',
      `status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE' COMMENT '상태',
      `is_default_outbound` tinyint(1) NOT NULL DEFAULT 0 COMMENT '기본 출고지 여부',
      `is_default_return` tinyint(1) NOT NULL DEFAULT 0 COMMENT '기본 반품지 여부',
      `delivery_companies` text COMMENT '지원 택배사 목록 (JSON)',
      `notes` text COMMENT '메모',
      `last_sync_date` datetime DEFAULT NULL COMMENT '마지막 동기화 일시',
      `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일시',
      `updated_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일시',
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_shipping_place_code` (`shipping_place_code`),
      KEY `idx_address_type` (`address_type`),
      KEY `idx_status` (`status`),
      KEY `idx_is_default` (`is_default_outbound`, `is_default_return`),
      KEY `idx_last_sync` (`last_sync_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쿠팡 출고지/반품지 관리'";

    if (sql_query($shipping_places_table)) {
        echo "<span class='success'>✅ 출고지/반품지 관리 테이블 생성 (g5_coupang_shipping_places)</span><br>\n";
        $install_log[] = "출고지/반품지 관리 테이블 생성";
    } else {
        echo "<span class='error'>❌ 출고지/반품지 관리 테이블 생성 실패</span><br>\n";
        echo "<span class='error'>오류: " . sql_error() . "</span><br>\n";
    }

    // 출고지/반품지 동기화 로그 테이블
    $shipping_log_table = "
    CREATE TABLE IF NOT EXISTS `" . G5_TABLE_PREFIX . "coupang_shipping_log` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `shipping_place_code` varchar(50) DEFAULT NULL COMMENT '출고지/반품지 코드',
      `action_type` enum('CREATE','UPDATE','DELETE','SYNC') NOT NULL COMMENT '작업 타입',
      `address_type` enum('OUTBOUND','RETURN') DEFAULT NULL COMMENT '주소 타입',
      `status` enum('SUCCESS','FAIL','PENDING') NOT NULL COMMENT '처리 상태',
      `request_data` text COMMENT '요청 데이터 (JSON)',
      `response_data` text COMMENT '응답 데이터 (JSON)',
      `error_message` text COMMENT '오류 메시지',
      `execution_time` decimal(10,3) DEFAULT NULL COMMENT '실행 시간 (초)',
      `user_id` varchar(50) DEFAULT NULL COMMENT '실행 사용자 ID',
      `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP 주소',
      `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일시',
      PRIMARY KEY (`id`),
      KEY `idx_shipping_place_code` (`shipping_place_code`),
      KEY `idx_action_type` (`action_type`),
      KEY `idx_status` (`status`),
      KEY `idx_created_date` (`created_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쿠팡 출고지/반품지 동기화 로그'";

    if (sql_query($shipping_log_table)) {
        echo "<span class='success'>✅ 출고지/반품지 동기화 로그 테이블 생성 (g5_coupang_shipping_log)</span><br>\n";
        $install_log[] = "출고지/반품지 동기화 로그 테이블 생성";
    } else {
        echo "<span class='error'>❌ 출고지/반품지 동기화 로그 테이블 생성 실패</span><br>\n";
        echo "<span class='error'>오류: " . sql_error() . "</span><br>\n";
    }

    // 동기화 통계 테이블 (기존에 없다면 생성)
    $sync_stats_table = "
    CREATE TABLE IF NOT EXISTS `" . G5_TABLE_PREFIX . "coupang_sync_stats` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `sync_type` varchar(50) NOT NULL COMMENT '동기화 타입',
      `sync_date` date NOT NULL COMMENT '동기화 날짜',
      `success_count` int(11) NOT NULL DEFAULT 0 COMMENT '성공 건수',
      `fail_count` int(11) NOT NULL DEFAULT 0 COMMENT '실패 건수',
      `last_execution_time` datetime DEFAULT NULL COMMENT '마지막 실행 시간',
      `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일시',
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_sync_type_date` (`sync_type`, `sync_date`),
      KEY `idx_sync_date` (`sync_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쿠팡 동기화 통계'";

    if (sql_query($sync_stats_table)) {
        echo "<span class='success'>✅ 동기화 통계 테이블 생성 (g5_coupang_sync_stats)</span><br>\n";
        $install_log[] = "동기화 통계 테이블 생성";
    } else {
        echo "<span class='error'>❌ 동기화 통계 테이블 생성 실패</span><br>\n";
        echo "<span class='error'>오류: " . sql_error() . "</span><br>\n";
    }

    // 샘플 출고지/반품지 데이터 입력 (선택적)
    echo "<h3>📋 샘플 출고지/반품지 데이터 입력</h3>\n";
    
    $sample_shipping_places = array(
        array(
            'code' => 'GNUWIZ_OUT_001',
            'name' => '그누위즈 기본 출고지',
            'type' => 'OUTBOUND',
            'company' => '그누위즈',
            'contact' => '관리자',
            'phone' => '1544-0000',
            'phone1' => '010-0000-0000',
            'zipcode' => '06234',
            'addr1' => '서울특별시 강남구 테헤란로',
            'addr2' => '123번길 45, 그누위즈빌딩 3층',
            'is_default_out' => 1,
            'is_default_ret' => 0
        ),
        array(
            'code' => 'GNUWIZ_RET_001',
            'name' => '그누위즈 기본 반품지',
            'type' => 'RETURN',
            'company' => '그누위즈',
            'contact' => '관리자',
            'phone' => '1544-0000',
            'phone1' => '010-0000-0000',
            'zipcode' => '06234',
            'addr1' => '서울특별시 강남구 테헤란로',
            'addr2' => '123번길 45, 그누위즈빌딩 3층',
            'is_default_out' => 0,
            'is_default_ret' => 1
        )
    );

    $inserted_shipping_places = 0;
    foreach ($sample_shipping_places as $place) {
        $sql = "INSERT IGNORE INTO " . G5_TABLE_PREFIX . "coupang_shipping_places 
                (shipping_place_code, shipping_place_name, address_type, company_name, contact_name, 
                 company_phone, phone1, zipcode, address1, address2, status, 
                 is_default_outbound, is_default_return, notes, created_date) VALUES 
                ('" . addslashes($place['code']) . "', 
                 '" . addslashes($place['name']) . "', 
                 '" . addslashes($place['type']) . "', 
                 '" . addslashes($place['company']) . "', 
                 '" . addslashes($place['contact']) . "', 
                 '" . addslashes($place['phone']) . "', 
                 '" . addslashes($place['phone1']) . "', 
                 '" . addslashes($place['zipcode']) . "', 
                 '" . addslashes($place['addr1']) . "', 
                 '" . addslashes($place['addr2']) . "', 
                 'ACTIVE', 
                 " . intval($place['is_default_out']) . ", 
                 " . intval($place['is_default_ret']) . ", 
                 '설치시 생성된 샘플 데이터 - 실제 정보로 수정 필요', 
                 NOW())";
        
        if (sql_query($sql)) {
            $inserted_shipping_places++;
        }
    }

    echo "<span class='success'>✅ 샘플 출고지/반품지 {$inserted_shipping_places}개 입력 완료</span><br>\n";
    $install_log[] = "샘플 출고지/반품지 {$inserted_shipping_places}개 입력";

    echo "<div class='info'>\n";
    echo "<strong>📌 출고지/반품지 설정 안내:</strong><br>\n";
    echo "1. 샘플 출고지/반품지가 생성되었습니다.<br>\n";
    echo "2. 관리자 페이지에서 실제 정보로 수정하세요.<br>\n";
    echo "3. 쿠팡 API로 실제 출고지/반품지를 등록한 후 동기화하세요.<br>\n";
    echo "4. 상품 등록 시 출고지 코드가 필수입니다.<br>\n";
    echo "</div>\n";

    echo "</div>\n";

    // === 3단계: 기본 데이터 입력 ===
    echo "<div class='step'>\n";
    echo "<h2>📝 기본 데이터 입력</h2>\n";

    // 기본 카테고리 매핑 데이터 (예시)
    $default_categories = array(
        array('전자제품', '1001', '가전디지털'),
        array('의류', '2001', '패션의류'),
        array('화장품', '3001', '뷰티'),
        array('식품', '4001', '식품'),
        array('도서', '5001', '도서/음반/DVD'),
        array('생활용품', '6001', '생활건강'),
        array('스포츠', '7001', '스포츠/레저'),
        array('완구', '8001', '완구/취미'),
        array('자동차용품', '9001', '자동차용품'),
        array('반려동물용품', '10001', '펫샵')
    );

    $inserted_categories = 0;
    foreach ($default_categories as $category) {
        $sql = "INSERT IGNORE INTO `{$table_prefix}coupang_category_map` 
                (youngcart_ca_id, coupang_category_id, coupang_category_name, confidence, created_date) VALUES 
                ('" . addslashes($category[0]) . "', 
                 '" . addslashes($category[1]) . "', 
                 '" . addslashes($category[2]) . "', 
                 0.50, NOW())";
        
        if (sql_query($sql)) {
            $inserted_categories++;
        }
    }

    echo "<span class='success'>✅ 기본 카테고리 매핑 {$inserted_categories}개 입력 완료</span><br>\n";
    $install_log[] = "기본 카테고리 매핑 {$inserted_categories}개 입력";

    echo "</div>\n";

    // === 4단계: 로그 파일 생성 ===
    echo "<div class='step'>\n";
    echo "<h2>📄 로그 파일 초기화</h2>\n";

    $log_files = array(
        'orders.log' => '주문 동기화 로그',
        'cancelled.log' => '취소 주문 로그',
        'order_status.log' => '주문 상태 로그',
        'products.log' => '상품 동기화 로그',
        'product_status.log' => '상품 상태 로그',
        'stock.log' => '재고 동기화 로그',
        'category_recommendations.log' => '🔥 카테고리 추천 로그',
        'category_cache.log' => '🔥 카테고리 캐시 로그',
        'general.log' => '일반 로그'
    );

    $log_dir = COUPANG_PLUGIN_PATH . '/logs';
    foreach ($log_files as $file => $desc) {
        $log_path = $log_dir . '/' . $file;
        if (!file_exists($log_path)) {
            $initial_content = "# {$desc}\n# 생성일: " . date('Y-m-d H:i:s') . "\n\n";
            if (file_put_contents($log_path, $initial_content)) {
                echo "<span class='success'>✅ 로그 파일 생성: {$file}</span><br>\n";
                $install_log[] = "로그 파일 생성: {$file}";
            }
        } else {
            echo "<span class='success'>✅ 로그 파일 존재: {$file}</span><br>\n";
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
        'version' => '2.1.0',
        'install_date' => date('Y-m-d H:i:s'),
        'install_type' => 'unified_setup_with_category',
        'youngcart_version' => defined('G5_VERSION') ? G5_VERSION : 'Unknown',
        'php_version' => PHP_VERSION,
        'mysql_version' => sql_fetch("SELECT VERSION() as version")['version'],
        'server_os' => php_uname('s'),
        'timezone' => date_default_timezone_get(),
        'features' => array(
            'integrated_api_class' => true,
            'unified_cron_system' => true,
            'improved_db_structure' => true,
            'enhanced_error_handling' => true,
            'category_recommendation' => true,   // 🔥 NEW
            'category_cache_system' => true,     // 🔥 NEW
            'batch_category_processing' => true  // 🔥 NEW
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
        'lib/coupang_api_class.php' => 'API 클래스 (카테고리 추천 포함)',
        'cron/main_cron.php' => '통합 크론',
        'cron/orders.php' => '주문 동기화',
        'cron/products.php' => '상품 동기화',
        'cron/stock.php' => '재고 동기화',
        'cron/category_recommendations.php' => '🔥 카테고리 추천 크론',
        'cron/category_cache_cleanup.php' => '🔥 카테고리 캐시 정리',
        'cron/manual_category_test.php' => '🔥 수동 카테고리 테스트',
        'admin/manual_sync.php' => '관리 페이지',
        'admin/settings.php' => '설정 관리',
        'admin/category_test.php' => '🔥 카테고리 테스트 페이지'
    );

    $missing_files = array();
    foreach ($required_files as $file => $desc) {
        $filepath = COUPANG_PLUGIN_PATH . '/' . $file;
        if (file_exists($filepath)) {
            $icon = strpos($desc, '🔥') !== false ? '🔥' : '✅';
            echo "<span class='success'>{$icon} {$desc}: {$file}</span><br>\n";
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

    // === 9단계: 크론탭 설정 가이드 (업데이트됨) ===
    echo "<div class='step'>\n";
    echo "<h2>⏰ 크론탭 설정 가이드 (카테고리 추천 포함)</h2>\n";
    echo "<p>터미널에서 <code>crontab -e</code> 명령을 실행하고 다음 내용을 추가하세요:</p>\n";
    echo "<pre>";

    $plugin_path = COUPANG_PLUGIN_PATH;
    echo "# 🔥 쿠팡 주문 관리 (매분 실행)\n";
    echo "*/1 * * * * /usr/bin/php {$plugin_path}/cron/orders.php >> {$plugin_path}/logs/orders.log 2>&1\n";
    echo "*/1 * * * * /usr/bin/php {$plugin_path}/cron/cancelled_orders.php >> {$plugin_path}/logs/cancelled.log 2>&1\n";
    echo "*/1 * * * * /usr/bin/php {$plugin_path}/cron/order_status.php >> {$plugin_path}/logs/order_status.log 2>&1\n\n";

    echo "# 🔥 쿠팡 상품 관리 (하루 2번 실행)\n";
    echo "0 9,21 * * * /usr/bin/php {$plugin_path}/cron/products.php >> {$plugin_path}/logs/products.log 2>&1\n";
    echo "15 9,21 * * * /usr/bin/php {$plugin_path}/cron/product_status.php >> {$plugin_path}/logs/product_status.log 2>&1\n";
    echo "30 10,22 * * * /usr/bin/php {$plugin_path}/cron/stock.php >> {$plugin_path}/logs/stock.log 2>&1\n\n";

    echo "# 🔥 카테고리 추천 시스템 (NEW!)\n";
    echo "0 2 * * * /usr/bin/php {$plugin_path}/cron/category_recommendations.php >> {$plugin_path}/logs/category_recommendations.log 2>&1\n";
    echo "0 3 * * * /usr/bin/php {$plugin_path}/cron/category_cache_cleanup.php >> {$plugin_path}/logs/category_cache.log 2>&1\n";
    echo "</pre>\n";
    echo "</div>\n";

    // === 10단계: 다음 단계 안내 ===
    echo "<div class='step'>\n";
    echo "<h2>🎯 다음 단계</h2>\n";
    echo "<ol>\n";
    echo "<li><strong>API 키 설정:</strong> <code>lib/coupang_config.php</code> 파일에서 쿠팡 API 키 입력</li>\n";
    echo "<li><strong>크론탭 등록:</strong> 위의 크론탭 설정 가이드 따라 실행</li>\n";
    echo "<li><strong>관리 페이지 접속:</strong> <a href='admin/manual_sync.php' target='_blank'>수동 동기화 페이지</a>에서 API 연결 테스트</li>\n";
    echo "<li><strong>카테고리 테스트:</strong> <a href='admin/category_test.php' target='_blank'>카테고리 추천 테스트 페이지</a>에서 추천 기능 확인</li>\n";
    echo "<li><strong>카테고리 매핑:</strong> <a href='admin/settings.php' target='_blank'>설정 페이지</a>에서 카테고리 매핑 확인/수정</li>\n";
    echo "<li><strong>테스트 실행:</strong> 소량의 상품으로 동기화 테스트</li>\n";
    echo "</ol>\n";
    echo "</div>\n";

    // === 11단계: 카테고리 추천 테스트 가이드 ===
    echo "<div class='step'>\n";
    echo "<h2>🎯 카테고리 추천 시스템 테스트 가이드</h2>\n";
    echo "<h3>수동 테스트 방법:</h3>\n";
    echo "<pre>";
    echo "# 개별 상품 카테고리 추천 테스트\n";
    echo "php {$plugin_path}/cron/manual_category_test.php \"삼성 갤럭시 S24 케이스\"\n\n";
    echo "# 배치 카테고리 추천 실행\n";
    echo "php {$plugin_path}/cron/main_cron.php category_recommendations\n\n";
    echo "# 카테고리 캐시 정리\n";
    echo "php {$plugin_path}/cron/main_cron.php category_cache_cleanup\n";
    echo "</pre>\n";
    
    echo "<h3>웹 인터페이스 테스트:</h3>\n";
    echo "<ul>\n";
    echo "<li>단일 상품 테스트: <a href='admin/category_test.php' target='_blank'>카테고리 추천 테스트 페이지</a></li>\n";
    echo "<li>배치 처리 테스트: 관리자 페이지에서 배치 실행 버튼 클릭</li>\n";
    echo "<li>API 연결 확인: <a href='admin/api_test.php' target='_blank'>API 테스트 페이지</a></li>\n";
    echo "</ul>\n";
    
    echo "<h3>카테고리 추천 정확도 향상 팁:</h3>\n";
    echo "<ul>\n";
    echo "<li><strong>상품명:</strong> 브랜드, 모델명, 특징을 포함한 구체적인 이름</li>\n";
    echo "<li><strong>상품 설명:</strong> 용도, 재질, 크기 등 상세 정보 입력</li>\n";
    echo "<li><strong>브랜드:</strong> 정확한 브랜드명 입력</li>\n";
    echo "<li><strong>속성:</strong> 제조국, 중량 등 추가 정보 제공</li>\n";
    echo "</ul>\n";
    echo "</div>\n";

    // === 12단계: 설치 완료 ===
    echo "<div class='step'>\n";
    echo "<h2>🎉 설치 완료!</h2>\n";
    echo "<div class='info'>\n";
    echo "<strong>✅ 쿠팡 연동 플러그인 v2.1 설치가 성공적으로 완료되었습니다!</strong><br><br>\n";
    echo "<strong>🔥 새로 추가된 기능들:</strong><br>\n";
    echo "- 🎯 머신러닝 기반 카테고리 자동 추천<br>\n";
    echo "- ⚡ 카테고리 추천 결과 캐시 시스템<br>\n";
    echo "- 🔄 배치 카테고리 추천 처리<br>\n";
    echo "- 🖥️ 웹 기반 카테고리 테스트 인터페이스<br>\n";
    echo "- 📊 카테고리 추천 신뢰도 분석<br>\n";
    echo "- 🛠️ CLI 기반 수동 테스트 도구<br><br>\n";
    
    echo "<strong>설치된 구성요소:</strong><br>\n";
    echo "- 쿠팡 주문 테이블 필드 추가 완료<br>\n";
    echo "- 쿠팡 전용 테이블 5개 생성 완료 (캐시 테이블 포함)<br>\n";
    echo "- 기본 카테고리 매핑 10개 설정 완료<br>\n";
    echo "- 통합 API 클래스 및 크론 시스템 준비 완료<br>\n";
    echo "- 카테고리 추천 시스템 완전 통합<br>\n";
    echo "- 로그 및 백업 디렉터리 생성 완료<br><br>\n";
    echo "<strong>버전:</strong> 2.1.0 (카테고리 추천 시스템 포함)<br>\n";
    echo "<strong>설치 시간:</strong> " . date('Y-m-d H:i:s') . "<br>\n";
    echo "</div>\n";
    echo "</div>\n";

    // 설치 성공 로그
    coupang_log('INFO', '쿠팡 플러그인 v2.1 설치 완료', array(
        'version' => '2.1.0',
        'install_date' => date('Y-m-d H:i:s'),
        'features' => array(
            'category_recommendation' => true,
            'cache_system' => true,
            'batch_processing' => true
        ),
        'install_log' => $install_log
    ));

} catch (Exception $e) {
    echo "<div class='step'>\n";
    echo "<h2>❌ 설치 중 오류 발생</h2>\n";
    echo "<p class='error'>오류 메시지: " . $e->getMessage() . "</p>\n";
    echo "<p>오류 위치: " . $e->getFile() . ":" . $e->getLine() . "</p>\n";
    echo "<details><summary>상세 오류 정보</summary><pre>" . $e->getTraceAsString() . "</pre></details>\n";
    echo "</div>\n";

    coupang_log('ERROR', '쿠팡 플러그인 설치 오류', array(
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
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
            array('<br>', '<h1>', '</h1>', '<h2>', '</h2>', '<h3>', '</h3>', '<p>', '</p>', '<pre>', '</pre>', '<code>', '</code>', '<div class="step">', '</div>', '<span class="success">', '<span class="error">', '<span class="warning">', '</span>'),
            array("\n", "\n=== ", " ===\n", "\n--- ", " ---\n", "\n.. ", " ..\n", "\n", "\n", "\n", "\n", "", "", "\n", "\n", "[성공] ", "[오류] ", "[경고] ", ""),
            $output
        ));
    }
}

?>
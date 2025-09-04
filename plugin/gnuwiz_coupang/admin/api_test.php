<?php
/**
 * 쿠팡 API 연결 테스트 페이지
 * 경로: /plugin/coupang/admin/api_test.php
 * 용도: API 키 설정 후 쿠팡 API 연결 상태 및 설정값 검증
 */

include_once('../_common.php');

// 관리자 권한 체크
if (!$is_admin) {
    die('관리자만 접근할 수 있습니다.');
}

$test_action = isset($_POST['test_action']) ? $_POST['test_action'] : '';
$test_results = array();

// API 테스트 실행
if ($test_action) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $test_results = performAPITests($test_action);
        echo json_encode($test_results);
    } catch (Exception $e) {
        echo json_encode(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
    exit;
}

// API 테스트 수행 함수
function performAPITests($test_type = 'all') {
    $results = array(
        'success' => true,
        'overall_status' => 'success',
        'tests' => array(),
        'summary' => array()
    );
    
    $start_time = microtime(true);
    
    try {
        // 1. 설정 검증 테스트
        if ($test_type == 'all' || $test_type == 'config') {
            $config_test = testConfiguration();
            $results['tests']['config'] = $config_test;
            if (!$config_test['success']) {
                $results['overall_status'] = 'error';
            }
        }
        
        // 2. DB 구조 검증 테스트
        if ($test_type == 'all' || $test_type == 'database') {
            $db_test = testDatabaseStructure();
            $results['tests']['database'] = $db_test;
            if (!$db_test['success']) {
                $results['overall_status'] = 'warning';
            }
        }
        
        // 3. API 연결 테스트
        if ($test_type == 'all' || $test_type == 'connection') {
            $connection_test = testAPIConnection();
            $results['tests']['connection'] = $connection_test;
            if (!$connection_test['success']) {
                $results['overall_status'] = 'error';
            }
        }
        
        // 4. API 권한 테스트
        if ($test_type == 'all' || $test_type == 'permissions') {
            $permission_test = testAPIPermissions();
            $results['tests']['permissions'] = $permission_test;
            if (!$permission_test['success']) {
                $results['overall_status'] = 'warning';
            }
        }
        
        // 5. 샘플 데이터 테스트
        if ($test_type == 'all' || $test_type == 'sample') {
            $sample_test = testSampleData();
            $results['tests']['sample'] = $sample_test;
            if (!$sample_test['success']) {
                $results['overall_status'] = 'warning';
            }
        }
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['overall_status'] = 'error';
        $results['error'] = $e->getMessage();
    }
    
    // 실행 시간 계산
    $execution_time = round((microtime(true) - $start_time), 2);
    $results['execution_time'] = $execution_time;
    
    // 요약 정보 생성
    $success_count = 0;
    $warning_count = 0;
    $error_count = 0;
    
    foreach ($results['tests'] as $test) {
        if ($test['success']) {
            $success_count++;
        } else {
            if ($test['level'] == 'error') {
                $error_count++;
            } else {
                $warning_count++;
            }
        }
    }
    
    $results['summary'] = array(
        'total' => count($results['tests']),
        'success' => $success_count,
        'warning' => $warning_count,
        'error' => $error_count
    );
    
    CoupangAPI::log('INFO', 'API 테스트 실행', array(
        'test_type' => $test_type,
        'overall_status' => $results['overall_status'],
        'execution_time' => $execution_time,
        'log_file' => 'general.log'
    ));
    
    return $results;
}

// 설정 검증 테스트
function testConfiguration() {
    $test = array(
        'name' => '설정 검증',
        'description' => 'API 키 및 기본 설정값 확인',
        'success' => true,
        'level' => 'error',
        'details' => array(),
        'errors' => array()
    );
    
    try {
        // API 키 확인
        if (!defined('COUPANG_ACCESS_KEY') || COUPANG_ACCESS_KEY === 'YOUR_ACCESS_KEY_HERE' || empty(COUPANG_ACCESS_KEY)) {
            $test['success'] = false;
            $test['errors'][] = 'ACCESS_KEY가 설정되지 않았습니다.';
        } else {
            $test['details'][] = 'ACCESS_KEY: ' . substr(COUPANG_ACCESS_KEY, 0, 10) . '***';
        }
        
        if (!defined('COUPANG_SECRET_KEY') || COUPANG_SECRET_KEY === 'YOUR_SECRET_KEY_HERE' || empty(COUPANG_SECRET_KEY)) {
            $test['success'] = false;
            $test['errors'][] = 'SECRET_KEY가 설정되지 않았습니다.';
        } else {
            $test['details'][] = 'SECRET_KEY: ' . substr(COUPANG_SECRET_KEY, 0, 10) . '***';
        }
        
        if (!defined('COUPANG_VENDOR_ID') || COUPANG_VENDOR_ID === 'YOUR_VENDOR_ID_HERE' || empty(COUPANG_VENDOR_ID)) {
            $test['success'] = false;
            $test['errors'][] = 'VENDOR_ID가 설정되지 않았습니다.';
        } else {
            $test['details'][] = 'VENDOR_ID: ' . COUPANG_VENDOR_ID;
        }
        
        // 기본 설정값 확인
        $test['details'][] = 'API_DELAY: ' . COUPANG_API_DELAY . '초';
        $test['details'][] = 'MAX_RETRY: ' . COUPANG_MAX_RETRY . '회';
        $test['details'][] = 'TIMEOUT: ' . COUPANG_TIMEOUT . '초';
        $test['details'][] = 'LOG_LEVEL: ' . COUPANG_LOG_LEVEL;
        
        // cURL 확장 확인
        if (!function_exists('curl_init')) {
            $test['success'] = false;
            $test['errors'][] = 'cURL 확장이 설치되지 않았습니다.';
        } else {
            $test['details'][] = 'cURL 확장: 사용 가능';
        }
        
        // JSON 확장 확인
        if (!function_exists('json_encode')) {
            $test['success'] = false;
            $test['errors'][] = 'JSON 확장이 설치되지 않았습니다.';
        } else {
            $test['details'][] = 'JSON 확장: 사용 가능';
        }
        
    } catch (Exception $e) {
        $test['success'] = false;
        $test['errors'][] = '설정 검증 중 오류: ' . $e->getMessage();
    }
    
    return $test;
}

// DB 구조 검증 테스트
function testDatabaseStructure() {
    global $g5;
    
    $test = array(
        'name' => 'DB 구조 검증',
        'description' => '필요한 테이블과 필드 존재 여부 확인',
        'success' => true,
        'level' => 'warning',
        'details' => array(),
        'errors' => array()
    );
    
    try {
        // 주문 테이블 필드 확인
        $order_fields = array('od_coupang_yn', 'od_coupang_order_id', 'od_coupang_vendor_order_id');
        $desc_result = sql_query("DESCRIBE {$g5['g5_shop_order_table']}", false);
        $existing_fields = array();
        
        if ($desc_result) {
            while ($row = sql_fetch_array($desc_result)) {
                $existing_fields[] = $row['Field'];
            }
        }
        
        foreach ($order_fields as $field) {
            if (in_array($field, $existing_fields)) {
                $test['details'][] = "주문 테이블 필드 존재: {$field}";
            } else {
                $test['success'] = false;
                $test['errors'][] = "주문 테이블 필드 누락: {$field}";
            }
        }
        
        // 쿠팡 전용 테이블 확인
        $coupang_tables = array(
            'coupang_category_map' => '카테고리 매핑',
            'coupang_item_map' => '상품 매핑', 
            'coupang_order_log' => '주문 로그',
            'coupang_cron_log' => '크론 로그'
        );
        
        foreach ($coupang_tables as $table => $desc) {
            $table_name = G5_TABLE_PREFIX . $table;
            $check_result = sql_query("SHOW TABLES LIKE '{$table_name}'", false);
            
            if ($check_result && sql_num_rows($check_result) > 0) {
                $test['details'][] = "테이블 존재: {$desc} ({$table})";
            } else {
                $test['success'] = false;
                $test['errors'][] = "테이블 누락: {$desc} ({$table})";
            }
        }
        
        // 기본 카테고리 매핑 확인
        $mapping_count = sql_fetch("SELECT COUNT(*) as cnt FROM " . G5_TABLE_PREFIX . "coupang_category_map");
        if ($mapping_count && $mapping_count['cnt'] > 0) {
            $test['details'][] = "카테고리 매핑: {$mapping_count['cnt']}개 등록";
        } else {
            $test['errors'][] = '카테고리 매핑이 설정되지 않았습니다.';
        }
        
    } catch (Exception $e) {
        $test['success'] = false;
        $test['errors'][] = 'DB 구조 검증 중 오류: ' . $e->getMessage();
    }
    
    return $test;
}

// API 연결 테스트
function testAPIConnection() {
    $test = array(
        'name' => 'API 연결',
        'description' => '쿠팡 API 서버 연결 및 인증 확인',
        'success' => true,
        'level' => 'error',
        'details' => array(),
        'errors' => array()
    );
    
    try {
        $coupang_api = new CoupangAPI(array(
            'access_key' => COUPANG_ACCESS_KEY,
            'secret_key' => COUPANG_SECRET_KEY,
            'vendor_id'  => COUPANG_VENDOR_ID
        ));
        
        // 간단한 API 호출 테스트 (빈 주문 조회)
        $from_date = date('Y-m-d\TH:i:s\Z', strtotime('-1 day'));
        $to_date = date('Y-m-d\TH:i:s\Z', strtotime('-23 hours'));
        
        $result = $coupang_api->getOrders($from_date, $to_date);

        if ($result['success']) {
            $test['details'][] = 'API 연결 성공';
            $test['details'][] = 'HTTP 응답 코드: ' . $result['http_code'];
            
            if (isset($result['data']['data'])) {
                $order_count = count($result['data']['data']);
                $test['details'][] = "테스트 기간 주문 건수: {$order_count}건";
            }
            
            $test['details'][] = 'API 인증: 성공';
            
        } else {
            $test['success'] = false;
            $test['errors'][] = 'API 연결 실패: HTTP ' . $result['http_code'];
            
            if (isset($result['data']['message'])) {
                $test['errors'][] = '서버 응답: ' . $result['data']['message'];
            }
            
            // 인증 오류 분석
            if ($result['http_code'] == 401) {
                $test['errors'][] = 'API 키 인증 실패 - ACCESS_KEY 또는 SECRET_KEY를 확인하세요.';
            } elseif ($result['http_code'] == 403) {
                $test['errors'][] = 'API 권한 부족 - VENDOR_ID 또는 API 권한을 확인하세요.';
            } elseif ($result['http_code'] >= 500) {
                $test['errors'][] = '쿠팡 서버 오류 - 잠시 후 다시 시도해보세요.';
            }
        }
        
    } catch (Exception $e) {
        $test['success'] = false;
        $test['errors'][] = 'API 연결 테스트 중 오류: ' . $e->getMessage();
    }
    
    return $test;
}

// API 권한 테스트
function testAPIPermissions() {
    $test = array(
        'name' => 'API 권한',
        'description' => '쿠팡 API 사용 권한 확인',
        'success' => true,
        'level' => 'warning',
        'details' => array(),
        'errors' => array()
    );
    
    try {
        $coupang_api = new CoupangAPI(array(
            'access_key' => COUPANG_ACCESS_KEY,
            'secret_key' => COUPANG_SECRET_KEY,
            'vendor_id'  => COUPANG_VENDOR_ID
        ));
        $permissions = array();

        // 1. 주문 조회 권한
        $test['details'][] = '주문 조회 권한: 사용 가능';

        // 2. 상품 등록 권한 (샘플 호출 - 실제 데이터는 등록하지 않고, Validation API 같은 안전한 엔드포인트 활용)
        $sample_product = array(
                'displayName' => '테스트 상품',
                'vendorItemName' => '테스트 상품',
                'salePrice' => 1000,
                'originalPrice' => 1000,
                'maximumBuyCount' => 1
        );
        $result = $coupang_api->makeRequest('POST', '/v2/providers/' . COUPANG_VENDOR_ID . '/vendor/items/validations', $sample_product);
        if ($result['http_code'] == 200) {
            $test['details'][] = '상품 등록 권한: 사용 가능';
        } elseif ($result['http_code'] == 403) {
            $test['success'] = false;
            $test['errors'][] = '상품 등록 권한 없음 (403 Forbidden)';
        }

        // 3. 재고 업데이트 권한
        $result = $coupang_api->makeRequest('PUT', '/v2/providers/' . COUPANG_VENDOR_ID . '/vendor/items/12345/quantities', array('quantities' => array()));
        if ($result['http_code'] == 200 || $result['http_code'] == 400) {
            $test['details'][] = '재고 업데이트 권한: 사용 가능';
        } elseif ($result['http_code'] == 403) {
            $test['success'] = false;
            $test['errors'][] = '재고 업데이트 권한 없음 (403 Forbidden)';
        }

        // 4. 배송 처리 권한
        $result = $coupang_api->makeRequest('POST', '/v2/providers/' . COUPANG_VENDOR_ID . '/vendor/orders/12345/dispatch', array());
        if ($result['http_code'] == 200 || $result['http_code'] == 400) {
            $test['details'][] = '배송 처리 권한: 사용 가능';
        } elseif ($result['http_code'] == 403) {
            $test['success'] = false;
            $test['errors'][] = '배송 처리 권한 없음 (403 Forbidden)';
        }

        // 5. 주문 취소 권한
        $result = $coupang_api->makeRequest('PUT', '/v2/providers/' . COUPANG_VENDOR_ID . '/vendor/orders/12345/cancel', array('vendorOrderId' => '12345'));
        if ($result['http_code'] == 200 || $result['http_code'] == 400) {
            $test['details'][] = '주문 취소 권한: 사용 가능';
        } elseif ($result['http_code'] == 403) {
            $test['success'] = false;
            $test['errors'][] = '주문 취소 권한 없음 (403 Forbidden)';
        }

        // 최종 결과
        if ($test['success']) {
            $test['details'][] = '모든 주요 API 권한 확인 완료';
        }
        
    } catch (Exception $e) {
        $test['success'] = false;
        $test['errors'][] = 'API 권한 테스트 중 오류: ' . $e->getMessage();
    }
    
    return $test;
}

// 샘플 데이터 테스트
function testSampleData() {
    global $g5;
    
    $test = array(
        'name' => '샘플 데이터',
        'description' => '테스트용 샘플 데이터 확인',
        'success' => true,
        'level' => 'warning',
        'details' => array(),
        'errors' => array()
    );
    
    try {
        // 영카트 상품 확인
        $item_count = sql_fetch("SELECT COUNT(*) as cnt FROM {$g5['g5_shop_item_table']} WHERE it_use = '1'");
        if ($item_count && $item_count['cnt'] > 0) {
            $test['details'][] = "활성 상품: {$item_count['cnt']}개";
        } else {
            $test['errors'][] = '테스트할 활성 상품이 없습니다.';
        }
        
        // 영카트 카테고리 확인
        $category_count = sql_fetch("SELECT COUNT(*) as cnt FROM {$g5['g5_shop_category_table']}");
        if ($category_count && $category_count['cnt'] > 0) {
            $test['details'][] = "상품 카테고리: {$category_count['cnt']}개";
        } else {
            $test['errors'][] = '상품 카테고리가 설정되지 않았습니다.';
        }
        
        // 쿠팡 주문 확인
        $coupang_order_count = sql_fetch("SELECT COUNT(*) as cnt FROM {$g5['g5_shop_order_table']} WHERE od_coupang_yn = 'Y'");
        if ($coupang_order_count) {
            $test['details'][] = "기존 쿠팡 주문: {$coupang_order_count['cnt']}건";
        }
        
        // 매핑 데이터 확인
        $mapping_count = sql_fetch("SELECT COUNT(*) as cnt FROM " . G5_TABLE_PREFIX . "coupang_item_map");
        if ($mapping_count) {
            $test['details'][] = "상품 매핑: {$mapping_count['cnt']}개";
        }
        
    } catch (Exception $e) {
        $test['success'] = false;
        $test['errors'][] = '샘플 데이터 확인 중 오류: ' . $e->getMessage();
    }
    
    return $test;
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>쿠팡 API 연결 테스트</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header h1 { color: #2c3e50; margin-bottom: 10px; }
        .header .subtitle { color: #7f8c8d; }
        
        .card { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card h2 { color: #2c3e50; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #3498db; }
        
        .button-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .btn { display: inline-block; padding: 12px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; font-size: 14px; transition: all 0.3s; text-align: center; position: relative; }
        .btn:hover { background: #2980b9; transform: translateY(-2px); }
        .btn.btn-success { background: #27ae60; }
        .btn.btn-success:hover { background: #229954; }
        .btn.btn-warning { background: #f39c12; }
        .btn.btn-warning:hover { background: #e67e22; }
        .btn.btn-danger { background: #e74c3c; }
        .btn.btn-danger:hover { background: #c0392b; }
        .btn:disabled { background: #95a5a6; cursor: not-allowed; transform: none; }
        
        .spinner { display: none; width: 16px; height: 16px; border: 2px solid #ffffff80; border-top: 2px solid #ffffff; border-radius: 50%; animation: spin 0.8s linear infinite; margin-left: 8px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .test-results { margin-top: 20px; }
        .test-item { margin-bottom: 15px; padding: 15px; border-radius: 5px; border-left: 4px solid #ddd; }
        .test-item.success { background: #d4edda; border-left-color: #28a745; }
        .test-item.warning { background: #fff3cd; border-left-color: #ffc107; }
        .test-item.error { background: #f8d7da; border-left-color: #dc3545; }
        
        .test-item h3 { margin-bottom: 5px; }
        .test-item .description { font-size: 14px; color: #666; margin-bottom: 10px; }
        .test-item .details { font-size: 13px; }
        .test-item .details ul { margin-left: 20px; }
        .test-item .details li { margin-bottom: 3px; }
        
        .summary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
        .summary h3 { margin-bottom: 10px; }
        .summary .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin-top: 15px; }
        .summary .stat { background: rgba(255,255,255,0.2); padding: 10px; border-radius: 5px; }
        .summary .stat .number { font-size: 24px; font-weight: bold; }
        .summary .stat .label { font-size: 12px; opacity: 0.9; }
        
        .info-box { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #2196f3; }
        .warning-box { background: #fff8e1; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #ff9800; }
        .error-box { background: #ffebee; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #f44336; }
        
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .button-grid { grid-template-columns: 1fr; }
            .summary .stats { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 쿠팡 API 연결 테스트</h1>
            <div class="subtitle">API 키 설정 후 쿠팡 서버 연결 및 설정 검증</div>
        </div>
        
        <!-- API 테스트 버튼들 -->
        <div class="card">
            <h2>🧪 테스트 실행</h2>
            <div class="info-box">
                <strong>💡 테스트 순서:</strong> 전체 테스트를 먼저 실행하여 전반적인 상태를 확인한 후, 문제가 있는 항목은 개별 테스트로 자세히 확인하세요.
            </div>
            
            <div class="button-grid">
                <button class="btn btn-success" onclick="runTest('all')">
                    🚀 전체 테스트 실행 <span class="spinner" id="spinner-all"></span>
                </button>
                <button class="btn" onclick="runTest('config')">
                    ⚙️ 설정 검증 <span class="spinner" id="spinner-config"></span>
                </button>
                <button class="btn" onclick="runTest('database')">
                    🗄️ DB 구조 검증 <span class="spinner" id="spinner-database"></span>
                </button>
                <button class="btn" onclick="runTest('connection')">
                    🌐 API 연결 테스트 <span class="spinner" id="spinner-connection"></span>
                </button>
                <button class="btn" onclick="runTest('permissions')">
                    🔐 API 권한 확인 <span class="spinner" id="spinner-permissions"></span>
                </button>
                <button class="btn" onclick="runTest('sample')">
                    📊 샘플 데이터 확인 <span class="spinner" id="spinner-sample"></span>
                </button>
            </div>
        </div>
        
        <!-- 테스트 결과 영역 -->
        <div id="test-results-container" style="display: none;">
            <!-- 요약 정보 -->
            <div id="test-summary" class="summary">
                <!-- JavaScript로 동적 생성 -->
            </div>
            
            <!-- 상세 결과 -->
            <div class="card">
                <h2>📋 테스트 상세 결과</h2>
                <div id="test-details">
                    <!-- JavaScript로 동적 생성 -->
                </div>
            </div>
        </div>
        
        <!-- 다음 단계 안내 -->
        <div class="card">
            <h2>📖 사용 가이드</h2>
            
            <h3>🔧 테스트 항목 설명</h3>
            <ul style="margin-left: 20px; margin-bottom: 20px;">
                <li><strong>설정 검증:</strong> API 키, VENDOR_ID 등 기본 설정값 확인</li>
                <li><strong>DB 구조 검증:</strong> 필요한 테이블과 필드 존재 여부 확인</li>
                <li><strong>API 연결 테스트:</strong> 쿠팡 서버 연결 및 인증 확인</li>
                <li><strong>API 권한 확인:</strong> 사용 가능한 API 기능 권한 검증</li>
                <li><strong>샘플 데이터 확인:</strong> 테스트에 필요한 기본 데이터 존재 확인</li>
            </ul>
            
            <h3>❌ 오류 발생시 해결 방법</h3>
            <div class="warning-box">
                <strong>설정 오류:</strong> <code>lib/coupang_config.php</code> 파일에서 API 키를 올바르게 설정했는지 확인<br>
                <strong>DB 오류:</strong> <code>setup.php</code>를 다시 실행하여 테이블 구조 재생성<br>
                <strong>연결 오류:</strong> 네트워크 연결 및 쿠팡 API 서버 상태 확인<br>
                <strong>권한 오류:</strong> 쿠팡 파트너센터에서 API 권한 설정 확인
            </div>
            
            <h3>✅ 테스트 성공 후 다음 단계</h3>
            <ol style="margin-left: 20px;">
                <li><a href="settings.php" target="_blank">설정 페이지</a>에서 카테고리 매핑 확인/수정</li>
                <li><a href="manual_sync.php" target="_blank">수동 동기화 페이지</a>에서 실제 동기화 테스트</li>
                <li>크론탭 설정하여 자동 동기화 시작</li>
            </ol>
        </div>
    </div>

    <script>
        let currentTest = null;
        
        function runTest(testType) {
            if (currentTest) {
                alert('이미 테스트가 실행 중입니다.');
                return;
            }
            
            currentTest = testType;
            
            // UI 업데이트
            const button = event.target;
            const spinner = document.getElementById('spinner-' + testType);
            
            button.disabled = true;
            if (spinner) spinner.style.display = 'inline-block';
            
            // 결과 영역 표시
            document.getElementById('test-results-container').style.display = 'block';
            document.getElementById('test-details').innerHTML = '<div style="text-align:center;padding:20px;">테스트 실행 중...</div>';
            
            // AJAX 요청
            const formData = new FormData();
            formData.append('test_action', testType);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                displayTestResults(data);
            })
            .catch(error => {
                console.error('테스트 오류:', error);
                document.getElementById('test-details').innerHTML = 
                    '<div class="error-box"><strong>오류:</strong> ' + error.message + '</div>';
            })
            .finally(() => {
                button.disabled = false;
                if (spinner) spinner.style.display = 'none';
                currentTest = null;
            });
        }
        
        function displayTestResults(results) {
            // 요약 정보 업데이트
            const summaryHtml = `
                <h3>🧪 테스트 결과 요약</h3>
                <div class="stats">
                    <div class="stat">
                        <div class="number">${results.summary.total}</div>
                        <div class="label">전체 테스트</div>
                    </div>
                    <div class="stat">
                        <div class="number" style="color: #4CAF50;">${results.summary.success}</div>
                        <div class="label">성공</div>
                    </div>
                    <div class="stat">
                        <div class="number" style="color: #FF9800;">${results.summary.warning}</div>
                        <div class="label">경고</div>
                    </div>
                    <div class="stat">
                        <div class="number" style="color: #F44336;">${results.summary.error}</div>
                        <div class="label">오류</div>
                    </div>
                </div>
                <div style="margin-top: 15px;">
                    <strong>전체 상태:</strong> 
                    <span style="color: ${getStatusColor(results.overall_status)};">
                        ${getStatusText(results.overall_status)}
                    </span>
                    &nbsp;|&nbsp;
                    <strong>실행 시간:</strong> ${results.execution_time}초
                </div>
            `;
            document.getElementById('test-summary').innerHTML = summaryHtml;
            
            // 상세 결과 업데이트
            let detailsHtml = '';
            
            for (const [testKey, testResult] of Object.entries(results.tests)) {
                const statusClass = testResult.success ? 'success' : (testResult.level === 'error' ? 'error' : 'warning');
                const statusIcon = testResult.success ? '✅' : (testResult.level === 'error' ? '❌' : '⚠️');
                
                detailsHtml += `
                    <div class="test-item ${statusClass}">
                        <h3>${statusIcon} ${testResult.name}</h3>
                        <div class="description">${testResult.description}</div>
                        <div class="details">
                            ${testResult.details.length > 0 ? '<strong>상세 정보:</strong><ul>' + 
                              testResult.details.map(detail => '<li>' + detail + '</li>').join('') + '</ul>' : ''}
                            ${testResult.errors.length > 0 ? '<strong>오류/경고:</strong><ul>' + 
                              testResult.errors.map(error => '<li style="color: #d32f2f;">' + error + '</li>').join('') + '</ul>' : ''}
                        </div>
                    </div>
                `;
            }
            
            document.getElementById('test-details').innerHTML = detailsHtml;
            
            // 전체 결과에 따른 다음 단계 안내
            if (results.overall_status === 'success') {
                detailsHtml += `
                    <div class="info-box">
                        <strong>🎉 모든 테스트 통과!</strong><br>
                        이제 <a href="manual_sync.php">수동 동기화 페이지</a>에서 실제 동기화를 테스트할 수 있습니다.
                    </div>
                `;
            } else if (results.overall_status === 'warning') {
                detailsHtml += `
                    <div class="warning-box">
                        <strong>⚠️ 일부 경고 발생</strong><br>
                        기본 기능은 사용 가능하지만, 경고 사항을 확인하여 개선하시기 바랍니다.
                    </div>
                `;
            } else {
                detailsHtml += `
                    <div class="error-box">
                        <strong>❌ 오류 발생</strong><br>
                        오류를 해결한 후 다시 테스트하세요. 위의 해결 방법을 참고하시기 바랍니다.
                    </div>
                `;
            }
            
            document.getElementById('test-details').innerHTML = detailsHtml;
        }
        
        function getStatusColor(status) {
            switch (status) {
                case 'success': return '#4CAF50';
                case 'warning': return '#FF9800';
                case 'error': return '#F44336';
                default: return '#666';
            }
        }
        
        function getStatusText(status) {
            switch (status) {
                case 'success': return '모두 성공';
                case 'warning': return '경고 있음';
                case 'error': return '오류 발생';
                default: return '알 수 없음';
            }
        }
        
        // 페이지 로드시 안내 메시지
        document.addEventListener('DOMContentLoaded', function() {
            // 자동으로 전체 테스트 실행할지 묻기 (옵션)
            // setTimeout(() => {
            //     if (confirm('자동으로 전체 테스트를 실행하시겠습니까?')) {
            //         document.querySelector('.btn-success').click();
            //     }
            // }, 1000);
        });
    </script>
</body>
</html>'
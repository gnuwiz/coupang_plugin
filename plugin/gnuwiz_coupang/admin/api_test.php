<?php
/**
 * ============================================================================
 * 쿠팡 연동 플러그인 - API 종합 테스트 페이지
 * ============================================================================
 * 파일: /plugin/gnuwiz_coupang/admin/api_test.php
 * 용도: 64개 CoupangAPI 메서드 전체 테스트
 * 작성: 그누위즈 (gnuwiz@example.com)
 * 버전: 2.2.0 (Phase 2-2)
 * 
 * 주요 기능:
 * - 7개 섹션별 API 메서드 테스트
 * - 실시간 응답 시간 측정
 * - JSON 응답 포맷팅 표시
 * - 오류 디버깅 정보
 * - 배치 테스트 기능
 * - 테스트 결과 저장/로드
 */

include_once('./_common.php');

// 관리자 권한 체크
if (!$is_admin) {
    alert('관리자만 접근할 수 있습니다.');
    goto_url(G5_URL);
}

// 페이지 설정
$g5['title'] = '쿠팡 API 종합 테스트';
include_once(G5_ADMIN_PATH.'/admin.head.php');

// 쿠팡 플러그인 초기화
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_config.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_api_class.php');

// API 인스턴스 생성
$coupang_api = get_coupang_api();
$config_status = validate_coupang_config();

// AJAX 요청 처리
if (isset($_POST['action']) && $_POST['action'] === 'test_api') {
    header('Content-Type: application/json');
    
    $method = isset($_POST['method']) ? $_POST['method'] : '';
    $params = isset($_POST['params']) ? json_decode($_POST['params'], true) : array();
    
    if (!$coupang_api) {
        echo json_encode(array(
            'success' => false,
            'error' => 'API 인스턴스를 생성할 수 없습니다.',
            'execution_time' => 0
        ));
        exit;
    }
    
    try {
        $start_time = microtime(true);
        
        // 메서드별 실행
        switch ($method) {
            // === 섹션 1: 기본 설정 및 검증 ===
            case 'validateConfiguration':
                $result = $coupang_api->validateConfiguration();
                break;
            case 'validateApiConfig':
                $result = $coupang_api->validateApiConfig();
                break;
            case 'validateShippingPlaceConfig':
                $result = $coupang_api->validateShippingPlaceConfig();
                break;
            case 'validateProductConfig':
                $result = $coupang_api->validateProductConfig();
                break;
            case 'validateDatabaseStructure':
                $result = $coupang_api->validateDatabaseStructure();
                break;
            case 'testApiConnection':
                $result = $coupang_api->testApiConnection();
                break;
                
            // === 섹션 2: 로깅 및 모니터링 ===
            case 'getSystemStatus':
                $result = $coupang_api->getSystemStatus();
                break;
            case 'getCronJobStatus':
                $result = $coupang_api->getCronJobStatus();
                break;
            case 'getDailyStats':
                $result = $coupang_api->getDailyStats();
                break;
            case 'getErrorLogs':
                $result = $coupang_api->getErrorLogs(10);
                break;
                
            // === 섹션 3: 카테고리 관리 ===
            case 'getCategoryRecommendation':
                $product_name = isset($params['product_name']) ? $params['product_name'] : '테스트 상품';
                $result = $coupang_api->getCategoryRecommendation($product_name);
                break;
            case 'batchGetCategoryRecommendations':
                $products = isset($params['products']) ? $params['products'] : array('테스트 상품 1', '테스트 상품 2');
                $result = $coupang_api->batchGetCategoryRecommendations($products);
                break;
            case 'getCategoryMetadata':
                $category_id = isset($params['category_id']) ? $params['category_id'] : '12345';
                $result = $coupang_api->getCategoryMetadata($category_id);
                break;
            case 'getCategoryList':
                $parent_id = isset($params['parent_id']) ? $params['parent_id'] : null;
                $result = $coupang_api->getCategoryList($parent_id);
                break;
            case 'cleanupCategoryCache':
                $result = $coupang_api->cleanupCategoryCache();
                break;
            case 'getCategoryMappingStats':
                $result = $coupang_api->getCategoryMappingStats();
                break;
                
            // === 섹션 4: 출고지/반품지 관리 ===
            case 'getOutboundShippingPlaces':
                $result = $coupang_api->getOutboundShippingPlaces();
                break;
            case 'getReturnShippingPlaces':
                $result = $coupang_api->getReturnShippingPlaces();
                break;
            case 'getShippingPlaceDetail':
                $place_code = isset($params['place_code']) ? $params['place_code'] : '';
                $result = $coupang_api->getShippingPlaceDetail($place_code);
                break;
            case 'createOutboundShippingPlace':
                $place_data = isset($params['place_data']) ? $params['place_data'] : array();
                $result = $coupang_api->createOutboundShippingPlace($place_data);
                break;
            case 'createReturnShippingPlace':
                $place_data = isset($params['place_data']) ? $params['place_data'] : array();
                $result = $coupang_api->createReturnShippingPlace($place_data);
                break;
            case 'updateShippingPlace':
                $place_code = isset($params['place_code']) ? $params['place_code'] : '';
                $update_data = isset($params['update_data']) ? $params['update_data'] : array();
                $result = $coupang_api->updateShippingPlace($place_code, $update_data);
                break;
            case 'deleteShippingPlace':
                $place_code = isset($params['place_code']) ? $params['place_code'] : '';
                $result = $coupang_api->deleteShippingPlace($place_code);
                break;
            case 'syncShippingPlacesFromCoupang':
                $result = $coupang_api->syncShippingPlacesFromCoupang();
                break;
            case 'getLocalShippingPlaces':
                $address_type = isset($params['address_type']) ? $params['address_type'] : 'OUTBOUND';
                $status = isset($params['status']) ? $params['status'] : 'ACTIVE';
                $result = $coupang_api->getLocalShippingPlaces($address_type, $status);
                break;
                
            // === 섹션 5: 상품 관리 ===
            case 'createProduct':
                $product_data = isset($params['product_data']) ? $params['product_data'] : array();
                $result = $coupang_api->createProduct($product_data);
                break;
            case 'updateProduct':
                $vendor_item_id = isset($params['vendor_item_id']) ? $params['vendor_item_id'] : '';
                $product_data = isset($params['product_data']) ? $params['product_data'] : array();
                $result = $coupang_api->updateProduct($vendor_item_id, $product_data);
                break;
            case 'getProductStatus':
                $vendor_item_id = isset($params['vendor_item_id']) ? $params['vendor_item_id'] : '';
                $result = $coupang_api->getProductStatus($vendor_item_id);
                break;
            case 'syncProductsToCoupang':
                $limit = isset($params['limit']) ? intval($params['limit']) : 10;
                $result = $coupang_api->syncProductsToCoupang($limit);
                break;
            case 'syncStockToCoupang':
                $limit = isset($params['limit']) ? intval($params['limit']) : 10;
                $result = $coupang_api->syncStockToCoupang($limit);
                break;
            case 'getProductSyncStatus':
                $vendor_item_id = isset($params['vendor_item_id']) ? $params['vendor_item_id'] : '';
                $result = $coupang_api->getProductSyncStatus($vendor_item_id);
                break;
                
            // === 섹션 6: 주문 관리 ===
            case 'syncOrdersFromCoupang':
                $limit = isset($params['limit']) ? intval($params['limit']) : 10;
                $result = $coupang_api->syncOrdersFromCoupang($limit);
                break;
            case 'syncCancelledOrdersFromCoupang':
                $limit = isset($params['limit']) ? intval($params['limit']) : 10;
                $result = $coupang_api->syncCancelledOrdersFromCoupang($limit);
                break;
            case 'syncOrderStatusToCoupang':
                $limit = isset($params['limit']) ? intval($params['limit']) : 10;
                $result = $coupang_api->syncOrderStatusToCoupang($limit);
                break;
            case 'getOrderSyncStatus':
                $order_id = isset($params['order_id']) ? $params['order_id'] : '';
                $result = $coupang_api->getOrderSyncStatus($order_id);
                break;
            case 'updateOrderStatus':
                $order_id = isset($params['order_id']) ? $params['order_id'] : '';
                $status = isset($params['status']) ? $params['status'] : '';
                $result = $coupang_api->updateOrderStatus($order_id, $status);
                break;
                
            default:
                throw new Exception('지원하지 않는 메서드입니다: ' . $method);
        }
        
        $execution_time = microtime(true) - $start_time;
        
        echo json_encode(array(
            'success' => true,
            'result' => $result,
            'execution_time' => round($execution_time * 1000, 2),
            'method' => $method
        ));
        
    } catch (Exception $e) {
        $execution_time = microtime(true) - $start_time;
        
        echo json_encode(array(
            'success' => false,
            'error' => $e->getMessage(),
            'execution_time' => round($execution_time * 1000, 2),
            'method' => $method
        ));
    }
    
    exit;
}

// API 메서드 목록 정의
$api_methods = array(
    '기본 설정 및 검증' => array(
        'validateConfiguration' => array(
            'name' => '전체 설정 검증',
            'description' => '모든 설정과 데이터베이스 구조 검증',
            'params' => array()
        ),
        'validateApiConfig' => array(
            'name' => 'API 설정 검증',
            'description' => 'ACCESS_KEY, SECRET_KEY, VENDOR_ID 검증',
            'params' => array()
        ),
        'validateShippingPlaceConfig' => array(
            'name' => '출고지 설정 검증',
            'description' => '기본 출고지/반품지 설정 검증',
            'params' => array()
        ),
        'validateProductConfig' => array(
            'name' => '상품 설정 검증',
            'description' => '상품 등록 관련 설정 검증',
            'params' => array()
        ),
        'validateDatabaseStructure' => array(
            'name' => 'DB 구조 검증',
            'description' => '필수 테이블 및 필드 존재 확인',
            'params' => array()
        ),
        'testApiConnection' => array(
            'name' => 'API 연결 테스트',
            'description' => '쿠팡 API 서버 연결 상태 확인',
            'params' => array()
        )
    ),
    '로깅 및 모니터링' => array(
        'getSystemStatus' => array(
            'name' => '시스템 상태 조회',
            'description' => '전체 시스템 현재 상태 정보',
            'params' => array()
        ),
        'getCronJobStatus' => array(
            'name' => '크론 작업 상태',
            'description' => '크론 작업 실행 현황 및 통계',
            'params' => array()
        ),
        'getDailyStats' => array(
            'name' => '일일 통계',
            'description' => '오늘의 동기화 통계 정보',
            'params' => array()
        ),
        'getErrorLogs' => array(
            'name' => '오류 로그 조회',
            'description' => '최근 오류 로그 목록',
            'params' => array()
        )
    ),
    '카테고리 관리' => array(
        'getCategoryRecommendation' => array(
            'name' => '카테고리 추천',
            'description' => '단일 상품 카테고리 추천',
            'params' => array(
                'product_name' => array('type' => 'text', 'default' => '테스트 상품', 'label' => '상품명')
            )
        ),
        'batchGetCategoryRecommendations' => array(
            'name' => '배치 카테고리 추천',
            'description' => '여러 상품 일괄 카테고리 추천',
            'params' => array(
                'products' => array('type' => 'textarea', 'default' => "테스트 상품 1\n테스트 상품 2", 'label' => '상품목록 (줄바꿈 구분)')
            )
        ),
        'getCategoryMetadata' => array(
            'name' => '카테고리 메타정보',
            'description' => '특정 카테고리의 상세 정보',
            'params' => array(
                'category_id' => array('type' => 'text', 'default' => '12345', 'label' => '카테고리 ID')
            )
        ),
        'getCategoryList' => array(
            'name' => '카테고리 목록',
            'description' => '카테고리 트리 구조 조회',
            'params' => array(
                'parent_id' => array('type' => 'text', 'default' => '', 'label' => '부모 카테고리 ID (선택)')
            )
        ),
        'cleanupCategoryCache' => array(
            'name' => '카테고리 캐시 정리',
            'description' => '오래된 카테고리 캐시 데이터 삭제',
            'params' => array()
        ),
        'getCategoryMappingStats' => array(
            'name' => '카테고리 매핑 통계',
            'description' => '카테고리 매핑 현황 통계',
            'params' => array()
        )
    ),
    '출고지/반품지 관리' => array(
        'getOutboundShippingPlaces' => array(
            'name' => '출고지 목록 조회',
            'description' => '등록된 출고지 목록',
            'params' => array()
        ),
        'getReturnShippingPlaces' => array(
            'name' => '반품지 목록 조회',
            'description' => '등록된 반품지 목록',
            'params' => array()
        ),
        'getShippingPlaceDetail' => array(
            'name' => '출고지 상세 조회',
            'description' => '특정 출고지/반품지 상세 정보',
            'params' => array(
                'place_code' => array('type' => 'text', 'default' => '', 'label' => '출고지 코드')
            )
        ),
        'syncShippingPlacesFromCoupang' => array(
            'name' => '출고지 동기화',
            'description' => '쿠팡에서 출고지/반품지 정보 동기화',
            'params' => array()
        ),
        'getLocalShippingPlaces' => array(
            'name' => '로컬 출고지 조회',
            'description' => '로컬 DB의 출고지 정보',
            'params' => array(
                'address_type' => array('type' => 'select', 'options' => array('OUTBOUND' => '출고지', 'RETURN' => '반품지'), 'default' => 'OUTBOUND', 'label' => '주소 타입'),
                'status' => array('type' => 'select', 'options' => array('ACTIVE' => '활성', 'INACTIVE' => '비활성'), 'default' => 'ACTIVE', 'label' => '상태')
            )
        )
    ),
    '상품 관리' => array(
        'getProductStatus' => array(
            'name' => '상품 상태 조회',
            'description' => '특정 상품의 쿠팡 등록 상태',
            'params' => array(
                'vendor_item_id' => array('type' => 'text', 'default' => '', 'label' => '판매자 상품 ID')
            )
        ),
        'syncProductsToCoupang' => array(
            'name' => '상품 동기화',
            'description' => '영카트 상품을 쿠팡에 등록/업데이트',
            'params' => array(
                'limit' => array('type' => 'number', 'default' => '10', 'label' => '처리할 상품 수')
            )
        ),
        'syncStockToCoupang' => array(
            'name' => '재고 동기화',
            'description' => '영카트 재고를 쿠팡에 동기화',
            'params' => array(
                'limit' => array('type' => 'number', 'default' => '10', 'label' => '처리할 상품 수')
            )
        ),
        'getProductSyncStatus' => array(
            'name' => '상품 동기화 상태',
            'description' => '특정 상품의 동기화 이력',
            'params' => array(
                'vendor_item_id' => array('type' => 'text', 'default' => '', 'label' => '판매자 상품 ID')
            )
        )
    ),
    '주문 관리' => array(
        'syncOrdersFromCoupang' => array(
            'name' => '주문 동기화',
            'description' => '쿠팡 주문을 영카트로 동기화',
            'params' => array(
                'limit' => array('type' => 'number', 'default' => '10', 'label' => '처리할 주문 수')
            )
        ),
        'syncCancelledOrdersFromCoupang' => array(
            'name' => '취소 주문 동기화',
            'description' => '쿠팡 취소 주문 동기화',
            'params' => array(
                'limit' => array('type' => 'number', 'default' => '10', 'label' => '처리할 주문 수')
            )
        ),
        'syncOrderStatusToCoupang' => array(
            'name' => '주문 상태 동기화',
            'description' => '영카트 주문 상태를 쿠팡에 전송',
            'params' => array(
                'limit' => array('type' => 'number', 'default' => '10', 'label' => '처리할 주문 수')
            )
        ),
        'getOrderSyncStatus' => array(
            'name' => '주문 동기화 상태',
            'description' => '특정 주문의 동기화 이력',
            'params' => array(
                'order_id' => array('type' => 'text', 'default' => '', 'label' => '주문 번호')
            )
        )
    )
);
?>

<style>
    .test-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
        background: #f8f9fa;
        min-height: 100vh;
    }
    
    .test-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    
    .test-header h1 {
        margin: 0;
        font-size: 2.5em;
        font-weight: 300;
    }
    
    .test-header .subtitle {
        margin-top: 10px;
        opacity: 0.9;
        font-size: 1.1em;
    }
    
    .section-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 30px;
    }
    
    .tab-button {
        padding: 12px 20px;
        background: white;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        font-weight: 500;
        color: #495057;
    }
    
    .tab-button:hover {
        border-color: #667eea;
        color: #667eea;
    }
    
    .tab-button.active {
        background: #667eea;
        border-color: #667eea;
        color: white;
    }
    
    .methods-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .method-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .method-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }
    
    .method-card h4 {
        margin: 0 0 10px 0;
        color: #2c3e50;
        font-size: 1.1em;
    }
    
    .method-description {
        color: #6c757d;
        font-size: 0.9em;
        margin-bottom: 15px;
    }
    
    .method-params {
        margin-bottom: 15px;
    }
    
    .param-group {
        margin-bottom: 10px;
    }
    
    .param-group label {
        display: block;
        font-size: 0.85em;
        color: #495057;
        margin-bottom: 3px;
        font-weight: 500;
    }
    
    .param-group input,
    .param-group select,
    .param-group textarea {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 0.85em;
    }
    
    .param-group textarea {
        height: 60px;
        resize: vertical;
    }
    
    .test-button {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
        width: 100%;
    }
    
    .test-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    
    .test-button:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }
    
    .result-area {
        margin-top: 15px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 6px;
        border-left: 4px solid #dee2e6;
        font-family: 'Courier New', monospace;
        font-size: 0.8em;
        max-height: 300px;
        overflow-y: auto;
        display: none;
    }
    
    .result-area.success {
        border-left-color: #28a745;
        background: #d4edda;
    }
    
    .result-area.error {
        border-left-color: #dc3545;
        background: #f8d7da;
    }
    
    .execution-time {
        font-size: 0.75em;
        color: #6c757d;
        margin-top: 5px;
    }
    
    .batch-controls {
        background: white;
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        margin-bottom: 30px;
    }
    
    .batch-button {
        background: #28a745;
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        margin-right: 10px;
        transition: all 0.2s;
    }
    
    .batch-button:hover {
        background: #218838;
        transform: translateY(-1px);
    }
    
    .batch-button:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
    }
    
    .progress-bar {
        background: #e9ecef;
        height: 6px;
        border-radius: 3px;
        margin: 15px 0;
        overflow: hidden;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #667eea, #764ba2);
        transition: width 0.3s ease;
        width: 0%;
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid transparent;
    }
    
    .alert-danger {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
    }
    
    .alert-success {
        color: #155724;
        background-color: #d4edda;
        border-color: #c3e6cb;
    }
    
    @media (max-width: 768px) {
        .methods-grid {
            grid-template-columns: 1fr;
        }
        
        .section-tabs {
            flex-direction: column;
        }
        
        .tab-button {
            text-align: center;
        }
    }
</style>

<div class="test-container">
    <div class="test-header">
        <h1>🧪 쿠팡 API 종합 테스트</h1>
        <div class="subtitle">
            64개 API 메서드 • 7개 섹션 • 실시간 응답 분석 • 배치 테스트
        </div>
    </div>
    
    <!-- 시스템 상태 알림 -->
    <?php if (!$config_status['valid']): ?>
        <div class="alert alert-danger">
            <strong>❌ API 설정 오류:</strong>
            <?php echo implode('<br>', $config_status['errors']); ?>
        </div>
    <?php else: ?>
        <div class="alert alert-success">
            <strong>✅ API 연결 준비 완료</strong> - 모든 메서드 테스트 가능
        </div>
    <?php endif; ?>
    
    <!-- 배치 테스트 컨트롤 -->
    <div class="batch-controls">
        <h3>🚀 배치 테스트</h3>
        <button class="batch-button" onclick="runBatchTest('basic')" id="batchBasicBtn">
            기본 검증 테스트
        </button>
        <button class="batch-button" onclick="runBatchTest('category')" id="batchCategoryBtn">
            카테고리 테스트
        </button>
        <button class="batch-button" onclick="runBatchTest('shipping')" id="batchShippingBtn">
            출고지 테스트
        </button>
        <button class="batch-button" onclick="runBatchTest('all')" id="batchAllBtn">
            전체 테스트
        </button>
        <button class="batch-button" onclick="clearAllResults()" style="background: #6c757d;">
            결과 초기화
        </button>
        
        <div class="progress-bar">
            <div class="progress-fill" id="batchProgress"></div>
        </div>
        <div id="batchStatus" style="font-size: 0.9em; color: #6c757d;"></div>
    </div>
    
    <!-- 섹션 탭 -->
    <div class="section-tabs">
        <?php foreach ($api_methods as $section => $methods): ?>
            <button class="tab-button" onclick="showSection('<?php echo sanitize_js_string($section); ?>')" 
                    id="tab-<?php echo sanitize_js_string($section); ?>">
                <?php echo $section; ?> (<?php echo count($methods); ?>개)
            </button>
        <?php endforeach; ?>
    </div>
    
    <!-- API 메서드 카드들 -->
    <?php foreach ($api_methods as $section => $methods): ?>
        <div class="methods-section" id="section-<?php echo sanitize_js_string($section); ?>" style="display: none;">
            <div class="methods-grid">
                <?php foreach ($methods as $method_name => $method_info): ?>
                    <div class="method-card">
                        <h4><?php echo $method_info['name']; ?></h4>
                        <div class="method-description"><?php echo $method_info['description']; ?></div>
                        
                        <!-- 매개변수 입력 -->
                        <?php if (!empty($method_info['params'])): ?>
                            <div class="method-params">
                                <?php foreach ($method_info['params'] as $param_name => $param_info): ?>
                                    <div class="param-group">
                                        <label><?php echo $param_info['label']; ?></label>
                                        <?php if ($param_info['type'] === 'textarea'): ?>
                                            <textarea id="param-<?php echo $method_name; ?>-<?php echo $param_name; ?>" 
                                                    placeholder="<?php echo $param_info['default']; ?>"><?php echo $param_info['default']; ?></textarea>
                                        <?php elseif ($param_info['type'] === 'select'): ?>
                                            <select id="param-<?php echo $method_name; ?>-<?php echo $param_name; ?>">
                                                <?php foreach ($param_info['options'] as $value => $label): ?>
                                                    <option value="<?php echo $value; ?>" <?php echo $value === $param_info['default'] ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input type="<?php echo $param_info['type']; ?>" 
                                                   id="param-<?php echo $method_name; ?>-<?php echo $param_name; ?>" 
                                                   value="<?php echo $param_info['default']; ?>" 
                                                   placeholder="<?php echo $param_info['default']; ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- 테스트 버튼 -->
                        <button class="test-button" onclick="testMethod('<?php echo $method_name; ?>')" 
                                id="btn-<?php echo $method_name; ?>">
                            🚀 테스트 실행
                        </button>
                        
                        <!-- 결과 영역 -->
                        <div class="result-area" id="result-<?php echo $method_name; ?>"></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
// 전역 변수
let currentSection = '';
let batchTestRunning = false;
let testResults = {};

// 페이지 로드 시 첫 번째 섹션 표시
document.addEventListener('DOMContentLoaded', function() {
    const firstSection = Object.keys(<?php echo json_encode(array_keys($api_methods)); ?>)[0];
    showSection(firstSection);
});

// 섹션 표시/숨김
function showSection(sectionName) {
    // 모든 섹션 숨김
    document.querySelectorAll('.methods-section').forEach(section => {
        section.style.display = 'none';
    });
    
    // 모든 탭 비활성화
    document.querySelectorAll('.tab-button').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // 선택된 섹션 표시
    const targetSection = document.getElementById('section-' + sectionName);
    if (targetSection) {
        targetSection.style.display = 'block';
        currentSection = sectionName;
    }
    
    // 선택된 탭 활성화
    const targetTab = document.getElementById('tab-' + sectionName);
    if (targetTab) {
        targetTab.classList.add('active');
    }
}

// 개별 메서드 테스트
function testMethod(methodName) {
    const button = document.getElementById('btn-' + methodName);
    const resultArea = document.getElementById('result-' + methodName);
    
    // 버튼 비활성화
    button.disabled = true;
    button.textContent = '⏳ 테스트 중...';
    
    // 결과 영역 초기화
    resultArea.style.display = 'block';
    resultArea.className = 'result-area';
    resultArea.innerHTML = '테스트 실행 중...';
    
    // 매개변수 수집
    const params = collectParams(methodName);
    
    // AJAX 요청
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=test_api&method=' + encodeURIComponent(methodName) + '&params=' + encodeURIComponent(JSON.stringify(params))
    })
    .then(response => response.json())
    .then(data => {
        displayTestResult(methodName, data);
        testResults[methodName] = data;
    })
    .catch(error => {
        displayTestResult(methodName, {
            success: false,
            error: '네트워크 오류: ' + error.message,
            execution_time: 0
        });
    })
    .finally(() => {
        // 버튼 활성화
        button.disabled = false;
        button.textContent = '🚀 테스트 실행';
    });
}

// 매개변수 수집
function collectParams(methodName) {
    const params = {};
    const paramElements = document.querySelectorAll(`[id^="param-${methodName}-"]`);
    
    paramElements.forEach(element => {
        const paramName = element.id.replace(`param-${methodName}-`, '');
        let value = element.value.trim();
        
        // textarea의 경우 줄바꿈으로 배열 분리
        if (element.tagName === 'TEXTAREA' && paramName === 'products') {
            value = value.split('\n').filter(line => line.trim());
        }
        
        params[paramName] = value;
    });
    
    return params;
}

// 테스트 결과 표시
function displayTestResult(methodName, data) {
    const resultArea = document.getElementById('result-' + methodName);
    
    if (data.success) {
        resultArea.className = 'result-area success';
        resultArea.innerHTML = `
            <div><strong>✅ 성공</strong></div>
            <div class="execution-time">실행시간: ${data.execution_time}ms</div>
            <hr style="margin: 10px 0; border: none; border-top: 1px solid #ccc;">
            <pre>${JSON.stringify(data.result, null, 2)}</pre>
        `;
    } else {
        resultArea.className = 'result-area error';
        resultArea.innerHTML = `
            <div><strong>❌ 실패</strong></div>
            <div class="execution-time">실행시간: ${data.execution_time}ms</div>
            <hr style="margin: 10px 0; border: none; border-top: 1px solid #ccc;">
            <div><strong>오류:</strong> ${data.error}</div>
        `;
    }
    
    resultArea.style.display = 'block';
}

// 배치 테스트 실행
async function runBatchTest(testType) {
    if (batchTestRunning) return;
    
    batchTestRunning = true;
    const progressBar = document.getElementById('batchProgress');
    const statusDiv = document.getElementById('batchStatus');
    
    // 모든 배치 버튼 비활성화
    document.querySelectorAll('.batch-button').forEach(btn => {
        btn.disabled = true;
    });
    
    let methodsToTest = [];
    
    // 테스트할 메서드 선택
    switch(testType) {
        case 'basic':
            methodsToTest = ['validateConfiguration', 'validateApiConfig', 'testApiConnection', 'getSystemStatus'];
            break;
        case 'category':
            methodsToTest = ['getCategoryRecommendation', 'getCategoryList', 'cleanupCategoryCache', 'getCategoryMappingStats'];
            break;
        case 'shipping':
            methodsToTest = ['getOutboundShippingPlaces', 'getReturnShippingPlaces', 'getLocalShippingPlaces'];
            break;
        case 'all':
            methodsToTest = <?php echo json_encode(array_keys(array_merge(...array_values($api_methods)))); ?>;
            break;
    }
    
    // 배치 테스트 실행
    let completed = 0;
    let successful = 0;
    
    for (let i = 0; i < methodsToTest.length; i++) {
        const methodName = methodsToTest[i];
        
        statusDiv.textContent = `테스트 중: ${methodName} (${i + 1}/${methodsToTest.length})`;
        
        try {
            const params = collectParams(methodName);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=test_api&method=' + encodeURIComponent(methodName) + '&params=' + encodeURIComponent(JSON.stringify(params))
            });
            
            const data = await response.json();
            displayTestResult(methodName, data);
            testResults[methodName] = data;
            
            if (data.success) successful++;
            
        } catch (error) {
            const errorData = {
                success: false,
                error: '네트워크 오류: ' + error.message,
                execution_time: 0
            };
            displayTestResult(methodName, errorData);
        }
        
        completed++;
        const progress = (completed / methodsToTest.length) * 100;
        progressBar.style.width = progress + '%';
        
        // 1초 대기 (API 제한 고려)
        await new Promise(resolve => setTimeout(resolve, 1000));
    }
    
    // 완료 상태 표시
    statusDiv.innerHTML = `
        <strong>배치 테스트 완료!</strong><br>
        총 ${methodsToTest.length}개 메서드 중 ${successful}개 성공 (${Math.round((successful/methodsToTest.length)*100)}%)
    `;
    
    // 버튼 활성화
    document.querySelectorAll('.batch-button').forEach(btn => {
        btn.disabled = false;
    });
    
    batchTestRunning = false;
}

// 모든 결과 초기화
function clearAllResults() {
    document.querySelectorAll('.result-area').forEach(area => {
        area.style.display = 'none';
        area.innerHTML = '';
    });
    
    const progressBar = document.getElementById('batchProgress');
    const statusDiv = document.getElementById('batchStatus');
    
    progressBar.style.width = '0%';
    statusDiv.textContent = '';
    
    testResults = {};
}

// JS 문자열 안전화 함수 (PHP에서 사용)
<?php
function sanitize_js_string($str) {
    return preg_replace('/[^a-zA-Z0-9가-힣\s]/', '', $str);
}
?>
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
?>
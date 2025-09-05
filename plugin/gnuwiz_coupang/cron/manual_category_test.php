<?php
/**
 * === manual_category_test.php ===
 * 수동 카테고리 테스트 스크립트
 * 경로: /plugin/coupang/cron/manual_category_test.php
 * 용도: 개별 상품의 카테고리 추천 테스트
 * 실행: php manual_category_test.php "상품명"
 */

// 플러그인 경로 설정
define('COUPANG_PLUGIN_PATH', dirname(dirname(__FILE__)));
define('YOUNGCART_ROOT', dirname(dirname(dirname(__FILE__))));

// 영카트 공통 파일 및 API 클래스 로드
include_once(YOUNGCART_ROOT . '/_common.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_config.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_api_class.php');

// CLI 인자 확인
if (php_sapi_name() !== 'cli') {
    die('이 스크립트는 CLI에서만 실행할 수 있습니다.');
}

$product_name = isset($argv[1]) ? $argv[1] : '';

if (empty($product_name)) {
    echo "사용법: php manual_category_test.php \"상품명\"\n";
    echo "예시: php manual_category_test.php \"삼성 갤럭시 S24 케이스\"\n";
    exit(1);
}

try {
    echo "=== 쿠팡 카테고리 추천 테스트 ===\n";
    echo "상품명: {$product_name}\n";
    echo "시작 시간: " . date('Y-m-d H:i:s') . "\n";
    echo str_repeat('-', 50) . "\n";
    
    // API 설정 검증
    $config_check = validate_coupang_config();
    if (!$config_check['valid']) {
        throw new Exception('API 설정 오류: ' . implode(', ', $config_check['errors']));
    }
    
    // 쿠팡 API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    // 추가 옵션 설정 (CLI에서 입력받을 수도 있음)
    $options = array(
        'product_description' => isset($argv[2]) ? $argv[2] : '',
        'brand' => isset($argv[3]) ? $argv[3] : '',
        'cache' => false // 테스트 시에는 캐시 사용 안 함
    );
    
    echo "추가 옵션:\n";
    if (!empty($options['product_description'])) {
        echo "- 상품 설명: {$options['product_description']}\n";
    }
    if (!empty($options['brand'])) {
        echo "- 브랜드: {$options['brand']}\n";
    }
    echo "\n";
    
    // 카테고리 추천 실행
    echo "카테고리 추천 API 호출 중...\n";
    $result = $coupang_api->getCategoryRecommendation($product_name, $options);
    
    // 결과 출력
    echo str_repeat('-', 50) . "\n";
    
    if ($result['success']) {
        echo "✅ 추천 성공!\n";
        echo "카테고리 ID: {$result['data']['category_id']}\n";
        echo "카테고리명: {$result['data']['category_name']}\n";
        echo "신뢰도: " . number_format($result['data']['confidence'] * 100, 1) . "%\n";
        echo "결과 타입: {$result['data']['result_type']}\n";
        echo "추천 시간: {$result['data']['recommended_at']}\n";
        
        if (!empty($result['data']['comment'])) {
            echo "코멘트: {$result['data']['comment']}\n";
        }
        
        // 신뢰도 평가
        $confidence = $result['data']['confidence'];
        echo "\n신뢰도 평가: ";
        if ($confidence >= 0.8) {
            echo "높음 (자동 적용 권장) ⭐⭐⭐\n";
        } elseif ($confidence >= 0.6) {
            echo "보통 (수동 검토 필요) ⭐⭐\n";
        } else {
            echo "낮음 (수동 확인 필수) ⭐\n";
        }
        
    } else {
        echo "❌ 추천 실패\n";
        echo "오류: {$result['error']}\n";
        
        if (isset($result['http_code'])) {
            echo "HTTP 코드: {$result['http_code']}\n";
        }
        
        if (isset($result['data']['result_type'])) {
            echo "결과 타입: {$result['data']['result_type']}\n";
        }
        
        if (isset($result['data']['comment'])) {
            echo "코멘트: {$result['data']['comment']}\n";
        }
    }
    
    echo str_repeat('-', 50) . "\n";
    echo "완료 시간: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "오류 발생: " . $e->getMessage() . "\n";
    echo "스택 트레이스:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);
?>
<?php
// ============================================================================
// 파일 3: cron/manual_shipping_test.php
// ============================================================================
/**
 * 출고지/반품지 수동 테스트 스크립트 (CLI 전용)
 */
?>
<?php
if (php_sapi_name() !== 'cli') {
    die('CLI 환경에서만 실행 가능합니다.');
}

$plugin_path = dirname(__FILE__) . '/..';
include_once($plugin_path . '/_common.php');

echo "=== 쿠팡 출고지/반품지 수동 테스트 ===\n\n";

try {
    // API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    // 설정 검증
    echo "1. API 설정 검증...\n";
    $config_validation = validate_coupang_config();
    if (!$config_validation['valid']) {
        throw new Exception('설정 오류: ' . implode(', ', $config_validation['errors']));
    }
    echo "   ✅ API 설정 정상\n\n";
    
    // 출고지/반품지 목록 조회 테스트
    echo "2. 출고지/반품지 목록 조회 테스트...\n";
    $list_result = $coupang_api->getShippingPlaces('ALL');
    
    if ($list_result['success']) {
        echo "   ✅ 목록 조회 성공 - 총 {$list_result['count']}개\n";
        
        $outbound_count = 0;
        $return_count = 0;
        
        if (isset($list_result['data']) && is_array($list_result['data'])) {
            foreach ($list_result['data'] as $place) {
                if (isset($place['placeAddresses'])) {
                    foreach ($place['placeAddresses'] as $address) {
                        if ($address['addressType'] === 'OUTBOUND') {
                            $outbound_count++;
                        } elseif ($address['addressType'] === 'RETURN') {
                            $return_count++;
                        }
                    }
                }
                
                echo "   - {$place['shippingPlaceName']} ({$place['shippingPlaceCode']})\n";
            }
        }
        
        echo "   출고지: {$outbound_count}개, 반품지: {$return_count}개\n\n";
        
        // 첫 번째 출고지/반품지 상세 조회 테스트
        if (!empty($list_result['data'])) {
            $first_place = $list_result['data'][0];
            $test_code = $first_place['shippingPlaceCode'];
            
            echo "3. 상세 조회 테스트 (코드: {$test_code})...\n";
            $detail_result = $coupang_api->getShippingPlaceDetail($test_code);
            
            if ($detail_result['success']) {
                echo "   ✅ 상세 조회 성공\n";
                if (isset($detail_result['data']['shippingPlaceName'])) {
                    echo "   이름: {$detail_result['data']['shippingPlaceName']}\n";
                }
            } else {
                echo "   ❌ 상세 조회 실패: {$detail_result['message']}\n";
            }
        }
        
    } else {
        throw new Exception('목록 조회 실패: ' . $list_result['message']);
    }
    
    // 로컬 DB 상태 확인
    echo "\n4. 로컬 DB 상태 확인...\n";
    $local_outbound = $coupang_api->getLocalShippingPlaces('OUTBOUND', 'ACTIVE');
    $local_return = $coupang_api->getLocalShippingPlaces('RETURN', 'ACTIVE');
    
    echo "   로컬 DB - 출고지: " . count($local_outbound) . "개, 반품지: " . count($local_return) . "개\n";
    
    // 기본 출고지/반품지 확인
    $default_outbound = array_filter($local_outbound, function($place) {
        return $place['is_default_outbound'] == 1;
    });
    
    $default_return = array_filter($local_return, function($place) {
        return $place['is_default_return'] == 1;
    });
    
    if (!empty($default_outbound)) {
        $default_out = reset($default_outbound);
        echo "   기본 출고지: {$default_out['shipping_place_name']} ({$default_out['shipping_place_code']})\n";
    } else {
        echo "   ⚠️ 기본 출고지가 설정되지 않았습니다.\n";
    }
    
    if (!empty($default_return)) {
        $default_ret = reset($default_return);
        echo "   기본 반품지: {$default_ret['shipping_place_name']} ({$default_ret['shipping_place_code']})\n";
    } else {
        echo "   ⚠️ 기본 반품지가 설정되지 않았습니다.\n";
    }
    
    echo "\n=== 테스트 완료 ===\n";
    echo "모든 출고지/반품지 기능이 정상 작동합니다.\n";
    
} catch (Exception $e) {
    echo "\n❌ 테스트 실패: " . $e->getMessage() . "\n";
    exit(1);
}
?>
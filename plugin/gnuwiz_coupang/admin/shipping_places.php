<?php
/**
 * 쿠팡 출고지/반품지 관리 페이지
 * 경로: /plugin/gnuwiz_coupang/admin/shipping_places.php
 * 용도: 출고지/반품지 등록, 수정, 삭제, 동기화 관리
 */

$sub_menu = '600700';
include_once('./_common.php');

// 관리자 권한 체크
auth_check_menu($auth, $sub_menu, 'r');

// 쿠팡 설정 및 API 클래스 로드
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_config.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_api_class.php');

$g5['title'] = '쿠팡 출고지/반품지 관리';

// 액션 처리
$action = isset($_GET['action']) ? $_GET['action'] : '';
$shipping_place_code = isset($_GET['code']) ? clean_xss_tags($_GET['code']) : '';

// API 인스턴스 생성
$coupang_api = get_coupang_api();

// 메시지 변수
$alert_message = '';
$alert_type = '';

// 액션 처리
switch ($action) {
    case 'sync':
        // 출고지/반품지 동기화
        $result = $coupang_api->syncShippingPlacesFromCoupang();
        if ($result['success']) {
            $alert_message = $result['message'];
            $alert_type = 'success';
        } else {
            $alert_message = '동기화 실패: ' . $result['error'];
            $alert_type = 'danger';
        }
        break;
        
    case 'create':
        // 새 출고지/반품지 등록
        if ($_POST) {
            $shipping_data = array(
                'name' => clean_xss_tags($_POST['shipping_place_name']),
                'company_name' => clean_xss_tags($_POST['company_name']),
                'contact_name' => clean_xss_tags($_POST['contact_name']),
                'company_phone' => clean_xss_tags($_POST['company_phone']),
                'phone1' => clean_xss_tags($_POST['phone1']),
                'phone2' => clean_xss_tags($_POST['phone2']),
                'zipcode' => clean_xss_tags($_POST['zipcode']),
                'address1' => clean_xss_tags($_POST['address1']),
                'address2' => clean_xss_tags($_POST['address2'])
            );
            
            $address_type = clean_xss_tags($_POST['address_type']);
            
            if ($address_type === 'OUTBOUND') {
                $result = $coupang_api->createOutboundShippingPlace($shipping_data);
            } else {
                $result = $coupang_api->createReturnShippingPlace($shipping_data);
            }
            
            if ($result['success']) {
                $alert_message = ($address_type === 'OUTBOUND' ? '출고지' : '반품지') . ' 등록이 완료되었습니다.';
                $alert_type = 'success';
            } else {
                $alert_message = '등록 실패: ' . $result['error'];
                $alert_type = 'danger';
            }
        }
        break;
        
    case 'delete':
        // 출고지/반품지 삭제
        if ($shipping_place_code) {
            $result = $coupang_api->deleteShippingPlace($shipping_place_code);
            if ($result['success']) {
                $alert_message = '출고지/반품지가 삭제되었습니다.';
                $alert_type = 'success';
            } else {
                $alert_message = '삭제 실패: ' . $result['error'];
                $alert_type = 'danger';
            }
        }
        break;
        
    case 'set_default':
        // 기본 출고지/반품지 설정
        $address_type = clean_xss_tags($_GET['type']);
        if ($shipping_place_code && $address_type) {
            $field = ($address_type === 'OUTBOUND') ? 'is_default_outbound' : 'is_default_return';
            
            // 기존 기본값 해제
            sql_query("UPDATE " . G5_TABLE_PREFIX . "coupang_shipping_places SET {$field} = 0 WHERE address_type = '" . addslashes($address_type) . "'");
            
            // 새로운 기본값 설정
            sql_query("UPDATE " . G5_TABLE_PREFIX . "coupang_shipping_places SET {$field} = 1 WHERE shipping_place_code = '" . addslashes($shipping_place_code) . "'");
            
            $alert_message = '기본 ' . ($address_type === 'OUTBOUND' ? '출고지' : '반품지') . '가 설정되었습니다.';
            $alert_type = 'success';
        }
        break;
}

// 현재 등록된 출고지/반품지 목록 조회
$outbound_places = $coupang_api->getLocalShippingPlaces('OUTBOUND', 'ACTIVE');
$return_places = $coupang_api->getLocalShippingPlaces('RETURN', 'ACTIVE');

include_once(G5_ADMIN_PATH . '/admin.head.php');
?>

<style>
.shipping-place-card {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    background: #f9f9f9;
}
.shipping-place-card.default {
    border-color: #28a745;
    background: #d4edda;
}
.shipping-place-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.shipping-place-name {
    font-weight: bold;
    font-size: 16px;
    color: #333;
}
.shipping-place-code {
    font-size: 12px;
    color: #666;
    background: #e9ecef;
    padding: 2px 6px;
    border-radius: 3px;
}
.address-info {
    margin: 8px 0;
    font-size: 14px;
    color: #555;
}
.contact-info {
    font-size: 13px;
    color: #777;
}
.default-badge {
    background: #28a745;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
}
.btn-group-sm {
    margin-top: 10px;
}
.sync-info {
    background: #e7f3ff;
    border: 1px solid #b8daff;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 20px;
}
.stats-card {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 20px;
}
.stats-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}
.stats-item:last-child {
    margin-bottom: 0;
}
</style>

<div class="container-fluid">
    <?php if ($alert_message): ?>
    <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
        <?php echo $alert_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4><i class="fa fa-truck"></i> 쿠팡 출고지/반품지 관리</h4>
                    <div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addShippingPlaceModal">
                            <i class="fa fa-plus"></i> 새 출고지/반품지 등록
                        </button>
                        <a href="?action=sync" class="btn btn-success" onclick="return confirm('쿠팡에서 출고지/반품지 목록을 동기화하시겠습니까?')">
                            <i class="fa fa-sync"></i> 동기화
                        </a>
                        <a href="../cron/manual_shipping_test.php" class="btn btn-info" target="_blank">
                            <i class="fa fa-check-circle"></i> 연결 테스트
                        </a>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- 상태 정보 -->
                    <div class="stats-card">
                        <h6><i class="fa fa-chart-bar"></i> 현재 상태</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="stats-item">
                                    <span>등록된 출고지:</span>
                                    <strong><?php echo count($outbound_places); ?>개</strong>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-item">
                                    <span>등록된 반품지:</span>
                                    <strong><?php echo count($return_places); ?>개</strong>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-item">
                                    <span>기본 출고지:</span>
                                    <strong>
                                        <?php 
                                        $default_out = array_filter($outbound_places, function($p) { return $p['is_default_outbound']; });
                                        echo !empty($default_out) ? '설정됨' : '미설정';
                                        ?>
                                    </strong>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-item">
                                    <span>기본 반품지:</span>
                                    <strong>
                                        <?php 
                                        $default_ret = array_filter($return_places, function($p) { return $p['is_default_return']; });
                                        echo !empty($default_ret) ? '설정됨' : '미설정';
                                        ?>
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sync-info">
                        <h6><i class="fa fa-info-circle"></i> 출고지/반품지 관리 안내</h6>
                        <ul class="mb-0">
                            <li>쿠팡에서 상품 등록 시 출고지 코드가 필수입니다.</li>
                            <li>반품지는 고객 반품 시 사용되는 주소입니다.</li>
                            <li>기본 출고지/반품지를 설정하면 자동으로 사용됩니다.</li>
                            <li>동기화 버튼을 클릭하여 쿠팡의 최신 정보를 가져올 수 있습니다.</li>
                            <li>연결 테스트로 API 연동 상태를 확인할 수 있습니다.</li>
                        </ul>
                    </div>

                    <!-- 출고지/반품지 목록 -->
                    <div class="row">
                        <div class="col-md-6">
                            <h5><i class="fa fa-truck"></i> 출고지 목록</h5>
                            <?php if (empty($outbound_places)): ?>
                                <div class="alert alert-warning">
                                    등록된 출고지가 없습니다. 새 출고지를 등록하거나 동기화를 실행하세요.
                                </div>
                            <?php else: ?>
                                <?php foreach ($outbound_places as $place): ?>
                                <div class="shipping-place-card <?php echo $place['is_default_outbound'] ? 'default' : ''; ?>">
                                    <div class="shipping-place-header">
                                        <div>
                                            <span class="shipping-place-name"><?php echo htmlspecialchars($place['shipping_place_name']); ?></span>
                                            <?php if ($place['is_default_outbound']): ?>
                                                <span class="default-badge">기본 출고지</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="shipping-place-code"><?php echo htmlspecialchars($place['shipping_place_code']); ?></span>
                                    </div>
                                    
                                    <div class="address-info">
                                        <i class="fa fa-map-marker-alt"></i> 
                                        (<?php echo htmlspecialchars($place['zipcode']); ?>) 
                                        <?php echo htmlspecialchars($place['address1']); ?> 
                                        <?php echo htmlspecialchars($place['address2']); ?>
                                    </div>
                                    
                                    <div class="contact-info">
                                        <i class="fa fa-building"></i> <?php echo htmlspecialchars($place['company_name']); ?> | 
                                        <i class="fa fa-user"></i> <?php echo htmlspecialchars($place['contact_name']); ?> | 
                                        <i class="fa fa-phone"></i> <?php echo htmlspecialchars($place['phone1']); ?>
                                    </div>
                                    
                                    <?php if ($place['last_sync_date']): ?>
                                    <div class="contact-info">
                                        <i class="fa fa-clock"></i> 최종 동기화: <?php echo date('Y-m-d H:i', strtotime($place['last_sync_date'])); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="btn-group-sm">
                                        <?php if (!$place['is_default_outbound']): ?>
                                            <a href="?action=set_default&code=<?php echo urlencode($place['shipping_place_code']); ?>&type=OUTBOUND" 
                                               class="btn btn-outline-success btn-sm"
                                               onclick="return confirm('이 출고지를 기본 출고지로 설정하시겠습니까?')">
                                                기본으로 설정
                                            </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="viewShippingPlaceDetail('<?php echo $place['shipping_place_code']; ?>')">
                                            상세보기
                                        </button>
                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="editShippingPlace('<?php echo $place['shipping_place_code']; ?>')">
                                            수정
                                        </button>
                                        <a href="?action=delete&code=<?php echo urlencode($place['shipping_place_code']); ?>" 
                                           class="btn btn-outline-danger btn-sm"
                                           onclick="return confirm('이 출고지를 삭제하시겠습니까?')">
                                            삭제
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- 반품지 목록 -->
                        <div class="col-md-6">
                            <h5><i class="fa fa-undo"></i> 반품지 목록</h5>
                            <?php if (empty($return_places)): ?>
                                <div class="alert alert-warning">
                                    등록된 반품지가 없습니다. 새 반품지를 등록하거나 동기화를 실행하세요.
                                </div>
                            <?php else: ?>
                                <?php foreach ($return_places as $place): ?>
                                <div class="shipping-place-card <?php echo $place['is_default_return'] ? 'default' : ''; ?>">
                                    <div class="shipping-place-header">
                                        <div>
                                            <span class="shipping-place-name"><?php echo htmlspecialchars($place['shipping_place_name']); ?></span>
                                            <?php if ($place['is_default_return']): ?>
                                                <span class="default-badge">기본 반품지</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="shipping-place-code"><?php echo htmlspecialchars($place['shipping_place_code']); ?></span>
                                    </div>
                                    
                                    <div class="address-info">
                                        <i class="fa fa-map-marker-alt"></i> 
                                        (<?php echo htmlspecialchars($place['zipcode']); ?>) 
                                        <?php echo htmlspecialchars($place['address1']); ?> 
                                        <?php echo htmlspecialchars($place['address2']); ?>
                                    </div>
                                    
                                    <div class="contact-info">
                                        <i class="fa fa-building"></i> <?php echo htmlspecialchars($place['company_name']); ?> | 
                                        <i class="fa fa-user"></i> <?php echo htmlspecialchars($place['contact_name']); ?> | 
                                        <i class="fa fa-phone"></i> <?php echo htmlspecialchars($place['phone1']); ?>
                                    </div>
                                    
                                    <?php if ($place['last_sync_date']): ?>
                                    <div class="contact-info">
                                        <i class="fa fa-clock"></i> 최종 동기화: <?php echo date('Y-m-d H:i', strtotime($place['last_sync_date'])); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="btn-group-sm">
                                        <?php if (!$place['is_default_return']): ?>
                                            <a href="?action=set_default&code=<?php echo urlencode($place['shipping_place_code']); ?>&type=RETURN" 
                                               class="btn btn-outline-success btn-sm"
                                               onclick="return confirm('이 반품지를 기본 반품지로 설정하시겠습니까?')">
                                                기본으로 설정
                                            </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="viewShippingPlaceDetail('<?php echo $place['shipping_place_code']; ?>')">
                                            상세보기
                                        </button>
                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="editShippingPlace('<?php echo $place['shipping_place_code']; ?>')">
                                            수정
                                        </button>
                                        <a href="?action=delete&code=<?php echo urlencode($place['shipping_place_code']); ?>" 
                                           class="btn btn-outline-danger btn-sm"
                                           onclick="return confirm('이 반품지를 삭제하시겠습니까?')">
                                            삭제
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 새 출고지/반품지 등록 모달 -->
<div class="modal fade" id="addShippingPlaceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">새 출고지/반품지 등록</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="?action=create">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">주소 타입 *</label>
                                <select name="address_type" class="form-control" required>
                                    <option value="OUTBOUND">출고지</option>
                                    <option value="RETURN">반품지</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">출고지/반품지명 *</label>
                                <input type="text" name="shipping_place_name" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">회사명 *</label>
                                <input type="text" name="company_name" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">담당자명 *</label>
                                <input type="text" name="contact_name" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">회사 전화번호 *</label>
                                <input type="text" name="company_phone" class="form-control" required placeholder="예: 02-1234-5678">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">연락처1 *</label>
                                <input type="text" name="phone1" class="form-control" required placeholder="예: 010-1234-5678">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">연락처2</label>
                                <input type="text" name="phone2" class="form-control" placeholder="예: 070-1234-5678">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">우편번호 *</label>
                                <input type="text" name="zipcode" class="form-control" required placeholder="예: 06234">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">주소1 *</label>
                                <input type="text" name="address1" class="form-control" required placeholder="예: 서울특별시 강남구 테헤란로">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">주소2 *</label>
                                <input type="text" name="address2" class="form-control" required placeholder="예: 123번길 45, 그누위즈빌딩 3층">
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i> 
                        <strong>주의사항:</strong> 쿠팡 API에 실제로 등록되므로 정확한 정보를 입력하세요.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="submit" class="btn btn-primary">등록</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 상세보기 모달 -->
<div class="modal fade" id="shippingPlaceDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">출고지/반품지 상세 정보</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="shippingPlaceDetailContent">
                <!-- AJAX로 로드될 내용 -->
            </div>
        </div>
    </div>
</div>

<!-- 수정 모달 -->
<div class="modal fade" id="editShippingPlaceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">출고지/반품지 수정</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="editShippingPlaceContent">
                <!-- AJAX로 로드될 수정 폼 -->
            </div>
        </div>
    </div>
</div>

<script>
function viewShippingPlaceDetail(shippingPlaceCode) {
    // AJAX로 상세 정보 로드
    fetch('shipping_place_detail.php?code=' + encodeURIComponent(shippingPlaceCode))
        .then(response => response.text())
        .then(data => {
            document.getElementById('shippingPlaceDetailContent').innerHTML = data;
            new bootstrap.Modal(document.getElementById('shippingPlaceDetailModal')).show();
        })
        .catch(error => {
            alert('상세 정보를 불러오는데 실패했습니다.');
            console.error('Error:', error);
        });
}

function editShippingPlace(shippingPlaceCode) {
    // AJAX로 수정 폼 로드
    fetch('shipping_place_edit.php?code=' + encodeURIComponent(shippingPlaceCode))
        .then(response => response.text())
        .then(data => {
            document.getElementById('editShippingPlaceContent').innerHTML = data;
            new bootstrap.Modal(document.getElementById('editShippingPlaceModal')).show();
        })
        .catch(error => {
            alert('수정 폼을 불러오는데 실패했습니다.');
            console.error('Error:', error);
        });
}

// 폼 유효성 검사
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('#addShippingPlaceModal form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('input[required], select[required]');
            let hasError = false;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    hasError = true;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (hasError) {
                e.preventDefault();
                alert('필수 항목을 모두 입력해주세요.');
            }
        });
    }
    
    // 실시간 유효성 검사
    const inputs = document.querySelectorAll('#addShippingPlaceModal input[required], #addShippingPlaceModal select[required]');
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (!this.value.trim()) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });
    });
});

// 페이지 로드 시 알림 표시
<?php if ($alert_message): ?>
document.addEventListener('DOMContentLoaded', function() {
    // 3초 후 알림 자동 숨김
    setTimeout(function() {
        const alert = document.querySelector('.alert');
        if (alert) {
            alert.style.opacity = '0.5';
        }
    }, 3000);
});
<?php endif; ?>
</script>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
?>
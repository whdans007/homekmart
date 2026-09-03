<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/address.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();

$edit_id = (int)($_GET['edit'] ?? 0);
$is_form_mode = isset($_GET['add']) || $edit_id > 0;
$editing_address = null;

if ($edit_id > 0) {
    $editing_address = mall_address_get($member['id'], $edit_id);
    if (!$editing_address) {
        header('Location: /mall/address.php');
        exit;
    }
}

$mall_redesigned = true;
$mall_show_back = true;
$show_bottom_nav = true;
$active_nav = 'my';
$page_title = $is_form_mode ? ($editing_address ? '배송지 수정' : '배송지 추가') : '배송지 관리';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.addr-field { margin: 0 0 var(--space-4); }
.addr-field label { display: block; font: var(--t-label2) var(--font-sans); color: var(--label-neutral); margin-bottom: 6px; }
.addr-field input {
    width: 100%; border: 1px solid var(--line-normal); border-radius: var(--radius-md);
    padding: 11px 12px; font: var(--t-body2) var(--font-sans); background: var(--bg-normal); color: var(--label-normal);
}
.map-hint { margin: 8px var(--space-5) 0; font: var(--t-caption1) var(--font-sans); color: var(--label-alternative); }
.addr-default-check { display: flex; align-items: center; gap: 8px; font: var(--t-label2) var(--font-sans); color: var(--label-neutral); margin-bottom: var(--space-4); }
.address-card { margin: var(--space-4) var(--space-5) 0; padding: var(--space-4); border: 1px solid var(--line-alternative); border-radius: var(--radius-lg); }
.address-card .head { display: flex; align-items: center; gap: 8px; font: var(--t-label1) var(--font-sans); color: var(--label-normal); margin-bottom: 4px; }
.address-card .default-badge { font: 700 11px var(--font-sans); color: var(--primary-normal); background: var(--primary-bg); padding: 2px 8px; border-radius: var(--radius-full); }
.address-card .body { font: var(--t-caption1) var(--font-sans); color: var(--label-alternative); margin-bottom: var(--space-3); }
.address-card .actions { display: flex; gap: 12px; font: var(--t-caption1) var(--font-sans); }
.address-card .actions a, .address-card .actions button { color: var(--label-alternative); background: none; border: none; padding: 0; cursor: pointer; }
.address-card .actions button.danger { color: var(--brand-red); }
</style>

<?php if ($is_form_mode): ?>

<div id="address-search-wrap" style="margin: var(--space-4) var(--space-5) 0;">
    <input id="af-search" type="text" placeholder="장소·건물 이름으로 검색"
           style="width:100%;border:1px solid var(--line-normal);border-radius:var(--radius-md);padding:11px 12px;font:var(--t-body2) var(--font-sans);">
</div>
<div id="address-map" style="height:260px;margin:var(--space-4) var(--space-5) 0;border-radius:var(--radius-lg);background:var(--fill-normal);"></div>
<p class="map-hint">지도를 누르거나 핀을 드래그해서 배송받을 위치를 지정해주세요.</p>

<div class="section">
    <div class="addr-field">
        <label>수령인</label>
        <input id="af-recipient" type="text" placeholder="이름" value="<?php echo htmlspecialchars($editing_address['recipient_name'] ?? $member['name']); ?>">
    </div>
    <div class="addr-field">
        <label>휴대폰</label>
        <input id="af-phone" type="text" placeholder="+63 9XX XXX XXXX" value="<?php echo htmlspecialchars($editing_address['phone'] ?? $member['phone'] ?? ''); ?>">
    </div>
    <div class="addr-field">
        <label>상세주소/건물명</label>
        <input id="af-detail" type="text" placeholder="건물명, 동/호수 등" value="<?php echo htmlspecialchars($editing_address['detail_address'] ?? ''); ?>">
    </div>
    <div class="addr-field">
        <label>랜드마크 <span style="color:var(--brand-red);">*필수</span></label>
        <input id="af-landmark" type="text" placeholder="예: OOO 편의점 맞은편" value="<?php echo htmlspecialchars($editing_address['landmark'] ?? ''); ?>">
    </div>
    <div class="addr-field">
        <label>바랑가이(Barangay)</label>
        <input id="af-barangay" type="text" value="<?php echo htmlspecialchars($editing_address['barangay'] ?? ''); ?>">
    </div>
    <div class="addr-field">
        <label>시(City)</label>
        <input id="af-city" type="text" value="<?php echo htmlspecialchars($editing_address['city'] ?? ''); ?>">
    </div>
    <div class="addr-field">
        <label>지역(Region)</label>
        <input id="af-region" type="text" placeholder="지도에서 핀을 놓으면 자동으로 채워집니다" value="<?php echo htmlspecialchars($editing_address['region'] ?? ''); ?>">
    </div>
    <label class="addr-default-check">
        <input type="checkbox" id="af-default" <?php echo (!empty($editing_address['is_default'])) ? 'checked disabled' : ''; ?>>
        기본 배송지로 설정
    </label>
    <input type="hidden" id="af-lat" value="<?php echo htmlspecialchars($editing_address['lat'] ?? ''); ?>">
    <input type="hidden" id="af-lng" value="<?php echo htmlspecialchars($editing_address['lng'] ?? ''); ?>">
    <input type="hidden" id="af-id" value="<?php echo (int)($editing_address['id'] ?? 0); ?>">
</div>

<div id="address-form-error" style="display:none;margin:0 var(--space-5) var(--space-3);color:var(--brand-red);font:var(--t-caption1) var(--font-sans);"></div>

<div class="sticky-cta">
    <a href="/mall/address.php" class="btn" style="flex:1;background:var(--fill-strong);color:var(--label-normal);">취소</a>
    <button id="af-save-btn" class="btn btn-primary" style="flex:2;">저장</button>
</div>

<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode(MALL_GOOGLE_MAPS_API_KEY); ?>&libraries=places&callback=mallInitAddressMap" async defer></script>
<script>
    var mallMap, mallMarker;
    var MALL_DEFAULT_CENTER = { lat: 14.5995, lng: 120.9842 }; // Metro Manila
    var MALL_INITIAL_PIN = <?php echo ($editing_address && $editing_address['lat'] && $editing_address['lng'])
        ? json_encode(['lat' => (float)$editing_address['lat'], 'lng' => (float)$editing_address['lng']])
        : 'null'; ?>;

    function mallInitAddressMap() {
        var center = MALL_INITIAL_PIN || MALL_DEFAULT_CENTER;
        mallMap = new google.maps.Map(document.getElementById('address-map'), { center: center, zoom: MALL_INITIAL_PIN ? 16 : 12, streetViewControl: false, zoomControl: false, gestureHandling: 'greedy' });
        mallMarker = new google.maps.Marker({ position: center, map: mallMap, draggable: true });

        mallMap.addListener('click', function (e) { mallSetPin(e.latLng, true); });
        mallMarker.addListener('dragend', function () { mallSetPin(mallMarker.getPosition(), true); });

        var searchInput = document.getElementById('af-search');
        var autocomplete = new google.maps.places.Autocomplete(searchInput, { fields: ['geometry', 'name', 'formatted_address', 'address_components'] });
        autocomplete.bindTo('bounds', mallMap);
        autocomplete.addListener('place_changed', function () {
            var place = autocomplete.getPlace();
            if (!place.geometry || !place.geometry.location) return;
            mallMap.setCenter(place.geometry.location);
            mallMap.setZoom(17);
            // 검색으로 랜드마크를 선택한 경우 place 응답에 이미 상세 주소 정보가 들어있으므로,
            // 좌표만 넘겨 재조회하는 mallReverseGeocode 대신 그 정보를 그대로 옮겨 쓴다.
            mallSetPin(place.geometry.location, false);
            if (place.address_components) {
                mallApplyAddressComponents(place.address_components);
            } else {
                mallReverseGeocode(place.geometry.location);
            }
            var detailField = document.getElementById('af-detail');
            detailField.value = place.formatted_address || place.name || detailField.value;
        });

        mallMap.controls[google.maps.ControlPosition.RIGHT_BOTTOM].push(mallCreateLocateControl());
    }

    function mallCreateLocateControl() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.title = '현재 위치 사용';
        btn.style.cssText = 'margin:10px;width:40px;height:40px;border:none;border-radius:50%;background:#fff;' +
            'box-shadow:0 1px 4px rgba(0,0,0,0.3);cursor:pointer;display:flex;align-items:center;justify-content:center;color:#555;font-size:18px;';
        btn.innerHTML = '<i class="fas fa-location-crosshairs"></i>';
        btn.addEventListener('click', function () { mallUseCurrentLocation(btn); });
        return btn;
    }

    function mallUseCurrentLocation(btn) {
        if (!navigator.geolocation) {
            alert('이 브라우저에서는 위치 정보를 사용할 수 없습니다');
            return;
        }
        btn.disabled = true;
        navigator.geolocation.getCurrentPosition(
            function (position) {
                btn.disabled = false;
                var latLng = new google.maps.LatLng(position.coords.latitude, position.coords.longitude);
                mallMap.setCenter(latLng);
                mallMap.setZoom(17);
                mallSetPin(latLng, true);
            },
            function () {
                btn.disabled = false;
                alert('위치 정보를 가져올 수 없습니다. 브라우저의 위치 권한을 허용해주세요');
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }

    function mallSetPin(latLng, doReverseGeocode) {
        mallMarker.setPosition(latLng);
        document.getElementById('af-lat').value = latLng.lat();
        document.getElementById('af-lng').value = latLng.lng();
        if (doReverseGeocode) mallReverseGeocode(latLng);
    }

    function mallReverseGeocode(latLng) {
        new google.maps.Geocoder().geocode({ location: latLng }, function (results, status) {
            if (status !== 'OK' || !results[0]) return;
            mallApplyAddressComponents(results[0].address_components);
        });
    }

    function mallApplyAddressComponents(components) {
        var comp = {};
        components.forEach(function (c) {
            if (c.types.indexOf('administrative_area_level_1') !== -1) comp.region = c.long_name;
            if (c.types.indexOf('administrative_area_level_2') !== -1 || c.types.indexOf('locality') !== -1) comp.city = c.long_name;
            if (c.types.indexOf('sublocality') !== -1 || c.types.indexOf('neighborhood') !== -1) comp.barangay = c.long_name;
        });
        if (comp.region) document.getElementById('af-region').value = comp.region;
        if (comp.city) document.getElementById('af-city').value = comp.city;
        if (comp.barangay) document.getElementById('af-barangay').value = comp.barangay;
    }

    document.getElementById('af-save-btn').addEventListener('click', function () {
        var errorBox = document.getElementById('address-form-error');
        errorBox.style.display = 'none';

        var lat = document.getElementById('af-lat').value;
        var lng = document.getElementById('af-lng').value;
        if (!lat || !lng) {
            errorBox.textContent = '지도에서 배송 위치를 선택해주세요';
            errorBox.style.display = '';
            return;
        }

        var btn = this;
        btn.disabled = true;
        var params = new URLSearchParams();
        params.set('address_id', document.getElementById('af-id').value);
        params.set('recipient_name', document.getElementById('af-recipient').value);
        params.set('phone', document.getElementById('af-phone').value);
        params.set('region', document.getElementById('af-region').value);
        params.set('city', document.getElementById('af-city').value);
        params.set('barangay', document.getElementById('af-barangay').value);
        params.set('detail_address', document.getElementById('af-detail').value);
        params.set('landmark', document.getElementById('af-landmark').value);
        params.set('lat', lat);
        params.set('lng', lng);
        params.set('is_default', document.getElementById('af-default').checked ? '1' : '0');

        fetch('/mall/ajax/save_address.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.href = '/mall/address.php';
                } else {
                    btn.disabled = false;
                    errorBox.textContent = data.error?.message || '저장 중 오류가 발생했습니다';
                    errorBox.style.display = '';
                }
            })
            .catch(function () {
                btn.disabled = false;
                errorBox.textContent = '저장 중 오류가 발생했습니다';
                errorBox.style.display = '';
            });
    });
</script>

<?php else: ?>

<?php $addresses = mall_address_list($member['id']); ?>
<?php if (empty($addresses)): ?>
    <p class="empty-state">등록된 배송지가 없습니다.</p>
<?php else: ?>
    <?php foreach ($addresses as $addr): ?>
    <div class="address-card">
        <div class="head">
            <span><?php echo htmlspecialchars(($addr['detail_address'] ?? '') !== '' ? $addr['detail_address'] : '상세주소 미입력'); ?></span>
            <?php if ($addr['is_default']): ?><span class="default-badge">기본</span><?php endif; ?>
        </div>
        <div class="body">
            <?php echo htmlspecialchars(trim(implode(' ', array_filter([$addr['barangay'], $addr['city'], $addr['region']])))); ?><br>
            랜드마크: <?php echo htmlspecialchars($addr['landmark']); ?><br>
            <?php echo htmlspecialchars($addr['recipient_name']); ?> · <?php echo htmlspecialchars($addr['phone']); ?>
        </div>
        <div class="actions">
            <a href="/mall/address.php?edit=<?php echo (int)$addr['id']; ?>">수정</a>
            <?php if (!$addr['is_default']): ?>
                <button type="button" class="addr-default-btn" data-id="<?php echo (int)$addr['id']; ?>">기본으로 설정</button>
            <?php endif; ?>
            <button type="button" class="addr-delete-btn danger" data-id="<?php echo (int)$addr['id']; ?>">삭제</button>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="sticky-cta">
    <a href="/mall/address.php?add=1" class="btn btn-primary btn-block">+ 새 배송지 추가</a>
</div>

<script>
    document.querySelectorAll('.addr-default-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var params = new URLSearchParams();
            params.set('address_id', btn.dataset.id);
            fetch('/mall/ajax/set_default_address.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(function (r) { return r.json(); })
                .then(function () { window.location.reload(); });
        });
    });
    document.querySelectorAll('.addr-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('이 배송지를 삭제할까요?')) return;
            var params = new URLSearchParams();
            params.set('address_id', btn.dataset.id);
            fetch('/mall/ajax/delete_address.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(function (r) { return r.json(); })
                .then(function () { window.location.reload(); });
        });
    });
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

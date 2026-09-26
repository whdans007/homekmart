<?php
/**
 * 실시간 배송 지도 — 배송중인 모든 기사 위치를 폴링으로 표시
 * Design Ref: mall-delivery-dispatch.design.md §5.4
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';

ensure_logged_in();
require_mall_permission('mall_management', '../../admin/index.php');

$current_page = 'delivery_map.php';
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.delivery_map.page_title'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
    <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-map-location-dot mr-2"></i><?php echo t('mall_admin.nav.delivery_map'); ?></h1>
    <p class="text-xs text-gray-400 mb-3"><?php echo t('mall_admin.delivery_map.hint'); ?></p>
    <div id="delivery-map" style="height:70vh;border-radius:8px;border:1px solid #e5e7eb;"></div>
</main>

<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode(MALL_GOOGLE_MAPS_API_KEY); ?>&callback=mallInitDeliveryMap" async defer></script>
<script>
var mallDeliveryMap;
var mallDeliveryMarkers = {};
var mallDeliveryInfoWindow;
var MALL_DEFAULT_CENTER = { lat: 15.1655, lng: 120.5556 }; // 클락/앙헬레스(Clark/Angeles) 중심

function mallInitDeliveryMap() {
    mallDeliveryMap = new google.maps.Map(document.getElementById('delivery-map'), {
        center: MALL_DEFAULT_CENTER, zoom: 14
    });
    mallDeliveryInfoWindow = new google.maps.InfoWindow();
    mallPollDriverLocations();
    setInterval(mallPollDriverLocations, 15000);
}

function mallPollDriverLocations() {
    fetch('ajax/get_driver_locations.php')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            var seen = {};
            data.data.drivers.forEach(function (d) {
                seen[d.driver_id] = true;
                var pos = { lat: parseFloat(d.last_lat), lng: parseFloat(d.last_lng) };
                var infoText = d.name + ' — ' + d.order_number;
                if (mallDeliveryMarkers[d.driver_id]) {
                    mallDeliveryMarkers[d.driver_id].setPosition(pos);
                    mallDeliveryMarkers[d.driver_id].infoText = infoText;
                } else {
                    var marker = new google.maps.Marker({ position: pos, map: mallDeliveryMap, title: d.name });
                    marker.infoText = infoText;
                    marker.addListener('click', function () {
                        mallDeliveryInfoWindow.setContent(marker.infoText);
                        mallDeliveryInfoWindow.open(mallDeliveryMap, marker);
                    });
                    mallDeliveryMarkers[d.driver_id] = marker;
                }
            });
            Object.keys(mallDeliveryMarkers).forEach(function (id) {
                if (!seen[id]) {
                    mallDeliveryMarkers[id].setMap(null);
                    delete mallDeliveryMarkers[id];
                }
            });
        });
}
</script>
</body>
</html>

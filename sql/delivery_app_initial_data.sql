-- ===================================================================
-- 배달 앱 초기 설정 데이터
-- 작성일: 2025-10-03
-- 설명: delivery_app_schema.sql 실행 후 기본 설정 데이터 입력
-- ===================================================================

-- 1. 배달 설정 (delivery_settings)
-- 앱 전체 기본 설정값
-- 이미 존재하는 경우 건너뛰기
INSERT IGNORE INTO delivery_settings (setting_key, setting_value, setting_type, description) VALUES
('default_delivery_fee', '50.00', 'number', '기본 배달비 (PHP)'),
('free_delivery_threshold', '1000.00', 'number', '무료 배달 최소 금액 (PHP)'),
('max_delivery_distance', '20', 'number', '최대 배달 거리 (km)'),
('default_delivery_time', '60', 'number', '기본 배달 시간 (분)'),
('service_start_time', '08:00', 'string', '서비스 시작 시간'),
('service_end_time', '22:00', 'string', '서비스 종료 시간'),
('min_order_amount', '200.00', 'number', '최소 주문 금액 (PHP)'),
('cod_enabled', '1', 'boolean', 'COD 결제 활성화 (1=활성화, 0=비활성화)'),
('gcash_enabled', '0', 'boolean', 'GCash 결제 활성화'),
('paymaya_enabled', '0', 'boolean', 'PayMaya 결제 활성화'),
('max_cod_amount', '10000.00', 'number', 'COD 최대 결제 금액 (PHP)'),
('app_commission_rate', '10', 'number', '앱 수수료율 (%)');

-- 2. 배달 지역 예시 (delivery_zones)
-- Angeles City 기준 배달 지역 설정
-- 실제 서비스 지역에 맞게 수정 필요!
-- 이미 존재하는 경우 건너뛰기

INSERT IGNORE INTO delivery_zones
(zone_name, barangay, city, province, delivery_fee, min_order_amount, free_delivery_threshold,
 estimated_delivery_time, max_delivery_time, service_start_time, service_end_time, is_active)
VALUES
-- Angeles City Center (중심가)
('Angeles City Center', NULL, 'Angeles City', 'Pampanga',
 50.00, 200.00, 1000.00, 30, 60, '08:00:00', '22:00:00', TRUE),

-- Angeles City North (북부)
('Angeles City North', NULL, 'Angeles City', 'Pampanga',
 70.00, 300.00, 1200.00, 45, 90, '08:00:00', '22:00:00', TRUE),

-- Angeles City South (남부)
('Angeles City South', NULL, 'Angeles City', 'Pampanga',
 70.00, 300.00, 1200.00, 45, 90, '08:00:00', '22:00:00', TRUE),

-- Angeles City East (동부)
('Angeles City East', NULL, 'Angeles City', 'Pampanga',
 80.00, 400.00, 1500.00, 60, 120, '09:00:00', '21:00:00', TRUE),

-- Angeles City West (서부)
('Angeles City West', NULL, 'Angeles City', 'Pampanga',
 80.00, 400.00, 1500.00, 60, 120, '09:00:00', '21:00:00', TRUE);

-- 3. 테스트용 배달 주소 (선택사항)
-- 개발/테스트용 주소 데이터
-- 실제 고객 주소는 앱에서 등록됨

-- 주의: user_id는 실제 존재하는 사용자 ID로 변경 필요
-- 아래 예시는 주석 처리됨
/*
INSERT INTO delivery_addresses
(user_id, address_name, house_number, street, barangay, city, province,
 postal_code, landmark, delivery_notes, latitude, longitude, is_default, is_active)
VALUES
(1, '집', '123', 'Main Street', 'Santo Rosario', 'Angeles City', 'Pampanga',
 '2009', 'Near SM Clark', '2층 파란색 집', 15.1450, 120.5890, TRUE, TRUE),

(1, '회사', '456', 'MacArthur Highway', 'Balibago', 'Angeles City', 'Pampanga',
 '2009', 'Across from Jollibee', '화이트 빌딩 3층', 15.1500, 120.5920, FALSE, TRUE);
*/

-- ===================================================================
-- 확인 쿼리
-- ===================================================================

-- 설정 확인
SELECT * FROM delivery_settings ORDER BY setting_key;

-- 배달 지역 확인
SELECT zone_name, city, delivery_fee, free_delivery_threshold, is_active
FROM delivery_zones
ORDER BY zone_name;

-- 결과 요약
SELECT
    (SELECT COUNT(*) FROM delivery_settings) as settings_count,
    (SELECT COUNT(*) FROM delivery_zones) as zones_count,
    (SELECT COUNT(*) FROM delivery_addresses) as addresses_count;

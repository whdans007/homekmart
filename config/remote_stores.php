<?php
/**
 * 지점(원격) 서버 연동 설정 — 메인 서버가 지점의 공개 HTTPS 엔드포인트에서 가격을 "가져오는(pull)" 용도.
 * ─────────────────────────────────────────────────────────────
 * ★ 중요: 이 파일은 운영 DB 접속정보(config/db_config.php)와 **완전히 분리**되어 있다.
 *   이 파일을 서버에 올리거나 수정해도 메인 DB 연결(로그인 등)에는 영향이 없다.
 *
 * 방식: 메인이 지점 MySQL(3306)에 직접 붙지 않고(공유호스팅에서 차단·불가),
 *       지점 서버의 공개 HTTPS 엔드포인트(admin/export_prices.php)를 호출해 JSON 을 받는다.
 *
 * 각 지점 설정:
 *  - export_url : 지점 서버의 가격 내보내기 엔드포인트(공개 HTTPS 주소). 반드시 https.
 *  - api_key    : 지점의 export_prices.php 와 **동일하게** 맞춰야 하는 공유 시크릿.
 *  - store_pattern / local_store_id : 메인 서버의 반영 대상 점포 매칭(이름 LIKE 또는 ID 강제).
 */

if (!function_exists('get_remote_store_db')) {
    function get_remote_store_db($code) {
        $stores = [
            'kimsmall' => [
                'label'          => '킴스몰 (KIMS MALL)',
                'export_url'     => 'https://kimsmall.homekmart.net/export_prices.php',
                // 킴스몰의 admin/export_prices.php 의 EXPORT_API_KEY 와 반드시 동일해야 함
                'api_key'        => 'ks_sync_7Gq2Xr9TfWm4Zc8Ld1Bv6Np3Yh0Ke5A',
                'store_pattern'  => 'KIMS%', // 메인 서버 stores.name 매칭 패턴
                'local_store_id' => 0,        // 0이면 패턴으로 자동 탐지
            ],
        ];
        return $stores[$code] ?? null;
    }
}

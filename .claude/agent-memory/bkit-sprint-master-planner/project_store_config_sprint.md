---
name: project-store-config-sprint
description: homekmart-store-config 스프린트(포스 대수/근무시간 점포별 설정) 문서는 작성 완료, 사용자 확인 4건 미해소로 구현 착수 차단 상태
metadata:
  type: project
---

`homekmart-store-config` 스프린트(포스 대수 + 근무시간 점포별 설정)의 master-plan / PRD / Plan / Design 4종이 2026-09-08 작성 완료되었으나, **Pre-flight Gate 미통과로 Do 단계 진입이 차단**된 상태다.

미해소 항목 (Plan §10 / Design 상단 표):
- Q1 운영 DB `DESCRIBE stores` — 저장소 SQL 백업은 2026-03-12 스냅샷이라 신뢰 불가
- Q2 `office_schedule_items.supervisor_shift_time` 현재 ENUM 정의/데이터 분포
- Q4 `office/sales/pos2_entry.php` 용도 (사용자 확인 필요)
- Q5 GY/Mid 교대 시간의 정답 — 매출 모듈과 스케줄 모듈이 서로 다른 값 사용 중
- Q6/Q7 POS 3대 이상 점포 실재 여부 + 대수 상한

**Why:** 이 스프린트는 실매출 금액 테이블과 영속 ENUM 데이터를 건드리므로, 운영 DB 스키마 미확인 상태에서 마이그레이션을 실행하면 데이터 손상 위험이 있다. 그래서 문서 단계에서 의도적으로 멈춰 두었다.

**How to apply:** 사용자가 이 스프린트 구현을 요청하면 먼저 Q1~Q7 상태를 확인하고, 여전히 미해소라면 구현 전에 해소를 요청한다. 문서를 재생성하지 말고 기존 4종을 갱신한다.

관련: [[feedback-plan-owner-tagging]]

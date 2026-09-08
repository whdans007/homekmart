---
name: feedback-plan-owner-tagging
description: 이 프로젝트의 Plan 문서는 작업 항목마다 (Claude Code)/(Codex) 담당자 태그를 달고, 미확인 항목은 별도 차단 섹션으로 분리해야 한다
metadata:
  type: feedback
---

계획 문서를 작성할 때 두 가지를 항상 지킨다:

1. **작업 항목마다 `(Claude Code)` 또는 `(Codex)` 담당자 태그**를 단다. 요구사항 표, Impact Analysis의 파일 목록, Implementation Order 전부에.
2. **아직 확인되지 않은 항목은 "설계 전 확인 필요" 차단 섹션**으로 분리하고, 각 항목이 설계의 어느 부분을 막는지 명시한다. 본문에 섞어 쓰지 않는다.

**Why:** 이 프로젝트는 Codex와 Claude Code가 별도 워크트리에서 병렬 작업하고 Claude Code가 병합 게이트를 맡는다(`docs/00-conventions/agent-orchestration.md`). 담당 태그가 없으면 같은 파일을 두 에이전트가 동시에 건드려 충돌한다. 또 사용자는 미확인 사항을 근거 있는 결론과 섞어 제시하는 것을 원하지 않는다 — 확인해야 할 것을 명시적으로 요청받았다.

**How to apply:** 분담 기준은 agent-orchestration.md §3 — 도메인 판단/DB 스키마/권한/병합은 Claude Code, 정형화된 CRUD 폼과 스펙 확정 후 반복 화면 수정은 Codex. 한 파일 안에서 담당이 갈리면 "순차 공유(Claude → Codex)"로 표기한다.

관련: [[project-store-config-sprint]]

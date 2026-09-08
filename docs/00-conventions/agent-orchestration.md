# 컨벤션: Codex / Claude Code 작업 분담 오케스트레이션

**목적**: OpenAI Codex와 Claude Code가 같은 저장소에서 병렬로 작업할 때 충돌 없이 진행하고, 병합 시 코드 스타일을 일관되게 유지하기 위한 규칙.

---

## 1. 기본 원칙

- 작업 단위는 **bkit PDCA Plan 문서** (`docs/01-plan/features/{feature}.plan.md`) 를 그대로 사용한다. Codex든 Claude Code든 같은 Plan 문서를 스펙으로 보고 구현한다.
- 각 에이전트는 **독립된 git worktree + 브랜치**에서 작업한다. 같은 브랜치를 동시에 건드리지 않는다.
- **병합 게이트는 항상 Claude Code**가 담당한다. Codex 결과물이든 Claude 결과물이든, `main`에 들어가기 전에 Claude Code가 `/code-review`로 검토하고 코드 스타일(네이밍, 한글 UI 텍스트, 주석 유무, prepared statement 사용 등 이 프로젝트 컨벤션)을 통일시킨 뒤 병합한다.

## 2. 브랜치 / 워크트리 네이밍

- 브랜치: `codex/{feature}`, `claude/{feature}`
- 워크트리 경로: 저장소 옆 `../homekmart-worktrees/{agent}-{feature}/` (예: `../homekmart-worktrees/codex-fresh-product-catalog/`)
- 생성/삭제는 `scripts/agent-worktree.ps1` (PowerShell) 또는 `scripts/agent-worktree.sh` (bash) 사용

## 3. 역할 분담 기준

| 담당 | 적합한 작업 | 이유 |
|------|------------|------|
| Codex | 스펙이 명확한 반복 작업 — CRUD 화면, Excel 파싱, 단순 리스트/폼 | 정형화된 패턴, 빠른 처리 |
| Claude Code | 도메인 판단이 필요한 작업 — 권한 체계, DB 스키마 변경, 마진 계산, 매입-몰 연동 로직 | 기존 컨벤션·리스크 파악 필요 |
| Claude Code (항상) | 최종 리뷰 + 병합 + 스타일 통일 | 병합 게이트 고정 담당 |

## 4. 충돌 방지 — 파일 단위 분배

- 작업을 나눌 때 **파일 단위로도 겹치지 않게** 분배한다. 특히 아래 공용 파일은 한 번에 한 에이전트만 수정:
  - `admin/partials/header.php`, `admin/partials/footer.php`
  - `config/db_config.php`
  - `lib/permission_helper.php`, `lib/margin_helper.php`, `lib/session_helper.php`
  - `lang/ko.json`, `lang/en.json`
- Plan 문서 작성 시 "담당 파일" 목록을 명시해두면 분배 판단이 쉬워진다.

## 5. 병합 절차

1. 각 에이전트가 자기 브랜치에서 작업 완료 → PR 또는 diff 준비
2. Claude Code가 `/code-review` 실행 (버그 + 재사용/단순화 + 이 프로젝트 컨벤션 위반 여부)
3. 발견된 스타일 차이(들여쓰기, 네이밍, 한글 사용 여부 등)는 Claude Code가 병합 전에 직접 맞춤
4. `main`으로 병합 후 워크트리/브랜치 정리 (`scripts/agent-worktree.ps1 remove`)

## 6. 트레이드오프

병렬 진행으로 속도는 빨라지지만, 두 에이전트의 코드 스타일 차이는 자동으로 맞춰지지 않는다 — **병합 시점에 Claude Code가 항상 검토·통일하는 것을 전제로 한다.**

#!/usr/bin/env bash
# Codex/Claude Code 병렬 작업용 git worktree 관리 스크립트.
# 규칙: docs/00-conventions/agent-orchestration.md 참고.
#
# 사용법:
#   ./scripts/agent-worktree.sh create codex fresh-product-catalog [base-branch]
#   ./scripts/agent-worktree.sh list
#   ./scripts/agent-worktree.sh remove codex fresh-product-catalog
set -euo pipefail

action="${1:-}"
agent="${2:-}"
feature="${3:-}"
base_branch="${4:-main}"

repo_root="$(git rev-parse --show-toplevel)"
worktree_root="$(dirname "$repo_root")/homekmart-worktrees"

require_agent_feature() {
    if [[ -z "$agent" || -z "$feature" ]]; then
        echo "agent(codex|claude) 와 feature 값이 필요합니다." >&2
        exit 1
    fi
    if [[ "$agent" != "codex" && "$agent" != "claude" ]]; then
        echo "agent는 codex 또는 claude 여야 합니다." >&2
        exit 1
    fi
}

case "$action" in
    create)
        require_agent_feature
        branch="$agent/$feature"
        path="$worktree_root/$agent-$feature"
        mkdir -p "$worktree_root"
        if [[ -d "$path" ]]; then
            echo "이미 존재합니다: $path" >&2
            exit 1
        fi
        git worktree add -b "$branch" "$path" "$base_branch"
        echo "워크트리 생성 완료"
        echo "  경로: $path"
        echo "  브랜치: $branch (base: $base_branch)"
        ;;
    remove)
        require_agent_feature
        branch="$agent/$feature"
        path="$worktree_root/$agent-$feature"
        git worktree remove "$path" --force
        git branch -D "$branch"
        echo "워크트리/브랜치 제거 완료: $path / $branch"
        ;;
    list)
        git worktree list
        ;;
    *)
        echo "사용법: $0 {create|remove|list} [agent] [feature] [base-branch]" >&2
        exit 1
        ;;
esac

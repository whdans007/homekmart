<#
.SYNOPSIS
  Codex/Claude Code 병렬 작업용 git worktree 관리 스크립트.
  규칙: docs/00-conventions/agent-orchestration.md 참고.

.EXAMPLE
  ./scripts/agent-worktree.ps1 create -Agent codex -Feature fresh-product-catalog
  ./scripts/agent-worktree.ps1 list
  ./scripts/agent-worktree.ps1 remove -Agent codex -Feature fresh-product-catalog
#>
param(
    [Parameter(Mandatory=$true, Position=0)]
    [ValidateSet('create', 'remove', 'list')]
    [string]$Action,

    [ValidateSet('codex', 'claude')]
    [string]$Agent,

    [string]$Feature,

    [string]$BaseBranch = 'main'
)

$ErrorActionPreference = 'Stop'

$repoRoot = (git rev-parse --show-toplevel)
if (-not $?) { throw "git 저장소 안에서 실행하세요." }

$worktreeRoot = Join-Path (Split-Path $repoRoot -Parent) 'homekmart-worktrees'

function Get-Paths($Agent, $Feature) {
    if (-not $Agent -or -not $Feature) {
        throw "-Agent(codex|claude) 와 -Feature 값이 필요합니다."
    }
    $branch = "$Agent/$Feature"
    $path = Join-Path $worktreeRoot "$Agent-$Feature"
    return @{ Branch = $branch; Path = $path }
}

switch ($Action) {
    'create' {
        $p = Get-Paths $Agent $Feature
        New-Item -ItemType Directory -Force -Path $worktreeRoot | Out-Null
        if (Test-Path $p.Path) { throw "이미 존재합니다: $($p.Path)" }
        git worktree add -b $p.Branch $p.Path $BaseBranch
        Write-Host "워크트리 생성 완료"
        Write-Host "  경로: $($p.Path)"
        Write-Host "  브랜치: $($p.Branch) (base: $BaseBranch)"
    }
    'remove' {
        $p = Get-Paths $Agent $Feature
        git worktree remove $p.Path --force
        git branch -D $p.Branch
        Write-Host "워크트리/브랜치 제거 완료: $($p.Path) / $($p.Branch)"
    }
    'list' {
        git worktree list
    }
}

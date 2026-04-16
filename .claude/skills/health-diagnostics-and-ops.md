# Skill: Health, Diagnostics, and Operations

## Purpose

Use this skill when implementing or reviewing operational surfaces in `apntalk/laravel-freeswitch-esl`.

## When to use

Use this skill for:

- health reporters
- diagnostic services
- readiness/liveness surfaces
- operator-facing status commands
- metrics/logging integration points
- per-node and aggregate operational reporting

## Operational philosophy

Operational surfaces must be:

- structured
- explicit
- per-node aware
- multi-PBX aware
- useful without raw dumps alone

Avoid shallow “connected/not connected” status if richer truth exists.

## Minimum health model

Health should be able to express:

- connection state
- subscription state
- worker assignment scope
- retry state
- last heartbeat
- drain state
- recent failures
- inflight counts where relevant

## Design rules

### 1. One PBX or many
Operators should be able to inspect:
- one node
- grouped nodes
- all active nodes

### 2. Machine-usable state first
Prefer stable fields over vague prose.

### 3. Laravel-native surfaces
Support:
- services
- artisan commands
- optional HTTP integration if in scope
- structured log/metrics hooks

## Good package-owned components

- `HealthReporter`
- `NodeHealthSummaryBuilder`
- `AggregateHealthReporter`
- `ConnectionDiagnosticsService`
- `WorkerDiagnosticsService`

## Review checklist

Verify:

- does health reporting include multi-PBX scope?
- does it expose actionable operational state?
- does it avoid relying on raw dumps alone?
- does it stay inside Laravel/control-plane ownership?
- are commands/docs/tests aligned?

## Output format

Return:

1. health/ops summary
2. missing observability fields if any
3. operator usefulness judgment
4. tests/docs needed
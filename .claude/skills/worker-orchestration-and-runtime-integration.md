# Skill: Worker Orchestration and Runtime Integration

## Purpose

Use this skill when implementing or reviewing long-lived worker behavior in `apntalk/laravel-freeswitch-esl`.

This repo owns worker orchestration and Laravel-side runtime bootstrapping.
It does not own generic ESL runtime internals that belong in `apntalk/esl-react`.

## When to use

Use this skill for:

- worker commands
- worker supervisors
- assignment runners
- drain/shutdown orchestration
- integration with APNTalk async runtime
- reconnect-aware orchestration
- runtime session identity and operational reporting

## Ownership rules

### This repo owns
- assignment-aware startup
- worker target resolution
- Laravel command integration
- supervisor/orchestrator behavior
- operational state exposure
- package-level drain/retry/backpressure coordination

### `apntalk/esl-react` owns
- generic async connection loop
- reusable reconnect lifecycle
- reusable subscription/session runtime behavior
- low-level runtime primitives

## Worker requirements

Preserve:

- single-node mode
- all-active mode
- cluster mode
- tag mode
- provider-scope mode where supported
- graceful shutdown
- drain mode
- reconnect-aware supervision
- node-level failure isolation

## Preferred components

Good package-owned components include:

- `WorkerRuntime`
- `WorkerSupervisor`
- `AssignmentRunner`
- `DrainController`
- `RetryController`
- `BackpressureController`

Use adapters/factories for APNTalk runtime integration instead of duplicating runtime internals.

## Review checklist

Before approving worker changes, verify:

1. does this assume only one PBX?
2. does this duplicate `apntalk/esl-react` internals?
3. is startup/resolution explicit?
4. is failure isolated by node/scope?
5. are drain and shutdown behaviors explicit?
6. is operational state inspectable?
7. are tests possible without a live PBX for most cases?

## Output format

Return:

1. worker behavior summary
2. ownership/boundary judgment
3. orchestration model
4. risks
5. tests required
6. docs/command changes required
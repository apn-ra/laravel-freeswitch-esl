# Skill: Laravel Package Hardening

## Purpose

Use this skill when hardening `apntalk/laravel-freeswitch-esl` for safe evolution and release-readiness.

## When to use

Use this skill for:

- public API audits
- service-provider/config audits
- testbench coverage review
- release-readiness review
- docs/code alignment
- compatibility/support checks
- CI/static-analysis/test hardening

## Hardening goals

- stable public package surface
- explicit support policy
- docs that match code
- tests that match claims
- predictable container/config behavior
- minimal accidental architectural drift

## Primary audit areas

### 1. Public API surface
Review:
- public namespaces
- interfaces
- service bindings
- command names/options
- config shape
- migration expectations

### 2. Laravel integration correctness
Review:
- service provider registration
- publishable config/assets
- testbench coverage
- container resolution behavior

### 3. Boundary discipline
Review whether code has drifted into:
- protocol ownership
- async runtime ownership
- replay engine ownership

### 4. Operational claims
Check whether docs and commands reflect actual behavior.

### 5. Support matrix
Confirm PHP/Laravel support assumptions are explicit and tested where practical.

## Preferred outputs

For hardening passes, return:

1. release-readiness summary
2. files or surfaces audited
3. admission blockers
4. non-blocking improvements
5. tests run / not run
6. documentation gaps
7. recommended next pass only

## Anti-patterns

Avoid:
- broad speculative cleanup
- mixing unrelated refactors into release hardening
- claiming live verification without actually performing it
- allowing docs to overstate package ownership
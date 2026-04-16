# Skill: Architecture Boundary Guard

## Purpose

Use this skill when working on `apntalk/laravel-freeswitch-esl` to ensure changes stay within the correct package boundary.

This repository owns:

- Laravel integration
- multi-PBX control plane
- provider and node resolution
- worker assignment orchestration
- Laravel-facing health/diagnostics
- replay integration wiring

This repository does **not** own:

- low-level ESL protocol parsing
- reusable ReactPHP ESL runtime internals
- canonical replay engine primitives

Those belong to:

- `apntalk/esl-core`
- `apntalk/esl-react`
- `apntalk/esl-replay`

## When to use

Use this skill when:

- planning architecture changes
- reviewing implementation proposals
- adding new services/interfaces
- touching worker/runtime logic
- touching replay-related functionality
- auditing whether a requested change belongs in this repo

## Core rules

### 1. Do not re-own `apntalk/esl-core`
Do not move or centralize here:
- raw ESL frame parsing ownership
- typed protocol event ownership
- command/result protocol ownership
- core client/protocol behavior

### 2. Do not re-own `apntalk/esl-react`
Do not move or centralize here:
- generic async connection loop ownership
- generic reconnect engine ownership
- reusable transport/session lifecycle primitives
- reusable subscription runtime ownership

### 3. Do not re-own `apntalk/esl-replay`
Do not move or centralize here:
- canonical replay envelopes
- canonical replay cursor/store/projector contracts
- replay runner ownership
- replay engine ownership

### 4. This repo is Laravel-first
Prefer:
- service providers
- container bindings
- config
- artisan commands
- DB-backed resolution
- package integration services
- operational reporting

## Decision procedure

Before implementing, ask:

1. Is this Laravel integration or control-plane behavior?
2. Does it assume multi-PBX support?
3. Does it duplicate a lower-level APNTalk package?
4. Could this be solved as a thin adapter instead of new ownership?

If the answer suggests another package owns it, stop and state the boundary conflict explicitly.

## Output style

When using this skill, explicitly state:

- what belongs in this repo
- what belongs outside it
- whether the proposed change is acceptable here
- whether an adapter is enough
- whether follow-up work should happen in another APNTalk package
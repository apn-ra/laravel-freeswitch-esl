# Skill: Multi-PBX Schema and Resolution

## Purpose

Use this skill when designing or reviewing schema, models, and resolution logic for multi-PBX operation in `apntalk/laravel-freeswitch-esl`.

## When to use

Use this skill for:

- migrations
- Eloquent models
- registry lookups
- profile resolution
- worker assignment targeting
- health-state persistence
- schema review for control-plane behavior

## Required model concepts

The schema should support at least:

- provider families
- PBX nodes
- connection profiles
- worker assignments
- health/status snapshots
- optional tags, clusters, and scoped targeting

## Core schema principles

### 1. Inventory is live and queryable
The schema must support real operational targeting, not static metadata only.

### 2. Resolution is explicit
A worker should be able to resolve:
- one PBX node
- all active nodes
- cluster scope
- tag scope
- provider scope

### 3. Runtime identity persists
Store enough identity to correlate:
- provider
- PBX node
- profile
- worker session where appropriate
- health and diagnostic records

### 4. Secrets are referenced, not embedded casually
Prefer secret references over inline plaintext storage in operational models.

## Suggested entities

- `pbx_providers`
- `pbx_nodes`
- `pbx_connection_profiles`
- `worker_assignments`

Optional related tables may include:
- `pbx_health_snapshots`
- `worker_runtime_sessions`
- `replay_retention_policies`

## Review checklist

When reviewing schema changes, verify:

- does it still support multi-PBX operation?
- does it still support grouped assignment?
- are provider and node concepts separate?
- are secrets handled safely?
- are health and runtime identity fields sufficient?
- does it avoid single-host assumptions?
- are query paths testable and indexed sensibly?

## Deliverable format

When using this skill, return:

1. schema judgment
2. missing fields/tables if any
3. resolution impact
4. backward-compatibility risk
5. migration/test/doc recommendations
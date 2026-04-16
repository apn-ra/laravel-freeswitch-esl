# Skill: Replay Integration Wiring

## Purpose

Use this skill when working on replay-related behavior in `apntalk/laravel-freeswitch-esl`.

This package integrates replay into Laravel applications.
It does not own canonical replay abstractions.

## When to use

Use this skill for:

- replay capture wiring
- replay store bindings
- retention integration
- replay inspection commands
- session/correlation metadata propagation
- Laravel-side replay configuration and docs

## Ownership rules

### This repo may own
- Laravel container bindings for replay services
- config and policy wiring
- replay inspection commands
- retention/integration policies
- metadata enrichment needed for Laravel operations

### `apntalk/esl-replay` owns
- replay envelopes
- replay cursor contracts
- replay store/projector contracts
- scenario runners
- replay engine semantics

## Design goals

### 1. Integration, not duplication
Prefer binding and orchestration over redefinition.

### 2. Operational usefulness
Replay surfaces should help operators inspect:
- provider
- node
- worker session
- time window
- correlation IDs

### 3. Deterministic boundaries
Do not claim replay determinism unless supported by replay package behavior and tests.

## Good deliverables

- Laravel bindings for replay services
- replay config
- replay inspection command design
- retention policy integration
- tests for Laravel-side replay wiring
- docs clarifying package boundaries

## Review checklist

Check:

- is this redefining canonical replay primitives?
- is metadata propagation sufficient?
- can operators inspect replay partitions meaningfully?
- is config/policy shape explicit?
- are tests focused on Laravel integration, not replay engine duplication?

## Output format

Return:

1. replay integration scope
2. ownership check
3. services/bindings/commands to add
4. tests needed
5. docs to update
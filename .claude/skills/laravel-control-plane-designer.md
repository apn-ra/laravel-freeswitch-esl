# Skill: Laravel Control Plane Designer

## Purpose

Use this skill to design and implement the Laravel-facing control plane for `apntalk/laravel-freeswitch-esl`.

The control plane includes:

- PBX registry
- provider registry
- connection profile resolution
- worker assignment resolution
- secret resolution
- package-facing operational identity

## When to use

Use this skill when working on:

- service providers
- config publishing
- repository/services for PBX nodes and profiles
- provider-driver registration
- resolution pipelines
- control-plane documentation
- DB-backed inventory behavior

## Design goals

### 1. Multi-PBX first
Never assume a single PBX host.

Every control-plane path should support:
- one PBX node
- multiple nodes
- cluster/group targeting
- tag/provider targeting where applicable

### 2. Database-backed truth
Use config for framework defaults and driver maps.
Use database for live operational inventory.

### 3. Provider-aware abstraction
Use concepts such as:
- provider
- node
- profile
- assignment
- resolved connection context

Avoid reducing everything to “the FreeSWITCH host”.

### 4. Stable Laravel integration
Design services that fit Laravel naturally:
- explicit service classes
- interfaces where needed
- container bindings
- testbench-friendly construction
- predictable command integration

## Preferred components

Examples of good package-owned components:

- `DatabasePbxRegistry`
- `ProviderDriverRegistry`
- `ConnectionResolver`
- `ConnectionProfileResolver`
- `WorkerAssignmentResolver`
- `SecretResolver`
- `ConnectionContextFactory`

## Preferred output

For control-plane tasks, produce:

1. scope summary
2. proposed services/interfaces
3. data flow
4. config vs DB responsibility split
5. multi-PBX impact
6. tests needed
7. docs to update

## Anti-patterns

Avoid:
- `.env`-only connection design
- hardcoded single-host services
- facades as the primary architecture
- implicit runtime resolution with no inspectable state
- hidden provider assumptions
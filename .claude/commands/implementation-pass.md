Implement the requested scoped change in `apntalk/laravel-freeswitch-esl` while preserving package boundaries.

Before changing anything, verify:
- this work belongs in this Laravel package
- it does not duplicate ownership from `apntalk/esl-core`
- it does not duplicate ownership from `apntalk/esl-react`
- it does not duplicate ownership from `apntalk/esl-replay`

Repository role:
- Laravel integration
- multi-PBX control plane
- provider/node/profile/assignment resolution
- Laravel worker orchestration
- operational reporting and diagnostics
- replay integration wiring

Implementation rules:
- prefer explicit contracts and service classes
- prefer DB-backed control-plane behavior over single-host assumptions
- preserve multi-PBX targeting
- use thin adapters for APNTalk package integration where needed
- update docs/tests with any public-surface change
- do not mix unrelated cleanup into this pass

Required output:
1. summary of what changed
2. files added/modified
3. package-boundary judgment
4. tests run / not run
5. documentation updates made
6. known gaps or follow-up items
Implement or refine replay integration in `apntalk/laravel-freeswitch-esl`.

This repo may own:
- Laravel bindings for replay services
- replay configuration and policy integration
- replay inspection commands
- metadata enrichment for provider/node/session/correlation identity
- Laravel operational integration for replay

This repo must not become the owner of:
- canonical replay envelopes
- replay cursor/store/projector core contracts
- replay engine semantics
- scenario runner ownership
  Those belong in `apntalk/esl-replay`.

Requirements:
- prefer integration over duplication
- keep replay partitionable by provider, PBX node, worker session, and time window
- ensure operationally useful replay inspection
- update docs/tests for any integration-surface changes

Output:
1. replay integration summary
2. ownership/boundary decision
3. bindings/services/commands added or changed
4. tests run / not run
5. docs updated
6. remaining limitations
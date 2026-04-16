Implement or refine worker orchestration in `apntalk/laravel-freeswitch-esl`.

This repo owns:
- assignment-aware startup
- Laravel command integration
- worker supervision/orchestration
- drain/retry/backpressure coordination at package level
- worker diagnostics and runtime visibility

This repo does not own:
- generic ESL runtime internals
- generic reconnect engine internals
- reusable low-level async session primitives
  Those belong in `apntalk/esl-react`.

Requirements:
- preserve multi-PBX worker modes
- support explicit targeting such as single node, cluster, tag, all-active, provider scope if applicable
- preserve graceful shutdown and drain behavior
- preserve reconnect-aware orchestration
- preserve node-level failure isolation
- keep runtime state inspectable

Do not:
- reduce worker design to a single-host forever loop
- add hidden singleton runtime behavior
- duplicate reusable async runtime internals

Output:
1. worker behavior implemented or revised
2. ownership/boundary judgment
3. files changed
4. tests run / not run
5. docs/commands updated
6. operational risks or follow-up items
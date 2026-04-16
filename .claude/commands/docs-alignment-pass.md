Audit and align documentation for `apntalk/laravel-freeswitch-esl`.

Focus on:
- README
- public API docs
- architecture docs
- control-plane docs
- worker/runtime docs
- replay integration docs
- compatibility/support docs
- changelog entries if affected

Rules:
- docs must match actual code and tests
- docs must preserve package boundaries
- docs must not imply this repo owns protocol/runtime/replay internals that belong elsewhere
- docs should explain multi-PBX concepts clearly
- docs should prefer operator clarity over marketing language

Deliver:
1. docs-alignment summary
2. files updated
3. inaccurate claims corrected
4. boundary clarifications added
5. remaining documentation gaps
6. whether code/tests already support the updated docs
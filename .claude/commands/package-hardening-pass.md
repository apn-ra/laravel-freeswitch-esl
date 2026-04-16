Perform a hardening pass for `apntalk/laravel-freeswitch-esl`.

Audit and improve only within release-hardening scope.

Focus on:
- public API clarity
- service-provider/container correctness
- config surface stability
- command behavior consistency
- docs/code alignment
- Laravel Testbench coverage
- package-boundary discipline
- multi-PBX correctness in control-plane/runtime-facing surfaces

Do not:
- add new product capabilities unless required to fix a hardening blocker
- perform broad unrelated refactors
- silently expand the public surface
- claim live behavior verification unless actually performed

Required output:
1. hardening summary
2. files changed
3. admission blockers fixed
4. remaining non-blocking issues
5. tests run / not run
6. documentation changes
7. release-readiness judgment
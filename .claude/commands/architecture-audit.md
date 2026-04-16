Review this repository’s current architecture against the intended role of `apntalk/laravel-freeswitch-esl`.

Authoritative package role:
- this repo owns Laravel integration, multi-PBX control plane, worker assignment orchestration, operational surfaces, and replay integration wiring
- `apntalk/esl-core` owns protocol/core ESL contracts, parsing, typed protocol behavior
- `apntalk/esl-react` owns async ReactPHP runtime and long-lived connection lifecycle
- `apntalk/esl-replay` owns replay-safe capture/store abstractions and replay tooling

Your task:
1. audit the current repo structure, contracts, services, docs, and tests
2. identify architectural drift
3. identify where responsibilities are correctly placed
4. identify where responsibilities appear to belong in another APNTalk package
5. recommend the smallest safe architectural corrections

Constraints:
- do not propose speculative platform redesign
- do not reassign ownership casually
- preserve multi-PBX-first design
- preserve Laravel-native integration style
- prefer thin adapters over duplicated internals

Output format:
1. overall verdict
2. correct current boundaries
3. drift or violations
4. recommended corrections
5. blockers vs non-blockers
6. docs/tests that should change
7. next recommended pass only
Perform a release-readiness audit for `apntalk/laravel-freeswitch-esl`.

Scope:
- release-facing docs
- changelog accuracy
- public API truthfulness
- package boundary consistency
- test coverage relative to claims
- config/command/docs alignment
- support-policy clarity
- operational documentation quality

Authoritative architectural posture:
- Laravel integration and multi-PBX orchestration live here
- core ESL protocol ownership lives in `apntalk/esl-core`
- async runtime ownership lives in `apntalk/esl-react`
- replay ownership lives in `apntalk/esl-replay`

Tasks:
1. audit repo-wide release-facing materials
2. identify inaccurate or overstated claims
3. tighten docs for operational clarity
4. run only the relevant verification commands for release confidence
5. avoid capability expansion unless needed for a release blocker

Output:
1. release-readiness summary
2. files added/modified
3. blockers
4. non-blocking issues
5. tests/verification run
6. recommended next step only
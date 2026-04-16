Implement or refine the Laravel multi-PBX control plane for `apntalk/laravel-freeswitch-esl`.

Focus areas may include:
- PBX registry
- provider registry
- connection profile resolution
- worker assignment resolution
- secret resolution
- DB-backed operational inventory
- service-provider/container integration for control-plane services

Architectural rules:
- never assume one PBX host
- provider, node, profile, and assignment are separate concepts
- config is for framework defaults and wiring
- database is for live PBX inventory and targeting
- preserve inspectable runtime identity

Do not:
- collapse the design to `.env`-only configuration
- build facade-first architecture
- hide resolution logic in ambiguous container magic
- duplicate lower-level ESL protocol/runtime concerns

Deliver:
1. summary of the control-plane changes
2. services/interfaces added or revised
3. schema/model/resolution impact
4. multi-PBX implications
5. tests added/updated
6. docs updated
7. remaining gaps only if directly relevant
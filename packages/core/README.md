# axiomasoft/sockit

SocKit is an embeddable, panel-isolated social engine for Laravel applications built directly on `axiomasoft/packman` (`packman-plugins`, `packman-panels`, `packman-schema`).

## Features

- **Storage-Boundary Panels & Surfaces:** Server-side panel isolation (`PanelId`, `PanelStorage`, `SockitPanelProvider`, `SockitPanelBuilder`) and surface incarnations (`SurfaceProvider`, `SurfaceRef`).
- **Trusted Actor & Audience Privacy:** `ActorContext`, `ActorContexts`, `PrivacyFloor`, `AudienceSpec`, `AudienceEvaluator`, `AudienceGate`, and fail-closed `SurfaceAudiencePolicy` / `ActionAuthorizer`.
- **Stateless Code Plugins:** `ReactionsPlugin`, `PostsPlugin`, and `GroupsPlugin` composed via `Packman\Plugins\Contracts\Registry\ExtensionPlugin` and `With*` traits with zero sibling imports and zero comments module (M6/D14).
- **Atomic SQL Operation Envelope:** Transactional receipts (`UNIQUE` claim + canonical payload digest), monotonic CAS revisions, non-content tombstones, and transactional outbox.

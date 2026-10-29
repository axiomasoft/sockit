# Граница ядра

| Модуль (`SocKit\<X>\`) | Что содержит | Публичное | Фаза |
|:--|:--|:--|:--|
| Attributes | `#[Api]`, `#[Spi(kind, since)]`, `SpiKind` (Implement, Call, Extend) | всё | P1 |
| Support | ids (UUIDv7), `HostEntityRef`, `ActorRef`, `ResourceRef`, `Revision`, `CommandKey`, `PayloadDigest`, `CanonicalJson`, `WireVersion` | Data/, Contracts/ | P1 |
| Actors | `ActorKind`, `ActorContext`, `ActorContextResolver` SPI, `TrustedActorContextFactory`, исключения | Data/, Contracts/, Enums/, Exceptions/ | P1 |
| Audience | `Scope`, `AudienceSpec`, `PrivacyFloor`, `PrivacyDescriptor`, `ContourResolver` SPI, `AudienceResolver` SPI, `AudienceVerdict`, `AudienceEvaluator` (чистый), `TenantResolver` SPI | всё кроме Internal | P1 |
| Surfaces | `SurfaceProvider`, `Surface` builder, `SurfaceKindId`, `SurfaceRef`, `SurfaceDefinitions` (compiled), `Incarnation`; runtime instances — P2 | Contracts/, Data/, Exceptions/, SurfaceProvider, Surface | P1 (compile) / P2 (runtime) |
| Operations | `OperationOutcome` (confirmed/rejected/pending/unknown), `OperationResult`, `CommandEnvelope`; конверт receipts/CAS/outbox/tombstones — P2 | Data/, Enums/, Contracts/ | P1 (типы) / P2 |
| Errors | `ProblemCode` enum-реестр, `ProblemSpec`, `SocKitException` иерархия | всё | P1 |
| Modules | `Module` контракт, `ModuleManifest`, `ModuleRegistrar`, `Modules::module()`/`add()` | всё кроме Internal | P1 |
| Configuration | `SocKitConfig` типизированные секции, `InvalidConfigurationException` с путём ключа | Data/, Exceptions/ | P1 |
| Testing | `FakeActorContextResolver`, `FakeContourResolver`, `FakeAudienceResolver`, `FakeTenantResolver` | всё (#[Api]) | P1 |
| Internal | компилятор реестров, codec | — | P1+ |

Модули posts/reactions/groups (P4/P5) — `packages/core/modules/<m>/src` с namespace `SocKit\Posts\`, `SocKit\Reactions\`, `SocKit\Groups\`; ядро их не импортирует.

Panels — обязательный модуль механизма: PanelId, PanelProvider, Panel builder, immutable PanelDefinition/PanelStorage, PanelDefinitions. Контракты P1.13; runtime P2, генератор P3. Surfaces всегда разрешается внутри panel. Schema-компиляция появляется в P3 под конкретного потребителя, не как универсальный plugin runtime в P1.

## Deletion tests

Минимальный потребитель L0 = likes/reactions на Article хоста, без posts/groups/Chatom/Forum. L1 = социальная лента Edufy.
| Элемент | Почему нужен L0/L1 | Решение |
|:--|:--|:--|
| Panels + storage descriptors | Нельзя выбрать независимые таблицы/settings одной entity в разных панелях | kernel mechanism |
| Surfaces + incarnation | Привязка к конкретной entity, политика/lifecycle и fencing | kernel mechanism |
| Trusted actor + current audience | Подмена actor и stale access | kernel mechanism |
| Modules | Ядро не знает функциональные модули по имени | kernel mechanism |
| Receipts/CAS/outbox/tombstones/problems | Retry, delete/replay и отказ без silent success | kernel mechanism |
| reactions | Полезная функция L0; отключается как модуль | optional module |
| posts/feed, groups | L0 работает без них | optional modules |
| comments/forum | Внешние владельцы Chatom/Forum | вне SocKit domain |
| UI/search/ranking/delivery | PHP API/reactions работает без них | отдельные треки |

## Три теста адреса

Механизм принадлежит ядру, если минимальный потребитель нуждается в нём; функция — модулю, если может быть отключена; чужое состояние принадлежит внешнему владельцу и доступно только через его публичный контракт. Наблюдение 2026-10-17: граница проверяется deletion test и публичной поверхностью.

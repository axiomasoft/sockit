# Оси расширения

| Ось | Механизм | Обязательность | Потребитель | Swap-тест | Фаза |
|:--|:--|:--|:--|:--|:--|
| Tenant/инсталляция | config FQCN `TenantResolver` | обязательный host resolver; standalone FQCN выбирается явно | Edufy (инсталляция = tenant), мульти-tenant хост | FakeTenantResolver | P1 |
| Доверенный актор | config FQCN `ActorContextResolver` | обязателен для операций | любой хост | FakeActorContextResolver | P1 |
| Контур | config FQCN `ContourResolver` | нужен для contour-scope | Edufy (class/school), FlexCRM (отдел/компания) | FakeContourResolver | P1 |
| Аудитория (batch) | config FQCN `AudienceResolver` | default — встроенный evaluator | хост с собственной ACL (FlexCRM task ACL) | FakeAudienceResolver | P1 |
| Surface kind | SurfaceProvider FQCN | хост объявляет свои | все | тест компиляции чужого провайдера | P1 |
| Модуль | ModuleRegistrar | opt-in `sockit.modules` | posts/reactions/groups | тест «модуль не включён → нет регистраций» | P1 |
| Content safety (prescreen) | SPI `ContentSafety` (verdict allow/hold/reject) | optional, configured unavailable ≠ success | Edufy (premoderation), ModKit-адаптер | FakeContentSafety | P4 |
| Media association | SPI `MediaResolver` | optional | Edufy, Memster | FakeMediaResolver | P4 |
| Projection/delivery | outbox consumer SPI | optional | уведомления хоста, мосты | fake consumer | P2 |
| Ranking | SPI `FeedRanker` | optional, default chronology | Memster | — (трек, без потребителя в v1 — не реализуется) | — |

Обязательные стадии конверта (авторизация, аудитория, receipt, lock+CAS, persist, outbox) расширением не отменяются; расширения могут только запретить/сузить (как AzGuard restrictions).

Panels/host target — отдельные SPI с потребителями L0 reactions и L1 feed. Физическая storage identity не предоставляется клиентом. Adapters AzGuard/Vaulter/Chatom/Forum — условные packages; нет обязательного общего ecosystem runtime.

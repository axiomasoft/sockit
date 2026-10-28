# Практики экосистемы

Факты ниже — наблюдение 2026-10-17, уточнённое в материалах плана 2026-10-28; подтвердить в P3.1/P7.1. Evidence — перенесённые в план наблюдения исходников и тестов, не доказательство запуска тестов соседей. Исторические ADR и примеры не являются production API.

## Матрица решений

| Практика и evidence в плане | Решение SocKit | Проверка владельца |
|---|---|---|
| Chatom compiler; Vaulter ProfileRegistry; AzGuard PanelRegistryTest: providers и регистрация | Принять сбор всех declarations, проверку графа и атомарный immutable freeze; duplicate и unknown — ошибка | P1.9/P1.13: перестановки providers, invalid unused graph, поздняя мутация |
| Chatom space-bound models и feasibility test; Vaulter HostKeyColumns | Адаптировать конкретные модели для panel/entity и независимые стратегии ключей; без runtime setTable | P3: hydration, relations, queue restore, одинаковые IDs панелей |
| AzGuard MakeCommand preflight; Chatom provider stub | Усилить: полный manifest и preflight, staging и cleanup; не обещать файловую транзакцию | P3: конфликт последнего пути и реальный consumer migrate/write/read |
| Chatom SchemaNames, ConnectionIdentity и StorageCollisionDetector | Принять проверку итоговых имён и physical identity; connection alias сам по себе недостаточен | P3: 63 байта, коллизии aliases, разные серверы |
| Chatom ADR lifecycle; Vaulter OwnsSchema и ModuleSchemas | Адаптировать одного schema owner/writer и append-only snapshots; отклонить install-all-offered-schema Vaulter | P3/P4: disable сохраняет данные, additive upgrade, rollback соседней панели |
| Chatom connection transactions; AzGuard AccessRequest и resource scope test | Адаптировать явные роли и panel connection для атомарного конверта | P2/P4: cross-installation deny, rollback state/receipt/outbox |
| Chatom ScopeRehydrator; AzGuard PanelContext; Vaulter tenant job boundary | Адаптировать scalar refs и повторную авторизацию; scoped не гарантирует Fiber isolation | P2/P6: A→B→A, exception, revoke и deleted actor |
| Chatom Comments readiness ADR и example fixture; Forum provider | Отклонить собственный comments domain и перенос singleton companion context; готовность API не подтверждена | P6/P7: standalone и отсутствие чужого SQL при выключенном adapter |
| Vaulter shared storage | Отклонить как default: SocKit задаёт storage отдельно на панель | P3: независимые таблицы и настройки Article/Product |

Evidence идентифицируется именами компонентов и тестов из `knowledge/ecosystem-practices`; источник — снимок плана. Противоречие Chatom docs и compiler о duplicate default ID разрешается по коду после повторного подтверждения. Устаревшие примеры API не копируются.

# Панели и storage

## Модель и компиляция

Panel — стабильная server-side конфигурация и граница storage, не UI, tenant или surface. Provider задаёт ID, host aliases, выбранные модули, настройки, connection, tables и concrete models. SurfaceRef включает panel, kind, host и incarnation; один host может присутствовать в нескольких панелях, а все host instances одной панели используют общую схему. Installation и доступ проверяет host, tenant_id не вводится.

Все providers сначала регистрируют declarations; booting компилирует modules → panels → surfaces, проверяет весь граф и атомарно публикует immutable snapshot. Ранний доступ и поздняя мутация — ошибка. Настройки объединяются defaults → global → panel без расширения policy ceiling; config содержит только scalars, arrays и FQCN. Singleton допустим для definitions, не для текущих actor/installation/panel.

## Schema и имена

Kernel владеет surface instances, receipts, outbox и tombstones; каждый модуль владеет своими fragments и единственным writer. Чужие таблицы не читаются, не изменяются и не ALTER-ятся. Offered, enabled и selected различаются; выключенный модуль не создаёт SQL или таблицы. На панели один storage; разделение физических таблиц aliases не поддерживается.

Schema compiler материализует выбранные fragments, model/table maps, connection и key strategies до DDL. Проверять полное ASCII имя с DB prefix и suffix, включая indexes/FKs, максимум 63 байта без усечения. Коллизия определяется driver, server/cluster fingerprint, database, schema и именем. Aliases одного сервера конфликтуют; разные известные серверы независимы. При неоднозначном proxy/failover нужен явный deployment descriptor; секреты в diagnostics запрещены.

## Генерация и upgrade

P3 строит manifest provider и models, затем отдельный manifest migration, проверяет пути, имена, modules и коллизии до записи, предлагает dry-run и staging с cleanup. Ошибка файловой системы сообщает уже записанные пути; атомарность filesystem не обещается. Повторный запуск — явный no-op или конфликт без перезаписи. Consumer владеет provider, models и миграциями; custom provider сохраняется.

Initial migration содержит frozen descriptor и fixed component versions или самодостаточный DDL snapshot; старые migrations не зависят от нового config. Create следует dependency order, drop — обратному. Добавление модуля создаёт additive migration; disable сохраняет данные, schema history и pending outbox с явным draining или quarantine. Удаление только явное и только для принадлежащих snapshot таблиц. Vendor и published migrations одновременно не запускаются.

## Приёмка

P3 проверяет реальный consumer с fixture module acme.demo-targets: генерация двух панелей, загрузка моделей, migrate/write/read, одинаковые IDs, rollback одной без изменения другой, старые snapshots после смены config, конфликт последнего файла и partial failure manifest. Это не social e2e: настоящий reactions smoke принадлежит P4. PostgreSQL integration требуется для DDL и concurrency; fixture или SQLite не заменяют его. Worker восстанавливает scalar refs и повторно проверяет actor и доступ, без system fallback.

Consumer-owned snapshots хранят versions, digest и ancestry; generated не означает applied. Для конкретных команд, preflight и recovery см. [runbook](../guides/panel-install-upgrade.md). Concrete Eloquent models — persistence extension; domain API не содержит ORM. Tombstone имеет composite identity: обычные одно-ключевые Eloquent операции по primary key не заменяют scoped composite операции.

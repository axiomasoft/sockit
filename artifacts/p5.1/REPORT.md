# PLAN1 P5.1

Owning writer: session `01a186df-7f50-7842-83d3-c901f2e029e3`, `codex/gpt-6.1-sol/high`, `danger-full-access`. Actual session metadata and turn context recorded in `runtime-proof.json`. Base HEAD: `508f30f896f61406205a8d7c1c8f61497a97cff4`.

Реализован явно подключаемый `sockit.groups`: provider, manifest/autoload, закрытые пустые settings, public enums JoinPolicy/MembershipState. Offered, enabled и selected проверены отдельно, включая 24 порядка providers и настоящий `config:cache` с fresh bootstrap без SQL. Core и соседние модули не импортируют Groups. PHPStan/Rector и архитектурные gates сканируют новый owner; отрицательные контроли сохранены и расширены. Composer lock изменён только для metadata path-пакета, версии зависимостей сохранены.

Append-only v1 описывает groups/group_members, generated concrete models/casts и единственный FK к собственной mapped groups. UUIDv7, полная installation/panel/management-surface identity, уникальные bounded digests с fail-closed full-ref verification, positive revisions/generation, nullable consent fence, enum/byte bounds проверены реальным PG16. Name и consent token ограничены 512 bytes, actor wire — 609 bytes. Тесты используют две панели, custom table/model map, driver prefix, максимальные UTF-8 refs, одинаковые opaque IDs, все enum values; down frozen snapshot сохраняет другую панель и host/decoy sentinels. Создание строки группы не создаёт membership. Group API/consent/audience операции и installed generator consumer остаются пунктам P5.2–P5.8.

До DDL штатные pg-run/PgTarget проверили invocation-owned container label, private loopback endpoint/volume, manifest mode 0600, exact *_test databases и run marker. Каждый PG запуск завершён с удалением своего контейнера/volume. Dev/prod не использовались.

| Check | Exit | Результат |
| --- | --- | --- |
| V1 | 0 | 23 tests, 2131 assertions |
| V2 | 0 | 7 PG tests, 1635 assertions |
| V89 | 0 | 55 arch tests, 830 assertions |
| V90 | 0 | PHPStan max, no errors |
| V91 | 0 | Pint passed |
| V92 (доп.) | 0 | Rector dry-run, no diff |
| V93 (доп.) | 0 | 36 existing module/schema feature tests, 328 assertions |
| V94 (доп.) | 0 | 10 existing social schema/disable PG tests, 1530 assertions |

Команды исполнены буквально; V*.json содержат check-evidence, cwd, actual exit/timestamps, source digest и SHA-256 логов. Итоговые проверки не содержат skipped/incomplete/warning/risky tests. V90/Rector печатают существующее startup-сообщение PHP об optional PHPStan turbo extension; exits — 0, ошибки анализа не подавлены. Evidence schema и хеши проверены отдельно. `source-fingerprint.json` фиксирует итоговые исходники; artifacts/foreign dirty исключены из source digest.

Начальные неуспешные проверки сохранены: V1 red до реализации/provider autoload; V2 — неканонический PanelId в maximum-ref тесте; V90 — nullable errorInfo в тестовом assertion; V92-initial=2 — Rector потребовал class constant в новом arch test. Причины исправлены, все затронутые проверки повторены на итоговом source fingerprint. Первичная попытка schema validation обнаружила absolute cwd в metadata; исправлено на repository-relative `.` без изменения реально выполненной команды.

Review mode=requested: независимый reviewer этой сессией не запускался, receipt не создавался. После scoped commit owning writer передаёт candidate координатору; ожидаемое ожидание review не является blocked. Routes и результаты принятых фаз не менялись; чужие dirty/untracked файлы оставлены outside. Dry-run commit хранится вне репозитория.

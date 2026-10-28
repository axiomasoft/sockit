# Трассировка требований

Источник — native PLAN1: brief, requirements-trace, canon-rules и panel-design. Актуальные решения D14–D16 имеют приоритет над историческими формулировками. P1 даёт контракты/fixtures и архитектурные проверки; это не end-to-end и не доказательство готовности runtime. Нижеследующие строки описывают полные требования; результаты P2 fixture указаны отдельно, P7/P8 условны. Связанные документы: [границы](core-boundary.md), [приватность](privacy.md), [панели](panels-and-storage.md), [внешние обсуждения](external-discussions.md).

## Brief B1–B21

| ID | Требование и владелец | Доказательство / остаётся |
|---|---|---|
| B1 | Комментарии у Chatom/Forum; D14, P1.3, P1.11 | P1 arch и границы; внешний API и P7 pending |
| B2 | Standalone; P1.3, P6 | P1 dependency gate; standalone conformance P6 pending |
| B3 | Forum владеет обсуждениями; P1.11, P7 | P1 границы; adapter P7 conditional |
| B4 | Минимальное ядро; P1.2/3/9, P2 | P1 deletion tests и arch; runtime P2 pending |
| B5 | Практики экосистемы; P1.11/13, P2/3 | P1 матрица и контракты; реализация P2/3 pending |
| B6 | Тезисы и arch gates; P1.2/3 | P1 документы и arch tests |
| B7 | Host-owned сущности; P1.5/10/13, P2/6 | P1 refs и bindings контракт/fixture; интеграция pending |
| B8 | Self-contained reads; план, P1.11 | knowledge плана и P1 документы; проверка актуальности продолжается |
| B9 | Детализация P1; план | Native items и проверки; P2+ детализируются отдельно |
| B10 | Факты экосистемы; P1.11 | P1 practices с provenance, не свидетельство нового runtime |
| B11 | Посты, реакции, feed, группы; P4/5 | Реализация P4 и optional P5 pending |
| B12 | Edufy и host policy; P1.7, P6 | P1 audience контракт/fixture; пилот P6 pending |
| B13 | Idempotency, CAS, outbox, tombstones; P2/4/6 | P1 operation контракт/fixture; транзакционные сценарии pending |
| B14 | Трассировка; P1.12, P9 | Эта матрица; release qualification P9 pending |
| B15 | UI, SDK и admin отдельно; P9 | P1 границы; проверка релиза P9 pending |
| B16 | Аудит и решения; knowledge/perplexity-adoption, P1.11 | P1 practices/adoption; применение будущими фазами pending |
| B17 | Панели и panel identity; P1.5/13/10, P2/3 | P1 контракт/fixture; storage и генерация pending |
| B18 | Consumer-owned генерация; P3 | P1 runbook; генерация и upgrade pending |
| B19 | Evidence и adapt/reject; P1.11, P6 | P1 practices; conformance P6 pending |
| B20 | Codex routes и выборочное review; D17, P1 | Native route и review records; каждый следующий item проверяется отдельно |
| B21 | Autopilot и handoff; D17 | Файлы управления и launcher; подготовка не доказывает выполнение checks |

## Reusable core S-01–S-10

| ID | Требование | Фаза и доказательство |
|---|---|---|
| S-01 | Host владеет actor, installation и surface refs | P1.5/6/10 контракт/fixture; P6 host pending |
| S-02 | Один writer body и state | P1.3/11 границы; P3/4 и внешний владелец pending |
| S-03 | Current privacy на каждом выходе | P1.7 контракт/fixture; P2/4 и conditional P8 pending |
| S-04 | Projection не заменяет текущую policy | P1.7 контракт/fixture; P4 revoke pending |
| S-05 | Versioned shrink/delete и tombstone replay | P2/4 runtime pending |
| S-06 | Хронология feed и ranking hook | P4 pending |
| S-07 | Safety отдельно от санкций и consent | P4 pending |
| S-08 | Standalone social без Chatom/CoreX | P1.3 arch; P6 runtime pending; обсуждения вне SocKit по D14 |
| S-09 | Durable events, revisions, subscribers и legacy adapters | P2/6 pending |
| S-10 | UI/admin/SDK и платные расширения optional | P1 границы; P9 qualification pending |

## Acceptance SN-01–SN-22

Все строки — proposed, не пройденные acceptance; source observations и fixtures не заменяют интеграционный прогон.

| ID | Сценарий и критерий | Владелец / статус |
|---|---|---|
| SN-01 | Manifest → API → host trace, один writer, точные refs и версии | P6 pending; comment trace у внешнего владельца |
| SN-02 | Spoofed author, другой контур и worker: trusted context, deny до effect | P1.6 контракт/fixture; P2/4 pending |
| SN-03 | Один человек в двух школах, разные роли: стабильный ref, observer без write | P1.7 контракт/fixture; P4 pending |
| SN-04 | Неизвестные grants/уровень/возраст: fail-closed и floor | P1.7 контракт/fixture; runtime pending |
| SN-05 | Revoke и archive: current access, historical policy, запрет записи | P2/4 pending |
| SN-06 | Comment lifecycle, parent, revision и media | Внешний владелец Chatom/Forum; не gate SocKit |
| SN-07 | Safety hold, consent revoke и host ban без обхода | P4 pending |
| SN-08 | Commit/rollback/crash: committed outbox, reconcile, честный remote outcome | P2 pending |
| SN-09 | Concurrent duplicate и lost response: один ref, conflict по fingerprint, lookup | P2 pending |
| SN-10 | Повтор promotion и изменение consent: replay, без повторной публикации | P4 pending |
| SN-11 | Privacy shrink и revoke: current deny и fences всех projections | P1.7 контракт/fixture; P2/4 pending, внешний adapter отдельно |
| SN-12 | Failed hide после consent decision: receipt/reconcile, privacy закрыта | P4 pending |
| SN-13 | Delete, hide, retention и erasure: actor/reason/revision и tombstone | P4 pending |
| SN-14 | Поздние события после delete: tombstone/incarnation, durable receipt | P2 pending |
| SN-15 | Restore с prior state и current admission; erased не воскресает | P4 pending |
| SN-16 | Rebuild/backup/clone: ledger и tombstones до reads, generation fence | P9 pending |
| SN-17 | Link-only announcement: containment и подтверждённый Chatom ref | P8 conditional, pending |
| SN-18 | Lost response/crash/remove: исходный key/backend и lookup | P8 conditional, pending |
| SN-19 | Duplicate event с другим payload/backend: conflict или quarantine | P8 conditional, pending |
| SN-20 | UUIDv7/ULID, opaque refs и routing без truncation | P8 conditional, pending |
| SN-21 | Erasure всех owner stores: receipts/counts, failure не success | P9 pending |
| SN-22 | Edufy и второй host без обязательных school schema и Chatom | P6 pending |

## Canon B-правила

| ID | Граница | Доказательство / владелец |
|---|---|---|
| B-01 | Один authoritative writer | P1 ownership; P3/4 runtime pending |
| B-02 | Денежный исполнитель операции | Не применимо: SocKit не проводит платежи |
| B-03 | Межсемейные зависимости через public API | P1.3 arch; P7/8 conditional pending |
| B-04 | Bridge отдельно, только integration state | P1.11 границы; P7/8 conditional pending |
| B-05 | Модули через контракты ядра | P1.3/9 arch и контракт; P4/5 pending |
| B-06 | Пакет не импортирует продукт | P1.3 arch; P6 host pending |
| B-07 | Чужие таблицы не читаются и не пишутся | P1 ownership; P3/7/8 pending |
| B-08 | Нет сети в DB transaction, unknown разрешается lookup | P1 invariants; P2 runtime pending |
| B-09 | Key + digest, конфликт при другом payload | P1 контракт/fixture; P2 concurrency pending |
| B-10 | Tenancy-neutral, без обязательного tenant_id | P1 refs/arch; P3 schema pending |
| B-11 | Authorization через порт, AzGuard optional | P1 SPI/arch; P6 integration pending |
| B-12 | Extraction gate или решение канона | P9 release qualification pending |
| B-13 | Имена пакетов и обоснованное ядро | P1 structure/deletion tests; P9 pending |
| B-14 | Омонимы разведены в глоссарии | P1 glossary; дальнейшие API проверяются по мере появления |
| B-15 | Платность — доступность пакета, не ветка кода | P1 границы; P9 pending |
| B-16 | MoneyWire без float на границе | Не применимо: SocKit не проводит платежи |

## Панели PANEL-01–PANEL-12

| ID | Сценарий | Владелец / доказательство |
|---|---|---|
| PANEL-01 | Две панели: refs и state не смешиваются | P1.5/13/10 контракт/fixture; P2/3/4 pending |
| PANEL-02 | Неизвестная панель, foreign binding, collision и server identity | P1.13 контракт; P3 schema pending |
| PANEL-03 | Boot без SQL, config:cache и fresh bootstrap | P1.9/13 проверки конфигурации; runtime DB не заявлен |
| PANEL-04 | Порядок providers, invalid graph, freeze и late mutation | P1.9/13/10 compile checks; не e2e |
| PANEL-05 | Две реальные панели, миграция, read/write и независимый rollback | P3/4 pending |
| PANEL-06 | Hydration и worker A→B→A без stale context | P2/3/6 pending |
| PANEL-07 | Module schema isolation, additive enable и disabled outbox | P3/4 pending |
| PANEL-08 | Reactions-only host без внешних пакетов и sibling import | P4/6 pending |
| PANEL-09 | Atomic mutation/receipt/outbox и concurrent claim | P2/4 PostgreSQL pending |
| PANEL-10 | Cross-installation, revoke, archive, containment и unknown deny | P1.7 контракт/fixture; P4 pending |
| PANEL-11 | Frozen migration, no overwrite, partial failure manifest | P3/4 pending |
| PANEL-12 | Нет comments domain/schema или mandatory Chatom; adapter не пишет чужие таблицы | P1.3 arch; P6 и conditional P7/8 pending |


## P2 runtime evidence

`tests/Feature/Consumer/StandaloneRuntimeTest.php` installs an independent Laravel consumer
from a copied package and checks discovery, cached configuration and fresh boot without SQL.
Its `acme.runtime` fixture uses two panels with the same host ID and exercises public surface
lifecycle, owner mutation, duplicate replay, payload/CAS conflict, a rollback fault, revoked
actor denial and relay to an SQL inbox on isolated PostgreSQL. Missing target resolver is
checked in a separate process. `tests/Integration/Runtime` covers the wider P2 transaction,
concurrency, fence, worker and outbox matrix. These observations do not establish complete
SN/PANEL acceptance: P3 generated migrations and models, P4 social functions, P6 real host
conformance and P9 release qualification remain pending. See `artifacts/p2.10/REPORT.md`.

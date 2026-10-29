# PLAN1 P5.4

Реализована panel-bound композиция group audience и content target через существующие SPI. Host явно выбирает `GroupAudienceResolver` через `AudienceResolver`; provider его автоматически не заменяет. Остальные четыре scope используют существующий evaluator и host ContourResolver. GroupRef wire и stable kernel signatures сохранены.

Членство проверяется по текущим active rows конкретной панели, installation, полному management binding/incarnation и trusted actor. Pending/invited/left, авторство и создание группы доступа не дают. Management admission, lifecycle и host target продолжают ограничивать чтение, batch, публикацию и lookup; read_only/archive не дают write. Disabled panel не обращается к groups SQL и не использует host group fallback.

Alias `group` сохраняет отдельный read-only management/receipt путь для явно допущенного nonmember. Consumer SurfaceProvider объявляет content surface с host `sockit.group`; запись поддерживается для той же группы. Необоснованная публикация group audience на иной host surface и unrelated group отклоняются. Target fence выполняет локальный host fence → group lock → проверку текущих group/member rows и management authority → post/reaction owner locks. Group lock сохраняется до commit. Host обязан доказать management admission/lifecycle своим существующим локальным authority fence; remote/unproven authority отклоняется, cross-DB атомарность не заявляется.

PG acceptance наблюдает `pg_blocking_pids`/`pg_stat_activity` при leave vs publication/reaction в обоих порядках. При ожидающем content contender `FOR UPDATE NOWAIT` подтверждает свободную post row. Если leave выигрывает, content effect отсутствует; если content выигрывает, его durable effect сохраняется, последующий leave отзывает чтение и receipt disclosure. Отзыв после commit может скрыть ответ через финальный current guard.

| Check | Exit | Результат |
| --- | ---: | --- |
| V1 | 0 | 78 tests, 207 assertions: composition + прежние Audience tests |
| V2 | 0 | 20 tests, 266 assertions: текущая приватность, public posts/reactions, PG races |
| V89 | 0 | 57 tests, 877 assertions: все architecture gates и negative controls |
| V90 | 0 | PHPStan max, без новых baseline/suppressions |
| V91 | 0 | Pint passed |
| V92 | 0 | 52 tests, 2268 assertions: весь Integration/Groups, включая P5.1–P5.3 |
| V93 | 0 | 1 test, 452 assertions: реальный generated posts/feed/reactions consumer P4.9 |
| V94 | 0 | 7 tests, 120 assertions: весь Unit/Groups |

V1/V2/V89/V90/V91 выполнены буквально по item. Все восемь evidence JSON проверены по native `check-evidence/v1` с `defs`; SHA256 логов и привязка к source fingerprint проверены. Fingerprint 859 файлов не изменился после проверок. PostgreSQL запускался только через `scripts/testing/pg-run.py`: invocation-owned container/volume, loopback endpoint, точные owner/decoy `*_test`, version/run marker и Laravel connection identity доказаны до fixture DDL. Skipped/incomplete/warning/risky tests и timeout не засчитывались. V90 сохраняет прежнее PHP startup warning о недоступной turbo-extension; анализ завершился без ошибок.

Session: `01a1871a-9dc1-7690-a6f3-224ee4814e7b`; runtime: `codex/gpt-6.1-sol/xhigh`, `danger-full-access` (проверено по session metadata, `runtime-proof.json`). Base HEAD: `0a9e10ca0ee547eacbec33706be30057c6a75f90`.

Review requested: reviewer/subagents не запускались, review receipt не создавался. После scoped commit следует native finish с настоящими checks/evidence; ожидаемый review gate передаётся координатору для fresh read-only review. Routes и следующий пункт не изменялись; foreign dirty/untracked остаются outside.

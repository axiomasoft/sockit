# PLAN1 P5.5 — PostgreSQL membership

Проверены реальные Groups API на отдельных PostgreSQL соединениях и процессах. Барьеры подтверждают ожидание по `pg_blocking_pids`: duplicate claim даёт один effect, digest conflict и CAS отклоняют конкурента; rollback, stale и revoked consent не оставляют membership effects. В обоих порядках проверены approve/leave, join/leave и invite/закрытие policy; свежий leave после выигравшей активации выполняется явно.

Проверены rollback pending/active member, aggregate, receipt и outbox, полная actor identity при совпадении bounded digest, исторический replay после leave, доступ к защищённому post и receipt после revoke, независимость panel claims/state/locks и изоляция вложенных worker actor contexts. PostgreSQL запускается исключительно через `pg-run.py`: invocation-owned container, отдельные owner/decoy базы, проверка контейнера, endpoint, версии и database marker до fixture DDL.

V1: 24 теста, 397 assertions, exit 0; V89: 57 тестов, 878 assertions, exit 0; V90 и V91: exit 0. Дополнительно весь Integration/Groups: 98 тестов, 3117 assertions, exit 0. Evidence `check-evidence/v1` и SHA-256 логов проверены по native schema; fingerprint связывает результат с исходным деревом. V90 сообщает прежнее предупреждение загрузки PHPStan turbo extension, но анализ завершился без ошибок. Skipped/incomplete/warning/risky tests и timeout не принимаются как успех.

Executor: `codex/gpt-6.1-terra/high`, session `01a18755-d354-7250-896c-6761343640f1`; base HEAD `5bf226dfc1208105f366ca93acc3de186db4d1ab`.

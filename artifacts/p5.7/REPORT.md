# PLAN1 P5.7

Session `01a1877e-2f34-73f1-bc7d-27800fafcec5`; заданный executor `codex/gpt-6.1-terra/high`, sandbox `danger-full-access`. Base main `8890b41309fad5fc89d2b18afedb6e07b7d912d0`. Метаданные фактической модели в окружении отдельно не предоставлены.

Composer устанавливает отдельную nonsymlink-копию production пакета; генератор создаёт две панели и миграции. Через публичные API проверены auto/approval/invite/closed, отказ и неизвестный consent, отсутствие чтения до active, публикация и reaction, текущие host ban/consent и ancestor target revoke, read-only observer, archive и unknown containment. После leave автора повторно проверяются read, history, feed, target, reaction, count и lookup при сохранённых candidate/ref/receipt. Mixed batch проверяет две панели и две installation; вложенные worker contexts проверяются при исключениях.

В установленном consumer два независимых PostgreSQL backend с детерминированным барьером проверяют leave перед publication/reaction: ожидающая операция не удерживает post lock и отклоняется после commit leave, изменение group generation не допускает stale write. Порядок group до post lock проверен по SQL. Существующий fixture дополнительно проверяет оба порядка сериализации. Groups-only consumer мигрирует и делает join без загрузки Posts/Reactions/Chatom/Forum и без их таблиц; P4 reactions-only regression остаётся в V1.

V1, V89, V90, V91 — буквальные команды и результаты в соответствующих check-evidence/log. V90: существующее предупреждение загрузки необязательного PHPStan turbo, exit 0, No errors. PostgreSQL запускался только через pg-run: отдельный контейнер с label, loopback, уникальные *_test базы и manifest/marker, проверенные до DDL. Это fixture-host proof, не конформность Edufy/Chatom/Forum и не P9 erasure.

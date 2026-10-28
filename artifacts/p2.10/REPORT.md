# P2.10 — standalone consumer и P2 runtime

В `tests/Fixtures/Consumer` находится отдельное Laravel 13 приложение с собственными
`composer.json`, решённым `composer.lock`, bootstrap, artisan, host adapters и `acme.runtime`.
`StandaloneRuntimeTest` создаёт временную копию приложения и текущего `packages/core`,
устанавливает пакет с `--no-dev` и `symlink=false`, сравнивает каждый файл установленного
пакета с кандидатом и проверяет граф зависимостей. Пакет обнаруживается Laravel без ручной
регистрации. Default и объявленная конфигурация проходят `config:cache` и новый процесс;
listener, установленный до bootstrap, и менеджер соединений фиксируют 0 SQL и 0 соединений.

Перед подготовкой схемы тест проверяет приватный manifest и реальные owner/decoy подключения
к PostgreSQL 16; production миграции не запускаются. В отдельном consumer процессе public
surface API создаёт две панели с одинаковым `article:42`, читает их и выполняет fixture
mutation. Проверены lookup и replay исходного ключа, typed payload conflict и CAS loser,
исключение после outbox SQL с откатом state/revision/receipt/outbox и сохранением полных
снимков B и decoy, revoke actor, archive/resume/delete и tombstone. Relay доставляет одно
событие в SQL inbox с одним effect; другое устаревшее событие помещается в quarantine,
повторный poll не создаёт effect. Отдельный cached-config процесс проверяет typed отказ при
отсутствующем target resolver и отсутствие мутации. JSON результата и SQL counts в логе V1.

`ConnectionCleanupTest` повторяет создание и teardown 35 раз в одном процессе: owner DB
sessions остаются baseline=0/final=0. Teardown освобождает consumer PDO, Laravel connections
и fixture PDO; обработчики запросов больше не удерживают fixture сильной циклической ссылкой.
Дочерние процессы закрывают свои соединения при завершении. Исходные V2 и повтор до исправления
упали с исчерпанием клиентов; лимит PostgreSQL не повышался. После исправления полный V2:
169 passed, 3204 assertions. V3 после отдельного повтора: 295 passed, 3725 assertions. Один
предыдущий V3 завершился из-за ошибки параллельного процесса PHPStan; его повтор прошёл,
причина предыдущего сбоя не установлена.

Все обязательные проверки последнего кандидата: V1=0, V2=0, V3=0, V4=0, V5=0, V6=0,
V90=0, V91=0. Команда, время, session, код и SHA-256 лога каждой проверки записаны в
`V*.json`; сами логи — `V*-evidence.log`. V1 содержит fingerprint установленного пакета,
проверенные SQL counts и список assertions. В логах нет credentials. V4 замечания Rector
исправлены в 16 названных им файлах; затронутые проверки повторены.

Это доказательство core runtime и тестового consumer, а не полноценный social e2e. P3
production generators/migrations и models, P4 reactions/posts, P6 реальный Edufy/host pilot
и P9 release qualification остаются отдельной работой. Полные SN/PANEL acceptance и D11
не объявляются закрытыми; cross-database atomic revocation и exactly-once remote delivery
не обещаются.

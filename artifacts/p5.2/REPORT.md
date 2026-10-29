# P5.2

Добавлен guarded Groups API: создание на host management surface, чтение метаданных и CAS смены join policy. Текущие host admission, target и ActionAuthorizer проверяются при чтении и раскрытии receipt; alias `group` предназначен только для guarded lookup. Создатель автоматически не становится участником. Состояние группы, receipt и outbox сохраняются через существующий envelope на соединении панели.

Проверки V1, V2, V89, V90 и V91 выполнены на зафиксированном source fingerprint; PostgreSQL использует созданный текущим запуском изолированный контейнер и проверенный fixture manifest. Membership/consent и content surface остаются следующими пунктами P5.

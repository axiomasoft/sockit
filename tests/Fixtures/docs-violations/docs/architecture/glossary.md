# Глоссарий

| Термин | Значение |
|:--|:--|
| Panel | Стабильная декларация модулей, настроек, привязок и storage; не tenant и не UI. |
| Storage descriptor | Неизменяемое отображение connection, таблиц и моделей панели. |
| Surface kind | Объявленный тип поведения и политики поверхности. |
| Surface instance | Конкретная сущность хоста в панели; panel-bound surface несёт panel identity. |
| Incarnation | Поколение instance для защиты от устаревших событий после удаления. |
| Backend binding | Привязка к внешнему владельцу через его публичный контракт. |
| Privacy scope | Форма аудитории: direct, group, contour, platform или public. |
| Contour / contour level | Граница хоста и её уровень, например класс или школа. |
| Floor | Ограничение допустимой публикации, не разрешение чтения. |
| Actor / ActorContext | Доверенный субъект и контекст, полученные от хоста. |
| Installation / tenant | Контекст изоляции хоста; не панель и не обязательный tenant_id. |
| Social reaction / message reaction / forum vote | Разные состояния, принадлежащие SocKit, Chatom и Forum соответственно. |
| External comment owner | Chatom Comments или Forum владеет телом, деревом и модерацией. |
| Module / plugin | Модуль — включаемая функция SocKit; plugin — механизм расширения, не обязательный общий runtime. |

# Контракты SPI

## Шаблон документации SPI

Для каждого SPI при реализации укажите назначение и реализатора; точную сигнатуру; результаты и ошибки; default или его отсутствие; consistency boundary; fake и swap-пример; правила версионирования. До реализации сигнатура не считается подтверждённой.

## Реестр P1

- `TenantResolver`: доверенная инсталляция и изоляция.
- `ActorContextResolver`: доверенный текущий актор.
- `ContourResolver`: текущие факты контура.
- `AudienceResolver`: вычисление аудитории; обязательные guards не обходятся.
- `SurfaceAudiencePolicy`: admission и containment.
- `ActionAuthorizer`: ограничение действия.
- `TargetResolver`: видимость и lifecycle сущности хоста.
- `PanelProvider`, `SurfaceProvider`, `Module`: декларация и композиция.

Точные сигнатуры, ошибки и swap-примеры заполняются по мере реализации соответствующих пунктов P1.

# PLAN1 P4.10

Документированы реальные API reactions и posts/feed, host target/fence, команды, privacy и генерация. Сверка P4.1–P4.9 связала evidence, log hashes и source fingerprints с SN/PANEL; P4.8 V2 подтверждает production reactions consumer на PostgreSQL, сохраняя исторический результат P3. P4.9 подтверждает наблюдателя read-only и текущую privacy в своей области. Внешние adapters P7/P8, host qualification P6 и полная erasure/release P9 остаются pending.

Исправлена изоляция P3 consumer: до выполнения проверяется отсутствие собственных таблиц; после теста удаляются только они, без CASCADE, на проверенной invocation-owned БД. Применены замечания Rector в 17 файлах без baseline или исключений. Первоначальные V401 и V403 завершились с exit 2; исправленные проверки ниже выполнены заново.

| Check | Exit | Результат |
|---|---:|---|
| V1 | 0 | Сверка P4 evidence и фазовых gates |
| V89 | 0 | 53 tests, 596 assertions |
| V90 | 0 | PHPStan No errors |
| V91 | 0 | Pint passed |
| V401 | 0 | 471 tests, 6314 assertions |
| V402 | 0 | 313 tests, 9226 assertions |
| V403 | 0 | Rector clean |
| V404 | 0 | Docs OK |

PostgreSQL запущен только через pg-run.py; все evidence содержат реальные команды, streams, hashes и source fingerprint и проверены по check-evidence schema. PHPStan сохраняет startup warning необязательного turbo extension. Runtime session `01a186c0-245b-7f41-b049-0f343f206418`: codex/gpt-6.1-terra/medium, danger-full-access; metadata — runtime-proof.json. Review mode none.

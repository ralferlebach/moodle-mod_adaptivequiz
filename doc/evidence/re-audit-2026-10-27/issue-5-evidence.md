# mod_adaptivequiz #5 – Evidenz: Fortsetzen oder neu starten (Re-Audit 27.10.2026)

**Stand:**
- v3: `v-3.0` @ `8ef48b8`
- Legacy: `ALiSe-v-1.2.0-legacy` @ `f95a747`
- beide in diesem Paket mit Version 2026102800

**Gegenstücke:**
- local_catquiz 1.3 (2026102406) bzw. 1.2 (2026102405)
- Adapter `v-3.0` 2026102600 bzw. Legacy 2026101801

**Umgebung:** Moodle 4.5.15 (Legacy) und 5.1.8/5.2+/5.3+ (v3), PHP 8.3.6, PostgreSQL 16/17, MariaDB 10.11/11.4,
Chrome 147. Die Behat-Matrix mit Exit-Codes steht in local_catquiz `doc/re-audit-2026-10-27.md` §3.

- **Echte Browserläufe:** `local/catquiz/tests/behat/catquiz_attempt_resume.feature` mit 6 Szenarien:
  - Die drei Zustände `resumable`, `last_item_expired_but_attempt_resumable` und `attempt_expired`.
  - Reload und Zurück bei offenem Dialog, Doppelklick, parallele Tabs: Es bleibt genau ein offener Versuch, ohne
    doppelte Slots.
  - Negativfälle: veralteter sesskey, fremde Attempt-ID, manipulierte Aktion.
  - **Zeitablauf während der Wahl** (Fix in diesem Paket): Das Zeitlimit beendet den Versuch mit Stopgrund
    `timelimit`, nicht „neu gestartet“.
  - Jedes Szenario prüft den Zustand aller Versuche der Person: Stopgrund, `timefinished`, Fragen, Antworten,
    Duplikate, CAT-Ergebnis.
- **PHPUnit:** `tests/local/attempt/resume_test.php` mit dem neuen Fall
  `test_restart_of_an_attempt_out_of_time_records_the_time_limit` und dem Finalizer-Aufruf des CAT-Modells.
- **Matrix:** 5.3 PG grün (6/6), 4.5 PG Legacy siehe §3.
- **Grenze:** Abschluss und Notenbuch nach Neustart sind über die bestehenden Szenarien von `result_gradebook_completion.feature`
  abgedeckt. Einen eigenen Lauf „Neustart → Notenbuch“ gibt es nicht.

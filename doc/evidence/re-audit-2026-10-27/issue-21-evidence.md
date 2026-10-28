# mod_adaptivequiz #21 – Evidenz: Trusted HTML/JS ohne Stored XSS (Re-Audit 27.10.2026)

**Stand:**
- v3: `v-3.0` @ `8ef48b8`
- Legacy: `ALiSe-v-1.2.0-legacy` @ `f95a747`
- beide in diesem Paket mit Version 2026102800

**Gegenstücke:**
- local_catquiz 1.3 (2026102406) bzw. 1.2 (2026102405)
- Adapter `v-3.0` 2026102600 bzw. Legacy 2026101801

**Umgebung:** Moodle 4.5.15 (Legacy) und 5.1.8/5.2+/5.3+ (v3), PHP 8.3.6, PostgreSQL 16/17, MariaDB 10.11/11.4,
Chrome 147. Die Behat-Matrix mit Exit-Codes steht in local_catquiz `doc/re-audit-2026-10-27.md` §3.

- `attempt_feedback_trust.feature` mit 3 Szenarien:
  - Vertrauenswürdiger Autor: Edit → Save → Reload → Edit → Versuchsende → Feedback, mit Link, Tabelle, `data-*`, Datei
    und Skript.
  - Nicht vertrauenswürdig und **gefälschtes Feld**: `<script>`, Event-Handler, `javascript:`, SVG – nichts läuft.
  - `forceclean` bereinigt auch den vertrauenswürdigen Autor.
- Dazu `attempt_feedback.feature` (#21-Szenarien) und die PHPUnit-Rundreise- und Negativtests. Die Trust-Matrix steht
  in `doc/attempt-feedback-trust.md`.
- 5.3 PG grün, 4.5 PG siehe Matrix.
- **Legacy-Befund:** Der Editor der Versuchsrückmeldung nimmt in der Legacy-Linie keine Dateien an. Es gilt
  `maxfiles` 0, es gibt keinen Dateibereich `attemptfeedback` und kein `adaptivequiz_pluginfile`. Ein von Hand
  eingesetztes `@@PLUGINFILE@@` ging verloren und löste eine Debug-Meldung aus (`format_text()` ohne Umschreiben).
  - Das Legacy-Szenario prüft deshalb HTML und Skript ohne Datei.
  - Dateien in der Rückmeldung sind eine Funktion der v3-Linie.
  - Soll Legacy sie können, wäre das ein eigenes Issue: Rückportierung von Entwurfsbereich, `pluginfile`, Backup und
    Privacy.

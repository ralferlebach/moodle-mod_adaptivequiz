# mod_adaptivequiz #18 – Evidenz: Zwei Modals und echte Pluginfile-Antworten (Re-Audit 27.10.2026)

**Stand:**
- v3: `v-3.0` @ `8ef48b8`
- Legacy: `ALiSe-v-1.2.0-legacy` @ `f95a747`
- beide in diesem Paket mit Version 2026102800

**Gegenstücke:**
- local_catquiz 1.3 (2026102406) bzw. 1.2 (2026102405)
- Adapter `v-3.0` 2026102600 bzw. Legacy 2026101801

**Umgebung:** Moodle 4.5.15 (Legacy) und 5.1.8/5.2+/5.3+ (v3), PHP 8.3.6, PostgreSQL 16/17, MariaDB 10.11/11.4,
Chrome 147. Die Behat-Matrix mit Exit-Codes steht in local_catquiz `doc/re-audit-2026-10-27.md` §3.

- `catquiz_question_modal.feature`: zwei Modals nacheinander. Das Bild in der Frage wurde **tatsächlich geladen**
  (Schritt `the image … in the question dialogue has loaded`: `naturalWidth > 0`). Die Pluginfile-Antwort ist HTTP 200
  mit Bild-MIME, protokolliert im Netzwerkbeleg.
- Gleiches Testpaket wie #16, eigene Abnahme. Grenze: nur Chrome.

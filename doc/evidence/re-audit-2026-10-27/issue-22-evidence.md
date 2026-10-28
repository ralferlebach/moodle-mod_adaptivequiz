# mod_adaptivequiz #22 – Evidenz: Review-Navigation und instanzgebundenes Feedback (Re-Audit 27.10.2026)

**Stand:**
- v3: `v-3.0` @ `8ef48b8`
- Legacy: `ALiSe-v-1.2.0-legacy` @ `f95a747`
- beide in diesem Paket mit Version 2026102800

**Gegenstücke:**
- local_catquiz 1.3 (2026102406) bzw. 1.2 (2026102405)
- Adapter `v-3.0` 2026102600 bzw. Legacy 2026101801

**Umgebung:** Moodle 4.5.15 (Legacy) und 5.1.8/5.2+/5.3+ (v3), PHP 8.3.6, PostgreSQL 16/17, MariaDB 10.11/11.4,
Chrome 147. Die Behat-Matrix mit Exit-Codes steht in local_catquiz `doc/re-audit-2026-10-27.md` §3.

- **Zwei Instanzen, dieselbe Person, unterschiedliches Feedback:** `local/catquiz/tests/behat/catquiz_instance_feedback.feature`.
  - Jede Ergebnisseite zeigt nur ihr Feedback.
  - Manipulierte Links (fremde `uattid`, `id` oder `cmid`) zeigen nichts.
  - Eine andere Person sieht mit denselben Links nichts.
  - Die Lehrperson sieht je Versuch das Feedback der eigenen Aktivität.
- **Navigation:** `attempt_review_navigation.feature`. „Zurück“ führt zur Versuchsliste der Person, „Zum Kurs“ zum Kurs.
  Das gilt auch ohne Header und Breadcrumb wie in LTI. Dazu Tastaturbedienung und axe (wcag143).
- **Grenze:** Ein echter LTI-Provider-Lauf ist hier nicht möglich. Der eingebettete Fall ist ohne Header nachgestellt.

# mod_adaptivequiz #16 – Evidenz: Historisches Review bei verborgener Aktivität (Re-Audit 27.10.2026)

**Stand:**
- v3: `v-3.0` @ `8ef48b8`
- Legacy: `ALiSe-v-1.2.0-legacy` @ `f95a747`
- beide in diesem Paket mit Version 2026102800

**Gegenstücke:**
- local_catquiz 1.3 (2026102406) bzw. 1.2 (2026102405)
- Adapter `v-3.0` 2026102600 bzw. Legacy 2026101801

**Umgebung:** Moodle 4.5.15 (Legacy) und 5.1.8/5.2+/5.3+ (v3), PHP 8.3.6, PostgreSQL 16/17, MariaDB 10.11/11.4,
Chrome 147. Die Behat-Matrix mit Exit-Codes steht in local_catquiz `doc/re-audit-2026-10-27.md` §3.

- **Modal-, QUBA- und Rechte-DoD:** `local/catquiz/tests/behat/catquiz_question_modal.feature` mit 3 Szenarien.
  - Zwei beantwortete Fragen mit eingebettetem Bild und STACK-Asset, Aktivität danach verborgen, beide Modals
    nacheinander.
  - Eigentümer, Prüfende mit Capability, Fremde und Rolle ohne Recht.
  - Purgierte Ressourcen: sichtbarer Fehler statt Spinner.
  - Netzwerkbelege (Status, MIME) über `CATQUIZ_NETWORK_EVIDENCE`.
- **`reviewattempt.php`, entschieden:** Die Seite bleibt eine Berichtsseite für Prüfende und an das Kursmodul gebunden.
  `attempt_review_navigation.feature` belegt:
  - Prüfende mit `viewreport` und `viewhiddenactivities` sehen den Versuch auch bei verborgener Aktivität.
  - Abgewiesen werden eine nicht bearbeitende Lehrperson, eine Prüfrolle ohne Sicht auf verborgene Aktivitäten und die
    teilnehmende Person.
  - Die teilnehmende Person erreicht ihr Ergebnis über `attemptfinished.php`.
- **Grenze:** Firefox ist hier nicht installiert, gelaufen ist nur Chrome.

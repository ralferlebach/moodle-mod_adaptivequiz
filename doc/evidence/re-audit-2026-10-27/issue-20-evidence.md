# mod_adaptivequiz #20 – Evidenz: Stopgrund-Dialog (Re-Audit 27.10.2026)

**Stand:**
- v3: `v-3.0` @ `8ef48b8`
- Legacy: `ALiSe-v-1.2.0-legacy` @ `f95a747`
- beide in diesem Paket mit Version 2026102800

**Gegenstücke:**
- local_catquiz 1.3 (2026102406) bzw. 1.2 (2026102405)
- Adapter `v-3.0` 2026102600 bzw. Legacy 2026101801

**Umgebung:** Moodle 4.5.15 (Legacy) und 5.1.8/5.2+/5.3+ (v3), PHP 8.3.6, PostgreSQL 16/17, MariaDB 10.11/11.4,
Chrome 147. Die Behat-Matrix mit Exit-Codes steht in local_catquiz `doc/re-audit-2026-10-27.md` §3.

- `attempt_stop_notice.feature` mit 8 Szenarien:
  - kein Modal bei `maximumquestions`;
  - „keine Frage mehr“ ohne zweite Anzeige nach Reload;
  - ohne JavaScript bleiben Grund und Feedback erreichbar;
  - Outline mit 5 Codes: timelimit, manual, restarted, error, unbekannt. Geprüft mit axe (wcag131, wcag141, wcag412),
    Name und Beschreibung über `aria-describedby`, Fokus bleibt im Dialog, Escape schließt, danach ist das Feedback für
    Hilfstechnik erreichbar.
- **Zeitlimit während des Antwort-POSTs:** `local/catquiz/tests/behat/catquiz_stop_reasons.feature`, „The time runs out“.
  Beide Antworten sind in der Usage gespeichert und bewertet, auch die nach Ablauf. Nur dann behauptet der Hinweis
  „Antwort gespeichert“.
- **Grenze:** Ein Screenreader-Protokoll (NVDA) gibt es hier nicht. Ersetzt ist es durch axe und die ARIA-Prüfungen im
  Browser.

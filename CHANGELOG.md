# Changelog – mod_adaptivequiz (ALiSe)

## 2026102800 – Nacharbeiten zum Re-Audit vom 27.10.2026

Nachweise je Issue: `doc/evidence/re-audit-2026-10-27/issue-<nr>-evidence.md`.

- #5 (Fehlerbehebung): Läuft die Zeit eines Versuchs ab, während die Wahl „fortsetzen oder neu beginnen“ offen ist,
  beendet jetzt das Zeitlimit den Versuch. Gespeichert werden der Stopgrund `timelimit` und der Hinweis zum Zeitlimit.
  Vorher stand dort „neu gestartet“. Dazu PHPUnit (`resume_test::test_restart_of_an_attempt_out_of_time_records_the_time_limit`)
  und Behat in local_catquiz (`catquiz_attempt_resume.feature`): drei Zustände, Reload/Back, Doppelklick, parallele
  Tabs, fremde und manipulierte Aktionen, Zeitablauf während der Wahl.
- #16/#18: `reviewattempt.php` bleibt eine Berichtsseite für Prüfende und an das Kursmodul gebunden. Entschieden und
  getestet: Prüfende mit `mod/adaptivequiz:viewreport` und `moodle/course:viewhiddenactivities` sehen den Versuch
  auch bei verborgener Aktivität. Eine nicht bearbeitende Lehrperson, eine Prüfrolle ohne Sicht auf verborgene
  Aktivitäten und die teilnehmende Person werden abgewiesen. Die Teilnehmenden erreichen ihr Ergebnis über
  `attemptfinished.php` (`attempt_review_navigation.feature`).
- #20: Barrierefreier Stopgrund-Dialog für timelimit, manual, restarted, error und unbekannte Codes. Geprüft mit
  axe (wcag131, wcag141, wcag412), Name und Beschreibung des Dialogs, Fokus im Dialog, Escape, danach ist das Feedback
  für Hilfstechnik erreichbar (`attempt_stop_notice.feature`).
- #21: `attempt_feedback_trust.feature` prüft den Browser-Roundtrip eines vertrauenswürdigen Autors (HTML und
  Skript; Dateien nimmt der Editor der Legacy-Linie nicht an, `maxfiles` 0, kein Dateibereich), ein nicht vertrauenswürdiges oder gefälschtes Feld (kein Skript, keine Event-Handler, keine
  `javascript:`-Links) sowie `forceclean`.
- #22: Navigation der Review-Seite per Tastatur, axe-Prüfung (wcag143). Instanzbindung des CATquiz-Feedbacks mit
  manipulierten Links: `catquiz_instance_feedback.feature` in local_catquiz.

# Changelog – mod_adaptivequiz (ALiSe)

## 2026111500 – Löschen als eine Transaktion (adaptivequizcatmodel_catquiz #10, #11; 15.11.2026)

- Eine Aktivität löschen (`adaptivequiz_delete_instance()`), einen Versuch löschen (neu: `adaptivequiz_delete_attempt()`
  in `locallib.php`, von `delattempt.php` genutzt) und den Kurs zurücksetzen (je Aktivität) sind jetzt jeweils eine
  Transaktion zusammen mit der Löschung des CAT-Modells. Kann das CAT-Modell seine Daten nicht löschen, bleibt alles,
  wie es war: Versuch, Question Usage, Aktivität. Dieselbe Löschung noch einmal schließt sie ab. Vorher waren
  Versuch und Usage schon weg, wenn das CAT-Modell scheiterte; seine Daten blieben ohne Weg zurück liegen.
- `delattempt.php`: Hatte das CAT-Modell keinen Lösch-Callback, endete die Seite leer ohne Neubewertung; jetzt
  wird immer neu bewertet und weitergeleitet.
- Nachweis über den Adapter: `adaptivequizcatmodel_catquiz` `tests/local/attempt_deletion_chain_test.php`. Ohne
  diese Transaktionen werden dort 10 Tests rot.
- CI (#10 im Adapter, wie local_catquiz #156): keine Zelle mehr mit PHP 8.1 (8.1/pgsql → 8.2/pgsql). `version.php`
  bleibt bei seiner Kompatibilitätsangabe.

## 2026102801 – CI nur auf Anforderung (02.11.2026)

- `a11y-axe.yml`: zeitgesteuerter Lauf (`schedule`, montags) entfernt. Der Workflow läuft auf Anforderung
  (`workflow_dispatch`) und bei Push. Damit gibt es in diesem Plugin keine zeitgesteuerten CI-Läufe mehr.
- Am Plugin-Code ändert sich nichts.

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

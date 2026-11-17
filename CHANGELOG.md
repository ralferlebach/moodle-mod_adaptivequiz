# Changelog – mod_adaptivequiz (ALiSe)

## 2026111701 – Issues #24, #25 und die Gegenstücke zu local_catquiz #172 und Adapter #12 (17.11.2026)

- #24: Die Trennungsregel „der Host kennt CATquiz nicht“ ist aufgehoben.
  - Das CI-Gate „Keine CATquiz-Referenzen im Host“ ist entfernt; der Selbsttest des Deprecation-Classifiers bleibt als eigener Job.
  - Regeltexte in Kommentaren, README und Tests sind angepasst.
  - Inventar und Folgekonzept stehen in `doc/transition-mod_catquiz.md`.
  - Funktionale Schnittstellen (Subplugin-Callbacks, Result-Provider, Linkfunktions-Registry) und Schutzprüfungen
    sind unverändert.
- #25: Bewertungseinstellungen der Aktivität.
  - **Bewertung abschaltbar** (`gradingmode`): Der Bewertungsaspekt bekommt keinen Werttyp. Gültige Ergebnisse
    bleiben gültig, ohne Note (`resultreason` `grading_off`), nie 0. Ein Abschluss über das Erhalten oder Bestehen
    einer Bewertung wird abgelehnt.
  - **Sechs Linkfunktionen**, wählbar je Aktivität (`linkfunction`; leer = Vorgabe der Ergebnisquelle):
    - `linear`, `piecewise_pass50`, `logistic_mid`, `logistic_pass` (Steilheit `linksteepness`), `guttman_pass`;
    - `fisher_rao`: adaptive Simpson-Quadratur mit Fehlerschranke über sqrt der Testinformation der aktuell
      kalibrierten Itembatterie. Die Information liefert der Ergebnis-Provider (`test_information_source`).
  - **Nicht berechenbar** heißt: keine Note, `link_not_computable`, kein Ersatzwert.
  - **Unbrauchbare Einstellungen** werden im Formular abgelehnt.
  - **Option „bestehende Bewertungen behalten“** (`keepgrades`): Ist sie aus, berechnet eine geänderte Einstellung
    jede Note aus dem gespeicherten Score neu. `cli/regrade_results.php --relink` zeigt eine Vorschau und schreibt
    erst mit `--confirm`.
  - Datensicherung und Wiederherstellung übernehmen die neuen Felder.
  - Upgrade: bestehende Aktivitäten bleiben bewertet, mit der Linkfunktion ihrer Ergebnisquelle, Steilheit 1 und
    keepgrades 0.
- Stoppgründe aus local_catquiz #172 und Adapter #12, je mit eigenem Hinweis:
  - `scalescomplete`: jede gemessene Skala fertig;
  - `noeligiblebeforeminimum`: keine passende Frage vor der Mindestlänge.

## 2026111601 – CI vom 16.11.2026: MariaDB, Moodle 5.2/5.3, PHP 8.5

- Löschen einer Aktivität und das Zurücksetzen entfernen die Question Usage jetzt selbst
  (`$DB->delete_records('question_usages', …)`), wie es `adaptivequiz_delete_attempt()` schon tat.
  `question_engine::delete_questions_usage_by_activity()` löscht auf MySQL/MariaDB über einen Join mit den
  Frageversuchen; eine Usage ohne Frageversuche blieb dort liegen (CI: `deletion_transaction_test`, MariaDB).
- `deletion_transaction_test`: löscht die Aktivität ab Moodle 5.2 über `core_courseformat` statt über das dort
  veraltete `course_delete_module()` (CI: Notice/Deprecation auf 5.2 und 5.3).
- Tests rufen `ReflectionProperty::setAccessible()` nicht mehr auf: seit PHP 8.1 wirkungslos, ab PHP 8.5 veraltet
  (Canary-Zelle).

## 2026111502 – Löschen als eine Transaktion (Adapter-Issue #11; 15.11.2026)

- Code, Tests und das Test-CAT-Modell nennen kein bestimmtes CAT-Modell (Host-Prüfung „CATquiz-Referenz im Host“,
  Issue #10 §13); die Fassung vom selben Tag mit solchen Verweisen in Kommentaren ist damit ersetzt.
- Eine Aktivität löschen (`adaptivequiz_delete_instance()`), einen Versuch löschen (`adaptivequiz_delete_attempt()`)
  und den Kurs zurücksetzen (je Aktivität) sind jetzt jeweils eine Transaktion zusammen mit der Löschung des
  CAT-Modells. Kann das CAT-Modell seine Daten nicht löschen, bleibt alles, wie es war: Versuch, Question Usage,
  Aktivität. Dieselbe Löschung noch einmal schließt sie ab.
- Test: `tests/local/catmodel/deletion_transaction_test.php` mit dem Test-CAT-Modell, das auf Wunsch beim Löschen
  scheitert (`adaptivequizcatmodel_testcatmodel_fail_deletions()`). Ohne die Transaktionen werden die Pfade
  „Versuch“ und „Reset“ rot.
- CI: Dieser Zweig hatte keine Zelle mit PHP 8.1 oder älter; unverändert.

## 2026102802 – CI nur auf Anforderung (02.11.2026)

- `a11y-axe.yml`: zeitgesteuerter Lauf (`schedule`, montags) entfernt. Der Workflow läuft auf Anforderung
  (`workflow_dispatch`) und bei Push. Damit gibt es in diesem Plugin keine zeitgesteuerten CI-Läufe mehr.
- Am Plugin-Code ändert sich nichts.

## 2026102801 – CI-Logs und #17 vom 28.10.2026

- CI, Job „PHP 8.5 · Canary“ (#13, nicht blockierend): Das PHPUnit-Init brach mit „Required locale 'en_AU.UTF-8' is
  not installed“ ab, dazu kam „Undefined property `$dboptions`“. Die Canary hat damit bisher nie getestet. Ergänzt
  sind `locale-gen` und `$CFG->dboptions = []`. PHP 8.5 gibt es in meiner Umgebung nicht; ob der Job jetzt Tests
  ausführt, zeigt erst der nächste Lauf.
- #17: Lastmatrix auf v3 (Moodle 5.3, PostgreSQL) ohne Störung durch andere Läufe neu gemessen:
  `doc/evidence/re-audit-2026-10-28/issue-17-evidence.md`.
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
- #21: `attempt_feedback_trust.feature` prüft den Browser-Roundtrip eines vertrauenswürdigen Autors (HTML, Datei,
  Skript), ein nicht vertrauenswürdiges oder gefälschtes Feld (kein Skript, keine Event-Handler, keine
  `javascript:`-Links) sowie `forceclean`.
- #22: Navigation der Review-Seite per Tastatur, axe-Prüfung (wcag143). Instanzbindung des CATquiz-Feedbacks mit
  manipulierten Links: `catquiz_instance_feedback.feature` in local_catquiz.

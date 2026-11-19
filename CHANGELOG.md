# Changelog – mod_adaptivequiz (ALiSe)

## 2026111702 – Screenreader-Workflow läuft vollständig in GitHub (19.11.2026)

- `a11y-screenreader.yml` nach dem Muster von local_adele umgebaut: Ein Linux-Job baut die Testsite wie
  `a11y-axe.yml` (Moodle 4.5), führt `tests/a11y/seed_stop_notice.php` aus und stellt sie über einen
  temporären Cloudflare-Tunnel bereit; der Windows-Job (`windows-2025`) wartet auf deren Adresse und lässt NVDA gegen
  den Tunnel laufen. Keine Pflichteingaben, keine Secrets, keine externe Site mehr. Bisher verlangte das Formular
  Adresse und Aktivität einer bestehenden Site – leer abgeschickt lehnte GitHub den Start ab („Workflow dispatch
  failed“) –, dazu Secrets und eine Site mit Composer-Entwicklungspaketen und `tests/a11y`, die das Release-ZIP nicht
  enthält.
- Guidepup wie in local_adele: Bibliothek 0.33.0 (bisher nicht gepinnt, aufgelöst zu 0.36.0), Integration 0.19.1,
  Setup 0.24.1 im Workflow (statt `npx guidepup setup --no-interactive` und `@guidepup/setup` 0.29.1 in
  `package.json`). Mit neuerer Bibliothek findet die Integration das installierte NVDA nicht („NVDA not installed“).
- Spec A11Y-AQ-SR-01: „Start attempt“ als Button oder Link (die Linie 1.2 rendert einen Link mit Button-Stil, 1.3
  einen `single_button`). Mit dem bisherigen Button-Locator wäre der Test auf 1.2 schon im ersten Schritt
  gescheitert; lokal nachgestellt.
- Browser: Playwrights Chromium statt des Chrome-Kanals. NVDA mit `capture: true`; das Gesprochene liegt zusätzlich
  als `transcripts/*.txt` im Artefakt.
- Die Lockdatei der Screenreader-Suite fehlte (`.gitignore`); `npm ci` wäre am Fehlen gescheitert. Jetzt
  eingecheckt.
- Keine Laufzeitänderung; `.github/` und `tests/a11y/` sind nicht Teil des Release-Pakets.

## 2026111701 – Issues #17, #23, #24 (Re-Audit 18.11.2026, 19.11.2026)

- #17 Produktionsstandard „Trace aus“: `request_timing` misst nur noch, wenn jemand die Spans liest – Einstellung
  `adaptivequiz/requesttiming` oder ein CAT-Modell mit eigenem Trace (Callback
  `adaptivequizcatmodel_<name>_wants_request_timing()`; der CATquiz-Adapter liefert `local_catquiz/attempttrace`). Aus,
  führen `start()`/`stop()`/`measure()`/`measure_db()` nur den Schritt aus; kein Shutdown-Handler, keine Spans.
  Entscheidung einmal je Request (`enabled()`), Test-Naht `request_timing::$force`. Neu `request_timing_switch_test`;
  die Tests der Spans schalten die Messung ausdrücklich ein. Doku: Klassenkommentar von `request_timing`.
- #23: Behat `result_after_technical_error.feature` (3 Szenarien) gegen die echte Ergebnisseite: technischer Fehler
  nach gültiger Messung – Fehlerdialog, Note, „Bestehensnote“ und „gültiges Ergebnis“ erfüllt; Fehler ohne Schätzung –
  Fehlerdialog, keine Note (nicht 0), nichts erfüllt; reguläres Ende gleich bewertet. Neue Schritte: Versuch über
  `adaptivequiz_complete_attempt()` mit Stoppcode abschließen; Stoppcode und Gültigkeit des letzten Versuchs prüfen.
- Abschluss (gefunden mit dem neuen Behat zu #23): `custom_completion::get_sort_order()` nannte „Bestehensnote
  erhalten“ (`completionpassgrade`) nicht. Core wirft dann auf der Aktivitätsseite eine `coding_exception`, sobald
  die Aktivität diese Bedingung zusammen mit einer eigenen Regel („gültiges Ergebnis“, „Versuch abgeschlossen“) hat
  – die Seite war nicht aufrufbar. Ergänzt; Test `custom_completion_test::test_the_sort_order_lists_every_condition`.
- #24: `TRANSITION-mod_catquiz.md` §5 – Abgleich der Gate-Entfernung mit Workflows, Preflight, Resolver/Callbacks, Schutzprüfungen und
  Dokumenten, mit Pfad und Zeile. Keine Codeänderung dafür.


## 2026111700 – Issues #24, #25 und die Gegenstücke zu local_catquiz #172 und Adapter #12 (17.11.2026)

- #24: Die Trennungsregel „der Host kennt CATquiz nicht“ ist aufgehoben.
  - Das CI-Gate „Keine CATquiz-Referenzen im Host“ ist entfernt.
  - Regeltexte in Kommentaren sind angepasst.
  - Inventar und Folgekonzept stehen in `TRANSITION-mod_catquiz.md`.
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

## 2026111600 – Question Usage auch auf MySQL/MariaDB löschen (CI vom 16.11.2026)

- Löschen einer Aktivität, eines Versuchs und das Zurücksetzen entfernen die Question Usage jetzt selbst
  (`$DB->delete_records('question_usages', …)`). `question_engine::delete_questions_usage_by_activity()` löscht
  die Usage auf MySQL/MariaDB über einen Join mit ihren Frageversuchen; eine Usage ohne Frageversuche blieb dort
  liegen, auf PostgreSQL nicht.
- Test: `tests/question_usage_deletion_test.php` (drei Pfade, Usage ohne Frageversuche). Auf MariaDB ohne die
  Änderung 3 von 3 rot.

## 2026111501 – Löschen als eine Transaktion (Adapter-Issues #10, #11; 15.11.2026)

- Code, Tests und das Test-CAT-Modell nennen kein bestimmtes CAT-Modell (Host-Prüfung „CATquiz-Referenz im Host“,
  Issue #10 §13); die Fassung vom selben Tag mit solchen Verweisen in Kommentaren ist damit ersetzt.
- Eine Aktivität löschen (`adaptivequiz_delete_instance()`), einen Versuch löschen (neu: `adaptivequiz_delete_attempt()`
  in `locallib.php`, von `delattempt.php` genutzt) und den Kurs zurücksetzen (je Aktivität) sind jetzt jeweils eine
  Transaktion zusammen mit der Löschung des CAT-Modells. Kann das CAT-Modell seine Daten nicht löschen, bleibt alles,
  wie es war: Versuch, Question Usage, Aktivität. Dieselbe Löschung noch einmal schließt sie ab. Vorher waren
  Versuch und Usage schon weg, wenn das CAT-Modell scheiterte; seine Daten blieben ohne Weg zurück liegen.
- `delattempt.php`: Hatte das CAT-Modell keinen Lösch-Callback, endete die Seite leer ohne Neubewertung; jetzt
  wird immer neu bewertet und weitergeleitet.
- Nachweis über den Adapter (dessen `tests/local/attempt_deletion_chain_test.php`): ohne diese Transaktionen
  werden dort 10 Tests rot.
- CI (Adapter-Issue #10): keine Zelle mehr mit PHP 8.1 (8.1/pgsql → 8.2/pgsql). `version.php`
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

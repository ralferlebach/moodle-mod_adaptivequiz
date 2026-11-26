# Changelog – mod_adaptivequiz (ALiSe)

## 2026112300 – CI-Werkzeugwechsel (23.11.2026)

- **Code-Style:** `moodle-plugin-ci ^4` zog 4.5.12 mit phpcs 4.0.4 und moodle-cs 3.9.2. Neu verlangt: Leerzeile nach
  dem namespace-Block (die acht Dateien unter `classes/local/result/link/`, phpcbf) und kein Kommentar hinter `{`
  (`lib.php`, Kommentar jetzt als erste Zeile im Block). Nur Leerzeilen und Kommentarposition. Code-Style bleibt auf diesem Zweig beratend (Altlasten);
  die neuen Befunde in neuem Code sind behoben.
- **Grunt:** `moodle-plugin-ci grunt` kopiert das Plugin weg und danach zurück; das Zurückkopieren scheiterte an den
  schreibgeschützten Packdateien von `.git` im Plugin („Permission denied“). Ein Schritt vor Grunt gibt nur den
  `.git`-Verzeichnissen des Plugins Schreibrecht. Nachgestellt als Nicht-root: ohne den Schritt rot, mit grün.

## 2026111707 – CI-Evidenz fail-closed ohne Ausführungsnachweis (Critical-Friend-Re-Audit 23.11.2026)

- **Behat-Evidenz (wie local_catquiz #176, Fall B):** `ci_evidence.py` mit Pflicht-Features, aber ohne
  `--behat-executed` hat nur die Dateien und Tags geprüft und trotzdem „All required tests ran …“ ausgegeben. Jetzt
  ist das ein Befund und der Job rot: ohne Aufzeichnung des Laufs ist es eine Inventur, kein Lauf. Der Workflow gibt die
  Aufzeichnung immer an; an ihm ändert sich nichts. Selbsttest für den Fall, Zahntest: mit dem alten Skript rot.

## 2026111706 – #25 DoD5 vervollständigt, Behat-Evidenz-Randfälle (Re-Audit 22.11.2026)

- **#25 DoD5 – Bewertung über mehrere Versuche, vervollständigt** (`tests/local/result/grade_method_matrix_test.php`):
  - **Erneute Finalisierung desselben Versuchs:** Note, Reihenfolge, Abschlusszeit, Stoppcode, Ergebnis und Meldung
    bleiben; kein zweites Abschluss-Event; die Finalisierung über die ID meldet „schon abgeschlossen“. Befund dabei:
    `adaptivequiz_complete_attempt()` prüfte das selbst nicht. Ein zweiter Aufruf überschrieb die Meldung des Endes und
    lief Callback, Bewertung und Event erneut durch. Die produktiven Aufrufer prüfen vorher (`cat_session`:
    nur ein laufender Versuch; `closeattempt.php`: Fehler bei einem abgeschlossenen). Die Funktion lässt einen
    abgeschlossenen Versuch jetzt selbst unverändert, wie `adaptivequiz_complete_attempt_by_id()` es schon tat. Im Betrieb
    ändert sich dadurch nichts. Zahntest: ohne die Prüfung rot.
  - **Technischer Stopp vor bzw. nach einer gültigen Messung:** zählt nach seinem Ergebnis, nicht nach dem Stoppcode;
    der Stoppcode bleibt der technische (#23). Vier Folgen, je Methode.
  - **Gradebook und Completion über mehrere Versuche:** „Bestehensnote erhalten“ folgt der Note, die die Methode wählt,
    live und nach Neubewertung mit jeder anderen Methode; „gültiges Ergebnis“ gilt, sobald ein Versuch eines hatte;
    „Versuch abgeschlossen“, sobald einer abgeschlossen ist. Fünf Folgen, darunter genau auf der Grenze und nur ungültige.
- **#176-Randfälle (local_catquiz):** `.github/scripts/ci_evidence.py` zählt „gelaufen“ an den Szenarien der Datei und
  wertet Zeilen eines anderen Stands der Feature-Datei als rot; Selbsttest 15 Fälle.

## 2026111705 – Bewertungsmatrix und Behat-Ausführungsnachweis (Re-Audit 20.11.2026)

- **#25 DoD5 – Bewertungsmethoden über mehrere Versuche:** neuer Test `tests/local/result/grade_method_matrix_test.php`.
  Jede Folge von Versuchen wird je Methode (höchste, erste, letzte) gespielt, live bewertet und danach mit jeder Methode
  neu bewertet. Die Folgen: drei gültige; ein gültiger zwischen ungültigen; nur ungültige (keine Note, nicht 0);
  Gleichstand; anders beendet als begonnen; ungültiger nach gültigem. Zahntest: Ordnet „erste“ nach ID statt nach
  Beendigung, ist der Test rot. In die Pflichtliste aufgenommen.
- **local_catquiz #176 – Pflichtfeatures müssen gelaufen sein:** Der Kontext `tests/behat/behat_mod_adaptivequiz_evidence.php`
  schreibt mit `ADAPTIVEQUIZ_BEHAT_EXECUTED` jedes Szenario mit Ergebnis. `ci_evidence.py --behat-executed` prüft jedes
  Pflichtfeature Szenario für Szenario: gelaufen und im letzten Lauf bestanden. Fehlt die Aufzeichnung, ist die Evidenz
  rot. Ein Selbsttest (`ci_evidence_selftest.py`) läuft vor dem Evidenzschritt.
  Auf dieser Linie bleibt Behat beratend. Ein Pflichtfeature, das nicht läuft oder scheitert, macht jetzt aber den
  Evidenzschritt und damit den Job rot.
- `version.php` 2026111705.

## 2026111704 – CI-Nachweis je Zelle (Re-Audit 19.11.2026)

- PHPUnit läuft wie bisher über die Testsuite der Komponente, schreibt aber ein JUnit-Protokoll; Behat schreibt sein
  Protokoll mit. `.github/scripts/ci_evidence.py` legt je Zelle die eingebauten Commits und Versionen, Moodle, PHP,
  Datenbank, Tests/Assertions/Skips und Wiederholungen in die Zusammenfassung des Laufs (Artefakt
  `evidence-…`) und prüft gegen `.github/ci-required-tests.txt` (15 Tests der Issues #16, #17, #23, #25) und
  `.github/ci-required-features.txt` (`result_after_technical_error`, `grading_settings`,
  `result_gradebook_completion`, `attempt_stop_notice`): Läuft einer nicht, scheitert oder wird übersprungen, ist die
  Zelle rot.
- Host #23: Die Regel „gültige Messung nach dem Minimum zählt trotz technischem Ende, Stoppcode bleibt `error`“ ist
  am 19.11.2026 fachlich freigegeben (Adapter `stop-contract.md` §3). Im Browser belegt in local_catquiz
  `tests/playwright/tests/cat-attempt-path.spec.ts` (Dialog, Fokus, axe, Note/Abschluss) – keine Codeänderung hier.

## 2026111703 – Screenreader-Test: Ansage beim Öffnen des Dialogs wird mitgeschnitten (19.11.2026)

- Erster Lauf in GitHub (4.5 und 5.1): Site, Tunnel, NVDA-Installation und Sprache funktionieren; gescheitert ist
  nur die Prüfung der Ansage beim Öffnen des Dialogs – das Protokoll war leer. Ursache: Guidepup protokolliert nur,
  was NVDA während einer eigenen Aktion sagt; der Klick auf „Submit answer“ lief über Playwright. Jetzt in
  `nvda.capture()` (Klick, Warten auf den Dialog, Sprechpause), wie von Guidepup für Playwright-Aktionen vorgesehen.
- Nach dem Schließen liest NVDA 20 statt 12 Schritte, damit das Feedback unter dem Hinweis sicher erfasst wird.

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

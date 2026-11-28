# Changelog – mod_adaptivequiz (ALiSe)

## 2026112801 – #28 Reihenfolge der Sprachstrings (28.11.2026)

- `attemptlost` (aus #27) steht in `lang/en` und `lang/de` an der alphabetisch richtigen Stelle. Der Code-Style ist in dieser Linie blockierend (Lauf 46070698604: 13 von 16 Jobs rot, nur deshalb).
- Keine Text- oder Funktionsänderung.
- Damit das nicht wieder passiert, prüft die Entwicklungsumgebung den Code-Style jetzt im Moodle-Baum wie die CI. Die
  Regel zur Reihenfolge greift nur dort. Vor jedem Commit läuft ein Hook, vor jedem Paket ein grüner Preflight.

## 2026112701 – #27 Lebenszyklus: Reset und Löschung nach Generation, Frage nur für den eigenen Versuch (27.11.2026)

- **Reset:**
  - `adaptivequiz_reset_attempts()` entfernt die gelesenen Versuche nach ID.
  - Vorher entfernte er alle Versuche der Aktivität, auch einen, der zwischen dem Lesen und dem Löschen gestartet
    wurde. Dessen Usage und CAT-Daten blieben ohne Versuch zurück. Das ist das Muster von CLARA 27.11.: Host 29477
    fehlt, CAT-Versuch und QUBA bestehen.
  - Test `lifecycle_generation_test`, rot gegen den alten Stand.
- **Löschen der Aktivität:** `adaptivequiz_delete_instance_data()` entfernt jeden Versuch mit seiner Usage, nach den
  gelesenen Zeilen, in bis zu drei Durchgängen. Ein dazwischen gestarteter Versuch wird ebenfalls erfasst.
- **Frageseite** (`attempt.php`, `local\attempt\page_binding`, AK01):
  - Vor jeder Ausgabe wird der Versuch der angezeigten Usage für Person und Aktivität neu gelesen.
  - Fehlt er (Reset, Löschung), endet die Anfrage mit dem Fehler `attemptlost`, ohne HTTP 200 mit Frageformular.
  - Für ihn wird kein neuer Versuch gestartet.
  - Ein inzwischen abgeschlossener Versuch zeigt sein Ergebnis.
  - In der Legacy-Linie startete `get_attempt()` nach dem Seitenkopf bisher einen neuen Versuch.
- **Offen, nicht in dieser Version:** atomare Initialisierung (AK02), Koordination von Start und Reset über Sperren,
  Fault-Injection und parallele Requests (DoD02, DoD03).

## 2026112301 – CI-Werkzeugwechsel (23.11.2026)

- **Code-Style:** `moodle-plugin-ci ^4` zog 4.5.12 mit phpcs 4.0.4 und moodle-cs 3.9.2. Neu verlangt: Leerzeile nach
  dem namespace-Block (die acht Dateien unter `classes/local/result/link/`, phpcbf) und kein Kommentar hinter `{`
  (`lib.php`, Kommentar jetzt als erste Zeile im Block). Nur Leerzeilen und Kommentarposition.
- **Grunt:** `moodle-plugin-ci grunt` kopiert das Plugin weg und danach zurück; das Zurückkopieren scheiterte an den
  schreibgeschützten Packdateien von `.git` im Plugin („Permission denied“). Ein Schritt vor Grunt gibt nur den
  `.git`-Verzeichnissen des Plugins Schreibrecht. Nachgestellt als Nicht-root: ohne den Schritt rot, mit grün.

## 2026111709 – CI-Evidenz fail-closed ohne Ausführungsnachweis (Critical-Friend-Re-Audit 23.11.2026)

- **Behat-Evidenz (wie local_catquiz #176, Fall B):** `ci_evidence.py` mit Pflicht-Features, aber ohne
  `--behat-executed` hat nur die Dateien und Tags geprüft und trotzdem „All required tests ran …“ ausgegeben. Jetzt
  ist das ein Befund und der Job rot: ohne Aufzeichnung des Laufs ist es eine Inventur, kein Lauf. Der Workflow gibt die
  Aufzeichnung immer an; an ihm ändert sich nichts. Selbsttest für den Fall, Zahntest: mit dem alten Skript rot.

## 2026111708 – #25 DoD5 vervollständigt, Behat-Evidenz-Randfälle (Re-Audit 22.11.2026)

- **#25 DoD5 – Bewertung über mehrere Versuche, vervollständigt** (`tests/local/catmodel/grade_method_matrix_test.php`):
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

## 2026111707 – Bewertungsmatrix und Behat-Ausführungsnachweis (Re-Audit 20.11.2026)

- **#25 DoD5 – Bewertungsmethoden über mehrere Versuche:** neuer Test `tests/local/catmodel/grade_method_matrix_test.php` (mit dem Test-CAT-Modell, nicht im Release-Paket).
  Jede Folge von Versuchen wird je Methode (höchste, erste, letzte) gespielt, live bewertet und danach mit jeder Methode
  neu bewertet. Die Folgen: drei gültige; ein gültiger zwischen ungültigen; nur ungültige (keine Note, nicht 0);
  Gleichstand; anders beendet als begonnen; ungültiger nach gültigem. Zahntest: Ordnet „erste“ nach ID statt nach
  Beendigung, ist der Test rot. In die Pflichtliste aufgenommen.
- **local_catquiz #176 – Pflichtfeatures müssen gelaufen sein:** Der Kontext `tests/behat/behat_mod_adaptivequiz_evidence.php`
  schreibt mit `ADAPTIVEQUIZ_BEHAT_EXECUTED` jedes Szenario mit Ergebnis. `ci_evidence.py --behat-executed` prüft jedes
  Pflichtfeature Szenario für Szenario: gelaufen und im letzten Lauf bestanden. Fehlt die Aufzeichnung, ist die Evidenz
  rot. Ein Selbsttest (`ci_evidence_selftest.py`) läuft vor dem Evidenzschritt.
- `version.php` 2026111707.

## 2026111706 – CI-Nachweis je Zelle (Re-Audit 19.11.2026)

- PHPUnit läuft wie bisher über die Testsuite der Komponente, schreibt aber ein JUnit-Protokoll; Behat schreibt sein
  Protokoll mit. `.github/scripts/ci_evidence.py` legt je Zelle die eingebauten Commits und Versionen, Moodle, PHP,
  Datenbank, Tests/Assertions/Skips und Wiederholungen in die Zusammenfassung des Laufs (Artefakt
  `evidence-…`) und prüft gegen `.github/ci-required-tests.txt` (15 Tests der Issues #16, #17, #23, #25) und
  `.github/ci-required-features.txt` (`result_after_technical_error`, `grading_settings`,
  `result_gradebook_completion`, `attempt_stop_notice`): Läuft einer nicht, scheitert oder wird übersprungen, ist die
  Zelle rot.
- Matrix: PHP 8.4 auch mit MariaDB auf 5.1, 5.2 und 5.3 (vorher nur PostgreSQL).
- Host #23: Die Regel „gültige Messung nach dem Minimum zählt trotz technischem Ende, Stoppcode bleibt `error`“ ist
  am 19.11.2026 fachlich freigegeben (Adapter `stop-contract.md` §3). Im Browser belegt in local_catquiz
  `tests/playwright/tests/cat-attempt-path.spec.ts` (Dialog, Fokus, axe, Note/Abschluss) – keine Codeänderung hier.

## 2026111705 – Screenreader-Test: Ansage beim Öffnen des Dialogs wird mitgeschnitten (19.11.2026)

- Erster Lauf in GitHub (4.5 und 5.1): Site, Tunnel, NVDA-Installation und Sprache funktionieren; gescheitert ist
  nur die Prüfung der Ansage beim Öffnen des Dialogs – das Protokoll war leer. Ursache: Guidepup protokolliert nur,
  was NVDA während einer eigenen Aktion sagt; der Klick auf „Submit answer“ lief über Playwright. Jetzt in
  `nvda.capture()` (Klick, Warten auf den Dialog, Sprechpause), wie von Guidepup für Playwright-Aktionen vorgesehen.
- Nach dem Schließen liest NVDA 20 statt 12 Schritte, damit das Feedback unter dem Hinweis sicher erfasst wird.

## 2026111704 – Screenreader-Workflow läuft vollständig in GitHub (19.11.2026)

- `a11y-screenreader.yml` nach dem Muster von local_adele umgebaut: Ein Linux-Job baut die Testsite wie
  `a11y-axe.yml` (Moodle-Linie im Formular wählbar: 5.1, 5.2, 5.3; Vorgabe 5.1), führt `tests/a11y/seed_stop_notice.php` aus und stellt sie über einen
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
- Keine Laufzeitänderung; `.github/` und `tests/a11y/` sind nicht Teil des Release-Pakets.

## 2026111703 – Issues #17, #23, #24 (Re-Audit 18.11.2026, 19.11.2026)

- #17 Produktionsstandard „Trace aus“: `request_timing` misst nur noch, wenn jemand die Spans liest – Einstellung
  `adaptivequiz/requesttiming` oder ein CAT-Modell mit eigenem Trace (Callback
  `adaptivequizcatmodel_<name>_wants_request_timing()`; der CATquiz-Adapter liefert `local_catquiz/attempttrace`). Aus,
  führen `start()`/`stop()`/`measure()`/`measure_db()` nur den Schritt aus; kein Shutdown-Handler, keine Spans.
  Entscheidung einmal je Request (`enabled()`), Test-Naht `request_timing::$force`. Neu `request_timing_switch_test` und
  `request_timing_catmodel_test` (Callback über das Test-CAT-Modell, nicht ausgeliefert);
  die Tests der Spans schalten die Messung ausdrücklich ein. Doku: `doc/request-timing.md`.
- #23: Behat `result_after_technical_error.feature` (3 Szenarien) gegen die echte Ergebnisseite: technischer Fehler
  nach gültiger Messung – Fehlerdialog, Note, „Bestehensnote“ und „gültiges Ergebnis“ erfüllt; Fehler ohne Schätzung –
  Fehlerdialog, keine Note (nicht 0), nichts erfüllt; reguläres Ende gleich bewertet. Neue Schritte: Versuch über
  `adaptivequiz_complete_attempt()` mit Stoppcode abschließen; Stoppcode und Gültigkeit des letzten Versuchs prüfen.
- Abschluss (gefunden mit dem neuen Behat zu #23): `custom_completion::get_sort_order()` nannte „Bestehensnote
  erhalten“ (`completionpassgrade`) nicht. Core wirft dann auf der Aktivitätsseite eine `coding_exception`, sobald
  die Aktivität diese Bedingung zusammen mit einer eigenen Regel („gültiges Ergebnis“, „Versuch abgeschlossen“) hat
  – die Seite war nicht aufrufbar. Ergänzt; Test `custom_completion_test::test_the_sort_order_lists_every_condition`.
- #24: `doc/transition-mod_catquiz.md` §5 – Abgleich der Gate-Entfernung mit Workflows, Preflight, Resolver/Callbacks, Schutzprüfungen und
  Dokumenten, mit Pfad und Zeile. Keine Codeänderung dafür.
- Test-CAT-Modell: Callback `adaptivequizcatmodel_testcatmodel_wants_request_timing()` für den Schaltertest.


## 2026111702 – Zeilenenden und Canary-Vertrag (17.11.2026)

- `.gitattributes`: `* text=auto eol=lf`, wie in den anderen ALiSe-Repositories. Ohne diese Zeile checkt Git unter
  Windows mit `core.autocrlf=true` die Dateien mit CRLF aus. Jede darüber entpackte Auslieferung (LF) erschien dann als
  Hunderte geänderter Dateien ohne Inhaltsänderung: Git vergleicht zuerst die Dateigröße im Index. Im Repository selbst
  stehen alle Textdateien schon mit LF; `git add --renormalize .` ändert nichts.
- `doc/php85-canary.md`: Vertrag zum PHP-8.5-Canary (Re-Audit 17.11.2026). Kein Code geändert.

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

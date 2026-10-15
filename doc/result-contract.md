# Ergebnisvertrag (Issue #14)

Die Aktivität bewertet Ergebnisse, sie misst sie nicht. Die Messung kommt von einer
**Ergebnisquelle** – dem eingebauten Algorithmus oder einem CAT-Modell –, die Aktivität macht
daraus Prozent, Note, Bestehensgrenze und Abschluss. Beide Arten von Quellen laufen durch
denselben Weg.

## Was eine Quelle liefert

`mod_adaptivequiz\local\result\result_provider`:

- `get_result_definition(stdClass $adaptivequiz): result_definition` –
  `supportsgrading`, `lower`, `upper` (in den Einheiten der Quelle), `label`, `linkfunction`.
  Wird auch aus dem Einstellungsformular gerufen, dann mit den Formulardaten über dem
  gespeicherten Datensatz; eine Quelle darf sich nicht darauf verlassen, dass gespeichert ist.
- `get_attempt_result(stdClass $adaptivequiz, stdClass $attempt): attempt_result` –
  `attempt_result::valid($score, $lower, $upper, $link)` oder
  `attempt_result::invalid($reason)`. Ein ungültiges Ergebnis trägt keinen Score; ein
  diagnostischer Wert bleibt in der Quelle.

Ein CAT-Modell bietet eine Quelle an, indem es das Interface unter
`<subplugin>\local\catmodel\result\` implementiert (`catmodel_resolver` sucht dort). Ein
CAT-Modell ohne Quelle hat keine bewertbaren Ergebnisse; die Aktivität fällt **nicht** auf ihr
eigenes `measure` zurück.

## Was die Aktivität daraus macht

- **Snapshot bei Abschluss** (`result_service::snapshot()`, aus `adaptivequiz_complete_attempt()`
  nach `post_complete_attempt_callback`): `resultvalid`, `resultstatus` (`valid`/`invalid`),
  `resultreason`, `resultscore`, `resultlower`, `resultupper`, `resultpercent`, `resultlink`,
  `resultlinkversion`, `resulttime`. Einmal je Versuch; spätere Änderungen von Bereich oder Quelle schreiben die
  Historie nicht um.
- **Prozent**: `100 * (score - lower) / (upper - lower)`, auf 0–100 begrenzt. `upper <= lower`,
  nicht endliche Werte: kein Prozent, `resultvalid = 0`, Grund `range_invalid`. Kein Epsilon.
- **Linkfunktion**: Registry (`link_function_registry`) mit `linear` und den Linkfunktionen, die
  installierte CAT-Modelle unter `<subplugin>\local\catmodel\result\link\` mitbringen
  (Klassen, die `link_function` implementieren). Ein Bezeichner, den zwei Funktionen beanspruchen,
  ist ein Konfigurationsfehler (`resulterrorlinkfunctionduplicate`); ein unbekannter ebenso
  (`link_function_unknown`), kein stiller Rückfall auf linear.
- **Version der Linkfunktion**: `link_function::get_version()`; jede Änderung der Abbildung
  erhöht sie. Der Snapshot hält Bezeichner **und** Version (`resultlinkversion`) fest; die Note
  liest das gespeicherte `resultpercent` und rechnet nicht mit der heutigen Version nach.
- **Gradebook**: Notenelement immer 0–100. Rohnote = `resultpercent` gültiger Versuche;
  wer nur ungültige Versuche hat, bekommt `null`, nie 0. Erster/Letzter nach Abschlusszeit unter
  den gültigen Versuchen, Höchster nach Prozent.
- **Bestehensgrenze**: `passscore` in Einheiten der Quelle, innerhalb des Bereichs (Formular
  prüft). Das Notenelement bekommt sie als `gradepass` in Prozent; „Bestehensnote erhalten“ ist
  Moodles eigene Abschlussregel darauf. Neuer Bereich ⇒ `gradepass` wird neu berechnet,
  Versuchsprozente bleiben. Ändert sich `gradepass` (neue Bestehensgrenze, neuer Bereich), wird
  „Bestehensnote erhalten“ für alle Teilnehmenden mit Note sofort neu beurteilt
  (`adaptivequiz_reevaluate_pass_completion()`), nicht erst beim nächsten Versuch.
- **Einstellungsformular**: zeigt die Ergebnisquelle mit ihrem Bereich und, für die gespeicherte
  Bestehensgrenze, die daraus folgende Bestehensnote in Prozent („A pass score of 5 is a grade to
  pass of 50.00 % in the gradebook.“).
- **Bewertungsmethode geändert** ⇒ der Regrade-Task baut die Noten aus den Snapshots neu auf.
- **Abschlussregel „gültiges Ergebnis“** liest dasselbe `resultvalid` wie das Gradebook.

## Eingebauter Algorithmus

`builtin_result_provider`: Bereich `lowestlevel`..`highestlevel`, Score
`catalgo::map_logit_to_scale(measure, …)` wie bisher. Neu: Ein Versuch ohne beantwortete Frage
ist ungültig (`no_answer`) – er bekam bisher die Note einer Fähigkeit 0, also Bereichsmitte.

Ebenso ein Versuch, der **ohne Schätzung** endete (`no_estimate`, Standardfehler ≤ 0): Der
Algorithmus lässt `measure` und `standarderror` auf 0, bis er schätzt, und das tat er erst ab der
Antwort *nach* dem Minimum (`adaptivequiz_min_attempts_reached()` zählt die gerade verarbeitete
Antwort noch nicht). Ein Test fester Länge (Minimum = Maximum) endete so immer bei `measure` 0 –
jede Person bekam die Bereichsmitte, gleich was sie antwortete. Behoben in
`cat_session::process_administered_item_result()`: Die letzte Antwort, die das Maximum zulässt,
wird immer geschätzt; wann der Algorithmus wegen Messgenauigkeit stoppt, ändert sich sonst nicht.
Endet ein Versuch trotzdem vor jeder Schätzung (Pool vor dem Minimum erschöpft), ist er ungültig
statt 50 %.

## Upgrade

Version 2026100902 legt die Felder an und stellt den Adhoc-Task `snapshot_results` ein. Er gibt
abgeschlossenen Versuchen ohne Snapshot einen (über die jeweilige Quelle) und baut alle
Notenelemente auf 0–100 mit den Noten aus den Snapshots neu auf.

Version 2026100910 legt `resultlinkversion` an und setzt sie für vorhandene `linear`-Snapshots auf 1.

Bestehensgrenzen (`passscore`) gibt es erst mit diesem Vertrag; vorhandene Aktivitäten haben
keine, eine Umrechnung alter Bestehensgrenzen entfällt deshalb.

Folge, die man kennen muss: Bei CATquiz-Aktivitäten zählen nur Versuche, die CATquiz finalisiert
hat und deren **globale** Skala gültig ist (das Ergebnis der Globalskala, nicht einer berichteten
Subskala). Ältere Versuche ohne finalisiertes Ergebnis verlieren
ihre bisher aus `measure` berechnete Rohnote (überschriebene Noten im Gradebook bleiben).

## Regrade (Issue #19)

`result_service::regrade()` baut die Noten einer Aktivität aus den Snapshots neu auf; per
Kommandozeile:

    php mod/adaptivequiz/cli/regrade_results.php --instance=N   # oder --all
    php mod/adaptivequiz/cli/regrade_results.php --instance=N --resnapshot=12,15

- Wiederholbar: Ein zweiter Lauf ändert weder Snapshot noch Note.
- Versuche ohne Snapshot bekommen einen; vorhandene bleiben, außer für die mit `--resnapshot`
  genannten Versuche. Ein neu genommener Snapshot liest den Ergebnisbereich von heute, nicht den
  des Abschlusses – deshalb nur gezielt.
- Die Note folgt der Bewertungsmethode (erster, letzter, bester Versuch) und nur gültigen
  Ergebnissen. Wer keinen gültigen Versuch hat, bekommt keine Note, nicht 0. Versuche ohne
  gültiges Ergebnis werden mit Grund aufgelistet.

Für CATquiz: Nach `local/catquiz/cli/revalidate_attempts.php --execute` (Versuche nach heutigen
Regeln neu beurteilt, z. B. das Mindestfragen-Kriterium) gibt das Skript den passenden
Regrade-Aufruf mit `--resnapshot` aus.

## Fragenzahl (Issue #19)

Die Aktivität legt keinen Fragen-Slot über `maximumquestions` an – gleich ob produktive Frage,
Pilotfrage oder technische Wiederholung. Ein CAT-Modell, das selbst anders zählt, kann den Test
dadurch nicht verlängern; erreicht die Nutzung das Maximum, endet der Versuch.

## Sicherung und Wiederherstellung

Die Sicherung trägt die Snapshot-Felder einschließlich `resultlinkversion` und das CAT-Modell der Aktivität (`catmodel` – sonst fiel eine wiederhergestellte CATquiz-Aktivität auf den eingebauten Algorithmus zurück); eine
wiederhergestellte Aktivität bewertet aus denselben Snapshots, ohne die Quelle erneut zu fragen.

## Nachweise

PHPUnit (`tests/local/result/`, `tests/local/catmodel/result_contract_test.php`): Prozent und Bereich, ungültige Ergebnisse ohne Note,
Bewertungsmethoden nur über gültige Versuche, Höchster nach Prozent, Bestehensgrenze →
`gradepass`, neue Bestehensgrenze → Abschluss neu beurteilt, neue Bewertungsmethode → neue
Noten, `linear` stabil und versioniert, eigene Linkfunktion eines CAT-Modells, neue Version deutet alte Snapshots nicht um, Quelle ohne numerisches Ergebnis ohne Note, Formularanzeige von Quelle und Bestehensnote,
Versuch ohne Schätzung ohne Note; `tests/cat_session_test.php`: Test fester Länge endet mit
Schätzung.

Behat (`tests/behat/result_gradebook_completion.feature`): Einstellungen zeigen Quelle und
Bestehensnote; gutes Ergebnis → Note im Gradebook und „Bestehensnote erhalten“ erfüllt;
schwaches Ergebnis → Note und „nicht bestanden“; niedrigere Bestehensgrenze → ohne neuen Versuch
bestanden. Die Note entsteht beim Abschluss in `attempt.php`; `attemptfinished.php` bewertet
nicht.

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
  `resulttime`. Einmal je Versuch; spätere Änderungen von Bereich oder Quelle schreiben die
  Historie nicht um.
- **Prozent**: `100 * (score - lower) / (upper - lower)`, auf 0–100 begrenzt. `upper <= lower`,
  nicht endliche Werte: kein Prozent, `resultvalid = 0`, Grund `range_invalid`. Kein Epsilon.
- **Linkfunktion**: Registry mit `linear`. Ein unbekannter Bezeichner ist ein
  Konfigurationsfehler (`link_function_unknown`), kein stiller Rückfall auf linear.
- **Gradebook**: Notenelement immer 0–100. Rohnote = `resultpercent` gültiger Versuche;
  wer nur ungültige Versuche hat, bekommt `null`, nie 0. Erster/Letzter nach Abschlusszeit unter
  den gültigen Versuchen, Höchster nach Prozent.
- **Bestehensgrenze**: `passscore` in Einheiten der Quelle, innerhalb des Bereichs (Formular
  prüft). Das Notenelement bekommt sie als `gradepass` in Prozent; „Bestehensnote erhalten“ ist
  Moodles eigene Abschlussregel darauf. Neuer Bereich ⇒ `gradepass` wird neu berechnet,
  Versuchsprozente bleiben.
- **Abschlussregel „gültiges Ergebnis“** liest dasselbe `resultvalid` wie das Gradebook.

## Eingebauter Algorithmus

`builtin_result_provider`: Bereich `lowestlevel`..`highestlevel`, Score
`catalgo::map_logit_to_scale(measure, …)` wie bisher. Neu: Ein Versuch ohne beantwortete Frage
ist ungültig (`no_answer`) – er bekam bisher die Note einer Fähigkeit 0, also Bereichsmitte.

## Upgrade

Version 2026100902 legt die Felder an und stellt den Adhoc-Task `snapshot_results` ein. Er gibt
abgeschlossenen Versuchen ohne Snapshot einen (über die jeweilige Quelle) und baut alle
Notenelemente auf 0–100 mit den Noten aus den Snapshots neu auf.

Folge, die man kennen muss: Bei CATquiz-Aktivitäten zählen nur Versuche, die CATquiz finalisiert
hat und deren primäre Skala gültig ist. Ältere Versuche ohne finalisiertes Ergebnis verlieren
ihre bisher aus `measure` berechnete Rohnote (überschriebene Noten im Gradebook bleiben).

# Gleichzeitige und wiederholte Antworten (mod_adaptivequiz #17)

Stand: 2026101802 (v3). Gilt für beide Linien; die Wege unterscheiden sich, das Verhalten nicht.

## Vertrag

| Fall | Ergebnis |
|---|---|
| Dieselbe Antwort zweimal gleichzeitig (Doppelklick, zweiter Tab) | einmal verarbeitet, ein Antwortschritt, eine nächste Frage; beide Anfragen zeigen dieselbe Frage |
| Dieselbe Antwort später noch einmal (veralteter Tab, Zurück) | ändert nichts, zeigt die Frage, bei der der Versuch steht |
| Die Antwort, die den Versuch beendet (Maximum, Standardfehler), zweimal gleichzeitig | einmal abgeschlossen: ein Ereignis `attempt_completed`, eine Bewertung, **kein neuer Versuch**, keine Frage nach dem Ende; beide Anfragen zeigen das Ergebnis |
| Eine Anfrage bricht nach dem Speichern der Antwort ab, die Person sendet erneut | die Antwort ist verarbeitet, die nächste Frage kommt einmal |
| Eine andere Anfrage hält den Versuch die ganze Lock-Wartezeit | die Antwort bleibt unverarbeitet, die Frage bleibt offen und kann erneut gesendet werden |

Mittel: der Item-Lock je Aktivität und Person (`adaptivequiz_itemadministration_<instanz>_<user>`) für die
Verarbeitung der Antwort, die Vergabe der nächsten Frage und den Abschluss; eine bereits verarbeitete
Antwort erkennt die Question Engine (`question_out_of_sequence_exception`).

- **v3:** `cat_session::process_administered_item_result()` schließt einen Versuch, den die Antwort beendet,
  unter demselben Lock ab (`complete_attempt_locked()`, prüft den Zustand erneut).
- **Legacy (seit 2026101801):** dasselbe. Vorher entschied der eingebaute Algorithmus das Ende in der
  Verarbeitung, abgeschlossen wurde erst in `run_item_administration()`. Das Duplikat fand den Versuch in
  diesem Fenster noch laufend, wartete auf den Lock und – da kein Versuch mehr lief – legte über
  `get_attempt()` einen **neuen Versuch mit einer Frage** an (Standardfehler-Fall: zusätzlich eine dritte
  Frage im beendeten Versuch). Jetzt: Abschluss unter dem Antwort-Lock, `finished_attempt()` vor
  `get_attempt()` in Verarbeitung und Vergabe, jeder Abschluss über `complete_attempt_once()`.

## Tests

`tests/concurrent_answer_test.php` – echte Prozesse mit eigenen DB-Verbindungen (`fixtures/concurrent_processes.php`):
zwei gleichzeitige Antworten, Wiederholung, Warten auf den Lock, abschließende Antwort doppelt (Maximum und
Standardfehler), Wiederholung nach abgebrochener Anfrage. Grün auf PostgreSQL 16 und MariaDB 10.11.
Legacy: gegen den Stand 2026101800 rot (neuer Versuch mit Frage). Mit `CATQUIZ_REQUIRE_CONCURRENCY=1`
(im CI gesetzt) scheitern die Tests, statt übersprungen zu werden, wenn pcntl fehlt.

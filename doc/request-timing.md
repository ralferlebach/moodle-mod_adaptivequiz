# Host-Timeline eines Requests (`request_timing`, #17)

`mod_adaptivequiz\local\request_timing` misst die Schritte, die der Host selbst in einem Request
ausführt – Warten auf Locks, Anlegen des Versuchs, Aufruf des CAT-Modells, Laden der Frage, die
Question Usage. Die Klasse ist in v3 und in der Legacy-Linie identisch.

## Trace-Entscheidung: immer messen, nie selbst persistieren

- Der Host misst **immer**. Pro Span fallen ein paar `hrtime()`- und `memory_get_usage()`-Aufrufe
  an; das ist billig genug, um es nicht abschaltbar zu machen. Es gibt deshalb keinen Schalter, der
  den normalen Pfad verändern könnte (Test „Fragenauswahl mit/ohne Messung identisch“).
- Der Host **schreibt nichts** – keine Tabelle, kein Log, keine Datei. Die Spans leben nur im
  Speicher des Requests.
- **Persistiert wird ausschließlich über das CAT-Modell**, wenn dessen eigener Trace eingeschaltet ist
  (Produktionsstandard: aus). Das CAT-Modell liest die Spans, legt sie neben seine eigenen und ruft
  danach `reset()` auf.
- Es wird nichts von Fragen oder Antworten aufgezeichnet: Span-Namen sind feste Strings aus dem Code,
  `meta` nimmt nur Zahlen und Wahrheitswerte an (Strings werden verworfen). Einzige Ausnahme ist
  `where` im Span `fatal` – ein Pfad unterhalb des Moodle-Wurzelverzeichnisses, nie eine Meldung.

## API-Vertrag für CAT-Modelle

Neuere Methoden sind per `method_exists()` zu prüfen; ältere Hosts kennen nur `get_spans()`/`reset()`.

| Methode | Ergebnis |
|---|---|
| `get_spans(): array` | Spans in Startreihenfolge: `name`, `depth`, `offsetms` (ms seit `REQUEST_TIME_FLOAT`), `ms`, `meta` |
| `reset(): void` | vergisst die Spans; Request-ID und Versuch bleiben |
| `request_id(): string` | Korrelations-ID des Requests (lazy) |
| `attempt(): ?array` | `['attemptid' => int, 'questionnumber' => int]`, sobald der Host den Versuch kennt |
| `summary(): array` | `memkb`, `peakkb`, `peakrealkb` des Requests bis jetzt, dazu `spans`, `dropped` |

Weitere öffentliche Methoden (Host-intern): `start()`, `stop()`, `measure($name, $fn, $meta = [])`,
`measure_db()` (zählt `dbreads`), `set_attempt()`, `send_request_id_header()`, `on_shutdown()`,
`reset_request()` (nur für Tests: vergisst zusätzlich Request-ID, Versuch und Test-Naht).

### Korrelation

- Ein eingehender Header `X-Request-Id`, der `^[A-Za-z0-9-]{8,64}$` erfüllt, wird übernommen
  (Proxy- oder Webserver-Log). Sonst wird `bin2hex(random_bytes(8))` erzeugt.
- `attempt.php` sendet die ID vor jeder Ausgabe und jedem Redirect als `X-ALiSe-Request-Id` zurück
  (nur wenn `headers_sent()` noch falsch ist).
- `set_attempt()` setzt v3 in `attempt.php` direkt nach `get_attempt()`; in der Legacy-Linie, wo
  `attempt.php` den Versuch erst in der Item-Administration erreicht, in
  `cat_session::run_item_administration_locked()` direkt nach `get_attempt()`.
  `questionnumber = questionsattempted + 1`: 1 ist die erste Frage eines Versuchs.

### Speicher je Span

Jeder geschlossene Span trägt in `meta`: `memkb` (Speicher am Ende), `memdeltakb` (Änderung während
des Spans), `peakkb` (Peak des Requests bis dahin), `peakgrewkb` (Zuwachs des Peaks während des
Spans). Der Peak wird **nie** zurückgesetzt (`memory_reset_peak_usage()` würde jeden anderen Messer
des Requests verfälschen); `peakgrewkb > 0` zeigt, welcher Span den Peak getrieben hat.

### Fatale Fehler

Beim ersten `start()` registriert sich `on_shutdown()` beim `core_shutdown_manager` – vor allem, was
später registriert wird. Bei einem fatalen Fehlertyp (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`,
`E_COMPILE_ERROR`, `E_USER_ERROR`, `E_RECOVERABLE_ERROR`) werden offene Spans mit `meta.open = true`
geschlossen und ein Span `fatal` mit `type`, `line` und `where` (Pfad unterhalb von `dirroot`, sonst
nur der Dateiname) angehängt – nie die Meldung. Ein CAT-Modell, das seinen Trace beim Shutdown
schreibt, findet ihn so schon vor. `measure()` markiert einen Span, dessen Schritt wirft, mit
`meta.threw = true` und wirft unverändert weiter. Test-Naht: `request_timing::$lasterror`.

### Puffergrenze

Höchstens `MAX_SPANS = 500` Spans je Request; weitere werden nur gezählt (`summary()['dropped']`).
Ein verworfener Span schließt beim `stop()` keinen anderen. `fatal` wird auch bei vollem Puffer
aufgenommen.

## Spans

| Span | Ort | meta |
|---|---|---|
| `process_response` | `attempt.php`, Antwort-POST | |
| `answer_lock_wait` | `cat_session::process_administered_item_result()` | `acquired` |
| `quba_load`, `quba_process`, `quba_save` | Antwortpfad | |
| `catmodel_item_result` | Antwortpfad, Callback des CAT-Modells | |
| `administer_item` | `attempt.php` | |
| `lock_wait` | Item-Lock in `cat_session` | `acquired` |
| `attempt_create_lock_wait` | `attempt::get_attempt()`, nur wenn kein offener Versuch existiert | `acquired` |
| `attempt_create` | Insert des Versuchs | |
| `catmodel_attempt_created` | Callback „Versuch angelegt“ | |
| `quba_load` | `attempt::initialize_quba()` (vorhandene Usage), v3 auch `set_quba_id()` | `slots` |
| `select_item` | Auswahl; jede weitere Nachfrage eigener Span | `retry` = n |
| `load_question` | `question_bank::load_question()` | `dbreads` |
| `question_usage` mit Kindern `quba_add`, `quba_start`, `quba_save` | CAT-Modell- und eingebauter Pfad | `slots` |
| `render_question` | `attempt.php` | |
| `fatal` | Shutdown nach fatalem Fehler | `type`, `line`, `where` |

### Question-Bank: kein eigener Lock

Auf dem Pfad `question_bank::load_question()` gibt es keinen Lock. Der einzige Lock dieses Pfads ist
der Item-Lock (`lock_wait`). Ein `meta.cached` wird bewusst nicht gesetzt: die Frage, ob der Cache
traf, wäre ein zweiter Cache-Zugriff bei jedem Laden. Stattdessen zählt `load_question` die
Datenbank-Lesezugriffe (`dbreads`, aus `$DB->perf_get_reads()` – kostenlos): `0` heißt, die Frage kam
aus dem `questiondata`-Cache. Fragetypen, die in `make_question()` selbst lesen, zeigen auch bei
Cache-Treffer `dbreads > 0`.

## Locks auf dem Pfad

- **Anlage des Versuchs** (`adaptivequiz_attemptcreate_<instanz>_<user>`): Läuft das Warten ab und
  existiert noch kein Versuch, wird **kein** Versuch angelegt, sondern `attemptcreatelocktimeout`
  geworfen (#17; vorher entstand dann doch ein Doppelversuch, #5). Hat die andere Anfrage ihren
  Versuch inzwischen angelegt, wird er übernommen. Wartezeit: `attempt::$createlocktimeout`.
- **Item-Lock** (`adaptivequiz_itemadministration_<instanz>_<user>`): umschließt die Item-Administration
  und – seit dem Audit vom 18.10. – auch die Verarbeitung der Antwort. Zwei gleichzeitige POSTs
  derselben Antwort schrieben vorher beide einen Antwortschritt mit derselben Sequenznummer; einer
  endete auf einer Fehlerseite (Mehrprozesstest `tests/concurrent_answer_test.php`). Jetzt wird
  serialisiert, und ein POST, den die Question Engine als „out of sequence“ erkennt, ist das Duplikat
  einer verarbeiteten Antwort: er ändert nichts (`processed = false`) und zeigt die Frage, bei der der
  Versuch steht. Ist der Versuch dadurch schon beendet, leitet `attempt.php` auf die Ergebnisseite.

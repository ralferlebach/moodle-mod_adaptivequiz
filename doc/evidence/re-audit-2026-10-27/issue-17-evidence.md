# mod_adaptivequiz #17 – Evidenz: Performance-, Lock- und Memory-Timeline, Wiederherstellung (Re-Audit 27.10.2026)

**Stand:**
- v3: `v-3.0` @ `8ef48b8`
- Legacy: `ALiSe-v-1.2.0-legacy` @ `f95a747`
- beide in diesem Paket mit Version 2026102800

**Gegenstücke:**
- local_catquiz 1.3 (2026102406) bzw. 1.2 (2026102405)
- Adapter `v-3.0` 2026102600 bzw. Legacy 2026101801

**Umgebung:** Moodle 4.5.15 (Legacy) und 5.1.8/5.2+/5.3+ (v3), PHP 8.3.6, PostgreSQL 16/17, MariaDB 10.11/11.4,
Chrome 147. Die Behat-Matrix mit Exit-Codes steht in local_catquiz `doc/re-audit-2026-10-27.md` §3.

**Werkzeug:**
- `local/catquiz/tests/load/attempt_load.py`: echte HTTP-Formular-POSTs bis zur Ergebnisseite.
- `seed_attempt_load.php`: 160 Fragen je Versuch, Aktivitätsgrenze 160.
- `analyse_attempt_load.php`: Traces je Anfrage mit Spans aus Host, Adapter und local_catquiz, korreliert über
  `X-ALiSe-Request-Id`, dazu Fragenverwendung und Integrität.
- Server: `php -S` mit 4 Workern und `memory_limit=512M`.

**Wichtig zur Einordnung:** Die Maschine hat **2 CPU-Kerne**, und neben den Läufen liefen Behat und PHPUnit. Die
Latenzen sind deshalb **keine Zielwerte** und kein Linienvergleich. Grenzwerte legen Sie
fachlich fest.

### Moodle 4.5 / Legacy (1.2), „Infer all subscales“, 160 Fragen

| Lauf | Versuche | Fragen je Versuch | doppelte Fragen | doppelt verarbeitete Slots | Kaltstart ms | Folgefrage p50/p95/p99 ms | DB p95 | Peak KB | Abschluss p50 ms | HTTP | Exceptions/Feedback-Fehler | Integritätsfehler |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| seriell, 1 Person | 1 | 160 | 0 | 0 | 2235 | 5124.81/8251.33/9356.78 | 308 | 24576 | 3822.69 | {200: 163} | 0/0 | 0 |
| parallel, 4 Personen | 4 | 160, 160, 160, 160 | 0 | 0 | 8066 | 16650.02/26084.76/31359.56 | 307 | 24576 | 2471.2 | {200: 652} | 0/0 | 0 |
| Doppelklick letzte Antwort, 4 parallel | 4 | 160, 160, 160, 160 | 0 | 0 | 8942 | 7496.26/11204.37/15010.07 | 310 | 24576 | 2367.22 | {200: 656} | 0/0 | 0 |
| Abbruch POST bei Frage 10, 4 parallel | 4 | 160, 160, 160, 160 | 0 | 0 | 6154 | 8267.36/10556.18/11176.95 | 308 | 24576 | 3718.62 | {200: 652, None: 4} | 0/0 | 0 |

PHP-Worker RSS max 93.1 MiB (memory_limit 512M).

### Moodle 5.3 / v3 (1.3), „Infer all subscales“, 160 Fragen

| Lauf | Versuche | Fragen je Versuch | doppelte Fragen | doppelt verarbeitete Slots | Kaltstart ms | Folgefrage p50/p95/p99 ms | DB p95 | Peak KB | Abschluss p50 ms | HTTP | Exceptions/Feedback-Fehler | Integritätsfehler |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| seriell, 1 Person | 1 | 160 | 0 | 0 | 14054 | 6833.9/11710.64/12766.36 | 309 | 26624 | 4032.61 | {200: 163} | 0/0 | 0 |
| parallel, 4 Personen | fehlt ([Errno 2] No such file or directory: '/tmp/claude-0/evidence/host17_53/parallel.analyse.json') |
| Doppelklick letzte Antwort, 4 parallel | fehlt ([Errno 2] No such file or directory: '/tmp/claude-0/evidence/host17_53/doublefinal.analyse.json') |
| Abbruch POST bei Frage 10, 4 parallel | fehlt ([Errno 2] No such file or directory: '/tmp/claude-0/evidence/host17_53/abort.analyse.json') |

PHP-Worker RSS max 105.3 MiB (memory_limit 512M).

### Ergebnisse

- **≥ 150 Items** seriell und parallel: jeder Versuch mit 160 Fragen beantwortet, 160 Slots, 0 doppelte Fragen, 0
  doppelt verarbeitete Slots.
- **Doppelklick auf die abschließende Antwort**, auch Legacy: Beide POSTs liefern 200 und ein Ergebnis. Es gibt keinen
  zweiten QUBA-Step, die Analyse zählt bewertete Schritte je Slot.
- **Abgebrochener POST** (50 ms) mit Wiederaufnahme: Der Versuch läuft weiter bis 160, ohne doppelten Slot.
- **Speicher:** PHP-Worker RSS unter 100 MiB, Peak je Anfrage laut Trace 24 MiB, bei 512 MiB Grenze.
- **Latenz:** Unter der Volllast dieser Umgebung (Last 8–9 auf 2 Kernen) dominiert `strategy:select_question`, die
  rechengebundene Auswahl der nächsten Frage. Auf beiden Linien liegt sie seriell bei mehreren Sekunden; auf 5.3 war
  sie sogar langsamer als auf 4.5. Die Zahlen hier vergleichen also keine Linien. Ruhig gemessen am 26.10. lag 5.3 bei
  p50 279 ms je Anfrage (160 Fragen, ein Teilnehmer). Eine ruhige Vergleichsmessung Legacy/v3 steht aus.
- **5.3:** Nur der serielle Lauf ist vollständig. Parallel, Doppelklick und Abbruch wurden unter dieser Last nach
  40 Minuten abgebrochen. Für 4.5 liegen alle vier Läufe vor.
- **Ein echter OOM-Fall** wurde nicht provoziert. Die Fatal- und Shutdown-Erfassung samt Request-ID existiert seit
  #17/#136, ein Lauf mit erzwungenem OOM steht aus.
- **Instrumentierung aus:** kein Overhead-Vergleich gelaufen.

Rohdaten: `issue-17/45/*`, `issue-17/53/*`.

# mod_adaptivequiz #17 – Lastmatrix v3, ruhig gemessen (28./29.10.2026)

**Stand:**
- `v-3.0`, Basis `8a72637`, in diesem Paket 2026102801. Der Plugin-Code ist unverändert gegenüber 2026102800.
- Gegenstücke: local_catquiz 1.3 2026102407 und Adapter `v-3.0` 2026102800.

**Umgebung:**
- Moodle 5.3+, PHP 8.3.6, PostgreSQL 17.
- `php -S` mit 4 Workern und `memory_limit=512M`.
- 2 CPU-Kerne. Neben dem Lauf lief nichts.

**Wiederholung:** Der Lauf vom 27.10. auf 5.3 war unvollständig. Ich hatte während des Laufs die Spiegel
synchronisiert, und es kamen 404-Antworten. Diesmal blieben alle Spiegel bis zum Ende unberührt.

**Werkzeug wie am 27.10.:**
- `attempt_load.py`: echte Formular-POSTs bis zur Ergebnisseite.
- Seed: 160 Fragen, Strategie „Infer all subscales“.
- `analyse_attempt_load.php`: Spans, Fragenverwendung, Integrität.

| Lauf | Versuche | Fragen je Versuch | doppelte Fragen | doppelt verarbeitete Slots | Kaltstart ms | Folgefrage p50/p95/p99 ms | DB p95 | Peak KB | Abschluss p50 ms | HTTP | Exceptions/Feedback-Fehler | Integritätsfehler |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| seriell, 1 Person | 1 | 160 | 0 | 0 | 3944 | 3916.77/4803.05/5356.48 | 316 | 26624 | 2658.34 | {200: 163} | 0/0 | 0 |
| parallel, 4 Personen | 4 | 160, 160, 160, 160 | 0 | 0 | 7897 | 10545.28/15715.28/23589.4 | 312 | 26624 | 3881.42 | {200: 652} | 0/0 | 0 |
| Doppelklick letzte Antwort, 4 parallel | 4 | 160, 160, 160, 160 | 0 | 0 | 4305 | 8391.76/10445.34/11553.56 | 306 | 26624 | 3352.11 | {200: 656} | 0/0 | 0 |
| Abbruch POST bei Frage 10, 4 parallel | 4 | 160, 160, 160, 160 | 0 | 0 | 9717 | 11219.86/14402.18/15154.78 | 308 | 26624 | 4615.38 | {200: 652, None: 4} | 0/0 | 0 |

PHP-Worker RSS max 112.7 MiB (memory_limit 512M).

### Ergebnisse

- **≥ 150 Items:** In allen vier Läufen hat jeder Versuch 160 Fragen beantwortet. Es gab 0 doppelte Fragen und 0
  doppelt verarbeitete Slots.
- **Antworten:** Alle Antworten waren HTTP 200, ohne Exceptions und ohne Feedback-Fehler.
- **Integrität:** 0 Fehler. Je Versuch gab es genau einen CAT-Versuch und einen Progress, keinen fremden.
- **Doppelklick auf die letzte Antwort:** Beide POSTs liefern 200. Es gibt keinen zweiten bewerteten Schritt.
- **Abgebrochener POST bei Frage 10 (50 ms):** Die vier `None` sind die absichtlich abgebrochenen Anfragen. Danach
  laufen die Versuche bis 160 weiter, ohne doppelten Slot.
- **Speicher:** PHP-Worker RSS höchstens 112,7 MiB, Peak je Anfrage 26 MiB, bei 512 MiB Grenze.
- **Latenz:**
  - Seriell braucht die Folgefrage p50 3,9 s. Davon entfallen 3,7 s auf `catquiz:fetch_question_id`; darin
    stecken `strategy:select_question` mit 2,0 s (0 DB-Abfragen, rein rechnerisch), `context:person_ability` mit
    0,9 s und `strategy:update_attemptfeedback` mit 0,6 s.
  - Mit 4 Personen auf 2 Kernen steigt der p50 auf 10,5 s. Das passt zu einer CPU-gebundenen Auswahl und ist keine
    Sperre: Die DB-Abfragen bleiben gleich.
  - Am 27.10. lag der serielle Wert auf 5.3 unter Fremdlast bei 6,8 s.
  - Die Zahlen sind Vergleichswerte dieser Maschine, keine Zielwerte. Grenzwerte legen Sie fest.

### Nicht in diesem Lauf

- Ein erzwungener OOM-Fall.
- Ein Overhead-Vergleich mit abgeschalteter Instrumentierung.
- Ein ruhiger Gegenlauf auf 4.5/Legacy (dessen Werte vom 27.10. entstanden unter Fremdlast).

Rohdaten: `issue-17/53/`. RSS-Zeitreihe gepackt.

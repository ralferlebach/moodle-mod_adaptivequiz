# PHP-8.5-Canary (#13)

Job `php85-canary` in `.github/workflows/ci.yml`: PHPUnit dieses Plugins unter PHP 8.5 gegen
`MOODLE_503_STABLE`, nicht unterstützt und nicht blockierend (`continue-on-error`). Jede Deprecation
wird mit Datei erfasst und von `tools/classify_deprecations.py` ihrer Herkunft zugeordnet.

## Herkunft

| Herkunft | Pfad |
|---|---|
| `PLUGIN` (Gate) | `mod/adaptivequiz/` außerhalb von `catmodel/` und jedem `vendor/` – und `catmodel/testcatmodel/`, das zum Host gehört |
| `CATMODEL` | übrige `mod/adaptivequiz/catmodel/*` |
| `MOODLE_CORE` | Moodle-Baum ohne Zusatz-Plugins, Standard-Plugins (`lib/plugins.json`), gebündelte Bibliotheken |
| `DEPENDENCY` | Nicht-Standard-Plugins und jedes `vendor/` unterhalb von `mod/adaptivequiz/` |
| `UNKNOWN` | Pfad außerhalb des Baums oder Meldung ohne Pfad |

PHPUnits eigene Liste wird nur innerhalb ihrer Deprecation-Abschnitte gelesen; jede andere
Abschnittsüberschrift (Warnings, Failures, Errors …) und die Abschlusszeile beenden ihn.

## Exit-Codes

| Code | Bedeutung |
|---|---|
| 0 | Gate bestanden, Infrastruktur in Ordnung |
| 1 | mindestens eine `PLUGIN`-Deprecation |
| 2 | falscher Aufruf |
| 3 | Lauf nicht beurteilbar: leeres Log, kein Moodle-Baum (`lib/components.json` fehlt), mit `--expect-phpunit` kein PHPUnit-Ergebnis (`Tests: N` / `OK (N tests`) oder PHPUnit-Exit ≠ 0/1 |

Exit 3 geht vor Exit 1: ein unvollständiger Lauf kann PLUGIN-Meldungen verschweigen. Früher
verschluckte `init.php … | tail -n 5` ohne `pipefail` ein gescheitertes Init, die Canary war dann grün,
ohne getestet zu haben; jetzt brechen die Schritte ab (`shell: bash`, `set -o pipefail`).

## Reproduzierbarkeit

`deprecations-summary.json` (Schlüssel sortiert) enthält `meta` mit `moodle_sha`, `php_version`,
`phpunit_exit`, `tests_run` und `gate` mit `plugin`, `result` (PASS/FAIL), `infrastructure` (OK/FEHLER)
und `problems`. Die Markdown-Zusammenfassung beginnt mit Moodle-SHA, PHP-Version, PHPUnit-Exit und
Testzahl und der Zeile `Gate: PLUGIN=n → FAIL/PASS; Infrastruktur: OK/FEHLER`. Der Workflow
protokolliert die SHA des Moodle-Checkouts.

Der Selbsttest `tools/test_classify_deprecations.py` läuft zusätzlich blockierend im Job
`classifier` („Selbsttest des Deprecation-Classifiers“; bis 16.11.2026 im Job `architecture`, der mit
Host #24 entfallen ist) – ein Fehler der Einordnung bliebe in der nicht blockierenden Canary sonst
unbemerkt.

## Vertrag (Re-Audit 17.11.2026)

- **Unterstützt** sind die Zellen des Jobs `test`: Moodle 5.1 mit PHP 8.2 bis 8.4, Moodle 5.2 und 5.3 mit
  PHP 8.3 und 8.4 (das Minimum von 5.2 und 5.3 ist laut `admin/environment.xml` PHP 8.3), je PostgreSQL und
  MariaDB. Nur diese Zellen sind Release-Nachweis.
- **PHP 8.5 ist nicht unterstützt.** Zwei Jobs beobachten es:
  - `php85` (blockierend) prüft nur Syntax und Code-Style des eigenen Codes unter 8.5. Er sagt nichts über
    das Laufzeitverhalten und ist keine Support-Aussage.
  - `php85-canary` (nicht blockierend) fährt PHPUnit unter 8.5.
- **Was ein Canary-Lauf belegt:**
  - `Gate: PLUGIN=0 → PASS; Infrastruktur: OK` mit Testzahl > 0: Unter 8.5 meldet der eigene Code keine
    Deprecation. Mehr nicht.
  - `Infrastruktur: FEHLER` (Exit 3): Der Lauf ist **nicht beurteilbar**, etwa weil Moodle 5.3 unter 8.5 nicht
    installiert oder initialisiert. Das wird als „nicht beurteilbar“ berichtet, nie als bestanden.
  - Ein grünes Häkchen des Jobs allein belegt nichts, weil er nicht blockiert. Maßgeblich ist die Zeile `Gate:`
    der Zusammenfassung im Artefakt.
- **Abhaken in Host #13:** Ein Canary-Punkt gilt erst als erledigt, wenn ein Lauf auf dem finalen SHA mit
  `Infrastruktur: OK` und Testzahl > 0 vorliegt. Solange Moodle selbst unter 8.5 nicht startet, bleibt er offen
  und wird als „durch Moodle-Core blockiert“ geführt.
- **Prüfung der Matrix:** `tools/ci_matrix_audit.py` (Projekt-Werkzeug) expandiert alle Workflows und meldet
  PHP ≤ 8.1, PHP unter dem Minimum des Moodle-Zweigs und PHP 8.5 als blockierende Testzelle.

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
`architecture` – ein Fehler der Einordnung bliebe in der nicht blockierenden Canary sonst unbemerkt.

# mod_adaptivequiz #13 – Evidenz: PHP-8.5-Canary (Re-Audit 27.10.2026)

## Stand

| Linie | Branch | Basis-SHA | Version dieses Pakets |
|---|---|---|---|
| v3 | `v-3.0` | `8ef48b8` | 2026102800 |
| Legacy | `ALiSe-v-1.2.0-legacy` | `f95a747` | 2026102800 |

Der Canary-Job steht in `.github/workflows/ci.yml` (`php85-canary`). Dieses Paket ändert ihn nicht.

## Was hier gelaufen ist – und was nicht

**PHP 8.5 gibt es in dieser Umgebung nicht.** Ubuntu liefert es nicht. Das Nachinstallieren aus einer fremden
Paketquelle (PPA) wurde von der Umgebung abgelehnt. Der echte Canary-Lauf unter 8.5 ist deshalb nur in der GitHub-CI
möglich (AK 2). Er braucht einen Push.

**Lokal belegt ist das Gate**, also die Klassifikation und die Exit-Codes von `tools/classify_deprecations.py`. Grundlage
sind echte PHPUnit-Ausgaben: Moodle 5.3+, PHP 8.3.6, PHPUnit 11.5.56, PostgreSQL 17.

| Fall | Eingabe | PHPUnit-Exit | Klassifikator-Exit | Gate |
|---|---|---|---|---|
| künstliche Plugin-Deprecation | Probe-Test in `mod/adaptivequiz/tests/` mit `E_USER_DEPRECATED`, zusammen mit `lib_test.php` (7 Tests) | 1 | **1** | `PLUGIN=1 → FAIL; Infrastruktur: OK` |
| Core-only-Kontrolle | sauberer Lauf (`lib_test.php`, 6 Tests) plus eine Deprecation-Zeile aus `public/lib/moodlelib.php` | 0 | **0** | `PLUGIN=0 → PASS`, `MOODLE_CORE=1` |
| sauber | `lib_test.php`, 6 Tests | 0 | 0 | PASS |
| leeres Log | – | – | **3** | `Infrastruktur: FEHLER` |

Damit ist AK 3 auf Gate-Ebene belegt: Eine Plugin-Deprecation bricht das Gate, eine reine Core-Deprecation nicht. Die
Probe-Testdatei lag nur im Spiegel und wurde danach entfernt. Am Moodle-Core wurde nichts geändert.

Dazu läuft der Selbsttest `python3 tools/test_classify_deprecations.py` mit dem Ergebnis „self-test passed“.

**Dateien in `issue-13/`:**
- `probe_plugin.log`, `probe_clean.log`, `probe_coreonly.log`, `empty.log` – Eingaben
- `classify_*.txt` – Ausgaben des Klassifikators
- `out_*/deprecations-summary.{md,json}` – Zusammenfassungen
- `results.txt` – Exit-Codes

## Reguläre Matrix 5.1–5.3 (AK 1)

PHPUnit `mod_adaptivequiz_testsuite`, lokal mit PHP 8.3: siehe `re-audit-2026-10-27.md` im local_catquiz-Paket,
Abschnitt Matrix. Die CI-Run-URL folgt nach dem Push.

## AK 4

`doc/php85-canary.md` und der Job-Name kennzeichnen PHP 8.5 als „nicht unterstützt, Vorwärtskompatibilität“. Das ist
unverändert.

## Offen

- Zwei CI-Run-Belege (reguläre Matrix und Canary) mit den Artefakten `php85-canary-deprecations`. Sie entstehen nach
  dem Push.

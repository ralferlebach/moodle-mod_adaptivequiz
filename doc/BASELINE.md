# CI-Baseline des Integrationsbranches (Issue #10, Phase 2)

Dieser Branch (`rebase/vtos-moodle500-alise`) beginnt bei `vtos/MOODLE_500`
(`a769068`, „Add Moodle 5 support", 2026-06-10). Das Dokument hält den Zustand
fest, der **vor** jeder Portierung erreicht wurde. Alle späteren Läufe werden
gegen diese Zahlen gestellt.

## Zielplattform

Entwickelt wird für **Moodle 5.3 (LTS)**, abgesichert per CI gegen 5.1 und 5.2.
Moodle 4.x und 5.0 werden nicht unterstützt.

```php
$plugin->requires  = 2025100600;   // Moodle 5.1
$plugin->supported = [501, 503];
```

Systemvoraussetzungen von Moodle 5.3 (`public/admin/environment.xml`):

| Komponente | Minimum |
|---|---|
| PHP | 8.3.0 |
| PostgreSQL | 17 |
| MariaDB | 11.4.0 |
| MySQL | 8.4 |

Eine Obergrenze fuer PHP deklariert Moodle 5.3 nicht; PHP 8.5 wird deshalb als
oberes Ende der Spanne mitgefahren.

Ab Moodle 5.1 liegt der Baum unter `public/`; Plugins gehören nach
`public/mod/adaptivequiz`, `config.php` bleibt im Projektwurzelverzeichnis.

## Gemessene Baseline

Suite `mod_adaptivequiz_testsuite`, Host **ohne** `local_catquiz` und ohne
`adaptivequizcatmodel_catquiz`, PHP 8.3.6, PostgreSQL 17.

| Moodle | Release | PHPUnit | Ergebnis |
|---|---|---|---|
| 5.1 | 5.1.6+ (Build 20260903) | 11.5.55 | **152 Tests, 433 Assertionen, grün** |
| 5.2 | 5.2.2+ (Build 20260903) | 11.5.55 | **152 Tests, 433 Assertionen, grün** |
| 5.3 | 5.3dev (Build 20260903) | 11.5.56 | **152 Tests, 433 Assertionen, grün** |

Zum Vergleich, aus der Inventur (Issue #10 Phase 1):

| Stand | Moodle 4.5.13 | Moodle 5.0.9 |
|---|---|---|
| `vtos/MOODLE_404` | 146 Tests, 343 Assertionen, grün | – |
| `vtos/MOODLE_500` | 147 Tests, 36 Fehler (nur mit gesenktem `requires`) | 152 Tests, 433 Assertionen, grün |
| `ralferlebach/v-3.0` | 152 Tests, 649 Assertionen, grün | 136 Tests, 16 Fehler |

## Matrix Moodle x PHP

Alle Laeufe gegen PostgreSQL 17, Suite `mod_adaptivequiz_testsuite`, Stand nach
Phase 3 (156 Tests / 446 Assertionen).

| | PHP 8.3.6 | PHP 8.4.25 | PHP 8.5.10 |
|---|---|---|---|
| Moodle 5.1.6+ | gruen | – | gruen |
| Moodle 5.2.2+ | gruen | – | gruen |
| Moodle 5.3dev | gruen | gruen | gruen |

MariaDB 11.4.13 auf Moodle 5.3 unter PHP 8.3: gruen.

Unter PHP 8.5 meldet PHPUnit drei Deprecations. Alle drei stammen aus dem
Moodle-Kern, nicht aus dem Plugin: `lib/dml/pgsql_native_moodle_database.php`
(null als Array-Offset, zweimal) und `lib/classes/plugininfo/base.php` bzw.
`user/profile/lib.php` je nach Zweig. Aus dem Plugin selbst kommt keine.

Drei plugin-eigene Deprecations wurden dabei behoben: implizit nullable
Parameter in `adaptivequiz_add_instance()`, `adaptivequiz_update_instance()` und
`mod_adaptivequiz_generator::create_instance()`. Ab PHP 8.4 verlangt PHP dort
die explizite Form `?Type $x = null`.

## Die einzige Anpassung, die für 5.3 nötig war

`adaptivequiz_supports()` deklarierte `FEATURE_GROUPMEMBERSONLY`. Ab Moodle 5.3
lehnt `public/lib/upgradelib.php` Plugins mit dieser Deklaration hart ab
(`detectedbrokenplugin`, MDL-83231). In 5.0 bis 5.2 ist die Konstante noch
definiert und die Prüfung nicht scharf.

**Zahn-Test durchgeführt.** Wichtig dabei: Die Prüfung greift ausschliesslich bei
Neuinstallation oder Upgrade, nicht bei einem bereits installierten Plugin. Ein
blosses Zurücksetzen der Datei im Spiegel bleibt deshalb grün und beweist nichts.
Der belastbare Ablauf ist:

```
Testdatenbank verwerfen, phpunit_dataroot leeren
  -> Zweig wieder eingesetzt : init bricht mit detectedbrokenplugin ab (rot)
  -> Zweig entfernt          : 152 Tests, 433 Assertionen (grün)
```

## Stand nach Phase 3

| Zustand | Ergebnis |
|---|---|
| Host aus dem Release-ZIP, kein Catmodel installiert | 152 Tests, 433 Assertionen, gruen |
| Arbeitsstand mit adaptivequizcatmodel_testcatmodel | 161 Tests, 459 Assertionen, gruen |

Beides ist die Abnahme aus Issue #10 §8: der Host laeuft ohne jedes Catmodel,
und er laeuft mit einem beliebigen neutralen Catmodel.

Der Vertrag umfasst sechs Erweiterungspunkte, aufgeloest ueber
`catmodel_resolver`, angewandt an vier Stellen:

| Stelle | Erweiterungspunkt |
|---|---|
| `adaptivequiz_add_instance()` | `catmodel_add_instance_handler` |
| `adaptivequiz_update_instance()` | `catmodel_update_instance_handler` |
| `adaptivequiz_delete_instance()` | `catmodel_delete_instance_handler` |
| `mod_form_extension::apply()` | `catmodel_mod_form_modifier` |
| `mod_form_extension::validate()` | `catmodel_mod_form_validator` |
| `mod_form_extension::preprocess()` | `catmodel_mod_form_data_preprocessor` |
| noch nicht aufgerufen | `item_administration_factory` |

### Was an der Item-Administration offen ist

Die Vertragsklassen `item_administration`, `item_administration_factory`,
`item_administration_evaluation` und `next_item` sind portiert, und der Resolver
findet eine Fabrik, die ein Catmodel anbietet. **Aufgerufen wird sie noch
nicht.** Das ist bewusst so ausgeliefert, und der Grund gehoert dokumentiert:

`attempt.php` wickelt einen Versuch prozedural ab. Antwortverarbeitung,
Schwierigkeitsberechnung, Abbruchpruefung, `redirect()` und Rendering liegen in
einem Skript ineinander. Um die eingebaute Logik hinter den Vertrag zu ziehen,
muesste sie aus dem Skript herausgeloest werden - der Fork hat dafuer
`attempt.php` neu geschrieben und `cat_session` eingefuehrt. Das ist keine
mechanische Portierung, sondern ein Umbau von rund 130 Zeilen Upstream-Code mit
`redirect()`-Aufrufen mittendrin, und er laesst sich mit den vorhandenen Tests
nicht bitgenau absichern.

Deshalb als eigener Arbeitsschritt vorgemerkt, mit dieser Reihenfolge:

1. `default_item_administration` aus `attempt.php` herausloesen, ohne
   Verhaltensaenderung; Nachweis ueber `attempt_test`, `catalgo_test`,
   `fetchquestion_test` und `locallib_test`.
2. `attempt.php` auf die Fabrik umstellen, Default wie bisher.
3. Erst dann die Verzweigung auf ein Catmodel einschalten.

Bis dahin ist `item_administration_factory` ein veroeffentlichter Vertrag ohne
Aufrufstelle im Host. Ein Catmodel kann ihn implementieren, der Host fragt ihn
noch nicht.

## Offene Punkte, die aus der Baseline folgen

**Code-Style.** Der Upstream-Stand erzeugt unter dem Moodle-Standard
**1675 Fehler und 8 Warnungen in 118 Dateien**, davon 1451 maschinell
korrigierbar. Grösste Gruppen: PSR2-Funktionsaufruf-Signaturen (229), lange
Array-Syntax (225), Operator-Abstände (336), fehlende Docblocks (96). Der
Upstream fährt kein phpcs-Gate.

Damit kollidieren zwei Vorgaben aus Issue #10: die Definition of Done verlangt
„Moodle Coding Style und PHPDoc vollständig grün", §14 verlangt „keine
unnötigen Format-Diffs in unverändertem Upstream-Code". Ein `phpcbf` über den
ganzen Baum macht jede künftige Upstream-Übernahme konfliktreich.

Vorschlag: Das phpcs-Gate wird erst **nach** Abschluss der Portierung scharf
geschaltet, und die maschinelle Korrektur erfolgt als ein einzelner, klar
benannter Format-Commit am Ende – nicht verteilt über die Portierungs-Commits.
Bis dahin gilt das Gate nur für Dateien, die in diesem Branch neu entstehen oder
inhaltlich geändert werden.

**Versionsnummer.** `vtos/MOODLE_500` trägt `$plugin->version = 2026030101`, der
bisherige Fork `2026090604`. Ein Upgrade einer bestehenden Fork-Installation auf
diesen Branch wäre damit ein Downgrade und wird von Moodle abgelehnt. Die
Release-Metadaten werden nach Issue #10 ohnehin erst am Ende der Konsolidierung
festgelegt; dieser Punkt ist dort mitzuentscheiden.

**`$plugin->cron = 0`** und die leere Funktion `adaptivequiz_cron()` sind
Altbestand aus der Zeit vor den Scheduled Tasks und können in Phase 4 entfallen.

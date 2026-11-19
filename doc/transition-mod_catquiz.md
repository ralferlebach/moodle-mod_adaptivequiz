# Übergang zu mod_catquiz: Architektur-Gate aufgehoben (Issue #24)

Stand 16.11.2026. Gilt für beide Linien: `ALiSe-v-1.2.0-legacy` (Moodle 4.5) und `v-3.0` (Moodle 5.1–5.3).

## 1. Entscheidung

mod_adaptivequiz wird nicht mehr als CATquiz-unabhängiger Host weiterentwickelt. Ziel ist ein `mod_catquiz`, der
`local_catquiz` voraussetzt; der Adapter `adaptivequizcatmodel_catquiz` entfällt dann. Die Zielarchitektur und die
Migration werden gesondert beauftragt.

Mit diesem Issue fällt nur die Regel „Der Host darf CATquiz nicht kennen“. Nichts anderes ändert sich:
- Komponente bleibt `mod_adaptivequiz`, keine neue Abhängigkeit in `version.php`;
- der Adapter bleibt installiert und ist weiter der Weg zu CATquiz;
- keine Tabelle, URL, Fähigkeit oder Datensicherung ändert sich, keine Daten werden migriert.

## 2. Inventar der Trennungsregeln (AK1)

| Ort | Linie | Art | Wirkung bisher | Jetzt |
|---|---|---|---|---|
| CI-Job „Architekturgrenze zu local_catquiz“, Schritt „Keine CATquiz-Referenzen im Host“ (`.github/workflows/mod_adaptivequiz-ALiSe-v-1.2.0-legacy-ci.yml` bzw. `ci.yml`) | beide | Gate, blockierend | Jede Nennung von `local_catquiz`, `catquiz_handler`, `catscale`, `adaptivequizcatmodel_catquiz` in Code/Tests ließ die CI scheitern | entfernt; in v-3.0 bleibt der Selbsttest des Deprecation-Classifiers als eigener Job |
| Kopfkommentar der Legacy-CI („… und die Architekturgrenze zu local_catquiz“) | 1.2 | Regeltext | – | angepasst |
| `.github/workflows/playwright.yml`, Kommentar zur CATquiz-Geschichte | 1.3 | Begründungstext | – | als historisch gekennzeichnet; die Geschichte bleibt im Adapter-Repository |
| `README.md`, Abschnitt „What a CAT model subplugin is“ („holds no CAT model logic of its own“) | 1.3 | Regeltext | – | als derzeitiger Stand beschrieben, Regel aufgehoben |
| `classes/local/catmodel/catmodel_resolver.php`, Docblock („deliberately no other way“) | 1.3 | Regeltext an funktionalem Code | – | Text angepasst; der Resolver bleibt unverändert |
| `tests/local/catmodel/catmodel_resolver_test.php`, Docblock („never a real one“) | 1.3 | Testbegründung | – | sachlich begründet (Host-Tests laufen ohne CATquiz) |
| `tests/item_bank_readiness_test.php`, Docblock („must not know any real one (issue #10)“) | 1.3 | Testbegründung | – | angepasst |
| `classes/local/question_file_access.php`, Kommentar („the host knows no CAT model“) | 1.2 | Begründungstext | – | angepasst |
| Lokaler Preflight, Stufe 2 | Werkzeug | Gate | dieselbe Prüfung vor der Auslieferung | entfernt |

## 3. Was keine Trennungsregel ist und bleibt (AK3, AK4)

Diese Stellen trennen fachlich nichts zwischen CATquiz und Host. Sie sind Schnittstellen oder Schutzprüfungen und
bleiben unverändert:
- **Schnittstellen zu Subplugins:**
  - `catmodel_resolver` (1.3), dazu die Callbacks `adaptivequiz_catmodel_*_instance_callback()` in `lib.php` (1.2);
  - die Result-Provider-Suche in `result_service::provider()`;
  - die Formular-Erweiterung in `mod_form.php`;
  - `post_delete_attempt_callback`.

  Über diese Wege erreicht der Host den Adapter, solange es ihn gibt.
- **`link_function_registry`:** Die Bindung an den Namensraum `adaptivequizcatmodel_*` bleibt. Der Registry-Vertrag
  wird für Issue #25 (Linkfunktionen) gebraucht.
- **Das neutrale Test-CAT-Modell** `catmodel/testcatmodel` (1.3): Es prüft den Subplugin-Vertrag ohne installiertes
  CATquiz. Es ist keine Regel, sondern Testinfrastruktur.
- **Schutzprüfungen:**
  - Rechte und Kontext;
  - Eigentum von Versuch und Usage;
  - Trennung der ID-Räume (local_catquiz #95/#96);
  - Ergebnisgültigkeit (`resultvalid`, #14);
  - Transaktionen beim Löschen (Adapter #11);
  - Stoppcodes (#20).

## 4. Folgekonzept mod_catquiz (AK6, nicht umgesetzt)

Zu entscheiden und zu beauftragen, getrennt von diesem Issue:
1. **Komponente und Daten:**
   - Neuer Plugin-Name `mod_catquiz` oder Umbenennung mit Upgrade-Pfad. Moodle kennt keine Umbenennung, deshalb:
     neues Modul plus Migrationsskript.
   - Tabellen `adaptivequiz*` → `catquiz*`.
   - Kursmodule, Bewertungsaspekte, Abschlussregeln, Logs, Datensicherung/Wiederherstellung alter Sicherungen.
2. **Abhängigkeit:** `local_catquiz` als `$plugin->dependencies`; Mindestversion je Linie.
3. **Adapter ersetzen:**
   - Item-Auswahl, Ergebnisvertrag, Instanz-Callbacks, Löschpfade und Inventur (Adapter #11) wandern ins Modul.
   - Das Subplugin-Konzept `catmodel` entfällt oder bleibt für Nicht-CATquiz-Modelle (zu entscheiden).
4. **Pfade:** URLs `/mod/adaptivequiz/*`, LTI-Verknüpfungen, Webservices, Ereignisse, Fähigkeiten
   `mod/adaptivequiz:*` → `mod/catquiz:*`.
5. **Linien:** 1.2 (Moodle 4.5) und 1.3 (Moodle 5.x) gleichwertig oder nur 1.3.
6. **Betrieb:** CLARA- und ALiSe-Instanzen: Reihenfolge, Ausfallzeit, Rückweg.

## 5. Abgleich mit Code, CI und Werkzeug (Re-Audit 18.11.2026, Stand 19.11.2026)

Geprüft an den Branch-Ständen `v-3.0` (5bae215 + diese Auslieferung, 2026111703) und `ALiSe-v-1.2.0-legacy` (a05a188 +
diese Auslieferung, 2026111701), mit `grep` über Code, Tests, Workflows und Werkzeug:

| Prüfpunkt | v-3.0 | legacy | Ergebnis |
|---|---|---|---|
| Gate-Schritt „Keine CATquiz-Referenzen im Host“ in den Workflows | `.github/workflows/ci.yml`: nicht vorhanden | `.github/workflows/mod_adaptivequiz-ALiSe-v-1.2.0-legacy-ci.yml`: nicht vorhanden | entfernt (AK2) |
| CATquiz in Workflows | `playwright.yml` Z. 79–147: holt local_catquiz und Adapter für die kombinierte Geschichte; Z. 125 Kommentar „wegen der damaligen Architekturgrenze“ (historisch) | nur `CATQUIZ_REQUIRE_CONCURRENCY` (Z. 124) | Nutzung, kein Verbot |
| Lokaler Preflight | `tools/preflight.sh` Z. 116: „das Architektur-Gate (Host #10 §13) ist seit 16.11.2026 entfernt“ | dasselbe Werkzeug | entfernt |
| Resolver | `classes/local/catmodel/catmodel_resolver.php` Z. 34–60: `is_configured()`, `handler()` – Schnittstelle | Callbacks `adaptivequiz_catmodel_add_instance_callback()` / `_update_instance_callback()` in `lib.php` Z. 185/304 | Schnittstelle bleibt (AK3) |
| Result-Provider | `result_service::provider()` Z. 130 | dieselbe Methode (ohne Resolver, `core_component`) | Schnittstelle bleibt |
| Linkfunktionen | `link_function_registry.php` Z. 80 (`adaptivequizcatmodel_<name>`) | dieselbe Datei | bleibt für #25 |
| Löschpfad | `lib.php` Z. 947 `post_delete_attempt_callback` | `lib.php` Z. 954 | bleibt (Adapter #11) |
| Testinfrastruktur | `catmodel/testcatmodel`, Tests „neutral CAT model“ (`catmodel_resolver_test.php` Z. 40, `item_bank_readiness_test.php` Z. 29) | keine Test-CAT-Modell-Fixture (`question_file_access.php` Z. 45: Test-Naht) | Testinfrastruktur, keine Regel |
| Schutzprüfungen (AK4) | Rechte/Kontext, Eigentum Versuch/Usage, ID-Räume, `resultvalid`, Löschtransaktionen, Stoppcodes | dieselben | unverändert, keine als Gate entfernt |
| Dokumente | `README.md` Z. 216–219 („derzeitiger Stand … transition (issue #24)“), `CHANGELOG.md` (Eintrag 2026111701), dieses Dokument unter `doc/transition-mod_catquiz.md` | `README.md`, `CHANGELOG.md` (Eintrag 2026111700), dieses Dokument als `TRANSITION-mod_catquiz.md` | inhaltsgleich (Datei-Diff leer); nur der Ablageort unterscheidet sich je Linie |

Keine `mod_catquiz`-Implementierung in diesem Issue (AK6, DoD5): weder Komponente noch Tabellen, URLs, Fähigkeiten oder
Abhängigkeiten wurden geändert. Regressionen (DoD2/DoD3): Host-, Adapter- und CATquiz-Suiten beider Linien laufen in
dieser Auslieferung (Abnahmebericht `restarbeiten-2026-11-19.md` in local_catquiz).

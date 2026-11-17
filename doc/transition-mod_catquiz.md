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

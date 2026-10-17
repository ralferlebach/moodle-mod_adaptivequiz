# Attempt-Feedback: Trust-Vertrag (Issue #21)

Stand 16.10.2026, mod_adaptivequiz 2026100911 (v-3.0) / 2026100910 (Legacy).

## Befund

Beide Zweige sind auf dem Code-Pfad verlustfrei: Formular (`PARAM_RAW`, Editor `noclean`) → Datenbank →
Editor beim erneuten Öffnen → zweites Speichern → Ausgabe. Der Test
`tests/local/feedback/feedback_trust_test.php::test_each_stage_keeps_the_trusted_text` prüft jede Stufe
einzeln und benennt im Fehlerfall die Stufe. Mit TinyMCE in der Standardkonfiguration bleiben Markup und
Script erhalten; der Behat-Test prüft das im Browser.

Was Scripts oder Markup entfernt oder zerstört:

| Ursache | Wirkung | Umgang |
|---|---|---|
| Website-Einstellung „Content cleaning everywhere“ (`forceclean`) | Ausgabe bereinigt, Scripts weg | Entscheidung der Administration, wird nicht umgangen; das Formular weist darauf hin |
| TinyMCE-Einstellung `editor_tiny/extended_valid_elements` ohne `script` | Der Editor entfernt Scripts beim Bearbeiten | Konfiguration der Website |
| Format Plaintext/Moodle statt HTML | Der Editor fällt auf ein Textfeld zurück; die Ausgabe (v-3.0) wandelt Zeilenumbrüche in `<br>`, auch im Script | Feedback wird immer als HTML bearbeitet; Upgrade setzt HTML-Feedback auf HTML |
| Legacy: Ausgabe in `<p>` | Block-Markup (Listen, Tabellen) im Absatz, der Browser bricht ihn auf | Ausgabe in einem `div` |
| v-3.0: Feedback aus der Vorversion (`attemptfeedbackenable = -1`) | wird bis zum ersten Speichern als Text ausgegeben (`s()`) | gewollt, unverändert |

## Vertrag

- Aktive Inhalte darf nur speichern, wer `mod/adaptivequiz:trustedfeedback` hat (RISK_XSS; Standard:
  bearbeitende Lehrende und Manager, geklont von `moodle/course:manageactivities`).
- Die Entscheidung fällt **serverseitig** in `adaptivequiz_add_instance()`/`adaptivequiz_update_instance()`
  (`local\feedback\feedback_trust::apply_on_save()`):
  - Mit dem Recht: Text unverändert gespeichert, `attemptfeedbacktrusted = 1`.
  - Ohne das Recht: Text mit `clean_text()` bereinigt (Markup bleibt, Scripts und unsichere Attribute
    nicht), `attemptfeedbacktrusted = 0`.
  - Ausnahme: Ein unveränderter Text bleibt samt Markierung stehen. Ändert eine Person ohne das Recht nur
    andere Einstellungen, verschwindet das Script einer berechtigten Person nicht.
  - Ein Feld `attemptfeedbacktrusted` im Request wird ignoriert.
- Ausgabe (`feedback_trust::format()`): Ein vertrauenswürdiger Text wird nicht nachbereinigt (`noclean`),
  ein anderer schon. Platzhalter (v-3.0) und Datei-URLs werden vorher aufgelöst. „Content cleaning
  everywhere“ gilt immer.
- Wiederherstellung: Die Markierung bleibt nur, wenn die wiederherstellende Person im Zielkurs das Recht
  hat. Sicherungen ohne Markierung gelten dann als vertrauenswürdig, weil sie bisher unbereinigt
  ausgegeben wurden.
- Upgrade: Bestehendes Feedback wird als vertrauenswürdig markiert, weil es bisher unbereinigt
  ausgegeben wurde und nur Bearbeitende es schreiben konnten. Damit ändert sich für vorhandene
  Aktivitäten nichts.
  - v-3.0: Feedback mit Markup wird als HTML markiert.
  - Legacy: Alles wird als HTML markiert, weil die Seite es immer als HTML zeigte.
- Das Feedback eines CAT-Modells, etwa CATquiz, ist nicht dieser Text. Es läuft nicht durch diesen
  Vertrag und wird weder verändert noch doppelt bereinigt.

## Tests

- PHPUnit `tests/local/feedback/feedback_trust_test.php` (9 Fälle): jede Stufe, Person ohne Recht,
  manipulierter Request, unverändertes Speichern, bereinigte Ausgabe, Wiederherstellung, Formularhinweis,
  Editor immer HTML, Upgrade. Zahn-Tests liegen für jede Regel vor.
- Behat `tests/behat/attempt_feedback.feature`: Lehrperson speichert HTML und Script, öffnet erneut,
  speichert, Teilnehmende sehen Markup und das ausgeführte Script. Lehrperson ohne Recht: Markup ja,
  Script nein, Hinweis im Formular.

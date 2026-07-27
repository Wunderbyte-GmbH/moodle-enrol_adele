# [BUG] Kurzzeitige Abmeldung (z.B. Kohorten-Resync) löscht den kompletten Lernfortschritt unwiederbringlich

**Plugin:** enrol_adele 0.2.0 (`observer::user_enrolment_deleted`), Fix betrifft auch local_adele (`enrollment::subscribe_user_to_learning_path`)
**Schweregrad:** Hoch — unwiederbringlicher Datenverlust durch einen Sekunden-Blip

## Beschreibung

Verliert ein Nutzer seine letzte „tragende" Einschreibung (carrying enrolment), löscht
`enrol_adele\observer::user_enrolment_deleted` die `local_adele_path_user`-Zeile(n) des
Nutzers **hart** (`$DB->delete_records(...)`) und purged alle Adele-Enrolments.

Diese Zeile ist aber die **einzige Kopie der gesamten Lernhistorie** des Nutzers:
- Node-Fortschritt und Abschluss-Status,
- manuelle Master-Overrides (von Lehrenden gesetzt),
- `first_enrolled`-Zeitstempel, an denen Timed-Windows hängen.

Der Code-Kommentar akzeptiert den Verlust ausdrücklich („the documented caveats … are
accepted") — für den *endgültigen* Austritt ist das vertretbar. Das Problem: der Observer
kann **endgültigen Austritt nicht von einem kurzzeitigen Blip unterscheiden**. Ein
Kohorten-Resync, ein versehentliches Entfernen mit sofortigem Wieder-Hinzufügen, ein
Bulk-Tool, das Mitgliedschaften neu aufbaut — jede dieser Routine-Operationen feuert
`user_enrolment_deleted` und vernichtet in diesem Moment Monate an Fortschritt.
Bei der Wiedereinschreibung Sekunden später bekommt der Nutzer einen **frischen, leeren
Snapshot** und beginnt bei null.

## Ablauf

```
                НОRMALFALL                          BLIP (Kohorten-Resync u.ä.)

  Student lernt seit Monaten            Resync entfernt Mitglied kurzzeitig
  (Fortschritt, Overrides,                          │
  first_enrolled im Snapshot)                       ▼
              │                     ┌────────────────────────────────────┐
              │                     │ user_enrolment_deleted feuert       │
              │                     │ enrol_adele-Observer:               │
              │                     │  is_user_carried() == false         │
              │                     └────────────────┬───────────────────┘
              │                                      ▼
              │                     ┌────────────────────────────────────┐
              │                     │ DELETE local_adele_path_user        │  ← einzige Kopie!
              │                     │ purge_user + purge_all_host_user    │
              │                     └────────────────┬───────────────────┘
              │                                      │  Sekunden später:
              │                                      ▼
              │                     ┌────────────────────────────────────┐
              │                     │ Resync fügt Mitglied wieder hinzu   │
              │                     │ → Observer subscribed neu           │
              │                     │ → NEUE Zeile, LEERER Snapshot       │
              │                     └────────────────┬───────────────────┘
              ▼                                      ▼
  ┌───────────────────────┐         ┌────────────────────────────────────┐
  │ Fortschritt vorhanden  │         │ Monate Fortschritt WEG:             │
  └───────────────────────┘         │  · Nodes wieder „offen"             │
                                    │  · Overrides der Lehrenden weg      │
                                    │  · Timed-Windows starten neu        │
                                    │ Kein Backup im Plugin, kein Undo.   │
                                    └────────────────────────────────────┘
```

## Reproduktion (automatisiert, reproduce-first)

Beigefügter PHPUnit-Test: `tests/transient_unenrolment_data_loss_test.php`
(2 Tests, phpcs-clean). Aufbau über den ECHTEN Fluss (Generator-Enrolment feuert
mod_adele-Observer → Subscription), dann wird ein Fortschritts-Sentinel in den
Snapshot gepflanzt und der Blip ausgeführt:

1. **`test_losing_carrying_enrolment_archives_instead_of_deleting`**
   Reale Abmeldung (Event feuert) → **Erwartung:** Zeile überlebt archiviert.
   **Ist:** Zeile ist gelöscht. ❌

2. **`test_resync_blip_preserves_progress`**
   Abmeldung + sofortige Wiedereinschreibung → **Erwartung:** derselbe Snapshot
   (gleiche Zeilen-ID, Sentinel intakt) wird reaktiviert.
   **Ist:** neue Zeilen-ID, leerer Snapshot — der Test-Output zeigt es wörtlich:
   `Failed asserting that 367001 is identical to 367000.` ❌

```
$ vendor/bin/phpunit enrol/adele/tests/transient_unenrolment_data_loss_test.php
FF  — Tests: 2, Assertions: 3, Failures: 2.
```

## Lösungsvorschlag

**Archivieren statt löschen — aber zwingend zweiseitig.** Achtung, die naive Variante
(nur `delete_records` → `status='archived'`) **crasht die Wiedereinschreibung**:

- Der Unique-Index auf `local_adele_path_user` ist `(user_id, learning_path_id)` **ohne
  status** — eine archivierte Zeile kollidiert mit dem INSERT der Neu-Subscription.
- Die Race-Recovery in `local_adele\enrollment::subscribe_user_to_learning_path()` sucht
  nach der `dml_exception` nur `status='active'`-Zeilen (`buildsqlqueryuserpath()`),
  findet die archivierte Zeile nicht und **wirft die Exception weiter**.

Daher beide Seiten ändern:

1. **enrol_adele, Observer:** statt `delete_records` →
   `status='archived'` + `timemodified` setzen (Enrolment-Purge bleibt unverändert —
   der Zugriffs-Entzug ist korrekt und gewollt).
2. **local_adele, Subscription:** vor dem INSERT (bzw. in der Race-Recovery) auch nach
   einer archivierten Zeile des Nutzers suchen und diese **reaktivieren**
   (`status='active'`, recompute läuft ohnehin danach) statt neu einzufügen. Damit
   bekommt der Nutzer seinen eigenen Snapshot zurück; der anschließende Recompute
   aktualisiert die Verdicts gegen den aktuellen Moodle-Stand.

Endgültiges Aufräumen (DSGVO/Datenhygiene) kann separat erfolgen — z.B. Löschung
archivierter Zeilen nach N Tagen durch den nächtlichen Task, oder beim Löschen des
Lernpfads (dort archiviert local_adele heute schon statt zu löschen — dasselbe Muster).

## Akzeptanzkriterien

- [ ] Beide beigefügten Tests grün.
- [ ] Verlust der tragenden Einschreibung: Zeile wird archiviert, Enrolments werden
      weiterhin gepurged (Zugriffs-Entzug unverändert).
- [ ] Wiedereinschreibung reaktiviert die archivierte Zeile desselben Nutzers
      (gleiche ID, Snapshot-Inhalt erhalten); kein Unique-Index-Konflikt, keine
      durchgereichte dml_exception.
- [ ] Bestehende Tests bleiben grün (insb. `reconciler_test::test_host_course_removal_rules`,
      das heute das Löschen asserted — muss auf „archiviert" umgestellt werden).
- [ ] Entscheidung dokumentiert, wann archivierte Zeilen endgültig entfernt werden.

# [BUG] reconcile_all() heilt nur Zielkurs-Enrolments — Host-Kurs-Zugänge werden vom Safety-Net nie korrigiert

**Plugin:** enrol_adele 0.2.0 (betrifft auch mod_adele 0.2.0 als einzigen Grant/Revoke-Pfad)
**Schweregrad:** Hoch — dauerhafter, stiller Zugriffs-Drift ohne Selbstheilung

## Beschreibung

Host-Kurs-Zugänge (participantslist Option 2/3, `KIND_HOST`-Instanzen) werden ausschließlich
ereignisgetrieben verwaltet: mod_adele's Observer auf `user_enrolment_created` / `user_enrolment_deleted`
leitet die Berechtigung ab und ruft `reconciler::reconcile_host_user()` auf.

Der nächtliche Scheduled Task (`reconcile_task`, 03:20) — laut eigener Beschreibung das
**"Safety net: the primary trigger is the recompute hook in local_adele"** — führt in
`reconciler::reconcile_all()` jedoch nur durch:

1. `reconcile_user()` pro aktivem Pfadnutzer → **nur KIND_TARGET**,
2. Orphan-/Duplikat-Instanzbereinigung und Rollen-Sync.

**Host-Berechtigungen werden an keiner Stelle neu abgeleitet.** Verpasst das System ein
Enrolment-Ereignis, bleibt der Host-Zugang dauerhaft falsch — es gibt keinen Mechanismus,
der das je korrigiert (außer manuell „Recompute" auf manage.php).

Ereignisse gehen in der Praxis verloren durch:
- Bulk-Operationen mit unterdrückten Events (CSV-Upload-Tools, CLI-Skripte),
- eine Exception im Observer während der Verarbeitung,
- direkte DB-Eingriffe / partieller Restore.

`docs/issues/enrol_adele-issue-reconcile-all-incomplete.md` benennt das Problem bereits;
umgesetzt wurde aber nur der Instanz-Teil (Orphans/Duplikate), nicht der Nutzer-Teil für Hosts.

## Auswirkung (beide Richtungen)

```
                        HOST-KURS-ZUGANG (Option 2/3)

  ┌─────────────────────────┐
  │ Student wird in Start-/  │     Normalfall (funktioniert)
  │ Node-Kurs eingeschrieben │──────────────────────────────┐
  └─────────────────────────┘                               ▼
              │                              ┌──────────────────────────────┐
              │ user_enrolment_created/      │ mod_adele-Observer leitet     │
              │ deleted EVENT                │ Berechtigung ab, ruft         │
              │                              │ reconcile_host_user()         │
              │                              └──────────────┬───────────────┘
              │                                             ▼
              │                              ┌──────────────────────────────┐
              │                              │ Host-Zugang GEWÄHRT/ENTZOGEN  │  ✓ korrekt
              │                              └──────────────────────────────┘
              │
              │  ABER: Event kann verloren gehen
              │   · Bulk-Enrol/Unenrol ohne Events
              │   · Observer-Exception
              │   · direkte DB-Eingriffe / Restore
              ▼
  ┌─────────────────────────┐
  │ EVENT NIE VERARBEITET    │
  └────────────┬────────────┘
               ▼
  ┌───────────────────────────────┐      ┌────────────────────────────────────┐
  │ Realität und Host-Zugang       │      │ Nächtliches reconcile_all()        │
  │ driften auseinander:           │ ───▶ │ („Safety net") läuft — sweept      │
  │  a) berechtigt, kein Zugang    │      │ aber NUR KIND_TARGET.              │
  │  b) unberechtigt, hat Zugang   │      │ Host-Berechtigung: nie geprüft.    │
  └───────────────────────────────┘      └──────────────────┬─────────────────┘
                                                            ▼
                              ┌──────────────────────────────────────────────┐
                              │ DAUERHAFT und STILL falsch:                   │
                              │  a) Student sieht seinen Lernpfad nicht       │
                              │     (Support-Ticket)                          │
                              │  b) Ausgeschiedener behält Host-Zugang        │
                              │     (Zugriffskontroll-/Audit-Problem)         │
                              │ Kein Log, keine Warnung, keine Selbstheilung. │
                              └──────────────────────────────────────────────┘
```

Kontrast, der es zum Bug macht: exakt dieselben Drift-Szenarien werden für **Zielkurse**
vom nächtlichen Sweep geheilt. Nur die Host-Seite fehlt.

## Reproduktion (automatisiert, reproduce-first)

Beigefügter PHPUnit-Test: `tests/reconcile_all_host_sweep_test.php` (2 Tests, phpcs-clean).
Beide bauen den Normalfall real auf (Observer gewährt Host-Zugang — Precondition-Asserts
grün) und simulieren dann das verpasste Ereignis per direktem DB-Eingriff:

1. **`test_sweep_revokes_host_access_after_missed_unenrolment`**
   Tragendes Enrolment (Start-Node-Kurs, manual) wird DB-seitig gelöscht → kein Event →
   `reconcile_all()` → **Erwartung:** Host-Zugang entzogen/suspendiert.
   **Ist:** bleibt dauerhaft ACTIVE. ❌

2. **`test_sweep_restores_host_access_after_external_drift`**
   Adele-Host-Enrolment wird DB-seitig gelöscht, Nutzer weiterhin voll berechtigt →
   `reconcile_all()` → **Erwartung:** Zugang wiederhergestellt.
   **Ist:** wird nie wieder gewährt. ❌

```
$ vendor/bin/phpunit enrol/adele/tests/reconcile_all_host_sweep_test.php
FF  — Tests: 2, Assertions: 6, Failures: 2.
```

## Lösungsvorschlag

Zweiteilig, damit die Ableitungslogik nicht dupliziert wird:

1. **Eine Quelle der Wahrheit für die Host-Berechtigung.** Die Ableitung („ist Nutzer X
   für Host-Kurs Y berechtigt, und in welchem Modus?") aus mod_adele's Observer
   (`is_user_entitled_to_host_via_option()` + most-generous-mode-Gruppierung) in eine
   event-unabhängige, pure Funktion extrahieren — sinnvollerweise in
   `local_adele\enrol_state` (dem Eigentümer von `local_adele_host_courses`):
   `get_host_entitlement(int $lpid, int $hostcourseid, int $userid): array // [bool, mode]`.
   Der mod_adele-Observer ruft künftig dieselbe Funktion auf (Verhalten unverändert;
   `host_enrolment_priority_test` sichert das ab).

2. **Host-Pass im Sweep.** In `reconcile_all()` nach dem Target-Pass: je Lernpfad →
   je Host-Embedding → `reconcile_host_user()` mit frisch abgeleiteter Berechtigung, für
   die **Vereinigung** aus (a) aktiven Pfadnutzern (heilt verpasste Grants) und (b) aktuell
   über Adele-Host-Instanzen eingeschriebenen Nutzern (heilt verpasste Revokes, auch wenn
   die Pfadzeile schon weg ist). Recordset-gestreamt, mit `progress_trace` wie der Rest.

Nebeneffekt-Bonus: Änderungen am `hostenrolmentmode` einer Aktivität würden damit
spätestens nächtlich wirksam (heute erst beim nächsten Enrolment-Event des Nutzers).

## Akzeptanzkriterien

- [ ] Beide beigefügten Tests grün, ohne die Preconditions zu schwächen.
- [ ] Host-Berechtigungslogik existiert genau einmal (Observer und Sweep nutzen dieselbe Funktion).
- [ ] `reconcile_all()` heilt Host-Drift in beide Richtungen (Grant und Revoke).
- [ ] Bestehende Tests (insb. `host_enrolment_priority_test`, `reconciler_test`) bleiben grün.
- [ ] Sweep bleibt auf großen Installationen lauffähig (Streaming/Batching, Trace-Ausgabe).

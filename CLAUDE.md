# CLAUDE.md — FACE Samsung Klima (MDT/KNX)

## Übersicht
Standalone Device (Type 3), Prefix **SAMK**, **kein Parent**. Ein logisches
Klimagerät **pro Raum**. Referenziert die vom MDT SCN-MBGRTU.01 auf KNX (HG 4)
gelegten Gruppenadressen, die zuvor per KNX Configurator als „KNX DPT x"-
Instanzen angelegt wurden.

## Architektur
- **Discovery:** scannt `IPS_GetInstanceList()` nach Modulen „KNX DPT …",
  matcht `Address1==Hauptgruppe && Address2==Mittelgruppe`, mappt `Address3`
  (Subadresse) → Value-VarID. Ergebnis in Attributen `CmdMap` / `StatMap`.
- **Lesen/Status:** `RegisterMessage(VM_UPDATE)` auf alle Status-/Lese-Value-
  Variablen → `MessageSink` → `MirrorStatus()` dekodiert in die logischen Vars.
- **Schreiben:** `RequestAction($knxValueVar, $wert)` (DPT-unabhängig, keine
  KNX_-Funktionen nötig – jede KNX-DPT-Value-Var hat eine Aktion).
- **GA-Schema je Raum:** ungerade Sub = Befehl, gerade = Rückmeldung.
  1/2 Ein-Aus · 3/4 Betriebsart · 5/6 Lüfter · 7/8 Luftrichtung ·
  9/10 Soll · 11/12 Wind-Free · 13 Ist · 14 Komm · 15 Fehler.

## Steuerlogik
- **Erst Betriebsart, dann Ein** (`SetPower`, 250 ms Pause) – FJM-Kältekreis.
- Solltemperatur wird in `[SollMin, SollMax]` geklammert (MDT kann das nicht).
- Wind-Free: Bool ↔ Modbus 0/9. Komm-Status: `(v & 7)==7` → „Verbunden".
- Fehlercode → Klartext (`ErrorText()`), Roh-Isttemp 0xFFFF wird gefiltert.

## Thermostat (Zweipunkt + Totzone)
`Regulate()` – triggert bei: Ist-Quelle-Update, Soll-/Power-Änderung, Timer
(`RegInterval`). Ist-Quelle = `ExtTempVarID` (Wandtaster) oder Samsung-Ist.
Schaltschwellen `Soll ± Deadband/2`, Anti-Takt über `LastToggle`/`MinToggle`.
„RegActive"-Variable schaltbar (Szenen/Zeit/Anwesenheit); `TurnOffWhenInactive`
schaltet die Klima bei Deaktivierung aus.

## FACE-Konventionen (eingehalten)
- Klassenname = module.json-„name" ohne Leerzeichen → `SamsungKlima`
- Timer-Callback in Prefix-Form: `SAMK_Regulate($_IPS["TARGET"])`
- `KR_READY`-Guard in `ApplyChanges()` (Discovery erst wenn Kernel bereit)
- `SetValueIfChanged()` statt blindem `SetValue()`
- Eigener Status via `SetStatus()` (102/201)
- Gemeinsame Profile `SAMK.Mode` / `SAMK.Fan` / `SAMK.Setpoint`

## Dateistruktur
```
Symcon-SamsungKlima/
├── library.json            {B7E4B1A0-…}
├── README.md
├── CLAUDE.md
└── SamsungKlima/
    ├── module.json         Type 3, Prefix SAMK, {C1F2E3D4-…}
    ├── module.php
    └── form.json
```

## Verifiziert
- `php -l` fehlerfrei, alle JSON valide ✅
- Klassenname-Regel erfüllt ✅
- Schreibweg (`RequestAction` auf KNX-Value-Var) an Live-Instanzen bestätigt ✅

## Am echten System zu prüfen (WICHTIG)
1. Nach Configurator-Import: erzeugt er **pro GA eine eigene Instanz** (Sub 1..15)?
   Discovery erwartet getrennte Instanzen für Befehl (ungerade) und Status (gerade).
2. Eltern (4/2) zuerst testen – nur dieser Raum ist am MDT parametriert.
3. Betriebsart-/Lüfter-Codes gegen reale Anlage gegenprüfen.
4. Thermostat: Schaltschwellen + Anti-Takt am Gerät verifizieren.

## Kontext
Ausgangslage, MDT-Parametrierung und GA-Schema: Ordner `Klima`
(BRIEFING-Samsung-KNX.md, MDT-Kanalliste, Klima.csv). Ein/Aus + Luftrichtung
laufen als **1 Byte (V3/DPT 5.005)** – 1-Bit schaltet auf dieser Anlage nicht
zuverlässig (Read-Modify-Write trifft den 0xFFFF-Marker).

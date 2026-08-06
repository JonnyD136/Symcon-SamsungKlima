# CLAUDE.md — Samsung Klima (MDT/KNX)

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
- **Statusabfrage (Pflicht!):** Das MDT sendet Status-Objekte **nicht zyklisch**
  und nicht zuverlässig spontan – Änderungen an der Fernbedienung kämen sonst nie
  an. `PollStatus()` schickt darum je Status-GA ein `KNX_RequestStatus($instanz)`
  (GroupValueRead, 120 ms Abstand), Timer `PollTimer` mit `PollInterval`
  (Default 300 s, 0 = aus). Kickoff nach `Discover()` je Raum versetzt
  (5 + 3·Mittelgruppe s), danach stellt `PollStatus()` selbst auf das Intervall.
  Lese-Flag ist auf allen MDT-Status-Objekten gesetzt (live verifiziert).
- **Schreiben:** `RequestAction($knxValueVar, $wert)` (DPT-unabhängig, keine
  KNX_-Funktionen nötig – jede KNX-DPT-Value-Var hat eine Aktion).
- **GA-Schema je Raum:** ungerade Sub = Befehl, gerade = Rückmeldung.
  1/2 Ein-Aus · 3/4 Betriebsart · 5/6 Lüfter · 7/8 Luftrichtung ·
  9/10 Soll · 11/12 Wind-Free · 13 Ist · 14 Komm · 15 Fehler.

## Steuerlogik
- **Erst Betriebsart, dann Ein** (`SetPower`, 250 ms Pause) – FJM-Kältekreis.
- Solltemperatur wird in `[SollMin, SollMax]` geklammert (MDT kann das nicht).
- Wind-Free: Bool ↔ Modbus 0/9 (Status: alles ≠ 0 gilt als ein).
  Komm-Status: `(v & 7)==7` → „Verbunden".
- **Zwei Soll-Variablen:** `Setpoint` = Wunsch-Soll (bedienbar), `SetpointDevice`
  = Rückmeldung „Solltemperatur Anlage". Im Sollwert-Folgen-Modus liegt der
  Geräte-Soll durch den Bias bewusst darunter – die Status-Rückmeldung darf
  `Setpoint` dann **nicht** überschreiben (sonst läuft die Anzeige nach unten weg).
- Fehlercode → Klartext (`ErrorText()`), Roh-Isttemp 0xFFFF wird gefiltert.

## Thermostat (Zweipunkt + Totzone)
`Regulate()` – triggert bei: Ist-Quelle-Update, Soll-/Power-Änderung, Timer
(`RegInterval`). Ist-Quelle = `ExtTempVarID` (Wandtaster) oder Samsung-Ist.
Schaltschwellen `Soll ± Deadband/2`, Anti-Takt über `LastToggle`/`MinToggle`.
„RegActive"-Variable schaltbar (Szenen/Zeit/Anwesenheit); `TurnOffWhenInactive`
schaltet die Klima bei Deaktivierung aus.

## PV-Überschuss (Build 22)
Sobald mehrere Verbraucher um denselben Überschuss konkurrieren, darf **nur eine
Stelle** das Budget verteilen – sonst greifen alle Räume plus Warmwasser
gleichzeitig nach denselben Watt. Diese Stelle ist der Symcon-**Energie Manager**;
die Module liefern ihm nur noch Schaltpunkte.

- **SamsungKlima:** `PVSource` = 1 → `PVActive()` liest statt Schwelle/Verzögerung
  die eigene Variable **„PV-Freigabe"** (`PVRelease`, mit Aktion – der Manager
  braucht `requireAction`). Mit `PVForceRegulation` übernimmt eine anliegende
  Freigabe die Rolle von „Regelung aktiv": auch ein ungeregelter Raum kühlt vor
  und geht danach wieder aus (`SetPVRelease()`, fallende Flanke → `EnsureOff()`).
  `EffectiveSetpoint()` senkt nur noch ab, nie an (`min($soll, …)`) – bei
  Wunsch-Soll unter `PVMinTemp` hätte das Vorkühlen ihn sonst angehoben.
- **SamsungWaermepumpe:** `DHWPVEnabled` → `UpdateDHWDemand()` (Timer 60 s)
  verodert die Manager-Freigabe `DHWPVRelease` mit zwei Notbremsen:
  Speicher unter `DHWCriticalTemp` (sofort) und Deadline-Fenster
  `DHWDeadline`–`DHWEndTime` unter `DHWDeadlineTemp`. Beide rasten über das
  Attribut `DHWForced` ein, bis `DHWSetpoint − 1 K` erreicht ist – ohne Latch
  bräche die Ladung bei 45,1 °C sofort wieder ab und die WP würde takten.
  Grund der aktuellen Anforderung steht in `DHWDemandReason`.
- **Voraussetzung:** Der feste Warmwasser-Wochenplan an der Anlage bzw. im MDT
  (hier 11:00 EIN / 21:00 AUS) muss aus sein, sonst schaltet er gegen die
  PV-Steuerung.

## Lernen an der Wärmepumpe (Build 28)
Die MIM liefert **keine Leistungsmessung**, nur den Kompressorstrom *einer*
Phase. `I × 230 V` lag am Live-Datensatz um **Faktor 2,3–2,9** zu niedrig
(gemessen ~2900–3600 W statt 1600 W) – Phasenzahl, Leistungsfaktor, Pumpe und
Heizstab fehlen und stehen in keinem Register. Also gelernt statt geraten:

- **Leistung:** Bei jeder Ladung Hauslast-Ø minus Grundlast (**Median** des
  Vorlauffensters – ein niedriges Quantil rechnet der WP fremde Last zu und
  ergab 3862 statt 3558 W). Median über die letzten 10 Ladungen → `Usage` im
  Energie Manager, Verhältnis zur Modul-Schätzung → Korrekturfaktor auf
  `PowerElec`. **Achtung: das ändert den angezeigten COP** (von ~5,6 auf ~2,2 –
  der alte Wert war zu optimistisch).
- **`DHWPowerNow`** führt über das 3-Wegeventil nur den Warmwasser-Anteil.
  Als „aktuelle Nutzung" im Manager Pflicht, sonst zählt im Winter die
  Heizleistung als Warmwasser und der Verbraucher wird grundlos abgeworfen.
- **Bedarfszeiten:** Abfall > 1,5 K/h (Stillstandsverlust gemessen 0,25 K/h)
  = Zapfung, gebucht in den Stundenkorb des Wochentags. Der Tag wird gleitend
  ins Wochenprofil gemischt – mit `alpha = max(0,3; 1/(n+1))`, sonst käme der
  erste beobachtete Tag nur zu 30 % an und das Profil bliebe dauerhaft zu tief.
  `ProfileFor()` zieht wenig beobachtete Wochentage zum Gesamtschnitt.
- **Genutzt wird das zweifach:** Die Deadline wandert nach vorne, wenn typisch
  früher gezapft wird – aber **nur bei Bedarf nach `DHWEarliestDeadline`**. Die
  Morgendusche deckt die Ladung vom Vortag; zöge sie mit, würde mittags bei
  jeder Wolke Netzstrom gezogen statt auf die Sonne zu warten. Und die
  Auslöseschwelle steigt, wenn bis zum nächsten PV-Fenster viel ansteht.
- `LearnFromArchive()` rechnet die Historie mit denselben Regeln durch,
  `DumpLearning()` zeigt Profil, Messreihen und die abgeleitete Deadline.

## Bool-Register aus der MIM (Build 29)
Die erweiterbaren Register liefern gelegentlich den Marker 0xFFFF statt eines
Werts. Bei **Bool-Datenpunkten ist das gefährlich**: 65535 ist „nicht null" und
wird über `toBool()` zu EIN — und bleibt dort, bis ein gültiges Telegramm
kommt. Live passiert am 03.08. mit der Aktivierung der erweiterbaren Register:
Ersatzheizung stand zwei Tage auf EIN, ohne dass im Hauslastgang ein Heizstab
zu sehen war. `SetBoolIfPlausible()` nimmt für `BoosterDHW`/`BackupHeater` nur
noch exakt 0 oder 1 an; `Defrost` und `RemoteLock` hatten schon eigene Filter.
**Regel: kein neuer Bool-Datenpunkt aus einem Modbus-Register ohne Filter.**

## Ein/Aus ist 1 Byte (06.08.2026)
Bei der **Wärmepumpe** senden und erwarten die Ein/Aus-Objekte (Subs 5/6/18/19)
**DPT 5.005**, obwohl die Herstellerliste 1.001 sagt – dieselbe Falle wie auf
der Klimaseite. Eine „KNX DPT 1"-Instanz verwirft die 1-Byte-Nutzlast **still**:
kein Fehler, kein Log, die Variable behält ihren alten Wert.

Das kostete einen ganzen Tag Fehlersuche: `Wärmepumpe Ein/Aus` zeigte AUS,
während die Anlage lief, und darauf aufbauend war jede weitere Schlussfolgerung
falsch. **Im ETS-Gruppenmonitor stand die richtige 1** – der Fehler entsteht
erst in Symcon, ein Bus-Mitschnitt entlastet also nicht.

Erkennungsprobe: zweite, rein lesende DPT-5-Instanz auf dieselbe GA legen und
`KNX_RequestStatus` schicken. Antwortet die eine und die andere nicht, ist der
Typ falsch. Umgekehrt gegenprüfen (Silent/Away 27–30 sind echtes 1 Bit und
antworten nur der DPT-1-Instanz).

**Regel: Statusobjekte, die auf keine Leseanforderung antworten, sind
verdächtig – erst den Datenpunkttyp prüfen, bevor man dem Wert glaubt.**

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

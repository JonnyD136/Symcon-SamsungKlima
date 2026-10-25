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

## Review-Fixes Raumgerät (Build 43, 26.09.2026)
Befunde aus dem Anlagen-Review, Entscheidungen des Hausbesitzers: Handbetrieb
aus der Visu ist erlaubt, Kühl-Soll eigener Wert ≥ 24 °C, bei Abwesenheit aus.

- **Hochlauf:** `ApplyChanges()` bricht vor KR_READY ab. Ohne
  `RegisterMessage(0, IPS_KERNELMESSAGE)` + KR_READY-Zweig im `MessageSink`
  holte niemand `Discover()` nach – nach dem Neustart am 29.08. liefen alle
  5 Räume vier Wochen ohne Poll, ohne Nachrichten, ohne Fensterwächter.
  **Der alte „KR_READY-Guard" allein ist keine Konvention, sondern ein Bug.**
- **Kühl-Soll:** `ExtSetpointVarID` ist der HEIZ-Soll des Wandthermostats.
  `CurrentSoll()` = max(`CoolMin`, Heiz-Soll + `CoolOffset`), `WallSoll()` =
  Heiz-Soll roh (nur noch Warm/Kalt-Anzeige). PV-Vorkühlen bleibt mindestens
  `HEAT_COOL_GAP` (1 K) über dem Heiz-Soll – vorher kühlte die Klima im
  Esszimmer auf 18 °C, während der Stellantrieb heizen wollte.
- **Bias:** Fehler gegen das Regelziel statt gegen den Wand-Soll, und
  `AfterPowerOff()` setzt ihn bei jedem Aus zurück. Vorher startete jeder
  Lauf auf Anschlag (Geräte-Soll immer 18/19 °C).
- **Herkunft des Laufs:** `PowerByAuto` (1 = Regelung/PV hat eingeschaltet).
  PV-Ende schaltet nur eigene Läufe ab; ein Handlauf wird von der PV-Freigabe
  weder übernommen noch abgeschaltet. Fernbedienung-Ein zählt als Hand.
- **Komfort-Soll zurück:** `SollByReg` merkt, dass der Geräte-Soll von der
  Regelung stammt. `RestoreComfortSetpoint()` schreibt beim Aus den Kühl-Soll
  ans Gerät und in `Setpoint`; die Status-Rückmeldung überschreibt `Setpoint`
  währenddessen nicht mehr (Ursache der „Handstarts auf 18 °C").
- **Fenster in jedem Betrieb:** `Regulate()` prüft das Fenster vor allem
  anderen, der `RegTimer` läuft auch ohne Regelung, sobald Fenster oder
  Abwesenheit hinterlegt sind. Präsenz im Büro hebelt den Schutz nicht mehr aus.
- **Abwesenheit** (`AwayVarID`): Flanke „abwesend" schaltet aus, auch Hand.
  Nur auf der Flanke, damit ein von unterwegs gestarteter Handlauf zyklische
  Updates übersteht. Automatik-Läufe starten während Abwesenheit nicht.
- **`MigrateOnce()`** (einmalig): setzt einen auf < `CoolMin` verschmutzten
  Wunsch-Soll auf den Kühl-Soll zurück (auch am Gerät) und übernimmt einen
  gerade laufenden PV-Lauf als Automatik-Lauf.
- Szenarien lokal gegen einen Symcon-Nachbau geprüft (Hochlauf, Handbetrieb,
  Fenster per Timer, PV an/aus, Handlauf vs. PV, Abwesenheit, Fernbedienung,
  Migration). Nicht am Gerät getestet.

## Lernen an der Wärmepumpe (Build 28)
Die MIM liefert **keine Leistungsmessung**, nur den Kompressorstrom *einer*
Phase. `I × 230 V` lag am Live-Datensatz um **Faktor 2,3–2,9** zu niedrig
(gemessen ~2900–3600 W statt 1600 W) – Phasenzahl, Leistungsfaktor, Pumpe und
Heizstab fehlen und stehen in keinem Register. Also gelernt statt geraten:

- **Leistung:** Bei jeder Ladung Hauslast-Ø minus Grundlast (**Median** des
  Vorlauffensters – ein niedriges Quantil rechnet der WP fremde Last zu und
  ergab 3862 statt 3558 W). **Bis Build 43 galt das nur für
  `LearnFromArchive()`** – das Live-Lernen in `TrackCharge()` nahm weiter das
  0,2-Quantil und trieb den Median bis Oktober auf 4711 W (Archiv mit Median:
  3425 W). Der Manager verlangte damit 5,2 kW Überschuss – am 12., 15. und
  20.10. lag die Einspeisung bis zu 80 min über 4 kW, nie darüber, und der
  Speicher wurde jedes Mal um 18:00 aus dem Netz geladen.
  **`LearnFromArchive()` (Build 45)** zählt jetzt wie das Live-Lernen nur
  Messpunkte mit laufendem Verdichter (`COMP_MIN_A`) und Ventil auf Warmwasser,
  und eine Ladung endet erst nach `CHARGE_GAP` ohne Anstieg. Vorher zerfielen
  Ladungen beim Fühlerzittern in Stücke und Anlauf/Nachlauf zählten mit –
  Ergebnis 2945 W / Faktor 1,58 statt real **~4,3 kW / ~2,6** (Okt. 2026).
  Median über die letzten 10 Ladungen → `Usage` im
  Energie Manager, Verhältnis zur Modul-Schätzung → Korrekturfaktor auf
  `PowerElec`. **Achtung: das ändert den angezeigten COP** (von ~5,6 auf ~2,2 –
  der alte Wert war zu optimistisch).
- **Startschwelle nach Jahreszeit (Build 46):** In den Manager geht nicht die
  gelernte Leistung, sondern `gelernt × StartShare()` – Kosinus zwischen
  `DHWStartShareWinter` (21.12., Default 60 %) und `DHWStartShareSummer`
  (21.06., 100 %). Im Herbst kam die Einspeisung kaum über 4,4 kW, und der
  Speicher wurde fast täglich um 18:00 komplett aus dem Netz geladen; mittags
  mit Teil-Netzbezug ist billiger. `ForecastPlan()` rechnet mit derselben
  Schwelle, sonst hielte die Prognose ein Fenster für zu schwach, das der
  Manager längst nutzt. Nachgeführt wird ab 50 W Abweichung und **nie während
  einer Ladung** – `IPS_ApplyChanges()` am Manager setzt dessen Laufzeiten zurück.
- **Wärmste Stunde statt 18:00 (Build 46):** `BestHourDue()` – liegt der
  Speicher unter der Deadline-Temperatur und zeigt die Prognose heute kein
  Fenster, lädt das Modul zur ertragreichsten verbleibenden Stunde (Grund
  „Prognose", rastet ein bis Soll − 1 K). Vorher galt die Stundenregel nur
  unter `DHWCriticalTemp`; bei 46–50 °C fiel der Speicher durch die Lücke und
  wurde im Oktober an 8 von 15 Tagen um 18:00 komplett aus dem Netz geladen.
  Die Prognose (#52218, kWh) trifft die Stunde gut, unterschätzt aber die
  Höhe (25.10.: 2,2 kW erwartet, 4,2 kW erreicht).
- **Prognose-Vertrauen (Build 47):** `ForecastTrust` (Default 80 %) – nur
  dieser Anteil der Prognose zählt für „kommt heute noch ein Fenster?". Die
  beste Stunde bleibt davon unberührt. Hintergrund: Abgleich der Autotune-Daten
  aus dem Oktober 2026 – an klaren Tagen trifft die Prognose mittags, an
  gemischten liegt sie den ganzen Tag 15–30 % zu hoch. Die Morgen-Abweichung
  (25–55 % zu hoch) kam von einem Hindernis im Südosten und ist seit dem
  25.10. im Prognosemodul #52218 eingetragen (`obj_direction` 55 → Schatten bis
  Sonnen-Azimut 145°, Höhe 10 / Abstand 19 → unter 27,8°, `obj_size` 70,
  `obj_effect` 60 – aus den Daten gefittet, nicht vermessen).
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
- **Zapf-Erkennung nie von Takt zu Takt rechnen** (Build 34): Der Fühler löst
  0,1 K auf, und 0,1 K in 60 s ergeben rechnerisch 6 K/h – jeder Quantisierungs-
  schritt wäre eine Zapfung. Am 07.08. war die Donnerstagszeile des Profils
  deshalb über den ganzen Tag mit Phantomwerten gefüllt. Der Bezugspunkt bleibt
  jetzt stehen, bis `DRAW_MIN_DELTA` (0,5 K) gefallen sind oder `DRAW_WINDOW`
  (600 s) vergangen ist. **Beim Anlernen aus dem Archiv fiel das nicht auf** –
  dort liegen die Messpunkte weiter auseinander.
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
- `KR_READY`-Guard in `ApplyChanges()` **plus** `IPS_KERNELMESSAGE`-Handler, der `Discover()` nachholt (Build 43)
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

# Samsung Wärmepumpe EHS Mono — KNX-Gruppenadressen

Anlage: **Samsung AE120BXYDGG/EU** → MIM-B19N #2 (Modbus Slave 2, PDU-Basis 50,
NASA-Adresse 00) → MDT SCN-MBGRTU.01 (Kanäle 49–90) → KNX.

Stand: ETS-Export `Heizung 1.csv` vom 31.07.2026. **Dieser Export ist die
Referenz für das Modul**, nicht die Kanalliste-PDF: dort waren die Status-GAs
kompakt durchnummeriert (Kanal 74 → 5/2/31), tatsächlich angelegt wurde die
ursprüngliche Kanalreihenfolge mit Lücken.

Hauptgruppe **5**, Mittelgruppe **2** (`Heizung/Wärmepumpe`).
HG 5 MG 0 = Raumthermostate EG, MG 1 = OG — die gehören nicht zu diesem Modul.

## Nicht angelegt

| Kanal | Datenpunkt | Grund |
|---|---|---|
| 71–73 | Lüftung Ein/Aus, Betriebsart, Lüfterstufe | ERV-Register, kein Lüftungsgerät an der Anlage |
| 78 | Umwälzpumpe PWM | nicht angelegt |

Die Subadressen **31–36 und 41 sind dadurch frei**. Das Modul kommt damit
zurecht (Discovery über die Subadresse, fehlende Objekte werden übersprungen);
wer sie später nachrüstet, muss die Nummern aus dieser Tabelle verwenden, sonst
verschiebt sich alles.

## Gruppenadressen

Sub = Subadresse in 5/2/x. „B" = Befehl (schreibbar), „S" = Status.

| Sub | Rolle | Datenpunkt | Kanal · PDU | KNX DPT | Modul-Ident |
|---|---|---|---|---|---|
| 0 | S | Modul-Fehlerstatus | 49 · 0 | 5.005 | ModuleError |
| 1 | S | AG Sammelfehlercode | 50 · 1 | 7 | OutErrorCode |
| 2 | S | Abtaubetrieb | 51 · 2 | 7.001 | Defrost |
| 3 | S | Kommunikationsstatus | 52 · 50 | 5.005 | Comm |
| 4 | S | Gerätetyp | 53 · 51 | 7 | DeviceType |
| 5 | B | Ein / Aus | 54 · 52 | 1.001 | Power |
| 6 | S | Ein / Aus | 54 · 52 | 1.001 | Power |
| 7 | B | Betriebsart | 55 · 53 | 5.005 | Mode |
| 8 | S | Betriebsart | 55 · 53 | 5.005 | Mode |
| 9 | B | Raum-Soll | 56 · 58 | 9.001 | RoomSetpoint |
| 10 | S | Raum-Soll | 56 · 58 | 9.001 | RoomSetpoint |
| 11 | S | Raum-Ist | 57 · 59 | 9.001 | RoomTemp |
| 12 | S | Fehlercode | 58 · 63 | 7 | ErrorCode |
| 13 | S | Rücklauf-Ist | 59 · 65 | 9.001 | ReturnTemp |
| 14 | S | Vorlauf-Ist | 60 · 66 | 9.001 | FlowTemp |
| 15 | S | MCC Vorlauf-Ist | 61 · 67 | 9.001 | MCCFlowTemp |
| 16 | B | Vorlauf-Soll | 62 · 68 | 9.001 | FlowSetpoint |
| 17 | S | Vorlauf-Soll | 62 · 68 | 9.001 | FlowSetpoint |
| 18 | B | Warmwasser Ein / Aus | 63 · 72 | 1.001 | DHWPower |
| 19 | S | Warmwasser Ein / Aus | 63 · 72 | 1.001 | DHWPower |
| 20 | B | Warmwasser-Modus | 64 · 73 | 5.005 | DHWMode |
| 21 | S | Warmwasser-Modus | 64 · 73 | 5.005 | DHWMode |
| 22 | B | Warmwasser-Soll | 65 · 74 | 9.001 | DHWSetpoint |
| 23 | S | Warmwasser-Soll | 65 · 74 | 9.001 | DHWSetpoint |
| 24 | S | Warmwasser-Ist | 66 · 75 | 9.001 | DHWTemp |
| 25 | S | Fehlercode Slave | 67 · 76 | 7 | SlaveErrorCode |
| 26 | S | Fernbedienungssperre | 68 · 64 | 7 | RemoteLock |
| 27 | B | Silent-Betrieb | 69 · 78 | 1.001 | Silent |
| 28 | S | Silent-Betrieb | 69 · 78 | 1.001 | Silent |
| 29 | B | Away-Funktion | 70 · 79 | 1.001 | Away |
| 30 | S | Away-Funktion | 70 · 79 | 1.001 | Away |
| 37 | S | BW-Zusatzheizung | 74 · 82 | 5.005 | BoosterDHW |
| 38 | S | Ersatzheizung | 75 · 83 | 5.005 | BackupHeater |
| 39 | S | Wasserdurchfluss | 76 · 84 | 9.001 | WaterFlow |
| 40 | S | 3-Wegeventil | 77 · 85 | 5.005 | ThreeWayValve |
| 42 | S | Vorlauf-Ziel (Regler) | 79 · 87 | 9.001 | FlowTarget |
| 43 | S | Heizkurven-Ziel | 80 · 88 | 9.001 | CurveTarget |
| 44 | S | Wassertemperatur Zone 1 | 81 · 89 | 9.001 | WaterZone1 |
| 45 | S | Mischventil-Temperatur | 82 · 90 | 9.001 | MixValve |
| 46 | S | Außentemperatur | 83 · 4 | 9.001 | OutdoorTemp |
| 47 | S | Kompressorfrequenz | 84 · 5 | 7 | CompFreq |
| 48 | S | Stromaufnahme Kompressor | 85 · 6 | 9.001 | CompCurrent |
| 49 | S | Heißgastemperatur | 86 · 7 | 9.001 | HotGas |
| 50 | S | Hochdruck | 87 · 8 | 9.001 | HighPressure |
| 51 | S | Niederdruck | 88 · 9 | 9.001 | LowPressure |
| 52 | S | Betriebszustand Außengerät | 89 · 10 | 7 | OutdoorState |
| 53 | S | 4-Wege-Ventil | 90 · 11 | 5.005 | FourWayValve |

## Wertebedeutungen

| Datenpunkt | Werte |
|---|---|
| Betriebsart | 0 Auto · 1 Kühlen · 4 Heizen (am Bedienteil gegenprüfen) |
| Warmwasser-Modus | 0 Eco · 1 Standard · 2 Power · 3 Force |
| Kommunikationsstatus | Bits: 1 vorhanden · 2 Typ erkannt · 4 ready · 8 Komm-Fehler. **7 = betriebsbereit**, 255 ungültig |
| Modul-Fehlerstatus | Bits: 1 Adressfehler · 2 Kommunikation R1/R2 · 4 Tracking. 0 = ok |
| Abtaubetrieb | 0 **oder 255** = Abtauen aus |
| Betriebszustand AG | 0 Stop · 2 Normalbetrieb · 5 Abtauen |
| 3-Wegeventil | 0 Heizkreis · 1 Speicher |
| Fernbedienungssperre | 0 frei · **25443** gesperrt |
| Fehlercodes | 0 = kein Fehler |
| Vorlauf-Soll | Heizen 15–65 °C |
| Warmwasser-Soll | 30–70 °C |

**Samsung-Marker 0xFFFF (65535) = „kein Wert".** Nach der MDT-Skalierung ×0,1
kommt der als **−0,1** an. Ein globaler Filter darauf wäre falsch, weil −0,1 °C
bei der Außentemperatur ein gültiger Wert ist — das Modul prüft darum je
Datenpunkt ein eigenes Plausibilitätsfenster (`RANGE` in `module.php`).

## Abgeleitete Werte

Die Anlage hat keinen Wärmemengenzähler. Das Modul rechnet:

- **Spreizung** = Vorlauf-Ist − Rücklauf-Ist
- **Wärmeleistung** [W] = Durchfluss [l/min] × Spreizung [K] × 69,67
  (4,18 kJ/(kg·K) · 1 kg/l · 1000 / 60 s). Nur bei positiver Spreizung — beim
  Abtauen und im Kühlbetrieb wird sie negativ und würde den COP verfälschen.
- **Elektrische Leistung** [W] = Stromaufnahme Kompressor [A] × Netzspannung
  + pauschaler Zuschlag. Ohne Umwälzpumpe, Heizstab und Leistungsfaktor.
- **COP** = Wärmeleistung / elektrische Leistung, erst ab 100 W elektrisch.

Beides sind Richtwerte, keine Messwerte. Wer den echten COP will, braucht einen
Wärmemengenzähler und einen Zähler auf der Einspeisung der Wärmepumpe.

## Erweiterbare Register — aktiviert am 03.08.2026

Maßgeblich ist **`Neue Modbus Funktionen.xlsx`**, nicht das Blatt „Erweiterbare
Register aktivieren" der Kanalliste-PDF. Zwei Dinge stehen dort anders, und
beide sind entscheidend:

1. **Die Aktivierung ist EIN FC16-Blockschreibvorgang über 8 Register**, nicht
   acht einzelne Writes. Einzeln geschriebene Slots legen die Adresse zwar an
   (kein „Ausnahme 2" mehr), werden aber **nie mit Daten gefüllt**.
2. **Die Zuordnung Slot ↔ Set-ID ist fest.** Die PDF behauptete „Reihenfolge
   frei wählbar" — das ist falsch, jeder Slot akzeptiert nur seine eigene ID.

Die Werte laufen nach dem Blockwrite über etwa **zwei Minuten** nacheinander
ein, nicht sofort. Die Registrierung **übersteht einen Spannungsausfall** der
MIM (nachgemessen). Nach einem Neustart braucht sie rund eine Minute, bis der
Kommunikationsstatus von 3 auf 7 (ready) geht — vorher stehen auch die
Standardregister auf 0, das ist kein Fehler.

```
FC16 @6000, Quantity 8  <-  33336 33284 33303 33341 33286 33288 33290 33304
FC16 @7000, Quantity 8  <-  16670 17111 17110 16519 16492 17129 17137 16487
```

| Slot | Set ID | PDU | Signal | Einheit |
|---|---|---|---|---|
| 6000 | 33336 | 4 | Kompressorfrequenz | Hz |
| 6001 | 33284 | 5 | Außentemperatur | °C ×10 |
| 6002 | 33303 | 6 | Stromaufnahme Kompressor 1 | A ×10 |
| 6003 | 33341 | 7 | Lüfterdrehzahl Außengerät | rpm — **liefert 0, unbrauchbar** |
| 6004 | 33286 | 8 | Hochdruck | kgf/cm² ×10 |
| 6005 | 33288 | 9 | Niederdruck | kgf/cm² ×10 |
| 6006 | 33290 | 10 | Heißgastemperatur | °C ×10 |
| 6007 | 33304 | 11 | T Cond out | °C ×10 — **unplausibel (11 °C bei 63 °C Vorlauf)** |
| 7000 | 16670 | 82 | Zone 2 Ein/Aus | R/W |
| 7001 | 17111 | 83 | Sollwert Zone 2 | °C ×10, R/W |
| 7002 | 17110 | 84 | Raumtemperatur Zone 2 | °C ×10, R/W |
| 7003 | 16519 | 85 | BW-Zusatzheizung | 0/1 |
| 7004 | 16492 | 86 | Ersatzheizung | 0/1 |
| 7005 | 17129 | 87 | **Wasserdurchfluss** | l/min ×10 |
| 7006 | 17137 | 88 | FR Control | 0/1, R/W |
| 7007 | 16487 | 89 | **3-Wegeventil** | 0 = Heizung, 1 = Brauchwasser |

Weitere NASA-IDs für die Slots 6003/6007 stehen unten im xlsx, u. a.
`0x821A` Saugtemperatur, `0x8235` Fehlercode, `0x8229` Main EEV1,
`0x829F`/`0x82A0` Sättigungstemperaturen.

**Live verifiziert** bei Warmwasserladung: Außentemperatur 33,3 °C ·
Kompressor 53 Hz · 8,3 A · Hochdruck 40,7 · Niederdruck 10,2 · Heißgas 94,4 °C ·
Durchfluss 23,2 l/min · 3-Wegeventil = 1 · Vorlauf 63,0 / Rücklauf 56,4 °C
→ Spreizung 6,6 K, **10,67 kW thermisch, 1,91 kW elektrisch, COP 5,59**.

## Diese GAs haben keine Quelle

Für folgende Signale gibt es **kein aktivierbares Register**. Die Kanalliste
hatte sie vorgesehen, die MIM kennt sie nicht:

Betriebszustand Außengerät (5/2/52) · 4-Wege-Ventil (5/2/53) ·
Umwälzpumpe PWM · Vorlauf-Zieltemperatur (5/2/42) · Heizkurven-Ziel (5/2/43) ·
Wassertemperatur Zone 1 (5/2/44) · Mischventil (5/2/45).

**Konsequenz:** Die MDT-Kanäle 74–90 müssen in der ETS auf die Tabelle oben
umgezogen werden, und `STAT_SUB` in `module.php` muss danach nachgezogen
werden — insbesondere `Running`, das derzeit aus dem nicht existierenden
Betriebszustand Außengerät kommt. Für Spreizung, Wärmeleistung und COP braucht
das Modul nur **Vorlauf, Rücklauf, Durchfluss und Kompressorstrom**; alles vier
ist jetzt verfügbar.

## Adress-Landkarte der MIM

Ein Blockread, der eine nicht existierende Adresse berührt, wirft **Ausnahme 2
für den ganzen Block**. Existierend: **PDU 4–11 · 50–53 · 60–90**.
Nicht existierend: 0–3 · 54–59 · 91 und höher.

## Offen / zu verifizieren

- Betriebsart-Codes am Bedienteil gegenlesen (0/1/4 stammt aus der FJM-Analogie).
- Vorlauf-Soll als PV-Hebel: ob er die Heizkurve übersteuert, muss gemessen werden.
- Die zwölf Wasserregister (PDU 65–76) stammen aus zwei Community-Quellen, die
  sich decken, sind aber nicht von Samsung bestätigt. Messblatt im PDF.

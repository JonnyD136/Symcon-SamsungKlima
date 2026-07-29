# MDT-Kanalliste – Samsung Wärmepumpe (EHS Mono, MIM-B19N)

Vorlage zum Parametrieren des **MDT SCN-MBGRTU.01** für die WP. Das Symcon-Modul
`SamsungWaermepumpe` findet die Gruppenadressen automatisch über **Haupt-/Mittelgruppe
+ Sub-Adresse** (Schema unten). Wähle eine freie Mittelgruppe (Vorschlag: HG 4 / MG 6 –
die FJM-Räume liegen auf MG 0–5).

Konvention wie bei der FJM: **ungerade Sub = Befehl, gerade Sub = Rückmeldung**
(ab Sub 13 nur Rückmeldung). Ein/Aus als **1 Byte (V3 / DPT 5.005)**, nicht 1 Bit
(1-Bit-RMW trifft den Samsung-„kein Wert"-Marker – siehe FJM).

| Sub | Funktion | DPT | Richtung | Modbus-Register (MIM-B19N) | Bemerkung |
|----:|----------|-----|----------|----------------------------|-----------|
| 1  | Power (WP ein/aus)        | 5.005 | Befehl      | _(aus MIM-B19N-Doc eintragen)_ | 1 Byte, 0/1 |
| 2  | Power Status              | 5.005 | Rückmeldung | | |
| 3  | Betriebsart (Auto/Heizen/Kühlen) | 5 | Befehl | | 0=Auto,1=Kühlen,4=Heizen |
| 4  | Betriebsart Status        | 5 | Rückmeldung | | |
| 5  | Heiz-Soll (Vorlauf o. Raum) | 9 | Befehl    | | je nach WL-/Raum-Regelung |
| 6  | Heiz-Soll Status          | 9 | Rückmeldung | | |
| 7  | Raum-/Vorlauf-Ist         | 9 | Rückmeldung | | |
| 9  | Warmwasser ein/aus        | 5.005 | Befehl    | | |
| 10 | Warmwasser Status         | 5.005 | Rückmeldung | | |
| 11 | Warmwasser-Soll           | 9 | Befehl      | | |
| 12 | Warmwasser-Soll Status    | 9 | Rückmeldung | | |
| 13 | Warmwasser-/Speicher-Ist  | 9 | Rückmeldung | | |
| 14 | Außentemperatur           | 9 | Rückmeldung | | |
| 15 | Vorlauftemperatur         | 9 | Rückmeldung | | |
| 16 | Rücklauftemperatur        | 9 | Rückmeldung | | |
| 17 | In Betrieb / Verdichter   | 1 | Rückmeldung | | |
| 18 | Abtaubetrieb              | 1 | Rückmeldung | | |
| 19 | Elektrische Leistung (W)  | 14 | Rückmeldung | | Verbrauch |
| 20 | Fehlercode                | 7 | Rückmeldung | | 0 = OK |
| 21 | Komm-Status               | 5 | Rückmeldung | | ≠0 = verbunden |
| 22 | Wärmeleistung (W)         | 14 | Rückmeldung | | für COP |
| 23 | Energie elektrisch (kWh)  | 13/14 | Rückmeldung | | Zähler |
| 24 | Energie Wärme (kWh)       | 13/14 | Rückmeldung | | Zähler |

Nicht alle Register müssen belegt werden – das Modul nutzt nur die vorhandenen Subs
(fehlende Funktionen werden übersprungen). Mindestens Power (1/2) und ein Ist-Wert
sind sinnvoll, damit das Modul „Bereit" meldet.

**Register-Adressen:** Die konkreten Modbus-Register/Skalierungen des MIM-B19N (NASA)
gehören in die Spalte „Modbus-Register". Quelle: MIM-B19N-Registerdokumentation bzw.
Samsung-NASA-Liste. COP wird vom Modul aus Wärme-/El.-Leistung berechnet (kein eigenes
Register nötig).

# Samsung Klima & Wärmepumpe (MDT/KNX)

Logisches Samsung-FJM-Klimagerät **je Raum** für IP-Symcon. Bündelt die vom
MDT SCN-MBGRTU.01 auf KNX (Hauptgruppe 4) gelegten Gruppenadressen zu einem
Gerät mit sauberen Profilen, Steuerlogik und optionaler Thermostat-Regelung.

Die Library enthält zwei Geräte-Module:

- **SamsungKlima** – FJM-Split je Raum (Kühlen, Thermostat-Regelung).
- **SamsungWaermepumpe** – Samsung EHS Mono (MIM-B19N): Heizen, Warmwasser,
  Zeitsteuerung (Wochenpläne) und Energie/Diagnose (el. Leistung, Wärmeleistung,
  COP, Verbrauch). Anbindung ebenfalls über MDT→KNX; GA-Schema siehe
  `SamsungWaermepumpe/Kanalliste-WP.md`. **Gerüst – noch nicht an realer WP getestet.**

## Voraussetzung

Die KNX-Gruppenadressen müssen in Symcon existieren (per **KNX Configurator**
importiert → je GA eine „KNX DPT x"-Instanz unter dem KNX Gateway). Das Modul
findet die zu einem Raum gehörenden Instanzen **automatisch** über Haupt- und
Mittelgruppe – es legt selbst keine KNX-Instanzen an.

## Konfiguration

| Feld | Bedeutung |
|---|---|
| Hauptgruppe | i. d. R. `4` |
| Mittelgruppe | Raum = IG-Adresse + 1 (Kind 2 = 1, Eltern = 2, …) |
| Sollwertgrenzen | Klammern die Solltemperatur (Gateway kann das nicht) |
| Thermostat | Optionale Zweipunkt-Regelung mit Totzone (siehe unten) |

Danach **„Gruppenadressen neu einlesen"**. Der Instanz-Status zeigt, ob alle
Objekte gefunden wurden.

## Variablen

Ein/Aus · Betriebsart (Auto/Kühlen/Entfeuchten/Lüften/Heizen) · Solltemperatur ·
Isttemperatur · Lüfterstufe · Luftrichtung (Swing) · Wind-Free · Verbunden ·
Fehler (Klartext) · optional „Regelung aktiv".

## Thermostat-Regelung

Zweipunktregler mit Totzone auf Basis einer frei wählbaren Ist-Quelle
(z. B. KNX-Wandtaster; Fallback = Samsung-Isttemperatur):

- **Kühlen**: Ein wenn `Ist ≥ Soll + Totzone/2`, Aus wenn `Ist ≤ Soll − Totzone/2`
- **Heizen**: invers
- „Regelung aktiv" ist eine schaltbare Variable → über Szenen/Zeitpläne/
  Anwesenheit steuerbar. Option „bei Inaktiv ausschalten" für Abwesenheit.
- Anti-Takt-Sperre (Mindest-Umschaltzeit) + zyklischer Sicherheits-Check.

## Steuerlogik

- **Erst Betriebsart, dann Einschalten** (FJM teilt sich einen Kältekreis).
- Schreiben über `RequestAction` auf die Value-Variable der KNX-DPT-Instanz –
  DPT-unabhängig.
- Wind-Free wird als Schalter geführt (Modbus 0 = aus, 9 = ein).

Siehe auch die Kanalliste/Doku im Ordner `Klima` (MDT-Parametrierung, GA-Schema).

<?php

declare(strict_types=1);

/**
 * Samsung Wärmepumpe EHS Mono (AE120BXYDGG/EU) → MIM-B19N #2 (Slave 2) →
 * MDT SCN-MBGRTU.01 → KNX. Standalone Device (Type 3), Prefix SAMW, kein Parent.
 *
 * Bündelt die vom MDT auf KNX gelegten Gruppenadressen zu einem logischen Gerät.
 * Discovery per Haupt-/Mittelgruppe + Subadresse (wie SamsungKlima).
 *
 * GA-Schema laut ETS-Export (Heizung 1.csv, HG 5 / MG 2). Die Subadressen folgen
 * der MDT-Kanalnummerierung 49–90 und haben deshalb LÜCKEN: 31–36 (Lüftung, ERV,
 * an dieser Anlage nicht vorhanden) und 41 (Umwälzpumpe PWM) sind nicht angelegt.
 *
 *   0  Modul-Fehlerstatus       5.005      26 Fernbedienungssperre     7
 *   1  AG Sammelfehlercode      7          27/28 Silent-Betrieb        1.001
 *   2  Abtaubetrieb             7.001      29/30 Away-Funktion         1.001
 *   3  Kommunikationsstatus     5.005      37 BW-Zusatzheizung         5.005
 *   4  Gerätetyp                7          38 Ersatzheizung            5.005
 *   5/6   Ein / Aus             1.001      39 Wasserdurchfluss         9.001
 *   7/8   Betriebsart           5.005      40 3-Wegeventil             5.005
 *   9/10  Raum-Soll             9.001      42 Vorlauf-Ziel             9.001
 *   11 Raum-Ist                 9.001      43 Heizkurven-Ziel          9.001
 *   12 Fehlercode               7          44 Wasser Zone 1            9.001
 *   13 Rücklauf-Ist             9.001      45 Mischventil              9.001
 *   14 Vorlauf-Ist              9.001      46 Außentemperatur          9.001
 *   15 MCC Vorlauf-Ist          9.001      47 Kompressorfrequenz       7
 *   16/17 Vorlauf-Soll          9.001      48 Stromaufnahme Kompr.     9.001
 *   18/19 Warmwasser Ein/Aus    1.001      49 Heißgas                  9.001
 *   20/21 Warmwasser-Modus      5.005      50 Hochdruck                9.001
 *   22/23 Warmwasser-Soll       9.001      51 Niederdruck              9.001
 *   24 Warmwasser-Ist           9.001      52 Betriebszustand AG       7
 *   25 Fehlercode Slave         7          53 4-Wege-Ventil            5.005
 *
 * Abgeleitete Werte: Spreizung = Vorlauf − Rücklauf, Wärmeleistung aus
 * Durchfluss × Spreizung, elektrische Leistung aus der Kompressor-Stromaufnahme,
 * daraus der COP. Alles ohne Wärmemengenzähler, also Schätzwerte.
 *
 * Zeitsteuerung: zwei vom Modul verwaltete Wochenpläne (Heizzeiten,
 * Warmwasserzeiten), die SAMW_ApplyHeatState()/SAMW_ApplyDHWState() rufen.
 *
 * @author  FACE GmbH
 * @version 0.3
 */
class SamsungWaermepumpe extends IPSModule
{
    private const VM_UPDATE    = 10603;
    private const STATUS_OK    = 102;
    private const STATUS_NO_GA = 201;

    // Pause zwischen zwei Leseanforderungen (ms). Das MDT sendet Status nicht
    // zwingend zyklisch – siehe PollStatus().
    private const POLL_GAP = 120;

    /** Schreibbare Objekte: Ident → Subadresse (Befehls-GA). */
    private const CMD_SUB = [
        'Power'        => 5,
        'Mode'         => 7,
        'RoomSetpoint' => 9,
        'FlowSetpoint' => 16,
        'DHWPower'     => 18,
        'DHWMode'      => 20,
        'DHWSetpoint'  => 22,
        'Silent'       => 27,
        'Away'         => 29,
    ];

    /** Rückmeldungen: Ident → Subadresse (Status-GA). */
    private const STAT_SUB = [
        'ModuleError'    => 0,
        'OutErrorCode'   => 1,
        'Defrost'        => 2,
        'Comm'           => 3,
        'DeviceType'     => 4,
        'Power'          => 6,
        'Mode'           => 8,
        'RoomSetpoint'   => 10,
        'RoomTemp'       => 11,
        'ErrorCode'      => 12,
        'ReturnTemp'     => 13,
        'FlowTemp'       => 14,
        'MCCFlowTemp'    => 15,
        'FlowSetpoint'   => 17,
        'DHWPower'       => 19,
        'DHWMode'        => 21,
        'DHWSetpoint'    => 23,
        'DHWTemp'        => 24,
        'SlaveErrorCode' => 25,
        'RemoteLock'     => 26,
        'Silent'         => 28,
        'Away'           => 30,
        'BoosterDHW'     => 37,
        'BackupHeater'   => 38,
        'WaterFlow'      => 39,
        'ThreeWayValve'  => 40,
        'OutdoorTemp'    => 46,
        'CompFreq'       => 47,
        'CompCurrent'    => 48,
        'HotGas'         => 49,
        'HighPressure'   => 50,
        'LowPressure'    => 51,
        // Sub 42–45 und 52/53 fehlen absichtlich: Vorlauf-Ziel, Heizkurven-Ziel,
        // Wassertemperatur Zone 1, Mischventil, Betriebszustand Außengerät und
        // 4-Wege-Ventil haben in der MIM-B19N KEIN Register. Die Kanalliste hatte
        // sie vorgesehen, die Anlage kennt sie nicht.
    ];

    /**
     * Plausibilitätsfenster je Messwert. Samsung liefert für „kein Wert" 0xFFFF;
     * nach der MDT-Skalierung ×0,1 erscheint das als −0,1. Ein globaler Filter auf
     * −0,1 wäre falsch, weil das bei der Außentemperatur ein gültiger Wert ist –
     * darum je Datenpunkt ein eigenes Fenster.
     */
    private const RANGE = [
        'RoomTemp'     => [  5.0,  40.0],
        'ReturnTemp'   => [  5.0,  75.0],
        'FlowTemp'     => [  5.0,  75.0],
        'MCCFlowTemp'  => [  5.0,  75.0],
        'DHWTemp'      => [  5.0,  85.0],
        'OutdoorTemp'  => [-40.0,  50.0],
        'HotGas'       => [  0.0, 140.0],
        'HighPressure' => [  0.0,  60.0],
        'LowPressure'  => [  0.0,  60.0],
        'CompCurrent'  => [  0.0,  40.0],
        'WaterFlow'    => [  0.0, 100.0],
        'RoomSetpoint' => [  5.0,  40.0],
        'FlowSetpoint' => [ 15.0,  65.0],
        'DHWSetpoint'  => [ 30.0,  70.0],
    ];

    private const MODE_HEAT = 4;

    // Samsung-Sonderwerte
    private const NO_VALUE    = 65535;   // 0xFFFF
    private const LOCK_LOCKED = 25443;   // 0x6363 auf der Fernbedienungssperre

    // Betriebsart-Erkennung (Variable "Läuft für")
    private const OP_STANDBY = 0;
    private const OP_HEAT    = 1;
    private const OP_DHW     = 2;

    // Wochenplan-Aktions-IDs
    private const HEAT_COMFORT = 1;
    private const HEAT_ECO     = 2;
    private const HEAT_OFF     = 3;
    private const DHW_ON       = 1;
    private const DHW_OFF      = 2;

    // Regel-Ziel der Zeitsteuerung
    private const SCHED_ROOM = 0;
    private const SCHED_FLOW = 1;

    // Wasser: 4,18 kJ/(kg·K) · 1 kg/l · 1000 / 60 s  →  W je (l/min · K)
    private const W_PER_LPM_K = 69.67;

    // Unterhalb dieser Spreizung wird keine Wärmeleistung ausgewiesen – im
    // Stillstand liegen Vor- und Rücklauffühler im selben Wasser und weichen
    // trotzdem um ein bis zwei Zehntel voneinander ab.
    private const SPREAD_MIN = 0.5;

    // Takt der PV-Warmwasserprüfung (s) und Abstand zum Sollwert, ab dem eine
    // erzwungene Ladung als erledigt gilt (der letzte Zehntelgrad braucht lange).
    private const DHW_CHECK      = 60;
    // Wie lange auf die Bestätigung der Anlage gewartet wird und wie oft ein
    // unbestätigter Befehl überhaupt wiederholt wird.
    private const DHW_SETTLE     = 300;
    private const DHW_MAX_TRIES  = 3;
    private const DHW_DONE_DELTA = 1.0;

    // ── Lernen ──
    // Der Stillstandsverlust des Speichers liegt gemessen bei rund 0,25 K/h.
    // Alles ab 1,5 K/h ist mit Abstand darüber und damit eine echte Zapfung.
    private const DRAW_RATE        = 1.5;    // K/h
    private const DRAW_SIGNIFICANT = 3.0;    // K je Stunde, ab wann ein Korb als Bedarf zählt
    private const PROFILE_ALPHA    = 0.30;   // Gewicht eines neuen Tages im Wochenprofil
    private const PV_HOUR          = 10;     // ab dieser Stunde kann die PV wieder laden
    private const SAMPLES_MAX      = 10;     // Ladungen im Gedächtnis (Median daraus)
    private const LOAD_RING        = 15;     // Hauslast-Messpunkte für die Grundlast
    private const CHARGE_MIN_N     = 5;      // Mindest-Messpunkte für eine gültige Ladung
    private const CHARGE_MIN_K     = 3.0;    // Mindest-Temperaturhub für eine gültige Ladung

    private const WD = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    // ═══════════════════════════════════════════════════════════════
    //  LIFECYCLE
    // ═══════════════════════════════════════════════════════════════

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Hauptgruppe', 5);
        $this->RegisterPropertyInteger('Mittelgruppe', 2);

        $this->RegisterPropertyInteger('RoomSollMin', 15);
        $this->RegisterPropertyInteger('RoomSollMax', 30);
        $this->RegisterPropertyInteger('FlowSollMin', 15);
        $this->RegisterPropertyInteger('FlowSollMax', 65);
        $this->RegisterPropertyInteger('DHWSollMin', 30);
        $this->RegisterPropertyInteger('DHWSollMax', 70);

        // Elektrische Leistung wird aus der Kompressor-Stromaufnahme geschätzt
        $this->RegisterPropertyFloat('Mains', 230.0);
        $this->RegisterPropertyFloat('StandbyWatt', 0.0);

        // Aktive Statusabfrage (das MDT sendet Status nicht zwingend zyklisch)
        $this->RegisterPropertyInteger('PollInterval', 300);

        // Zeitsteuerung
        $this->RegisterPropertyBoolean('UseHeatSchedule', false);
        $this->RegisterPropertyBoolean('UseDHWSchedule', false);
        $this->RegisterPropertyInteger('ScheduleTarget', self::SCHED_ROOM);
        $this->RegisterPropertyFloat('HeatComfort', 22.0);
        $this->RegisterPropertyFloat('HeatEco', 19.0);

        // PV-geführte Warmwasserbereitung: Der Energiemanager sagt über die
        // Variable „Warmwasser PV-Freigabe", wann Überschuss da ist. Damit an
        // trüben Tagen trotzdem warmes Wasser da ist, kommen zwei Notbremsen
        // dazu, die den Manager überstimmen (er kann sie nicht ausschalten,
        // weil er nur seine eigene Freigabe kennt).
        $this->RegisterPropertyBoolean('DHWPVEnabled', false);
        $this->RegisterPropertyFloat('DHWCriticalTemp', 45.0);
        $this->RegisterPropertyString('DHWDeadline', '18:00');
        $this->RegisterPropertyFloat('DHWDeadlineTemp', 50.0);
        $this->RegisterPropertyString('DHWEndTime', '21:00');

        // Lernen: Ladeleistung aus dem Hauslast-Sprung, Bedarfszeiten aus dem
        // Temperaturverlauf des Speichers.
        $this->RegisterPropertyBoolean('DHWLearn', false);
        $this->RegisterPropertyInteger('HouseLoadVarID', 0);
        $this->RegisterPropertyInteger('DHWLeadTime', 60);
        $this->RegisterPropertyString('DHWEarliestDeadline', '12:00');
        $this->RegisterPropertyFloat('DHWMinUsable', 38.0);
        $this->RegisterPropertyInteger('EnergyManagerID', 0);
        $this->RegisterPropertyString('EMConsumerName', '');

        $this->RegisterAttributeString('CmdMap', '{}');
        $this->RegisterAttributeString('StatMap', '{}');
        $this->RegisterAttributeString('WatchedVars', '[]');
        $this->RegisterAttributeInteger('HeatEventID', 0);
        $this->RegisterAttributeInteger('DHWEventID', 0);
        $this->RegisterAttributeInteger('DHWForced', 0);      // Notbremse hält bis Speicher voll
        $this->RegisterAttributeString('DHWForcedReason', '');
        $this->RegisterAttributeString('LoadRing', '[]');     // letzte Hauslast-Messpunkte
        $this->RegisterAttributeString('Charge', '{}');       // laufende Vermessung
        $this->RegisterAttributeString('PowerSamples', '[]'); // Ø-Leistung je Ladung
        $this->RegisterAttributeString('FactorSamples', '[]');// Korrekturfaktor je Ladung
        $this->RegisterAttributeString('DrawToday', '{}');    // Zapfungen des laufenden Tages
        $this->RegisterAttributeString('Profile', '[]');      // Wochenprofil [7][24] in K
        $this->RegisterAttributeString('DayCounts', '[]');    // gesehene Tage je Wochentag
        $this->RegisterAttributeFloat('LastTemp', 0.0);
        $this->RegisterAttributeInteger('LastTempTs', 0);
        $this->RegisterAttributeFloat('LastPushed', 0.0);
        $this->RegisterAttributeInteger('DHWWish', -1);       // zuletzt gesendeter Schaltbefehl
        $this->RegisterAttributeInteger('DHWWishSince', 0);
        $this->RegisterAttributeInteger('DHWTries', 0);

        $this->RegisterTimer('PollTimer', 0, 'SAMW_PollStatus($_IPS["TARGET"]);');
        $this->RegisterTimer('DHWTimer', 0,
            'SAMW_LearnStep($_IPS["TARGET"]); SAMW_UpdateDHWDemand($_IPS["TARGET"]);');

        $this->RegisterVariables();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterVariables();
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        $laeuft = $this->ReadPropertyBoolean('DHWPVEnabled') || $this->ReadPropertyBoolean('DHWLearn');
        $this->SetTimerInterval('DHWTimer', $laeuft ? self::DHW_CHECK * 1000 : 0);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Discover();
            $this->SetupSchedules();
            $this->LearnStep();
            $this->UpdateDHWDemand();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->Discover();
            $this->SetupSchedules();
            return;
        }
        if ($Message !== self::VM_UPDATE) {
            return;
        }
        $statMap = json_decode($this->ReadAttributeString('StatMap'), true) ?: [];
        if (isset($statMap[(string) $SenderID])) {
            $this->MirrorStatus($statMap[(string) $SenderID], GetValue($SenderID));
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  DISCOVERY
    // ═══════════════════════════════════════════════════════════════

    public function Discover()
    {
        $hg = $this->ReadPropertyInteger('Hauptgruppe');
        $mg = $this->ReadPropertyInteger('Mittelgruppe');

        $bySub = [];
        foreach (IPS_GetInstanceList() as $iid) {
            $mn = @IPS_GetModule(IPS_GetInstance($iid)['ModuleInfo']['ModuleID'])['ModuleName'];
            if (strpos((string) $mn, 'KNX DPT') !== 0) {
                continue;
            }
            $cfg = json_decode(IPS_GetConfiguration($iid), true);
            if (!isset($cfg['Address1'], $cfg['Address2'], $cfg['Address3'])) {
                continue;
            }
            if ((int) $cfg['Address1'] !== $hg || (int) $cfg['Address2'] !== $mg) {
                continue;
            }
            $vid = @IPS_GetObjectIDByIdent('Value', $iid);
            if ($vid) {
                $bySub[(int) $cfg['Address3']] = $vid;
            }
        }

        $cmdMap = [];
        foreach (self::CMD_SUB as $ident => $sub) {
            if (isset($bySub[$sub])) {
                $cmdMap[$ident] = $bySub[$sub];
            }
        }
        $statMap = [];
        foreach (self::STAT_SUB as $ident => $sub) {
            if (isset($bySub[$sub])) {
                $statMap[(string) $bySub[$sub]] = $ident;
            }
        }
        $this->WriteAttributeString('CmdMap', json_encode($cmdMap));
        $this->WriteAttributeString('StatMap', json_encode($statMap));

        // Alte Registrierungen lösen, neue setzen
        foreach (json_decode($this->ReadAttributeString('WatchedVars'), true) ?: [] as $old) {
            @$this->UnregisterMessage((int) $old, self::VM_UPDATE);
        }
        $watched = array_map('intval', array_keys($statMap));
        foreach ($watched as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
        }
        $this->WriteAttributeString('WatchedVars', json_encode(array_values($watched)));

        // Ohne Ein/Aus-Status und Vorlauftemperatur ist die Anlage nicht brauchbar
        $ok = isset($bySub[self::STAT_SUB['Power']]) && isset($bySub[self::STAT_SUB['FlowTemp']]);
        $this->SetStatus($ok ? self::STATUS_OK : self::STATUS_NO_GA);

        if ($ok) {
            foreach ($statMap as $vid => $ident) {
                // Nie empfangene GAs (VariableUpdated=0) nicht seeden
                if ((int) IPS_GetVariable((int) $vid)['VariableUpdated'] <= 0) {
                    continue;
                }
                $this->MirrorStatus($ident, GetValue((int) $vid));
            }
            $this->UpdateDerived();
        }

        $this->SendDebug('Discover', sprintf('%d Befehls-GAs, %d Status-GAs gefunden (HG %d/MG %d)',
            count($cmdMap), count($statMap), $hg, $mg), 0);

        // Erste Abfrage kurz nach dem Start, danach stellt PollStatus() auf das Intervall
        $this->UpdatePollTimer($this->ReadPropertyInteger('PollInterval') > 0 ? 12 : 0);
    }

    // ═══════════════════════════════════════════════════════════════
    //  STATUSABFRAGE
    // ═══════════════════════════════════════════════════════════════

    /** Abfrage-Timer setzen. $override > 0 = einmaliger Kickoff in x Sekunden. */
    private function UpdatePollTimer(int $override = 0): void
    {
        $iv = $override > 0 ? $override : $this->ReadPropertyInteger('PollInterval');
        $this->SetTimerInterval('PollTimer', $iv > 0 ? $iv * 1000 : 0);
    }

    /**
     * Aktive Statusabfrage: je Status-GA eine KNX-Leseanforderung (GroupValueRead).
     * Das MDT sendet seine Status-Objekte nur, wenn das in der ETS ausdrücklich
     * parametriert wurde – sonst bliebe die Anzeige auf dem letzten eigenen Befehl
     * stehen. Am FJM-Teil derselben Hardware ist das Lese-Flag auf allen
     * Status-Objekten gesetzt und antwortet zuverlässig.
     */
    public function PollStatus()
    {
        $statMap = json_decode($this->ReadAttributeString('StatMap'), true) ?: [];
        $n = 0;
        foreach (array_keys($statMap) as $vid) {
            $vid = (int) $vid;
            if (!IPS_VariableExists($vid)) {
                continue;
            }
            $iid = IPS_GetParent($vid);
            if ($iid <= 0 || !IPS_InstanceExists($iid)) {
                continue;
            }
            try {
                KNX_RequestStatus($iid);
                $n++;
            } catch (Throwable $e) {
                $this->SendDebug('PollStatus', 'Lesen fehlgeschlagen (#' . $iid . '): ' . $e->getMessage(), 0);
            }
            IPS_Sleep(self::POLL_GAP);
        }
        $this->SendDebug('PollStatus', $n . ' Leseanforderungen gesendet', 0);
        $this->UpdatePollTimer();
    }

    // ═══════════════════════════════════════════════════════════════
    //  AKTIONEN
    // ═══════════════════════════════════════════════════════════════

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Power':
                $this->WriteKNX('Power', (bool) $Value);
                $this->SetValueIfChanged('Power', (bool) $Value);
                break;
            case 'Mode':
                $this->WriteKNX('Mode', (int) $Value);
                $this->SetValueIfChanged('Mode', (int) $Value);
                break;
            case 'RoomSetpoint':
                $v = $this->Clamp((float) $Value, (float) $this->ReadPropertyInteger('RoomSollMin'),
                                                  (float) $this->ReadPropertyInteger('RoomSollMax'));
                $this->WriteKNX('RoomSetpoint', $v);
                $this->SetValueIfChanged('RoomSetpoint', $v);
                break;
            case 'FlowSetpoint':
                $v = $this->Clamp((float) $Value, (float) $this->ReadPropertyInteger('FlowSollMin'),
                                                  (float) $this->ReadPropertyInteger('FlowSollMax'));
                $this->WriteKNX('FlowSetpoint', $v);
                $this->SetValueIfChanged('FlowSetpoint', $v);
                break;
            case 'DHWPower':
                $this->WriteKNX('DHWPower', (bool) $Value);
                $this->SetValueIfChanged('DHWPower', (bool) $Value);
                break;
            case 'DHWPVRelease':
                // Nur merken – die Entscheidung fällt in UpdateDHWDemand(), weil
                // dort auch die beiden Notbremsen mitgewertet werden.
                $this->SetValueIfChanged('DHWPVRelease', (bool) $Value);
                $this->UpdateDHWDemand();
                break;
            case 'DHWMode':
                $this->WriteKNX('DHWMode', (int) $Value);
                $this->SetValueIfChanged('DHWMode', (int) $Value);
                break;
            case 'DHWSetpoint':
                $v = $this->Clamp((float) $Value, (float) $this->ReadPropertyInteger('DHWSollMin'),
                                                  (float) $this->ReadPropertyInteger('DHWSollMax'));
                $this->WriteKNX('DHWSetpoint', $v);
                $this->SetValueIfChanged('DHWSetpoint', $v);
                break;
            case 'Silent':
                $this->WriteKNX('Silent', (bool) $Value);
                $this->SetValueIfChanged('Silent', (bool) $Value);
                break;
            case 'Away':
                $this->WriteKNX('Away', (bool) $Value);
                $this->SetValueIfChanged('Away', (bool) $Value);
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  ZEITSTEUERUNG (Wochenpläne)
    // ═══════════════════════════════════════════════════════════════

    /** Wochenplan-Aktion Heizen: 1=Komfort, 2=Absenkung, 3=Aus. */
    public function ApplyHeatState(int $State)
    {
        if ($State === self::HEAT_OFF) {
            $this->RequestAction('Power', false);
            return;
        }
        $soll = ($State === self::HEAT_ECO)
            ? $this->ReadPropertyFloat('HeatEco')
            : $this->ReadPropertyFloat('HeatComfort');

        $this->RequestAction('Power', true);
        $this->RequestAction(
            $this->ReadPropertyInteger('ScheduleTarget') === self::SCHED_FLOW ? 'FlowSetpoint' : 'RoomSetpoint',
            $soll
        );
    }

    /** Wochenplan-Aktion Warmwasser: 1=Ein, 2=Aus. */
    public function ApplyDHWState(int $State)
    {
        $this->RequestAction('DHWPower', $State === self::DHW_ON);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PV-GEFÜHRTE WARMWASSERBEREITUNG
    // ═══════════════════════════════════════════════════════════════

    /**
     * Entscheidet zyklisch, ob die Warmwasserbereitung freigegeben wird.
     *
     * Regel: Normalfall ist der PV-Überschuss – die Freigabe kommt vom
     * Energiemanager, der das Budget auf alle Verbraucher verteilt. Zwei
     * Notbremsen überstimmen ihn, damit an trüben Tagen niemand kalt duscht:
     *   1. Speicher unter der kritischen Temperatur → sofort laden.
     *   2. Ab der Deadline (z. B. 18:00) noch zu kalt → nachladen.
     * Beide rasten ein, bis der Speicher wirklich voll ist; sonst würde die
     * Ladung bei 45,1 °C sofort wieder abbrechen und die Wärmepumpe takten.
     */
    public function UpdateDHWDemand()
    {
        if (!$this->ReadPropertyBoolean('DHWPVEnabled')) {
            return;
        }

        $ist = (float) $this->GetValueSafe('DHWTemp', 0.0);
        if ($ist <= 0.0) {
            return;   // noch kein Messwert vom Bus – nichts erzwingen
        }
        $soll   = (float) $this->GetValueSafe('DHWSetpoint', 50.0);
        $fertig = $soll - self::DHW_DONE_DELTA;

        $forced = $this->ReadAttributeInteger('DHWForced') === 1;
        $grund  = $this->ReadAttributeString('DHWForcedReason');
        $imFenster = $this->InDeadlineWindow();
        [$dlMin, $dlTemp] = $this->EffectiveDeadline();

        if ($forced && ($ist >= $fertig || ($grund === 'Deadline' && !$imFenster))) {
            $forced = false;
            $grund  = '';
        }
        if (!$forced) {
            if ($ist <= $this->ReadPropertyFloat('DHWCriticalTemp')) {
                $forced = true;
                $grund  = 'Kritisch';
            } elseif ($imFenster && $ist < $dlTemp) {
                $forced = true;
                $grund  = 'Deadline';
            }
        }
        $this->WriteAttributeInteger('DHWForced', $forced ? 1 : 0);
        $this->WriteAttributeString('DHWForcedReason', $grund);

        $pv   = (bool) $this->GetValueSafe('DHWPVRelease', false);
        $want = $forced || $pv;

        if ($forced) {
            $text = $grund === 'Kritisch'
                ? sprintf('Notheizung – Speicher unter %.0f °C', $this->ReadPropertyFloat('DHWCriticalTemp'))
                : sprintf('Nachheizen ab %02d:%02d (unter %.1f °C)', (int) ($dlMin / 60), $dlMin % 60, $dlTemp);
        } elseif ($pv) {
            $text = 'PV-Überschuss';
        } else {
            $text = 'Wartet auf PV-Überschuss';
        }
        $this->SetValueIfChanged('DHWDemandReason', $text);

        $this->DriveDHWPower($want, $text, $ist);
    }

    /**
     * Schaltbefehl absetzen und die Bestätigung überwachen.
     *
     * Der eigene Wunsch wird im Attribut geführt, NICHT an der Statusvariable
     * abgelesen: Die spiegelt die Rückmeldung der Anlage, und wenn die dem
     * Befehl nicht folgt, würde ein Vergleich gegen sie im Sekundentakt neu
     * schreiben. Genau das ist am 06.08. passiert – über 700 Telegramme an
     * einem Tag, ohne dass je Warmwasser gemacht wurde.
     *
     * Bestätigt die Anlage nicht, wird der Befehl höchstens DHW_MAX_TRIES mal
     * im Abstand von DHW_SETTLE wiederholt und danach als Störung gemeldet.
     * Eine Anlage, die dreimal nicht reagiert, reagiert auch beim vierten Mal
     * nicht – das ist ein Fall für den Menschen, nicht für mehr Telegramme.
     */
    private function DriveDHWPower(bool $want, string $text, float $ist): void
    {
        $wunsch = $this->ReadAttributeInteger('DHWWish');       // -1 = noch keiner
        $seit   = $this->ReadAttributeInteger('DHWWishSince');
        $tries  = $this->ReadAttributeInteger('DHWTries');
        $neu    = $want ? 1 : 0;

        if ($wunsch !== $neu) {
            $this->WriteAttributeInteger('DHWWish', $neu);
            $this->WriteAttributeInteger('DHWWishSince', time());
            $this->WriteAttributeInteger('DHWTries', 1);
            $this->RequestAction('DHWPower', $want);
            $this->LogMessage('Warmwasser ' . ($want ? 'EIN' : 'AUS') . ' – ' . $text
                . sprintf(' (Ist %.1f °C)', $ist), KL_MESSAGE);
            return;
        }

        if ((bool) $this->GetValueSafe('DHWPower', false) === $want) {
            $this->WriteAttributeInteger('DHWTries', 0);        // bestätigt
            return;
        }
        if ($tries <= 0 || $tries >= self::DHW_MAX_TRIES || (time() - $seit) < self::DHW_SETTLE) {
            if ($tries >= self::DHW_MAX_TRIES) {
                $this->SetValueIfChanged('DHWDemandReason',
                    $text . ' – Anlage bestätigt nicht, Ansteuerung angehalten');
            }
            return;
        }
        $this->WriteAttributeInteger('DHWTries', $tries + 1);
        $this->WriteAttributeInteger('DHWWishSince', time());
        $this->RequestAction('DHWPower', $want);
        $this->LogMessage(sprintf('Warmwasser %s erneut gesendet (Versuch %d von %d) – '
            . 'die Anlage meldet weiterhin %s', $want ? 'EIN' : 'AUS', $tries + 1,
            self::DHW_MAX_TRIES, $want ? 'AUS' : 'EIN'), KL_WARNING);
    }

    /** Liegt die aktuelle Uhrzeit im Nachheiz-Fenster [Deadline, Ende)? */
    private function InDeadlineWindow(): bool
    {
        [$von, ] = $this->EffectiveDeadline();
        $bis = $this->ParseTime($this->ReadPropertyString('DHWEndTime'));
        if ($von === null || $bis === null) {
            return false;
        }
        $jetzt = (int) date('H') * 60 + (int) date('i');
        // Fenster über Mitternacht (z. B. 22:00–05:00) mitnehmen
        return $von <= $bis ? ($jetzt >= $von && $jetzt < $bis)
                            : ($jetzt >= $von || $jetzt < $bis);
    }

    /**
     * Nachheiz-Deadline und Auslöse-Temperatur – mit gelerntem Bedarfsprofil
     * beides angepasst, ohne Profil exakt die konfigurierten Werte.
     *
     * Die Deadline kann nur nach VORNE wandern: Wer typisch um 16:00 badet, dem
     * hilft ein Nachheizen ab 18:00 nichts. Nach hinten darf sie nie, sonst
     * würde ein Lernfehler den Speicher kalt laufen lassen. Vormittags wird
     * grundsätzlich nicht erzwungen (DHWEarliestDeadline) – da lädt die PV.
     *
     * Die Temperatur ist nur die Auslöseschwelle; geheizt wird danach ohnehin
     * bis zum Warmwasser-Sollwert. Steht viel Zapfung bevor, wird die Schwelle
     * angehoben, damit abends wirklich nachgeladen wird statt knapp daneben.
     *
     * @return array{0:?int,1:float}  Deadline in Minuten seit Mitternacht, Schwelle in °C
     */
    private function EffectiveDeadline(): array
    {
        $min  = $this->ParseTime($this->ReadPropertyString('DHWDeadline'));
        $temp = $this->ReadPropertyFloat('DHWDeadlineTemp');
        if (!$this->ReadPropertyBoolean('DHWLearn') || $min === null) {
            return [$min, $temp];
        }

        // Nur Bedarf, der NACH der Vormittagsgrenze liegt, darf die Deadline
        // vorziehen. Die morgendliche Dusche deckt die Ladung vom Vortag ab –
        // zöge sie die Deadline mit, würde mittags bei jeder Wolke aus dem Netz
        // geheizt, statt auf die Sonne zu warten.
        $frueh = $this->ParseTime($this->ReadPropertyString('DHWEarliestDeadline')) ?? 12 * 60;
        $lead  = $this->ReadPropertyInteger('DHWLeadTime');
        $relevant = $this->FirstDemandAfter($frueh + $lead);
        if ($relevant !== null) {
            $min = max($frueh, min($min, $relevant - $lead));
        }

        $erwartet = $this->ExpectedDrawAfter($min);
        if ($erwartet > 0) {
            $soll = (float) $this->GetValueSafe('DHWSetpoint', 50.0);
            $temp = max($temp, min($soll - 0.5, $this->ReadPropertyFloat('DHWMinUsable') + $erwartet));
        }
        return [$min, round($temp, 1)];
    }

    // ═══════════════════════════════════════════════════════════════
    //  LERNEN: LADELEISTUNG UND BEDARFSZEITEN
    // ═══════════════════════════════════════════════════════════════

    /**
     * Einmal je Timer-Takt: Hauslast mitschreiben, Zapfungen erkennen, eine
     * laufende Ladung vermessen und den Tageswechsel ins Profil einrechnen.
     */
    public function LearnStep()
    {
        if (!$this->ReadPropertyBoolean('DHWLearn')) {
            return;
        }
        $this->SampleHouseLoad();
        $ist = (float) $this->GetValueSafe('DHWTemp', 0.0);
        if ($ist > 0) {
            $this->DetectDraw($ist);
            $this->TrackCharge($ist);
        }
        $this->RollOverDay();
        $this->PublishLearned();
    }

    /**
     * Anlauf aus dem Archiv: rechnet die vorhandene Historie einmal durch,
     * damit das Modul nicht wochenlang mit Startwerten arbeitet. Nutzt dieselben
     * Regeln wie der Live-Betrieb, nur auf geloggten Werten statt auf dem Takt.
     * Voraussetzung: Speichertemperatur, Kompressorstrom und Hauslast sind
     * archiviert. Ergebnis ersetzt das bisher Gelernte.
     */
    public function LearnFromArchive(int $Tage = 30)
    {
        $ac = @IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID)[0] ?? 0;
        $temp = @$this->GetIDForIdent('DHWTemp');
        $last = $this->ReadPropertyInteger('HouseLoadVarID');
        if (!$ac || !$temp || !AC_GetLoggingStatus($ac, $temp)) {
            echo "Kein Archiv oder Warmwasser-Ist nicht archiviert.\n";
            return;
        }
        $von = time() - max(1, $Tage) * 86400;
        $t = array_reverse(AC_GetLoggedValues($ac, $temp, $von, time(), 0));
        if (count($t) < 20) {
            echo "Zu wenig Historie (" . count($t) . " Werte).\n";
            return;
        }
        $this->ResetLearning();

        // ── Zapfungen → Wochenprofil ──
        $tage = [];                                  // 'Y-m-d' => [24] Kelvin
        for ($i = 1; $i < count($t); $i++) {
            $std = ($t[$i]['TimeStamp'] - $t[$i - 1]['TimeStamp']) / 3600;
            if ($std <= 0 || $std > 1) {
                continue;
            }
            $ab = $t[$i - 1]['Value'] - $t[$i]['Value'];
            if ($ab / $std < self::DRAW_RATE) {
                continue;
            }
            $tag = date('Y-m-d', $t[$i]['TimeStamp']);
            $tage[$tag] = $tage[$tag] ?? array_fill(0, 24, 0.0);
            $tage[$tag][(int) date('G', $t[$i]['TimeStamp'])] += round($ab, 2);
        }
        $prof = array_fill(0, 7, array_fill(0, 24, 0.0));
        $zaehler = array_fill(0, 7, 0);
        ksort($tage);
        foreach ($tage as $tag => $k) {
            $wd = (int) date('w', strtotime($tag));
            $alpha = max(self::PROFILE_ALPHA, 1.0 / ($zaehler[$wd] + 1));
            for ($h = 0; $h < 24; $h++) {
                $prof[$wd][$h] = round($prof[$wd][$h] * (1 - $alpha) + $k[$h] * $alpha, 2);
            }
            $zaehler[$wd] = min(20, $zaehler[$wd] + 1);
        }
        $this->WriteAttributeString('Profile', json_encode($prof));
        $this->WriteAttributeString('DayCounts', json_encode($zaehler));

        // ── Ladungen → Leistung und Korrekturfaktor ──
        $amp   = @$this->GetIDForIdent('CompCurrent');
        $mains = $this->ReadPropertyFloat('Mains');
        $ladungen = 0;
        foreach ($this->ArchiveCharges($t) as [$a, $b, $hub]) {
            if ($hub < self::CHARGE_MIN_K || !$last || !AC_GetLoggingStatus($ac, $last)) {
                continue;
            }
            $grund = $this->Quantile(array_column(AC_GetLoggedValues($ac, $last, $a - 2400, $a - 120, 0), 'Value'), 0.5);
            $inn   = array_column(AC_GetLoggedValues($ac, $last, $a, $b, 0), 'Value');
            if ($grund === null || count($inn) < self::CHARGE_MIN_N) {
                continue;
            }
            $leistung = array_sum($inn) / count($inn) - $grund;
            if ($leistung < 300 || $leistung > 12000) {
                continue;
            }
            $this->PushSample('PowerSamples', round($leistung));
            $ladungen++;

            if ($amp && AC_GetLoggingStatus($ac, $amp)) {
                $a2 = array_column(AC_GetLoggedValues($ac, $amp, $a, $b, 0), 'Value');
                $a2 = array_values(array_filter($a2, fn ($x) => $x > 0.2));
                if ($a2) {
                    $roh = array_sum($a2) / count($a2) * $mains;
                    $f = $roh > 300 ? $leistung / $roh : 0;
                    if ($f >= 0.5 && $f <= 5.0) {
                        $this->PushSample('FactorSamples', round($f, 3));
                    }
                }
            }
        }
        $this->PublishLearned(true);
        printf("Archiv ausgewertet: %d Tage mit Zapfungen, %d vermessene Ladungen.\n"
             . "Gelernt: %.0f W Ladeleistung, Korrekturfaktor %.2f\n%s\n",
            count($tage), $ladungen, $this->MedianOf('PowerSamples') ?? 0,
            $this->PowerCorrection(), GetValue($this->GetIDForIdent('DHWReadyBy')));
    }

    /** Ladephasen aus dem Temperaturverlauf: zusammenhängender Anstieg. */
    private function ArchiveCharges(array $t): array
    {
        $out = []; $von = null; $start = null;
        for ($i = 1; $i < count($t); $i++) {
            $steigt = $t[$i]['Value'] > $t[$i - 1]['Value'] + 0.05;
            if ($steigt && $von === null) {
                $von = $t[$i - 1]['TimeStamp'];
                $start = $t[$i - 1]['Value'];
            } elseif (!$steigt && $von !== null) {
                if ($t[$i - 1]['TimeStamp'] - $von > 300) {
                    $out[] = [$von, $t[$i - 1]['TimeStamp'], $t[$i - 1]['Value'] - $start];
                }
                $von = null;
            }
        }
        return $out;
    }

    private function Quantile(array $w, float $q): ?float
    {
        if (count($w) < 3) {
            return null;
        }
        sort($w);
        return (float) $w[(int) (count($w) * $q)];
    }

    /** Was steckt gerade im Gedächtnis? Für Kontrolle und Fehlersuche. */
    public function DumpLearning()
    {
        $prof = $this->Profile();
        $zahl = $this->DayCounts();
        echo "Zapfprofil (K je Stunde, roh je Wochentag)\n     ";
        for ($h = 0; $h < 24; $h++) {
            printf('%5d', $h);
        }
        echo "\n";
        for ($d = 1; $d <= 7; $d++) {
            $wd = $d % 7;
            printf('%-3s %d', self::WD[$wd], (int) ($zahl[$wd] ?? 0));
            for ($h = 0; $h < 24; $h++) {
                printf('%5s', $prof[$wd][$h] > 0.05 ? number_format($prof[$wd][$h], 1) : '·');
            }
            echo "\n";
        }
        $wdHeute = (int) date('w');
        $eff = $this->ProfileFor($wdHeute);
        printf("\nHeute %s wirksam (mit Rückfall auf den Gesamtschnitt):\n     ", self::WD[$wdHeute]);
        for ($h = 0; $h < 24; $h++) {
            printf('%5s', $eff[$h] > 0.05 ? number_format($eff[$h], 1) : '·');
        }
        [$dl, $temp] = $this->EffectiveDeadline();
        printf("\n\nLadeleistungen (W) : %s  → Median %s\n", $this->ReadAttributeString('PowerSamples'),
            $this->MedianOf('PowerSamples') ?? '–');
        printf("Korrekturfaktoren  : %s  → Median %.2f\n", $this->ReadAttributeString('FactorSamples'),
            $this->PowerCorrection());
        printf("Erster Bedarf      : %s\n", $this->ReadyByMinutes() === null ? '–'
            : sprintf('%02d:00', (int) ($this->ReadyByMinutes() / 60)));
        printf("Erwartete Zapfung ab %02d:%02d bis morgen %02d:00 : %.1f K\n",
            (int) ($dl / 60), $dl % 60, self::PV_HOUR, $this->ExpectedDrawAfter($dl));
        printf("Wirksame Deadline  : %02d:%02d bei unter %.1f °C\n", (int) ($dl / 60), $dl % 60, $temp);
    }

    /** Alles Gelernte verwerfen und von vorn anfangen. */
    public function ResetLearning()
    {
        foreach (['LoadRing'=>'[]', 'Charge'=>'{}', 'PowerSamples'=>'[]',
                  'FactorSamples'=>'[]', 'DrawToday'=>'{}', 'Profile'=>'[]', 'DayCounts'=>'[]'] as $a => $leer) {
            $this->WriteAttributeString($a, $leer);
        }
        $this->WriteAttributeFloat('LastTemp', 0.0);
        $this->WriteAttributeInteger('LastTempTs', 0);
        $this->SetValueIfChanged('DHWPowerLearned', 0.0);
        $this->SetValueIfChanged('PowerCorrLearned', 1.0);
        $this->SetValueIfChanged('DHWReadyBy', 'noch keine Daten');
        $this->SetValueIfChanged('DHWProfileText', '');
        $this->LogMessage('Gelernte Warmwasser-Daten verworfen', KL_MESSAGE);
    }

    /** Ringpuffer der Hauslast – liefert die Grundlast vor einer Ladung. */
    private function SampleHouseLoad(): void
    {
        $vid = $this->ReadPropertyInteger('HouseLoadVarID');
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return;
        }
        $ring = json_decode($this->ReadAttributeString('LoadRing'), true) ?: [];
        $ring[] = [time(), (float) GetValue($vid)];
        if (count($ring) > self::LOAD_RING) {
            $ring = array_slice($ring, -self::LOAD_RING);
        }
        $this->WriteAttributeString('LoadRing', json_encode($ring));
    }

    /**
     * Zapfung = der Speicher fällt deutlich schneller als der Stillstandsverlust
     * (gemessen rund 0,25 K/h). Wird als Kelvin in den Stundenkorb des Tages
     * gebucht; die Kelvin sind das Maß für „wie viel Warmwasser".
     */
    private function DetectDraw(float $ist): void
    {
        $vorher = $this->ReadAttributeFloat('LastTemp');
        $ts     = $this->ReadAttributeInteger('LastTempTs');
        $this->WriteAttributeFloat('LastTemp', $ist);
        $this->WriteAttributeInteger('LastTempTs', time());

        if ($vorher <= 0 || $ts <= 0) {
            return;
        }
        $std = (time() - $ts) / 3600;
        if ($std <= 0 || $std > 1) {
            return;                                  // Lücke (Neustart) nicht werten
        }
        $rate = ($vorher - $ist) / $std;             // K/h Abfall
        if ($rate < self::DRAW_RATE || (bool) $this->GetValueSafe('DHWPower', false)) {
            return;
        }
        $tag = json_decode($this->ReadAttributeString('DrawToday'), true) ?: [];
        if (($tag['tag'] ?? '') !== date('Y-m-d')) {
            $tag = ['tag' => date('Y-m-d'), 'wd' => (int) date('w'), 'k' => array_fill(0, 24, 0.0)];
        }
        $tag['k'][(int) date('G')] += round($vorher - $ist, 2);
        $this->WriteAttributeString('DrawToday', json_encode($tag));
    }

    /**
     * Vermisst eine Warmwasserladung: Grundlast beim Start merken, während der
     * Ladung Hauslast und Modul-Schätzung mitteln, am Ende beides auswerten.
     * Gemessen wird erst, wenn der Speicher wirklich steigt – die ersten Minuten
     * nach der Freigabe passiert nur Umschalten und Anlaufen.
     */
    private function TrackCharge(float $ist): void
    {
        $c = json_decode($this->ReadAttributeString('Charge'), true) ?: [];
        $an = (bool) $this->GetValueSafe('DHWPower', false);
        $vid = $this->ReadPropertyInteger('HouseLoadVarID');
        $last = ($vid > 0 && IPS_VariableExists($vid)) ? (float) GetValue($vid) : null;

        if ($an && empty($c['aktiv'])) {
            $c = ['aktiv'=>1, 'base'=>$this->RingQuantile(0.2), 'tStart'=>$ist,
                  'sum'=>0.0, 'n'=>0, 'estSum'=>0.0, 'ts'=>time()];
            $this->WriteAttributeString('Charge', json_encode($c));
            return;
        }
        if (!$an) {
            if (!empty($c['aktiv'])) {
                $this->FinishCharge($c, $ist);
                $this->WriteAttributeString('Charge', '{}');
            }
            return;
        }
        // läuft: nur zählen, wenn der Speicher gegenüber dem Start gestiegen ist
        if ($last !== null && $ist > ($c['tStart'] ?? 99) + 0.3) {
            $c['sum']    += $last;
            $c['estSum'] += (float) $this->GetValueSafe('PowerElec', 0.0);
            $c['n']++;
            $this->WriteAttributeString('Charge', json_encode($c));
        }
    }

    /** Ladung beendet: Leistung und Korrekturfaktor ableiten, wenn belastbar. */
    private function FinishCharge(array $c, float $ende): void
    {
        $n = (int) ($c['n'] ?? 0);
        $base = $c['base'] ?? null;
        if ($n < self::CHARGE_MIN_N || $base === null || ($ende - ($c['tStart'] ?? 0)) < self::CHARGE_MIN_K) {
            return;                                   // zu kurz oder keine Grundlast
        }
        $leistung = $c['sum'] / $n - $base;
        if ($leistung < 300 || $leistung > 12000) {
            return;                                   // unplausibel, verwerfen
        }
        $this->PushSample('PowerSamples', round($leistung));

        // Korrekturfaktor nur, wenn die Modul-Schätzung überhaupt etwas gemeldet hat
        $est = $c['estSum'] / $n;
        $k   = $this->PowerCorrection();
        if ($est > 300 && $k > 0) {
            $roh = $est / $k;                         // Schätzung ohne bisherige Korrektur
            $f = $leistung / $roh;
            if ($f >= 0.5 && $f <= 5.0) {
                $this->PushSample('FactorSamples', round($f, 3));
            }
        }
        $this->PublishLearned();
        $this->LogMessage(sprintf('Warmwasserladung vermessen: %.0f W über %d Messpunkte '
            . '(Grundlast %.0f W, Speicher +%.1f K) → gelernt %.0f W, Faktor %.2f',
            $leistung, $n, $base, $ende - $c['tStart'],
            $this->MedianOf('PowerSamples') ?? 0, $this->PowerCorrection()), KL_MESSAGE);
    }

    /** Tageswechsel: Zapfungen des Vortags gleitend ins Wochenprofil mischen. */
    private function RollOverDay(): void
    {
        $tag = json_decode($this->ReadAttributeString('DrawToday'), true) ?: [];
        if (!$tag || ($tag['tag'] ?? '') === date('Y-m-d')) {
            return;
        }
        $prof = $this->Profile();
        $wd = (int) $tag['wd'];
        // Die Glättung startet bei null: Mit festem Alpha käme der erste
        // beobachtete Tag nur zu 30 % an und das Profil läge dauerhaft zu
        // niedrig. Solange wenige Tage vorliegen, zählt darum der laufende
        // Mittelwert (1/n), später übernimmt das Alpha.
        $n = (int) ($this->DayCounts()[$wd] ?? 0);
        $alpha = max(self::PROFILE_ALPHA, 1.0 / ($n + 1));
        for ($h = 0; $h < 24; $h++) {
            $prof[$wd][$h] = round($prof[$wd][$h] * (1 - $alpha)
                                 + ((float) $tag['k'][$h]) * $alpha, 2);
        }
        $this->WriteAttributeString('Profile', json_encode($prof));
        $zaehler = $this->DayCounts();
        $zaehler[$wd] = min(20, (int) $zaehler[$wd] + 1);   // deckeln: alte Tage sollen nicht ewig dominieren
        $this->WriteAttributeString('DayCounts', json_encode($zaehler));
        $this->WriteAttributeString('DrawToday', json_encode(
            ['tag'=>date('Y-m-d'), 'wd'=>(int) date('w'), 'k'=>array_fill(0, 24, 0.0)]));
        $this->PublishLearned();
    }

    // ── Zugriff auf das Gelernte ──────────────────────────────────────

    private function Profile(): array
    {
        $p = json_decode($this->ReadAttributeString('Profile'), true);
        if (!is_array($p) || count($p) !== 7) {
            $p = array_fill(0, 7, array_fill(0, 24, 0.0));
        }
        return $p;
    }

    private function DayCounts(): array
    {
        $c = json_decode($this->ReadAttributeString('DayCounts'), true);
        return (is_array($c) && count($c) === 7) ? $c : array_fill(0, 7, 0);
    }

    /**
     * Bedarfsprofil eines Wochentags – solange wenige Tage dieses Wochentags
     * gesehen wurden, zum Schnitt über alle Tage hingezogen. Ohne diese
     * Abmilderung wäre das Profil wochenlang unbrauchbar: Nach fünf Tagen
     * Datenbasis sind fünf von sieben Wochentagen schlicht leer.
     */
    private function ProfileFor(int $wd): array
    {
        $p = $this->Profile();
        $n = (int) ($this->DayCounts()[$wd] ?? 0);

        $alle = array_fill(0, 24, 0.0);
        $tage = 0;
        foreach ($this->DayCounts() as $d => $c) {
            if ($c > 0) {
                $tage++;
                for ($h = 0; $h < 24; $h++) {
                    $alle[$h] += $p[$d][$h];
                }
            }
        }
        if ($tage === 0) {
            return $p[$wd];
        }
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[$h] = round(($n * $p[$wd][$h] + $alle[$h] / $tage) / ($n + 1), 2);
        }
        return $out;
    }

    /** Gelernter Korrekturfaktor für die Leistungsschätzung (1.0 = ungelernt). */
    private function PowerCorrection(): float
    {
        $m = $this->MedianOf('FactorSamples');
        return ($m === null || $m <= 0) ? 1.0 : $m;
    }

    /** Erste Stunde des Tages mit nennenswertem Bedarf, in Minuten. */
    private function ReadyByMinutes(): ?int
    {
        return $this->FirstDemandAfter(0);
    }

    /** Erste Stunde ab $abMinute mit nennenswertem Bedarf, in Minuten. */
    private function FirstDemandAfter(int $abMinute): ?int
    {
        $p = $this->ProfileFor((int) date('w'));
        for ($h = (int) ceil($abMinute / 60); $h < 24; $h++) {
            if ($p[$h] >= self::DRAW_SIGNIFICANT) {
                return $h * 60;
            }
        }
        return null;
    }

    /**
     * Erwartete Zapfung (K) ab der Deadline bis zum nächsten PV-Fenster morgen –
     * so viel muss der Speicher über die Nacht bringen.
     */
    private function ExpectedDrawAfter(?int $abMinute): float
    {
        if ($abMinute === null) {
            return 0.0;
        }
        $heute  = $this->ProfileFor((int) date('w'));
        $morgen = $this->ProfileFor(((int) date('w') + 1) % 7);
        $summe = 0.0;
        for ($h = (int) ($abMinute / 60); $h < 24; $h++) {
            $summe += $heute[$h];
        }
        for ($h = 0; $h < self::PV_HOUR; $h++) {
            $summe += $morgen[$h];
        }
        return round($summe, 1);
    }

    private function PushSample(string $attr, float $wert): void
    {
        $s = json_decode($this->ReadAttributeString($attr), true) ?: [];
        $s[] = $wert;
        if (count($s) > self::SAMPLES_MAX) {
            $s = array_slice($s, -self::SAMPLES_MAX);
        }
        $this->WriteAttributeString($attr, json_encode($s));
    }

    private function MedianOf(string $attr): ?float
    {
        $s = json_decode($this->ReadAttributeString($attr), true) ?: [];
        if (!$s) {
            return null;
        }
        sort($s);
        return (float) $s[(int) (count($s) / 2)];
    }

    private function RingQuantile(float $q): ?float
    {
        $ring = json_decode($this->ReadAttributeString('LoadRing'), true) ?: [];
        if (count($ring) < 3) {
            return null;
        }
        $w = array_column($ring, 1);
        sort($w);
        return (float) $w[(int) (count($w) * $q)];
    }

    /** Gelernte Werte in die Anzeigevariablen und in den Energiemanager schreiben. */
    private function PublishLearned(bool $emImmer = false): void
    {
        $w = $this->MedianOf('PowerSamples');
        if ($w !== null) {
            $this->SetValueIfChanged('DHWPowerLearned', (float) $w);
            // Der Takt läuft jede Minute – den Manager nur anfassen, wenn sich
            // der gelernte Wert überhaupt bewegt hat.
            if ($emImmer || abs($w - $this->ReadAttributeFloat('LastPushed')) > 0.5) {
                $this->PushUsageToEnergyManager((float) $w, $emImmer);
                $this->WriteAttributeFloat('LastPushed', (float) $w);
            }
        }
        $this->SetValueIfChanged('PowerCorrLearned', round($this->PowerCorrection(), 2));

        $ready = $this->ReadyByMinutes();
        [$dl, $temp] = $this->EffectiveDeadline();
        $erw = $this->ExpectedDrawAfter($dl);
        $this->SetValueIfChanged('DHWReadyBy', $ready === null
            ? 'noch keine Daten'
            : sprintf('%s ab %02d:00 · bis zur nächsten Sonne %.0f K erwartet · nachheizen ab %02d:%02d unter %.1f °C',
                self::WD[(int) date('w')], (int) ($ready / 60), $erw,
                (int) ($dl / 60), $dl % 60, $temp));

        $prof = $this->Profile();
        $zahl = $this->DayCounts();
        $zeilen = [];
        for ($d = 1; $d <= 7; $d++) {
            $wd = $d % 7;                              // Anzeige Mo…So
            $summe = array_sum($prof[$wd]);
            if ($summe < 0.5) {
                $zeilen[] = self::WD[$wd] . ' –';
                continue;
            }
            $spitze = array_search(max($prof[$wd]), $prof[$wd], true);
            $zeilen[] = sprintf('%s %.0f K um %02d:00 (%dx)',
                self::WD[$wd], $summe, $spitze, (int) ($zahl[$wd] ?? 0));
        }
        $this->SetValueIfChanged('DHWProfileText', implode(' · ', $zeilen));
    }

    /**
     * Gelernte Ladeleistung als „Maximale Nutzung" in den Energie Manager
     * schreiben. Erst ab 10 % Abweichung, weil jedes Schreiben die Instanz neu
     * lädt – und die Zeile wird über ihren Namen gesucht, damit die Reihenfolge
     * der Verbraucher frei bleibt.
     */
    private function PushUsageToEnergyManager(float $watt, bool $immer = false): void
    {
        $em   = $this->ReadPropertyInteger('EnergyManagerID');
        $name = trim($this->ReadPropertyString('EMConsumerName'));
        if ($em <= 0 || $name === '' || !IPS_InstanceExists($em)) {
            return;
        }
        $cfg = json_decode(IPS_GetConfiguration($em), true);
        if (!isset($cfg['Consumers'])) {
            return;
        }
        $liste = json_decode($cfg['Consumers'], true) ?: [];
        $geaendert = false;
        foreach ($liste as &$zeile) {
            if (($zeile['Name'] ?? '') !== $name) {
                continue;
            }
            $alt = (float) ($zeile['Usage'] ?? 0);
            if ($immer || $alt <= 0 || abs($watt - $alt) / max($alt, 1.0) >= 0.10) {
                $zeile['Usage'] = round($watt);
                $geaendert = true;
            }
        }
        unset($zeile);
        if (!$geaendert) {
            return;
        }
        IPS_SetProperty($em, 'Consumers', json_encode($liste, JSON_UNESCAPED_UNICODE));
        IPS_ApplyChanges($em);
        $this->LogMessage(sprintf('Energie Manager #%d: "%s" auf %.0f W nachgeführt',
            $em, $name, $watt), KL_MESSAGE);
    }

    /** "HH:MM" → Minuten seit Mitternacht, sonst null. */
    private function ParseTime(string $s): ?int
    {
        if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $s, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        return ($h < 24 && $i < 60) ? $h * 60 + $i : null;
    }

    /** Legt die beiden Wochenpläne an (falls aktiviert) bzw. deaktiviert sie. */
    private function SetupSchedules(): void
    {
        $this->EnsureSchedule(
            'HeatEventID',
            $this->ReadPropertyBoolean('UseHeatSchedule'),
            'Heizzeiten',
            [
                [self::HEAT_COMFORT, 'Komfort',   0xFF6600],
                [self::HEAT_ECO,     'Absenkung', 0x3366FF],
                [self::HEAT_OFF,     'Aus',       0x808080],
            ],
            'SAMW_ApplyHeatState(' . $this->InstanceID . ', %d);',
            [[6, 0, self::HEAT_COMFORT], [22, 0, self::HEAT_ECO]]
        );

        $this->EnsureSchedule(
            'DHWEventID',
            $this->ReadPropertyBoolean('UseDHWSchedule'),
            'Warmwasserzeiten',
            [
                [self::DHW_ON,  'Ein', 0xFF6600],
                [self::DHW_OFF, 'Aus', 0x808080],
            ],
            'SAMW_ApplyDHWState(' . $this->InstanceID . ', %d);',
            [[5, 0, self::DHW_ON], [22, 0, self::DHW_OFF]]
        );
    }

    /**
     * Erstellt/aktualisiert einen Wochenplan als Kind der Instanz. $points sind
     * Standard-Umschaltzeiten [Stunde, Minute, AktionID] für alle Tage (Gruppe 0).
     */
    private function EnsureSchedule(string $attr, bool $enabled, string $name, array $actions, string $scriptFmt, array $points): void
    {
        $eid = $this->ReadAttributeInteger($attr);

        if (!$enabled) {
            if ($eid > 0 && IPS_EventExists($eid)) {
                IPS_SetEventActive($eid, false);
            }
            return;
        }

        if ($eid <= 0 || !IPS_EventExists($eid)) {
            $eid = IPS_CreateEvent(2); // 2 = Wochenplan
            IPS_SetParent($eid, $this->InstanceID);
            IPS_SetName($eid, $name);
            $this->WriteAttributeInteger($attr, $eid);

            foreach ($actions as $a) {
                IPS_SetEventScheduleAction($eid, $a[0], $a[1], $a[2], sprintf($scriptFmt, $a[0]));
            }
            IPS_SetEventScheduleGroup($eid, 0, 0b1111111); // alle Wochentage
            foreach ($points as $i => $p) {
                IPS_SetEventScheduleGroupPoint($eid, 0, $i, $p[0], $p[1], 0, $p[2]);
            }
        }
        IPS_SetEventActive($eid, true);

        // aktuellen Zustand einmalig anwenden
        $state = $this->CurrentScheduleAction($eid);
        if ($state !== null) {
            if ($attr === 'HeatEventID') {
                $this->ApplyHeatState($state);
            } else {
                $this->ApplyDHWState($state);
            }
        }
    }

    /** Aktuell gültige Wochenplan-Aktions-ID (oder null). */
    private function CurrentScheduleAction(int $eid): ?int
    {
        if (!IPS_EventExists($eid)) {
            return null;
        }
        $e = IPS_GetEvent($eid);
        $now = time();
        $dayBit = 1 << ((int) date('N', $now) - 1); // Mo=Bit0 … So=Bit6
        $secNow = (int) date('H', $now) * 3600 + (int) date('i', $now) * 60 + (int) date('s', $now);

        $best = null;
        $bestSec = -1;
        // 1) letzter bereits vergangener Punkt heute
        foreach ($e['ScheduleGroups'] as $g) {
            if (!($g['Days'] & $dayBit)) {
                continue;
            }
            foreach ($g['Points'] as $p) {
                $s = $p['Start']['Hour'] * 3600 + $p['Start']['Minute'] * 60 + $p['Start']['Second'];
                if ($s <= $secNow && $s > $bestSec) {
                    $bestSec = $s;
                    $best = (int) $p['ActionID'];
                }
            }
        }
        // 2) vor dem ersten Punkt heute → letzter Punkt (Vortag) = größter Start überhaupt
        if ($best === null) {
            foreach ($e['ScheduleGroups'] as $g) {
                foreach ($g['Points'] as $p) {
                    $s = $p['Start']['Hour'] * 3600 + $p['Start']['Minute'] * 60 + $p['Start']['Second'];
                    if ($s > $bestSec) {
                        $bestSec = $s;
                        $best = (int) $p['ActionID'];
                    }
                }
            }
        }
        return $best;
    }

    // ═══════════════════════════════════════════════════════════════
    //  STATUS-SPIEGELUNG
    // ═══════════════════════════════════════════════════════════════

    private function MirrorStatus(string $ident, $value): void
    {
        switch ($ident) {
            // ── Steuerobjekte (Rückmeldung) ──
            case 'Power':        $this->SetValueIfChanged('Power', $this->toBool($value)); break;
            case 'Mode':         $this->SetValueIfChanged('Mode', (int) $value); break;
            case 'DHWPower':     $this->SetValueIfChanged('DHWPower', $this->toBool($value)); break;
            case 'DHWMode':      $this->SetValueIfChanged('DHWMode', (int) $value); break;
            case 'Silent':       $this->SetValueIfChanged('Silent', $this->toBool($value)); break;
            case 'Away':         $this->SetValueIfChanged('Away', $this->toBool($value)); break;

            case 'RoomSetpoint':
            case 'FlowSetpoint':
            case 'DHWSetpoint':
                $this->SetIfPlausible($ident, $value);
                break;

            // ── Temperaturen und Messwerte ──
            case 'RoomTemp':
            case 'MCCFlowTemp':
            case 'DHWTemp':
            case 'OutdoorTemp':
            case 'HotGas':
            case 'HighPressure':
            case 'LowPressure':
                $this->SetIfPlausible($ident, $value);
                break;

            case 'FlowTemp':
            case 'ReturnTemp':
            case 'WaterFlow':
                $this->SetIfPlausible($ident, $value);
                $this->UpdateDerived();
                break;

            case 'CompCurrent':
                $this->SetIfPlausible($ident, $value);
                $this->UpdateDerived();
                break;

            case 'CompFreq':
                // Trägt jetzt „läuft die Anlage" – der Betriebszustand des
                // Außengeräts ist über Modbus nicht verfügbar.
                $f = (int) $value;
                if ($f !== self::NO_VALUE) {
                    $this->SetValueIfChanged('CompFreq', $f);
                    $this->UpdateDerived();
                }
                break;

            // ── Zustände ──
            case 'Defrost':
                // 0 oder 255 = Abtauen aus
                $d = (int) $value;
                $this->SetValueIfChanged('Defrost', $d !== 0 && $d !== 255 && $d !== self::NO_VALUE);
                break;

            case 'ThreeWayValve':
                // 0 = Heizkreis, 1 = Brauchwasser – trennt die Betriebsarten
                $this->SetValueIfChanged('ThreeWayValve', (int) $value);
                $this->UpdateDerived();
                break;

            // Beide kommen aus den erweiterbaren Registern (PDU 85/86) und sind
            // dort als reines 0/1 dokumentiert. Alles andere ist der Samsung-
            // Marker „kein Wert" oder Müll aus einer gestörten Übertragung –
            // und würde als „nicht null" zu einem hängenden EIN führen.
            case 'BoosterDHW':
            case 'BackupHeater':
                $this->SetBoolIfPlausible($ident, $value);
                break;

            case 'RemoteLock':
                // 0 = frei, 25443 = gesperrt
                $this->SetValueIfChanged('RemoteLock', ((int) $value) === self::LOCK_LOCKED);
                break;

            case 'Comm':
                // Bits: b0 vorhanden, b1 Typ erkannt, b2 ready, b3 Kommunikationsfehler.
                // Summe 7 = betriebsbereit. 255/0xFFFF ist kein gültiger Wert.
                $c = (int) $value;
                if ($c !== 255 && $c !== self::NO_VALUE) {
                    $this->SetValueIfChanged('Verbunden', ($c & 0x07) === 0x07);
                }
                break;

            case 'DeviceType':
                $this->SetValueIfChanged('DeviceType', (int) $value);
                break;

            // ── Fehler: drei Quellen, ein Klartext ──
            case 'ErrorCode':
            case 'OutErrorCode':
            case 'SlaveErrorCode':
            case 'ModuleError':
                // 0xFFFF heißt „Register liefert keinen Wert" – nicht als Code ablegen,
                // das sähe in der Objektliste wie ein Fehler aus.
                $c = (int) $value;
                if ($c !== self::NO_VALUE) {
                    $this->SetValueIfChanged($ident, $c);
                    $this->UpdateErrorText();
                }
                break;
        }
    }

    /**
     * Abgeleitete Werte. Ohne Wärmemengenzähler sind das Schätzungen:
     * Wärmeleistung aus Durchfluss und Spreizung, elektrische Leistung aus der
     * Kompressor-Stromaufnahme (ohne Pumpe/Heizstab, ohne Leistungsfaktor).
     */
    private function UpdateDerived(): void
    {
        $flow   = (float) $this->GetValueSafe('FlowTemp', 0.0);
        $ret    = (float) $this->GetValueSafe('ReturnTemp', 0.0);
        $lpm    = (float) $this->GetValueSafe('WaterFlow', 0.0);
        $amp    = (float) $this->GetValueSafe('CompCurrent', 0.0);
        $mains  = $this->ReadPropertyFloat('Mains');
        $standby = $this->ReadPropertyFloat('StandbyWatt');

        $spread = ($flow > 0 && $ret > 0) ? round($flow - $ret, 1) : 0.0;
        $this->SetValueIfChanged('Spread', $spread);

        // „In Betrieb" kommt aus der Kompressorfrequenz. Der Betriebszustand des
        // Außengeräts wäre der richtige Datenpunkt, ist über Modbus aber nicht
        // verfügbar – siehe Kommentar an STAT_SUB.
        $hz = (int) $this->GetValueSafe('CompFreq', 0);
        $laeuft = $hz > 0;

        // Wärmeleistung nur ausweisen, wenn der Verdichter läuft UND die Spreizung
        // über dem Fühlerrauschen liegt. Im Stillstand stehen beide Fühler auf
        // demselben Wasser: 0,2 K Differenz bei 31 l/min ergäben sonst 435 W aus
        // dem Nichts. Beim Abtauen ist die Spreizung negativ, das zählt auch nicht.
        $heat = ($laeuft && $lpm > 0 && $spread >= self::SPREAD_MIN)
            ? round($lpm * $spread * self::W_PER_LPM_K)
            : 0.0;
        $this->SetValueIfChanged('HeatPower', (float) $heat);

        // Der Kompressorstrom ist ein Phasenstrom. Wie viele Phasen dahinter
        // hängen und welcher Leistungsfaktor gilt, steht nirgends im Register –
        // deshalb der gelernte Korrekturfaktor: Er wird aus dem gemessenen
        // Hauslast-Sprung während der Warmwasserladungen bestimmt (LearnPower()).
        $k = $this->PowerCorrection();
        $elec = $amp > 0 ? round($amp * $mains * $k + $standby) : 0.0;
        $this->SetValueIfChanged('PowerElec', (float) $elec);

        $this->SetValueIfChanged('COP', ($elec > 100 && $heat > 0) ? round($heat / $elec, 2) : 0.0);

        $this->SetValueIfChanged('Running', $laeuft);

        // Wofür sie läuft, verrät das 3-Wegeventil. Wichtig, weil der COP
        // zwischen Heizen und Warmwasser deutlich auseinanderliegt.
        $ventil = (int) $this->GetValueSafe('ThreeWayValve', 0);
        $dhw = $laeuft && $ventil === 1;
        $this->SetValueIfChanged('Operation', $laeuft
            ? ($dhw ? self::OP_DHW : self::OP_HEAT)
            : self::OP_STANDBY);

        // Nur der Warmwasser-Anteil. Ein Energiemanager, der die Gesamtleistung
        // als „aktuelle Nutzung" des Verbrauchers Warmwasser bekäme, würde im
        // Winter die Heizung mitzählen und den Verbraucher grundlos abwerfen.
        $this->SetValueIfChanged('DHWPowerNow', $dhw ? (float) $elec : 0.0);
    }

    /** Fehlertext aus den drei Fehlercodes und dem Modul-Fehlerstatus. */
    private function UpdateErrorText(): void
    {
        $parts = [];

        $mod = (int) $this->GetValueSafe('ModuleError', 0);
        if ($mod > 0 && $mod !== self::NO_VALUE) {
            $bits = [];
            if ($mod & 0x01) { $bits[] = 'Adressfehler'; }
            if ($mod & 0x02) { $bits[] = 'Kommunikation R1/R2'; }
            if ($mod & 0x04) { $bits[] = 'Tracking'; }
            $parts[] = 'Modul: ' . ($bits ? implode(', ', $bits) : (string) $mod);
        }
        foreach (['ErrorCode' => 'Fehlercode', 'SlaveErrorCode' => 'Hydro-Einheit', 'OutErrorCode' => 'Außengerät'] as $id => $label) {
            $c = (int) $this->GetValueSafe($id, 0);
            if ($c > 0 && $c !== self::NO_VALUE) {
                $parts[] = $label . ' ' . $c;
            }
        }

        $this->SetValueIfChanged('ErrorText', $parts ? implode(' · ', $parts) : 'OK');
    }

    // ═══════════════════════════════════════════════════════════════
    //  VARIABLEN & PROFILE
    // ═══════════════════════════════════════════════════════════════

    private function RegisterVariables(): void
    {
        $this->EnsureProfiles();

        // Altlasten aus Build 17/18 entfernen: für diese Datenpunkte gibt es in der
        // MIM-B19N kein Register, die Variablen blieben also dauerhaft leer.
        foreach (['OutdoorState', 'FourWayValve', 'FlowTarget', 'CurveTarget', 'WaterZone1', 'MixValve'] as $alt) {
            if (@$this->GetIDForIdent($alt)) {
                $this->UnregisterVariable($alt);
            }
        }

        $p = 0;

        // ── Bedienung ──
        $this->RegisterVariableBoolean('Power', 'Wärmepumpe Ein/Aus', '~Switch', $p += 10);
        $this->EnableAction('Power');
        $this->RegisterVariableInteger('Mode', 'Betriebsart', 'SAMW.Mode', $p += 10);
        $this->EnableAction('Mode');
        $this->RegisterVariableFloat('RoomSetpoint', 'Raum-Sollwert', 'SAMW.SetRoom', $p += 10);
        $this->EnableAction('RoomSetpoint');
        $this->RegisterVariableFloat('FlowSetpoint', 'Vorlauf-Sollwert', 'SAMW.SetFlow', $p += 10);
        $this->EnableAction('FlowSetpoint');

        // ── Warmwasser ──
        $this->RegisterVariableBoolean('DHWPower', 'Warmwasser Ein/Aus', '~Switch', $p += 10);
        $this->EnableAction('DHWPower');
        $this->RegisterVariableInteger('DHWMode', 'Warmwasser-Modus', 'SAMW.DHWMode', $p += 10);
        $this->EnableAction('DHWMode');
        $this->RegisterVariableFloat('DHWSetpoint', 'Warmwasser-Sollwert', 'SAMW.SetDHW', $p += 10);
        $this->EnableAction('DHWSetpoint');
        $this->RegisterVariableFloat('DHWTemp', 'Warmwasser-Ist', '~Temperature', $p += 10);

        // Eingang für den Energiemanager + Anzeige, warum gerade geladen wird
        $this->RegisterVariableBoolean('DHWPVRelease', 'Warmwasser PV-Freigabe', '~Switch', $p += 10);
        $this->EnableAction('DHWPVRelease');
        $this->RegisterVariableString('DHWDemandReason', 'Warmwasser-Anforderung', '', $p += 10);
        $pv = $this->ReadPropertyBoolean('DHWPVEnabled');
        IPS_SetHidden($this->GetIDForIdent('DHWPVRelease'), !$pv);
        IPS_SetHidden($this->GetIDForIdent('DHWDemandReason'), !$pv);

        // ── Gelerntes ──
        $this->RegisterVariableFloat('DHWPowerNow', 'Warmwasser-Leistung (aktuell)', '~Watt', $p += 10);
        $this->RegisterVariableFloat('DHWPowerLearned', 'Warmwasser-Ladeleistung (gelernt)', '~Watt', $p += 10);
        $this->RegisterVariableFloat('PowerCorrLearned', 'Leistungs-Korrekturfaktor (gelernt)', 'SAMW.Factor', $p += 10);
        $this->RegisterVariableString('DHWReadyBy', 'Warmwasser typisch gebraucht ab', '', $p += 10);
        $this->RegisterVariableString('DHWProfileText', 'Warmwasser-Bedarfsprofil', '', $p += 10);
        $lern = $this->ReadPropertyBoolean('DHWLearn');
        foreach (['DHWPowerNow', 'DHWPowerLearned', 'PowerCorrLearned', 'DHWReadyBy', 'DHWProfileText'] as $i) {
            IPS_SetHidden($this->GetIDForIdent($i), !$lern);
        }

        // ── Komfortfunktionen ──
        $this->RegisterVariableBoolean('Silent', 'Silent-Betrieb', '~Switch', $p += 10);
        $this->EnableAction('Silent');
        $this->RegisterVariableBoolean('Away', 'Away-Funktion', '~Switch', $p += 10);
        $this->EnableAction('Away');

        // ── Heizkreis ──
        $this->RegisterVariableFloat('RoomTemp', 'Raumtemperatur', '~Temperature', $p += 10);
        $this->RegisterVariableFloat('FlowTemp', 'Vorlauftemperatur', '~Temperature', $p += 10);
        $this->RegisterVariableFloat('ReturnTemp', 'Rücklauftemperatur', '~Temperature', $p += 10);
        $this->RegisterVariableFloat('Spread', 'Spreizung', 'SAMW.Spread', $p += 10);
        $this->RegisterVariableFloat('MCCFlowTemp', 'MCC Vorlauftemperatur', '~Temperature', $p += 10);
        $this->RegisterVariableFloat('WaterFlow', 'Wasserdurchfluss', 'SAMW.Flow', $p += 10);
        $this->RegisterVariableInteger('ThreeWayValve', '3-Wegeventil', 'SAMW.Valve3', $p += 10);

        // ── Außengerät ──
        $this->RegisterVariableFloat('OutdoorTemp', 'Außentemperatur', '~Temperature', $p += 10);
        $this->RegisterVariableBoolean('Running', 'In Betrieb', '~Switch', $p += 10);
        $this->RegisterVariableInteger('Operation', 'Läuft für', 'SAMW.Operation', $p += 10);
        $this->RegisterVariableBoolean('Defrost', 'Abtaubetrieb', '~Switch', $p += 10);
        $this->RegisterVariableInteger('CompFreq', 'Kompressorfrequenz', 'SAMW.Hz', $p += 10);
        $this->RegisterVariableFloat('CompCurrent', 'Stromaufnahme Kompressor', '~Ampere', $p += 10);
        $this->RegisterVariableFloat('HotGas', 'Heißgastemperatur', '~Temperature', $p += 10);
        $this->RegisterVariableFloat('HighPressure', 'Hochdruck', 'SAMW.Pressure', $p += 10);
        $this->RegisterVariableFloat('LowPressure', 'Niederdruck', 'SAMW.Pressure', $p += 10);

        // ── Zusatzheizungen ──
        $this->RegisterVariableBoolean('BackupHeater', 'Ersatzheizung', '~Switch', $p += 10);
        $this->RegisterVariableBoolean('BoosterDHW', 'Warmwasser-Zusatzheizung', '~Switch', $p += 10);

        // ── Energie (geschätzt) ──
        $this->RegisterVariableFloat('HeatPower', 'Wärmeleistung (berechnet)', '~Watt', $p += 10);
        $this->RegisterVariableFloat('PowerElec', 'Elektrische Leistung (geschätzt)', '~Watt', $p += 10);
        $this->RegisterVariableFloat('COP', 'Arbeitszahl (COP)', 'SAMW.COP', $p += 10);

        // ── Diagnose ──
        $this->RegisterVariableBoolean('Verbunden', 'Verbunden', '~Switch', $p += 10);
        $this->RegisterVariableBoolean('RemoteLock', 'Fernbedienung gesperrt', '~Switch', $p += 10);
        $this->RegisterVariableString('ErrorText', 'Fehler', '', $p += 10);
        $this->RegisterVariableInteger('ErrorCode', 'Fehlercode', '', $p += 10);
        $this->RegisterVariableInteger('SlaveErrorCode', 'Fehlercode Hydro-Einheit', '', $p += 10);
        $this->RegisterVariableInteger('OutErrorCode', 'Sammelfehlercode Außengerät', '', $p += 10);
        $this->RegisterVariableInteger('ModuleError', 'Modul-Fehlerstatus', '', $p += 10);
        $this->RegisterVariableInteger('DeviceType', 'Gerätetyp', '', $p += 10);
    }

    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists('SAMW.Mode')) {
            IPS_CreateVariableProfile('SAMW.Mode', 1);
            IPS_SetVariableProfileIcon('SAMW.Mode', 'Climate');
            IPS_SetVariableProfileAssociation('SAMW.Mode', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('SAMW.Mode', 1, 'Kühlen', '', 0x3399CC);
            IPS_SetVariableProfileAssociation('SAMW.Mode', self::MODE_HEAT, 'Heizen', '', 0xCC8800);
        }
        if (!IPS_VariableProfileExists('SAMW.DHWMode')) {
            IPS_CreateVariableProfile('SAMW.DHWMode', 1);
            IPS_SetVariableProfileIcon('SAMW.DHWMode', 'Drops');
            IPS_SetVariableProfileAssociation('SAMW.DHWMode', 0, 'Eco', '', -1);
            IPS_SetVariableProfileAssociation('SAMW.DHWMode', 1, 'Standard', '', -1);
            IPS_SetVariableProfileAssociation('SAMW.DHWMode', 2, 'Power', '', -1);
            IPS_SetVariableProfileAssociation('SAMW.DHWMode', 3, 'Force', '', -1);
        }
        if (!IPS_VariableProfileExists('SAMW.SetRoom')) {
            IPS_CreateVariableProfile('SAMW.SetRoom', 2);
            IPS_SetVariableProfileIcon('SAMW.SetRoom', 'Temperature');
            IPS_SetVariableProfileText('SAMW.SetRoom', '', ' °C');
            IPS_SetVariableProfileDigits('SAMW.SetRoom', 1);
            IPS_SetVariableProfileValues('SAMW.SetRoom', 15, 30, 0.5);
        }
        if (!IPS_VariableProfileExists('SAMW.SetFlow')) {
            IPS_CreateVariableProfile('SAMW.SetFlow', 2);
            IPS_SetVariableProfileIcon('SAMW.SetFlow', 'Temperature');
            IPS_SetVariableProfileText('SAMW.SetFlow', '', ' °C');
            IPS_SetVariableProfileDigits('SAMW.SetFlow', 1);
            IPS_SetVariableProfileValues('SAMW.SetFlow', 15, 65, 0.5);
        }
        if (!IPS_VariableProfileExists('SAMW.SetDHW')) {
            IPS_CreateVariableProfile('SAMW.SetDHW', 2);
            IPS_SetVariableProfileIcon('SAMW.SetDHW', 'Drops');
            IPS_SetVariableProfileText('SAMW.SetDHW', '', ' °C');
            IPS_SetVariableProfileDigits('SAMW.SetDHW', 0);
            IPS_SetVariableProfileValues('SAMW.SetDHW', 30, 70, 1);
        }
        if (!IPS_VariableProfileExists('SAMW.Spread')) {
            IPS_CreateVariableProfile('SAMW.Spread', 2);
            IPS_SetVariableProfileIcon('SAMW.Spread', 'Temperature');
            IPS_SetVariableProfileText('SAMW.Spread', '', ' K');
            IPS_SetVariableProfileDigits('SAMW.Spread', 1);
        }
        if (!IPS_VariableProfileExists('SAMW.Flow')) {
            IPS_CreateVariableProfile('SAMW.Flow', 2);
            IPS_SetVariableProfileIcon('SAMW.Flow', 'Drops');
            IPS_SetVariableProfileText('SAMW.Flow', '', ' l/min');
            IPS_SetVariableProfileDigits('SAMW.Flow', 1);
        }
        if (!IPS_VariableProfileExists('SAMW.Pressure')) {
            IPS_CreateVariableProfile('SAMW.Pressure', 2);
            IPS_SetVariableProfileIcon('SAMW.Pressure', 'Gauge');
            IPS_SetVariableProfileText('SAMW.Pressure', '', ' kgf/cm²');
            IPS_SetVariableProfileDigits('SAMW.Pressure', 1);
        }
        if (!IPS_VariableProfileExists('SAMW.Hz')) {
            IPS_CreateVariableProfile('SAMW.Hz', 1);
            IPS_SetVariableProfileIcon('SAMW.Hz', 'Motor');
            IPS_SetVariableProfileText('SAMW.Hz', '', ' Hz');
        }
        if (!IPS_VariableProfileExists('SAMW.COP')) {
            IPS_CreateVariableProfile('SAMW.COP', 2);
            IPS_SetVariableProfileIcon('SAMW.COP', 'Graph');
            IPS_SetVariableProfileDigits('SAMW.COP', 2);
        }
        if (!IPS_VariableProfileExists('SAMW.Factor')) {
            IPS_CreateVariableProfile('SAMW.Factor', 2);
            IPS_SetVariableProfileIcon('SAMW.Factor', 'Calculator');
            IPS_SetVariableProfileDigits('SAMW.Factor', 2);
            IPS_SetVariableProfileText('SAMW.Factor', '× ', '');
        }
        if (!IPS_VariableProfileExists('SAMW.Valve3')) {
            IPS_CreateVariableProfile('SAMW.Valve3', 1);
            IPS_SetVariableProfileIcon('SAMW.Valve3', 'Shutter');
            IPS_SetVariableProfileAssociation('SAMW.Valve3', 0, 'Heizkreis', '', -1);
            IPS_SetVariableProfileAssociation('SAMW.Valve3', 1, 'Speicher', '', -1);
        }
        if (!IPS_VariableProfileExists('SAMW.Operation')) {
            IPS_CreateVariableProfile('SAMW.Operation', 1);
            IPS_SetVariableProfileIcon('SAMW.Operation', 'Information');
            IPS_SetVariableProfileAssociation('SAMW.Operation', 0, 'Standby', '', -1);
            IPS_SetVariableProfileAssociation('SAMW.Operation', 1, 'Heizen', '', 0xCC8800);
            IPS_SetVariableProfileAssociation('SAMW.Operation', 2, 'Warmwasser', '', 0x3399CC);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELFER
    // ═══════════════════════════════════════════════════════════════

    private function WriteKNX(string $ident, $value): bool
    {
        $cmd = json_decode($this->ReadAttributeString('CmdMap'), true) ?: [];
        if (!isset($cmd[$ident])) {
            $this->SendDebug('WriteKNX', 'keine Befehls-GA für ' . $ident, 0);
            return false;
        }
        return $this->WriteVarID((int) $cmd[$ident], $value);
    }

    private function WriteVarID(int $vid, $value): bool
    {
        if (!IPS_VariableExists($vid)) {
            return false;
        }
        switch (IPS_GetVariable($vid)['VariableType']) {
            case 0:  $cast = (bool) $value; break;
            case 1:  $cast = (int) round((float) $value); break;
            case 2:  $cast = (float) $value; break;
            default: $cast = (string) $value; break;
        }
        @RequestAction($vid, $cast);
        return true;
    }

    private function Clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    /**
     * Übernimmt einen Messwert nur, wenn er im Fenster von self::RANGE liegt.
     * Fängt den Samsung-Marker 0xFFFF ab, der nach der ×0,1-Skalierung als
     * −0,1 ankommt – ohne die Außentemperatur zu beschneiden, bei der −0,1
     * ein gültiger Wert ist.
     */
    private function SetIfPlausible(string $ident, $value): void
    {
        // DPT 9.001 rechnet krumm (54,480000000000004) – auf eine Stelle runden
        $f = round((float) $value, 1);
        if (!isset(self::RANGE[$ident])) {
            $this->SetValueIfChanged($ident, $f);
            return;
        }
        [$min, $max] = self::RANGE[$ident];
        if ($f >= $min && $f <= $max) {
            $this->SetValueIfChanged($ident, $f);
        } else {
            $this->SendDebug('Plausibilität', sprintf('%s = %.1f verworfen (erlaubt %.1f…%.1f)', $ident, $f, $min, $max), 0);
        }
    }

    private function GetValueSafe(string $ident, $default)
    {
        $id = @$this->GetIDForIdent($ident);
        return $id ? GetValue($id) : $default;
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id && GetValue($id) !== $value) {
            SetValue($id, $value);
        }
    }

    private function toBool($value): bool
    {
        return is_bool($value) ? $value : (((float) $value) != 0.0);
    }

    /**
     * Bool-Register, das nur 0 oder 1 kennt. Ein gestörtes Telegramm liefert
     * den Samsung-Marker 0xFFFF (oder nach MDT-Skalierung −0,1) – über toBool()
     * wäre das ein EIN, das bis zum nächsten gültigen Wert stehen bliebe. Ein
     * unplausibler Wert lässt den bisherigen Zustand darum unangetastet.
     */
    private function SetBoolIfPlausible(string $ident, $value): void
    {
        if (is_bool($value)) {
            $this->SetValueIfChanged($ident, $value);
            return;
        }
        $v = (float) $value;
        if (abs($v) < 0.001) {
            $this->SetValueIfChanged($ident, false);
        } elseif (abs($v - 1.0) < 0.001) {
            $this->SetValueIfChanged($ident, true);
        } else {
            $this->SendDebug('MirrorStatus', $ident . ': ' . $v . ' ist kein 0/1 – verworfen', 0);
        }
    }
}

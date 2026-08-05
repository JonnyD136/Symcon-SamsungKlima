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

        $this->RegisterAttributeString('CmdMap', '{}');
        $this->RegisterAttributeString('StatMap', '{}');
        $this->RegisterAttributeString('WatchedVars', '[]');
        $this->RegisterAttributeInteger('HeatEventID', 0);
        $this->RegisterAttributeInteger('DHWEventID', 0);

        $this->RegisterTimer('PollTimer', 0, 'SAMW_PollStatus($_IPS["TARGET"]);');

        $this->RegisterVariables();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterVariables();
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Discover();
            $this->SetupSchedules();
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

            case 'BoosterDHW':
                $this->SetValueIfChanged('BoosterDHW', $this->toBool($value));
                break;
            case 'BackupHeater':
                $this->SetValueIfChanged('BackupHeater', $this->toBool($value));
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

        $elec = $amp > 0 ? round($amp * $mains + $standby) : 0.0;
        $this->SetValueIfChanged('PowerElec', (float) $elec);

        $this->SetValueIfChanged('COP', ($elec > 100 && $heat > 0) ? round($heat / $elec, 2) : 0.0);

        $this->SetValueIfChanged('Running', $laeuft);

        // Wofür sie läuft, verrät das 3-Wegeventil. Wichtig, weil der COP
        // zwischen Heizen und Warmwasser deutlich auseinanderliegt.
        $ventil = (int) $this->GetValueSafe('ThreeWayValve', 0);
        $this->SetValueIfChanged('Operation', $laeuft
            ? ($ventil === 1 ? self::OP_DHW : self::OP_HEAT)
            : self::OP_STANDBY);
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
}

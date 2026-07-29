<?php

declare(strict_types=1);

/**
 * Samsung Wärmepumpe (EHS Mono R290/R32, MIM-B19N) über MDT SCN-MBGRTU.01 auf KNX.
 *
 * Reiner Lese-/Schreib-Bridge auf die vom MDT auf KNX gelegten Gruppenadressen.
 * Discovery per Haupt-/Mittelgruppe + Sub-Adresse -> Funktion (wie SamsungKlima).
 *
 * GA-Schema (Sub, ungerade=Befehl / gerade=Rückmeldung, ab 13 nur Rückmeldung):
 *   1/2  Power (WP ein/aus)          5.005 (1 Byte)
 *   3/4  Betriebsart (Auto/Heizen…)  5
 *   5/6  Heiz-Soll (Vorlauf o. Raum) 9
 *   7    Raum-/Vorlauf-Ist           9
 *   9/10 Warmwasser ein/aus          5.005
 *   11/12 Warmwasser-Soll            9
 *   13   Warmwasser-/Speicher-Ist    9
 *   14   Außentemperatur             9
 *   15   Vorlauftemperatur           9
 *   16   Rücklauftemperatur          9
 *   17   In Betrieb / Verdichter     1
 *   18   Abtaubetrieb                1
 *   19   Elektrische Leistung        14 (W)
 *   20   Fehlercode                  7
 *   21   Komm-Status                 5
 *   22   Wärmeleistung               14 (W)
 *   23   Energie elektrisch          13/14 (kWh)
 *   24   Energie Wärme               13/14 (kWh)
 *
 * Zeitsteuerung: zwei vom Modul verwaltete Wochenpläne
 *   - "Heizzeiten"      Aktionen 1=Komfort, 2=Absenkung, 3=Aus
 *   - "Warmwasserzeiten" Aktionen 1=Ein, 2=Aus
 * Die Wochenplan-Aktionen rufen SAMW_ApplyHeatState()/SAMW_ApplyDHWState().
 */
class SamsungWaermepumpe extends IPSModule
{
    private const VM_UPDATE     = 10603;
    private const STATUS_OK     = 102;
    private const STATUS_NO_GA  = 201;

    private const CMD_SUB = [
        'Power' => 1, 'Mode' => 3, 'HeatSetpoint' => 5, 'DHWPower' => 9, 'DHWSetpoint' => 11,
    ];
    private const STAT_SUB = [
        'Power' => 2, 'Mode' => 4, 'HeatSetpoint' => 6, 'RoomTemp' => 7,
        'DHWPower' => 10, 'DHWSetpoint' => 12, 'DHWTemp' => 13,
        'OutdoorTemp' => 14, 'FlowTemp' => 15, 'ReturnTemp' => 16,
        'Running' => 17, 'Defrost' => 18, 'PowerElec' => 19, 'Error' => 20,
        'Comm' => 21, 'HeatPower' => 22, 'EnergyElec' => 23, 'EnergyHeat' => 24,
    ];

    // Wochenplan-Aktions-IDs
    private const HEAT_COMFORT = 1;
    private const HEAT_ECO     = 2;
    private const HEAT_OFF     = 3;
    private const DHW_ON        = 1;
    private const DHW_OFF       = 2;

    // ═══════════════════════════════════════════════════════════════
    //  LIFECYCLE
    // ═══════════════════════════════════════════════════════════════

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Hauptgruppe', 4);
        $this->RegisterPropertyInteger('Mittelgruppe', 6);

        $this->RegisterPropertyInteger('HeatSollMin', 15);
        $this->RegisterPropertyInteger('HeatSollMax', 60);
        $this->RegisterPropertyInteger('DHWSollMin', 30);
        $this->RegisterPropertyInteger('DHWSollMax', 70);

        // Zeitsteuerung
        $this->RegisterPropertyBoolean('UseHeatSchedule', false);
        $this->RegisterPropertyBoolean('UseDHWSchedule', false);
        $this->RegisterPropertyFloat('HeatComfort', 22.0);
        $this->RegisterPropertyFloat('HeatEco', 19.0);

        $this->RegisterAttributeString('CmdMap', '{}');
        $this->RegisterAttributeString('StatMap', '{}');
        $this->RegisterAttributeString('WatchedVars', '[]');
        $this->RegisterAttributeInteger('HeatEventID', 0);
        $this->RegisterAttributeInteger('DHWEventID', 0);

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

        $ok = count($statMap) > 0;
        $this->SetStatus($ok ? self::STATUS_OK : self::STATUS_NO_GA);

        if ($ok) {
            foreach ($statMap as $vid => $ident) {
                // Nie empfangene GAs (VariableUpdated=0) nicht seeden
                if ((int) IPS_GetVariable((int) $vid)['VariableUpdated'] <= 0) {
                    continue;
                }
                $this->MirrorStatus($ident, GetValue((int) $vid));
            }
        }
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
            case 'HeatSetpoint':
                $v = $this->Clamp((float) $Value, $this->ReadPropertyInteger('HeatSollMin'), $this->ReadPropertyInteger('HeatSollMax'));
                $this->WriteKNX('HeatSetpoint', $v);
                $this->SetValueIfChanged('HeatSetpoint', $v);
                break;
            case 'DHWPower':
                $this->WriteKNX('DHWPower', (bool) $Value);
                $this->SetValueIfChanged('DHWPower', (bool) $Value);
                break;
            case 'DHWSetpoint':
                $v = $this->Clamp((float) $Value, $this->ReadPropertyInteger('DHWSollMin'), $this->ReadPropertyInteger('DHWSollMax'));
                $this->WriteKNX('DHWSetpoint', $v);
                $this->SetValueIfChanged('DHWSetpoint', $v);
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
        $this->RequestAction('HeatSetpoint', $soll);
    }

    /** Wochenplan-Aktion Warmwasser: 1=Ein, 2=Aus. */
    public function ApplyDHWState(int $State)
    {
        $this->RequestAction('DHWPower', $State === self::DHW_ON);
    }

    /** Legt die beiden Wochenpläne an (falls aktiviert) bzw. entfernt sie wieder. */
    private function SetupSchedules(): void
    {
        $this->EnsureSchedule(
            'HeatEventID',
            $this->ReadPropertyBoolean('UseHeatSchedule'),
            'Heizzeiten',
            [
                [self::HEAT_COMFORT, 'Komfort',    0xFF6600],
                [self::HEAT_ECO,     'Absenkung',  0x3366FF],
                [self::HEAT_OFF,     'Aus',        0x808080],
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
            case 'Power':       $this->SetValueIfChanged('Power', $this->toBool($value)); break;
            case 'Mode':        $this->SetValueIfChanged('Mode', (int) $value); break;
            case 'HeatSetpoint':$this->SetValueIfChanged('HeatSetpoint', (float) $value); break;
            case 'RoomTemp':    $this->SetTempIfValid('RoomTemp', $value); break;
            case 'DHWPower':    $this->SetValueIfChanged('DHWPower', $this->toBool($value)); break;
            case 'DHWSetpoint': $this->SetValueIfChanged('DHWSetpoint', (float) $value); break;
            case 'DHWTemp':     $this->SetTempIfValid('DHWTemp', $value); break;
            case 'OutdoorTemp': $this->SetTempIfValid('OutdoorTemp', $value); break;
            case 'FlowTemp':    $this->SetTempIfValid('FlowTemp', $value); break;
            case 'ReturnTemp':  $this->SetTempIfValid('ReturnTemp', $value); break;
            case 'Running':     $this->SetValueIfChanged('Running', $this->toBool($value)); break;
            case 'Defrost':     $this->SetValueIfChanged('Defrost', $this->toBool($value)); break;
            case 'PowerElec':   $this->SetValueIfChanged('PowerElec', (float) $value); $this->UpdateCOP(); break;
            case 'HeatPower':   $this->SetValueIfChanged('HeatPower', (float) $value); $this->UpdateCOP(); break;
            case 'EnergyElec':  $this->SetValueIfChanged('EnergyElec', (float) $value); break;
            case 'EnergyHeat':  $this->SetValueIfChanged('EnergyHeat', (float) $value); break;
            case 'Error':
                $c = (int) $value;
                $this->SetValueIfChanged('ErrorText', $c === 0 ? 'OK' : ('Fehler ' . $c));
                break;
            case 'Comm':
                $this->SetValueIfChanged('Verbunden', ((int) $value) !== 0);
                break;
        }
    }

    private function UpdateCOP(): void
    {
        $pe = (float) $this->GetValueSafe('PowerElec', 0.0);
        $ph = (float) $this->GetValueSafe('HeatPower', 0.0);
        $this->SetValueIfChanged('COP', $pe > 50 ? round($ph / $pe, 2) : 0.0);
    }

    // ═══════════════════════════════════════════════════════════════
    //  VARIABLEN & PROFILE
    // ═══════════════════════════════════════════════════════════════

    private function RegisterVariables(): void
    {
        $this->EnsureProfiles();
        $p = 0;

        $this->RegisterVariableBoolean('Power', 'Wärmepumpe Ein/Aus', '~Switch', $p += 10);
        $this->EnableAction('Power');
        $this->RegisterVariableInteger('Mode', 'Betriebsart', 'SAMW.Mode', $p += 10);
        $this->EnableAction('Mode');
        $this->RegisterVariableFloat('HeatSetpoint', 'Soll Heizen', 'SAMW.SetHeat', $p += 10);
        $this->EnableAction('HeatSetpoint');
        $this->RegisterVariableFloat('RoomTemp', 'Raum-/Vorlauf-Ist', '~Temperature', $p += 10);

        $this->RegisterVariableBoolean('DHWPower', 'Warmwasser Ein/Aus', '~Switch', $p += 10);
        $this->EnableAction('DHWPower');
        $this->RegisterVariableFloat('DHWSetpoint', 'Warmwasser-Soll', 'SAMW.SetDHW', $p += 10);
        $this->EnableAction('DHWSetpoint');
        $this->RegisterVariableFloat('DHWTemp', 'Warmwasser-Ist', '~Temperature', $p += 10);

        $this->RegisterVariableFloat('OutdoorTemp', 'Außentemperatur', '~Temperature', $p += 10);
        $this->RegisterVariableFloat('FlowTemp', 'Vorlauftemperatur', '~Temperature', $p += 10);
        $this->RegisterVariableFloat('ReturnTemp', 'Rücklauftemperatur', '~Temperature', $p += 10);
        $this->RegisterVariableBoolean('Running', 'In Betrieb', '~Switch', $p += 10);
        $this->RegisterVariableBoolean('Defrost', 'Abtaubetrieb', '~Switch', $p += 10);
        $this->RegisterVariableFloat('PowerElec', 'Elektrische Leistung', '~Watt', $p += 10);
        $this->RegisterVariableFloat('HeatPower', 'Wärmeleistung', '~Watt', $p += 10);
        $this->RegisterVariableFloat('COP', 'Arbeitszahl (COP)', 'SAMW.COP', $p += 10);
        $this->RegisterVariableFloat('EnergyElec', 'Energie elektrisch', '~Electricity', $p += 10);
        $this->RegisterVariableFloat('EnergyHeat', 'Energie Wärme', 'SAMW.Energy', $p += 10);
        $this->RegisterVariableString('ErrorText', 'Fehler', '', $p += 10);
        $this->RegisterVariableBoolean('Verbunden', 'Verbunden', '~Switch', $p += 10);
    }

    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists('SAMW.Mode')) {
            IPS_CreateVariableProfile('SAMW.Mode', 1);
            IPS_SetVariableProfileIcon('SAMW.Mode', 'Climate');
            IPS_SetVariableProfileAssociation('SAMW.Mode', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('SAMW.Mode', 1, 'Kühlen', '', 0x00AAFF);
            IPS_SetVariableProfileAssociation('SAMW.Mode', 4, 'Heizen', '', 0xFF6600);
        }
        if (!IPS_VariableProfileExists('SAMW.SetHeat')) {
            IPS_CreateVariableProfile('SAMW.SetHeat', 2);
            IPS_SetVariableProfileIcon('SAMW.SetHeat', 'Temperature');
            IPS_SetVariableProfileText('SAMW.SetHeat', '', ' °C');
            IPS_SetVariableProfileDigits('SAMW.SetHeat', 1);
            IPS_SetVariableProfileValues('SAMW.SetHeat', 15, 60, 0.5);
        }
        if (!IPS_VariableProfileExists('SAMW.SetDHW')) {
            IPS_CreateVariableProfile('SAMW.SetDHW', 2);
            IPS_SetVariableProfileIcon('SAMW.SetDHW', 'Drops');
            IPS_SetVariableProfileText('SAMW.SetDHW', '', ' °C');
            IPS_SetVariableProfileDigits('SAMW.SetDHW', 0);
            IPS_SetVariableProfileValues('SAMW.SetDHW', 30, 70, 1);
        }
        if (!IPS_VariableProfileExists('SAMW.COP')) {
            IPS_CreateVariableProfile('SAMW.COP', 2);
            IPS_SetVariableProfileIcon('SAMW.COP', 'Graph');
            IPS_SetVariableProfileDigits('SAMW.COP', 2);
        }
        if (!IPS_VariableProfileExists('SAMW.Energy')) {
            IPS_CreateVariableProfile('SAMW.Energy', 2);
            IPS_SetVariableProfileIcon('SAMW.Energy', 'Flame');
            IPS_SetVariableProfileText('SAMW.Energy', '', ' kWh');
            IPS_SetVariableProfileDigits('SAMW.Energy', 1);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELFER
    // ═══════════════════════════════════════════════════════════════

    private function WriteKNX(string $ident, $value): bool
    {
        $cmd = json_decode($this->ReadAttributeString('CmdMap'), true) ?: [];
        if (!isset($cmd[$ident])) {
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

    private function SetTempIfValid(string $ident, $value): void
    {
        $f = (float) $value;
        if ($f >= -60 && $f <= 150) {
            $this->SetValueIfChanged($ident, $f);
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

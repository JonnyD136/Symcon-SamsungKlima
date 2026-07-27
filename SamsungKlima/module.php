<?php

declare(strict_types=1);

/**
 * FACE Samsung Klima – logisches Innengerät je Raum (Samsung FJM → MIM-B19N →
 * MDT SCN-MBGRTU.01 → KNX). Standalone Device (Type 3), Prefix SAMK, kein Parent.
 *
 * Nur Kühlen. Das MDT erledigt die Modbus-Arbeit und legt alles auf KNX-GAs
 * (Hauptgruppe 4); die einzelnen GAs werden per KNX Configurator als
 * "KNX DPT x"-Instanzen angelegt. Dieses Modul findet die zu einem Raum
 * gehörenden Instanzen automatisch (Haupt-/Mittelgruppe), bündelt sie, legt
 * Profile an und kapselt die Steuerlogik inkl. optionalem Kühl-Thermostat.
 *
 * GA-Schema je Raum (Mittelgruppe = IG-Adresse + 1):
 *   1/2 Ein-Aus · 3/4 Betriebsart · 5/6 Lüfter · 7/8 Luftrichtung ·
 *   9/10 Soll · 11/12 Wind-Free · 13 Ist · 14 Komm · 15 Fehler.
 * Ungerade = Befehl, gerade = Rückmeldung.
 *
 * Zusatzfunktionen: globale Kühlfreigabe (Klima Zentrale), Fenster-Sperre,
 * Warm/Kalt-Rückschreiben auf eine KNX-GA für die Tasteranzeige.
 *
 * @author  FACE GmbH
 * @version 0.2
 */
class SamsungKlima extends IPSModule
{
    private const VM_UPDATE = 10603;

    private const STATUS_OK    = 102;
    private const STATUS_NO_GA = 201;

    private const CMD_SUB = [
        'Power' => 1, 'Mode' => 3, 'Fan' => 5, 'Swing' => 7, 'Setpoint' => 9, 'WindFree' => 11,
    ];
    private const STAT_SUB = [
        'Power' => 2, 'Mode' => 4, 'Fan' => 6, 'Swing' => 8, 'Setpoint' => 10, 'WindFree' => 12,
        'RoomTemp' => 13, 'Comm' => 14, 'Error' => 15,
    ];

    private const MODE_COOL = 1;

    private const WINDFREE_ON  = 9;
    private const WINDFREE_OFF = 0;

    // ═══════════════════════════════════════════════════════════════
    //  LIFECYCLE
    // ═══════════════════════════════════════════════════════════════

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Hauptgruppe', 4);
        $this->RegisterPropertyInteger('Mittelgruppe', 0);
        $this->RegisterPropertyString('RoomName', '');

        $this->RegisterPropertyInteger('SollMin', 16);
        $this->RegisterPropertyInteger('SollMax', 30);

        $this->RegisterPropertyBoolean('ThermostatEnabled', false);
        $this->RegisterPropertyInteger('ExtTempVarID', 0);
        $this->RegisterPropertyFloat('Deadband', 1.0);
        $this->RegisterPropertyInteger('ReleaseVarID', 0);
        $this->RegisterPropertyBoolean('TurnOffWhenInactive', true);
        $this->RegisterPropertyInteger('MinToggle', 180);
        $this->RegisterPropertyInteger('RegInterval', 120);

        $this->RegisterPropertyInteger('WindowVarID', 0);
        $this->RegisterPropertyBoolean('WindowInverted', false);

        $this->RegisterPropertyInteger('HeatCoolStatusVarID', 0);
        $this->RegisterPropertyBoolean('HeatCoolInverted', false);
        $this->RegisterPropertyFloat('WarmMargin', 0.3);

        // PV-Überschuss-Vorkühlung (optional, je Raum)
        $this->RegisterPropertyBoolean('PVEnabled', false);
        $this->RegisterPropertyInteger('PVSurplusVarID', 0);
        $this->RegisterPropertyBoolean('PVGridSign', false);
        $this->RegisterPropertyFloat('PVThreshold', 1500.0);   // ~ erwartete Klima-Leistung
        $this->RegisterPropertyFloat('PVHysteresis', 300.0);   // W unter Schwelle für Abschalten
        $this->RegisterPropertyInteger('PVOnDelay', 300);      // s über Schwelle bevor ein
        $this->RegisterPropertyInteger('PVOffDelay', 180);     // s unter Schwelle bevor aus
        $this->RegisterPropertyFloat('PVOffset', 2.0);
        $this->RegisterPropertyFloat('PVMinTemp', 20.0);

        $this->RegisterAttributeString('CmdMap', '{}');
        $this->RegisterAttributeString('StatMap', '{}');
        $this->RegisterAttributeString('WatchedVars', '[]');
        $this->RegisterAttributeInteger('LastToggle', 0);
        $this->RegisterAttributeInteger('LastWarm', -1);
        $this->RegisterAttributeInteger('PVActive', 0);      // 0/1: PV-Vorkühlung aktiv
        $this->RegisterAttributeInteger('PVCandSince', 0);   // seit wann Umschalt-Kandidat

        $this->RegisterTimer('RegTimer', 0, 'SAMK_Regulate($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->RegisterVariables();

        foreach (json_decode($this->ReadAttributeString('WatchedVars'), true) ?: [] as $vid) {
            $this->UnregisterMessage((int) $vid, self::VM_UPDATE);
        }
        $this->WriteAttributeString('WatchedVars', '[]');

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->Discover();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== self::VM_UPDATE) {
            return;
        }

        if ($SenderID === $this->ReadPropertyInteger('ExtTempVarID')) {
            $this->UpdateWarmCold();
            $this->Regulate();
            return;
        }
        if ($SenderID === $this->ReadPropertyInteger('ReleaseVarID')
            || $SenderID === $this->ReadPropertyInteger('WindowVarID')
            || $SenderID === $this->ReadPropertyInteger('PVSurplusVarID')) {
            $this->Regulate();
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

        $watched = array_map('intval', array_keys($statMap));
        foreach ([$this->ReadPropertyInteger('ExtTempVarID'),
                  $this->ReadPropertyInteger('ReleaseVarID'),
                  $this->ReadPropertyInteger('WindowVarID'),
                  $this->ReadPropertyInteger('PVSurplusVarID')] as $extra) {
            if ($extra > 0 && IPS_VariableExists($extra)) {
                $watched[] = $extra;
            }
        }
        $watched = array_values(array_unique($watched));
        foreach ($watched as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
        }
        $this->WriteAttributeString('WatchedVars', json_encode($watched));

        // Timer: für Sicherheits-Check und für die zeitbasierte PV-Zustandsmaschine
        $iv = $this->ReadPropertyInteger('RegInterval');
        $needTimer = $this->ReadPropertyBoolean('ThermostatEnabled')
            && ($iv > 0 || $this->ReadPropertyBoolean('PVEnabled'));
        $sec = $iv > 0 ? $iv : 60;
        $this->SetTimerInterval('RegTimer', $needTimer ? $sec * 1000 : 0);

        $ok = isset($bySub[self::STAT_SUB['Power']]) && isset($bySub[self::STAT_SUB['RoomTemp']]);
        $this->SetStatus($ok ? self::STATUS_OK : self::STATUS_NO_GA);

        if ($ok) {
            foreach ($statMap as $vid => $ident) {
                $this->MirrorStatus($ident, GetValue((int) $vid));
            }
            $this->UpdateWarmCold();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  AKTIONEN
    // ═══════════════════════════════════════════════════════════════

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Power':
                $this->SetPower((bool) $Value);
                break;
            case 'Mode':
                $this->WriteKNX('Mode', (int) $Value);
                $this->SetValueIfChanged('Mode', (int) $Value);
                break;
            case 'Fan':
                $this->WriteKNX('Fan', (int) $Value);
                $this->SetValueIfChanged('Fan', (int) $Value);
                break;
            case 'Swing':
                $this->WriteKNX('Swing', (bool) $Value ? 1 : 0);
                $this->SetValueIfChanged('Swing', (bool) $Value);
                break;
            case 'Setpoint':
                $v = $this->ClampSetpoint((float) $Value);
                $this->WriteKNX('Setpoint', $v);
                $this->SetValueIfChanged('Setpoint', $v);
                $this->UpdateWarmCold();
                $this->Regulate();
                break;
            case 'WindFree':
                $this->WriteKNX('WindFree', (bool) $Value ? self::WINDFREE_ON : self::WINDFREE_OFF);
                $this->SetValueIfChanged('WindFree', (bool) $Value);
                break;
            case 'RegActive':
                $this->SetRegActive((bool) $Value);
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    /** Einschalten: erst Betriebsart (Kühlen) schreiben, dann Ein (FJM-Regel). */
    private function SetPower(bool $on): void
    {
        if ($on) {
            $this->WriteKNX('Mode', self::MODE_COOL);
            $this->SetValueIfChanged('Mode', self::MODE_COOL);
            IPS_Sleep(250);
            $this->WriteKNX('Power', true);
        } else {
            $this->WriteKNX('Power', false);
        }
        $this->SetValueIfChanged('Power', $on);
        $this->WriteAttributeInteger('LastToggle', time());
    }

    private function SetRegActive(bool $on): void
    {
        if ($this->HasRegActive()) {
            $this->SetValueIfChanged('RegActive', $on);
        }
        if ($on) {
            $this->Regulate();
        } elseif ($this->ReadPropertyBoolean('TurnOffWhenInactive')) {
            $this->SetPower(false);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  KÜHL-THERMOSTAT
    // ═══════════════════════════════════════════════════════════════

    public function Regulate()
    {
        if (!$this->ReadPropertyBoolean('ThermostatEnabled')) {
            return;
        }

        // per-Raum-Freigabe
        if ($this->HasRegActive() && !GetValue($this->GetIDForIdent('RegActive'))) {
            return;
        }

        // Sperren, die sofort ausschalten: Fenster offen, keine globale Freigabe
        if ($this->WindowOpen()) {
            $this->EnsureOff();
            return;
        }
        $rel = $this->ReadPropertyInteger('ReleaseVarID');
        if ($rel > 0 && IPS_VariableExists($rel) && !GetValue($rel)) {
            if ($this->ReadPropertyBoolean('TurnOffWhenInactive')) {
                $this->EnsureOff();
            }
            return;
        }

        // nicht auf veralteten Daten regeln
        $vid = @$this->GetIDForIdent('Verbunden');
        if ($vid && !GetValue($vid)) {
            return;
        }

        $ist = $this->CurrentIst();
        if ($ist === null) {
            return;
        }
        $soll = $this->EffectiveSetpoint();
        $half = $this->ReadPropertyFloat('Deadband') / 2.0;

        if (time() - $this->ReadAttributeInteger('LastToggle') < $this->ReadPropertyInteger('MinToggle')) {
            return;
        }

        $powerOn = (bool) $this->GetValueSafe('Power', false);
        // Kühlen: Ein wenn zu warm, Aus wenn ausreichend kühl
        if (!$powerOn && $ist >= $soll + $half) {
            $this->RegulateSwitch(true);
        } elseif ($powerOn && $ist <= $soll - $half) {
            $this->RegulateSwitch(false);
        }
    }

    private function RegulateSwitch(bool $on): void
    {
        if ($on) {
            $this->WriteKNX('Mode', self::MODE_COOL);
            $this->SetValueIfChanged('Mode', self::MODE_COOL);
            IPS_Sleep(250);
            $this->WriteKNX('Power', true);
            $this->SetValueIfChanged('Power', true);
        } else {
            $this->WriteKNX('Power', false);
            $this->SetValueIfChanged('Power', false);
        }
        $this->WriteAttributeInteger('LastToggle', time());
        $this->LogMessage(sprintf('Thermostat: %s (Ist %.1f / Soll %.1f)',
            $on ? 'EIN' : 'AUS', $this->CurrentIst() ?? 0, $this->GetValueSafe('Setpoint', 0)), KL_MESSAGE);
    }

    /** Klima sicher ausschalten (Fenster/Freigabe), ohne Anti-Takt-Sperre. */
    private function EnsureOff(): void
    {
        if ((bool) $this->GetValueSafe('Power', false)) {
            $this->WriteKNX('Power', false);
            $this->SetValueIfChanged('Power', false);
            $this->WriteAttributeInteger('LastToggle', time());
        }
    }

    private function WindowOpen(): bool
    {
        $w = $this->ReadPropertyInteger('WindowVarID');
        if ($w <= 0 || !IPS_VariableExists($w)) {
            return false;
        }
        $open = (bool) GetValue($w);
        return $this->ReadPropertyBoolean('WindowInverted') ? !$open : $open;
    }

    /** Kühl-Sollwert inkl. PV-Überschuss-Absenkung (Vorkühlen). */
    private function EffectiveSetpoint(): float
    {
        $soll = (float) $this->GetValueSafe('Setpoint', 24.0);
        if ($this->PVActive()) {
            $soll = max($this->ReadPropertyFloat('PVMinTemp'),
                        $soll - $this->ReadPropertyFloat('PVOffset'));
        }
        return $soll;
    }

    /**
     * PV-Vorkühlung aktiv? Verlangt ausreichenden UND anhaltenden Überschuss:
     * Ein erst, wenn Überschuss >= Schwelle über PVOnDelay; Aus erst, wenn
     * Überschuss < Schwelle-Hysterese über PVOffDelay. Schwelle = erwartete
     * Klima-Leistung → es wird nur gekühlt, wenn der Überschuss sie deckt.
     */
    private function PVActive(): bool
    {
        if (!$this->ReadPropertyBoolean('PVEnabled')) {
            return false;
        }
        $pv = $this->ReadPropertyInteger('PVSurplusVarID');
        if ($pv <= 0 || !IPS_VariableExists($pv)) {
            $this->WriteAttributeInteger('PVActive', 0);
            $this->WriteAttributeInteger('PVCandSince', 0);
            return false;
        }
        $raw = (float) GetValue($pv);
        $surplus = $this->ReadPropertyBoolean('PVGridSign') ? -$raw : $raw;

        $thr   = $this->ReadPropertyFloat('PVThreshold');
        $hys   = $this->ReadPropertyFloat('PVHysteresis');
        $onD   = $this->ReadPropertyInteger('PVOnDelay');
        $offD  = $this->ReadPropertyInteger('PVOffDelay');
        $cur   = $this->ReadAttributeInteger('PVActive') === 1;
        $since = $this->ReadAttributeInteger('PVCandSince');
        $now   = time();

        if (!$cur) {
            if ($surplus >= $thr) {
                if ($since === 0) {
                    $this->WriteAttributeInteger('PVCandSince', $now);
                    $since = $now;
                }
                if ($now - $since >= $onD) {
                    $this->WriteAttributeInteger('PVActive', 1);
                    $this->WriteAttributeInteger('PVCandSince', 0);
                    return true;
                }
            } elseif ($since !== 0) {
                $this->WriteAttributeInteger('PVCandSince', 0);
            }
            return false;
        }

        // aktuell aktiv → Abschalt-Kandidat wenn Überschuss unter Schwelle-Hysterese
        if ($surplus < $thr - $hys) {
            if ($since === 0) {
                $this->WriteAttributeInteger('PVCandSince', $now);
                $since = $now;
            }
            if ($now - $since >= $offD) {
                $this->WriteAttributeInteger('PVActive', 0);
                $this->WriteAttributeInteger('PVCandSince', 0);
                return false;
            }
        } elseif ($since !== 0) {
            $this->WriteAttributeInteger('PVCandSince', 0);
        }
        return true;
    }

    private function CurrentIst(): ?float
    {
        $ext = $this->ReadPropertyInteger('ExtTempVarID');
        if ($ext > 0 && IPS_VariableExists($ext)) {
            $v = (float) GetValue($ext);
        } else {
            $id = @$this->GetIDForIdent('RoomTemp');
            if (!$id) {
                return null;
            }
            $v = (float) GetValue($id);
        }
        return ($v < -50 || $v > 80) ? null : $v;
    }

    // ═══════════════════════════════════════════════════════════════
    //  WARM/KALT-ANZEIGE (Rückschreiben auf KNX-GA)
    // ═══════════════════════════════════════════════════════════════

    private function UpdateWarmCold(): void
    {
        $h = $this->ReadPropertyInteger('HeatCoolStatusVarID');
        if ($h <= 0 || !IPS_VariableExists($h)) {
            return;
        }
        $ist = $this->CurrentIst();
        if ($ist === null) {
            return;
        }
        $soll = (float) $this->GetValueSafe('Setpoint', 24.0);
        $margin = $this->ReadPropertyFloat('WarmMargin');

        $last = $this->ReadAttributeInteger('LastWarm'); // -1 unbekannt, 0 kalt, 1 warm
        if ($ist >= $soll + $margin) {
            $warm = 1;
        } elseif ($ist <= $soll - $margin) {
            $warm = 0;
        } else {
            $warm = $last; // in der Totzone: nicht flattern
        }
        if ($warm === -1 || $warm === $last) {
            if ($warm === $last) {
                return;
            }
        }
        $this->WriteAttributeInteger('LastWarm', $warm);

        $out = ($warm === 1);
        if ($this->ReadPropertyBoolean('HeatCoolInverted')) {
            $out = !$out;
        }
        $this->WriteVarID($h, $out);
    }

    // ═══════════════════════════════════════════════════════════════
    //  STATUS-SPIEGELUNG
    // ═══════════════════════════════════════════════════════════════

    private function MirrorStatus(string $ident, $value): void
    {
        switch ($ident) {
            case 'Power':
                $this->SetValueIfChanged('Power', $this->toBool($value));
                $this->Regulate();
                break;
            case 'Mode':
                $this->SetValueIfChanged('Mode', (int) $value);
                break;
            case 'Fan':
                $this->SetValueIfChanged('Fan', (int) $value);
                break;
            case 'Swing':
                $this->SetValueIfChanged('Swing', $this->toBool($value));
                break;
            case 'Setpoint':
                $this->SetValueIfChanged('Setpoint', (float) $value);
                $this->UpdateWarmCold();
                break;
            case 'WindFree':
                $this->SetValueIfChanged('WindFree', ((int) $value) === self::WINDFREE_ON);
                break;
            case 'RoomTemp':
                $f = (float) $value;
                if ($f >= -50 && $f <= 80) {
                    $this->SetValueIfChanged('RoomTemp', $f);
                    // externe Ist-Quelle hat Vorrang – nur regeln/anzeigen, wenn keine externe da
                    if ($this->ReadPropertyInteger('ExtTempVarID') <= 0) {
                        $this->UpdateWarmCold();
                        $this->Regulate();
                    }
                }
                break;
            case 'Comm':
                $this->SetValueIfChanged('Verbunden', ((int) $value & 0x07) === 0x07);
                break;
            case 'Error':
                $this->SetValueIfChanged('ErrorText', $this->ErrorText((int) $value));
                break;
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
            case 0: $cast = (bool) $value; break;
            case 1: $cast = (int) round((float) $value); break;
            case 2: $cast = (float) $value; break;
            default: $cast = (string) $value; break;
        }
        @RequestAction($vid, $cast);
        return true;
    }

    private function ClampSetpoint(float $v): float
    {
        return max((float) $this->ReadPropertyInteger('SollMin'),
                   min((float) $this->ReadPropertyInteger('SollMax'), $v));
    }

    private function HasRegActive(): bool
    {
        return $this->ReadPropertyBoolean('ThermostatEnabled') && @$this->GetIDForIdent('RegActive') > 0;
    }

    private function GetValueSafe(string $ident, $default)
    {
        $id = @$this->GetIDForIdent($ident);
        return $id ? GetValue($id) : $default;
    }

    private function toBool($value): bool
    {
        return is_bool($value) ? $value : (((float) $value) != 0.0);
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id && GetValue($id) !== $value) {
            SetValue($id, $value);
        }
    }

    private function ErrorText(int $code): string
    {
        if ($code === 0) {
            return 'OK';
        }
        static $map = [
            101 => 'Kommunikationsfehler Innen-/Außengerät',
            121 => 'Fehler Raumtemperatursensor',
            122 => 'Fehler Wärmetauscher-Sensor (Einlass)',
            123 => 'Fehler Wärmetauscher-Sensor (Auslass)',
            152 => 'EEV-Fehler',
            154 => 'Fehler Lüftermotor Innengerät',
            201 => 'Kommunikationsfehler (Adressierung)',
            202 => 'Kommunikationsfehler Außengerät',
            416 => 'Kompressor-Überhitzung / Notabschaltung',
            458 => 'Fehler Außenlüfter',
            462 => 'Kompressor-Stromfehler',
        ];
        return $map[$code] ?? ('Fehler E' . $code);
    }

    // ═══════════════════════════════════════════════════════════════
    //  VARIABLEN & PROFILE
    // ═══════════════════════════════════════════════════════════════

    private function RegisterVariables(): void
    {
        $this->RegisterVariableBoolean('Power', 'Ein/Aus', '~Switch', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableInteger('Mode', 'Betriebsart', 'SAMK.Mode', 20);
        $this->EnableAction('Mode');

        $this->RegisterVariableFloat('Setpoint', 'Solltemperatur', 'SAMK.Setpoint', 30);
        $this->EnableAction('Setpoint');

        $this->RegisterVariableFloat('RoomTemp', 'Isttemperatur', '~Temperature', 40);

        $this->RegisterVariableInteger('Fan', 'Lüfterstufe', 'SAMK.Fan', 50);
        $this->EnableAction('Fan');

        $this->RegisterVariableBoolean('Swing', 'Luftrichtung (Swing)', '~Switch', 60);
        $this->EnableAction('Swing');

        $this->RegisterVariableBoolean('WindFree', 'Wind-Free', '~Switch', 70);
        $this->EnableAction('WindFree');

        if ($this->ReadPropertyBoolean('ThermostatEnabled')) {
            $this->RegisterVariableBoolean('RegActive', 'Regelung aktiv', '~Switch', 80);
            $this->EnableAction('RegActive');
        } elseif (@$this->GetIDForIdent('RegActive')) {
            $this->UnregisterVariable('RegActive');
        }

        $this->RegisterVariableBoolean('Verbunden', 'Verbunden', '~Switch', 90);
        $this->RegisterVariableString('ErrorText', 'Fehler', '', 100);
    }

    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists('SAMK.Mode')) {
            IPS_CreateVariableProfile('SAMK.Mode', 1);
            IPS_SetVariableProfileIcon('SAMK.Mode', 'Climate');
            IPS_SetVariableProfileAssociation('SAMK.Mode', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('SAMK.Mode', 1, 'Kühlen', '', 0x00AAFF);
            IPS_SetVariableProfileAssociation('SAMK.Mode', 2, 'Entfeuchten', '', 0x00CC99);
            IPS_SetVariableProfileAssociation('SAMK.Mode', 3, 'Lüften', '', 0xAAAAAA);
            IPS_SetVariableProfileAssociation('SAMK.Mode', 4, 'Heizen', '', 0xFF6600);
        }
        if (!IPS_VariableProfileExists('SAMK.Fan')) {
            IPS_CreateVariableProfile('SAMK.Fan', 1);
            IPS_SetVariableProfileIcon('SAMK.Fan', 'Ventilation');
            IPS_SetVariableProfileAssociation('SAMK.Fan', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('SAMK.Fan', 1, 'Niedrig', '', -1);
            IPS_SetVariableProfileAssociation('SAMK.Fan', 2, 'Mittel', '', -1);
            IPS_SetVariableProfileAssociation('SAMK.Fan', 3, 'Hoch', '', -1);
        }
        if (!IPS_VariableProfileExists('SAMK.Setpoint')) {
            IPS_CreateVariableProfile('SAMK.Setpoint', 2);
            IPS_SetVariableProfileIcon('SAMK.Setpoint', 'Temperature');
            IPS_SetVariableProfileText('SAMK.Setpoint', '', ' °C');
            IPS_SetVariableProfileDigits('SAMK.Setpoint', 1);
            IPS_SetVariableProfileValues('SAMK.Setpoint', 16, 30, 0.5);
        }
    }
}

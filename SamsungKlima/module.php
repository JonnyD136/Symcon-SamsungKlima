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

    // Regelmodus
    private const CTRL_TWOPOINT = 0;   // Ein/Aus mit Totzone
    private const CTRL_SETPOINT = 1;   // Sollwert-Folgen (Inverter moduliert)

    private const WINDFREE_ON  = 9;
    private const WINDFREE_OFF = 0;

    // Nach einem eigenen Power-Befehl wird der KNX-Status für diese Zeit nicht
    // gegen den Befehl gewertet (verzögerte/sporadische Rückmeldung → Anzeige-Flattern).
    private const PENDING_SETTLE = 120;

    // ═══════════════════════════════════════════════════════════════
    //  LIFECYCLE
    // ═══════════════════════════════════════════════════════════════

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Hauptgruppe', 4);
        $this->RegisterPropertyInteger('Mittelgruppe', 0);
        $this->RegisterPropertyString('RoomName', '');

        $this->RegisterPropertyInteger('SollMin', 18);
        $this->RegisterPropertyInteger('SollMax', 30);

        $this->RegisterPropertyInteger('ExtTempVarID', 0);
        $this->RegisterPropertyInteger('ExtSetpointVarID', 0);
        $this->RegisterPropertyInteger('ControlMode', self::CTRL_SETPOINT);
        $this->RegisterPropertyFloat('Deadband', 1.0);
        $this->RegisterPropertyFloat('OffReserve', 1.0);
        $this->RegisterPropertyBoolean('BiasEnabled', false);
        $this->RegisterPropertyFloat('BiasKi', 0.05);
        $this->RegisterPropertyFloat('BiasLimit', 3.0);

        // Start-Einstellungen, die die Regelung beim Einschalten setzt
        $this->RegisterPropertyBoolean('StartApply', true);
        $this->RegisterPropertyInteger('StartFan', 0);        // 0 Auto
        $this->RegisterPropertyBoolean('StartSwing', false);
        $this->RegisterPropertyBoolean('StartWindFree', false);
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
        $this->RegisterAttributeInteger('LastWindowOff', 0);
        $this->RegisterAttributeFloat('Bias', 0.0);
        $this->RegisterAttributeInteger('BiasLast', 0);
        $this->RegisterAttributeFloat('LastSollWritten', -999.0);
        $this->RegisterAttributeInteger('PVActive', 0);      // 0/1: PV-Vorkühlung aktiv
        $this->RegisterAttributeInteger('PVCandSince', 0);   // seit wann Umschalt-Kandidat
        $this->RegisterAttributeInteger('PendingPower', -1); // -1 keiner, 0/1 erwarteter Power-Status
        $this->RegisterAttributeInteger('PendingUntil', 0);  // Settle-Fenster: Status ignorieren bis
        $this->RegisterAttributeInteger('AutoArmed', 1);     // 1=scharf für Auto-EIN, 0=entschärft (Hand-Aus)

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

        if ($SenderID === $this->ReadPropertyInteger('ExtTempVarID')
            || $SenderID === $this->ReadPropertyInteger('ExtSetpointVarID')) {
            $this->UpdateWarmCold();
            $this->Regulate();
            return;
        }
        if ($SenderID === $this->ReadPropertyInteger('WindowVarID')) {
            $this->WindowGuard();
            return;
        }
        if ($SenderID === $this->ReadPropertyInteger('ReleaseVarID')
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
                  $this->ReadPropertyInteger('ExtSetpointVarID'),
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

        // Timer: für Sicherheits-Check und die zeitbasierte PV-Zustandsmaschine
        $this->UpdateTimer();

        $ok = isset($bySub[self::STAT_SUB['Power']]) && isset($bySub[self::STAT_SUB['RoomTemp']]);
        $this->SetStatus($ok ? self::STATUS_OK : self::STATUS_NO_GA);

        if ($ok) {
            foreach ($statMap as $vid => $ident) {
                $this->MirrorStatus($ident, GetValue((int) $vid));
            }
            $this->UpdateWarmCold();
        }

        // Falls ein Fenster bereits offen ist, wenn das Modul (neu) startet
        $this->WindowGuard();
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
                $this->SetValueIfChanged('Setpoint', $v);
                $this->UpdateWarmCold();
                // Frisches Trim-Fenster: verhindert, dass der aufgelaufene Bias
                // deinen neuen Soll im nächsten Zyklus sofort wieder verbiegt.
                $this->WriteAttributeInteger('BiasLast', time());
                if ($this->SetpointManagedByReg()) {
                    // Sollwert-Folgen aktiv → Regulate() schreibt den (Bias-korrigierten)
                    // Geräte-Soll als EINZIGEN Schreibvorgang (kein Doppelschreiben mehr).
                    $this->Regulate();
                } else {
                    // Zweipunkt / Handbetrieb: dein Soll geht 1:1 an die Anlage.
                    $this->WriteSamsungSoll($v);
                    $this->Regulate();   // nur die Ein/Aus-Schaltentscheidung
                }
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
        if ($on && $this->WindowOpen()) {
            // Fenster offen → Einschalten blockieren, sicher aus
            $this->WriteKNX('Power', false);
            $this->SetValueIfChanged('Power', false);
            $this->MarkPowerPending(false);
            return;
        }
        if ($on) {
            $this->WriteKNX('Mode', self::MODE_COOL);
            $this->SetValueIfChanged('Mode', self::MODE_COOL);
            IPS_Sleep(250);
            $this->WriteKNX('Power', true);
        } else {
            $this->WriteKNX('Power', false);
        }
        $this->SetValueIfChanged('Power', $on);
        $this->MarkPowerPending($on);
        $this->WriteAttributeInteger('LastToggle', time());
    }

    private function SetRegActive(bool $on): void
    {
        $this->SetValueIfChanged('RegActive', $on);
        $this->UpdateTimer();
        if ($on) {
            $this->WriteAttributeInteger('AutoArmed', 1); // Regelung an → scharf für Auto-EIN
            $this->Regulate();
        } elseif ($this->ReadPropertyBoolean('TurnOffWhenInactive')) {
            $this->SetPower(false);
        }
    }

    /**
     * Führt die Regelung den Geräte-Soll aktiv nach? Nur im Sollwert-Folgen-Modus
     * bei aktiver Regelung. Dann übernimmt Regulate() das (Bias-korrigierte)
     * Schreiben; sonst geht dein Soll 1:1 an die Anlage.
     */
    private function SetpointManagedByReg(): bool
    {
        return (bool) $this->GetValueSafe('RegActive', false)
            && $this->ReadPropertyInteger('ControlMode') === self::CTRL_SETPOINT;
    }

    /** Merkt einen gerade gesendeten Power-Befehl (Settle-Fenster gegen Status-Flattern). */
    private function MarkPowerPending(bool $on): void
    {
        $this->WriteAttributeInteger('PendingPower', $on ? 1 : 0);
        $this->WriteAttributeInteger('PendingUntil', time() + self::PENDING_SETTLE);
    }

    // ═══════════════════════════════════════════════════════════════
    //  KÜHL-THERMOSTAT
    // ═══════════════════════════════════════════════════════════════

    public function Regulate()
    {
        // Master-Schalter: Regelung nur, wenn die Variable "Regelung aktiv" an ist
        if (!(bool) $this->GetValueSafe('RegActive', false)) {
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
        $target  = $this->EffectiveSetpoint();                 // Wunsch-Soll inkl. PV-Absenkung
        $half    = $this->ReadPropertyFloat('Deadband') / 2.0;
        $powerOn = (bool) $this->GetValueSafe('Power', false);
        $canToggle = (time() - $this->ReadAttributeInteger('LastToggle'))
            >= $this->ReadPropertyInteger('MinToggle');

        if ($this->ReadPropertyInteger('ControlMode') === self::CTRL_SETPOINT) {
            // Regelung an → die Anlage BLEIBT an und der Inverter moduliert über den
            // Sollwert. Kein temperaturbedingtes Abschalten. Aus geschieht nur über
            // die Sperren oben (Fenster/Freigabe), RegActive-Aus oder Handbetrieb.
            // Wiedereinschalten aus dem Aus erst, wenn Ist >= Soll + Deadband (1 K).
            $restart = $this->ReadPropertyFloat('Deadband');
            if ($powerOn) {
                // läuft: Soll nachführen + Sensor-Offset langsam trimmen (kein Takten)
                $this->WriteAttributeInteger('AutoArmed', 0);
                $this->UpdateBias($ist);
                $this->WriteSamsungSoll($this->ComputeSamsungSoll($target));
            } elseif ($ist < $target + $restart) {
                // unter der Einschaltschwelle → für die nächste Überschreitung scharf
                // stellen (ein Hand-Aus über der Schwelle bleibt dagegen entschärft).
                $this->WriteAttributeInteger('AutoArmed', 1);
            } elseif ((bool) $this->ReadAttributeInteger('AutoArmed') && $canToggle) {
                // steigende Flanke über Soll+1 K UND scharf → wieder einschalten
                $this->WriteAttributeInteger('BiasLast', time());
                $this->WriteSamsungSoll($this->ComputeSamsungSoll($target));
                $this->RegulateSwitch(true);
                $this->WriteAttributeInteger('AutoArmed', 0);
            }
        } else {
            // Zweipunkt: Kompressor per Ein/Aus mit Totzone
            if ($canToggle) {
                if (!$powerOn && $ist >= $target + $half) {
                    $this->RegulateSwitch(true);
                } elseif ($powerOn && $ist <= $target - $half) {
                    $this->RegulateSwitch(false);
                }
            }
        }
    }

    /**
     * Samsung-Sollwert: Ziel − Bias. Der Bias wirkt (in Kühlung) nur NACH UNTEN
     * (kälter = mehr kühlen); der Geräte-Soll wird hart auf den Regelungs-Soll
     * gedeckelt und darf nie darüber liegen. Auf Geräte-Grenzen geklammert, 0,5-K-Raster.
     */
    private function ComputeSamsungSoll(float $target): float
    {
        $v = $target;
        if ($this->ReadPropertyBoolean('BiasEnabled')) {
            $v -= $this->ReadAttributeFloat('Bias');   // Bias >= 0 → nur absenken
        }
        $v = min($v, $target);                          // nie über dem Regelungs-Soll
        $min = (float) $this->ReadPropertyInteger('SollMin');
        $max = (float) $this->ReadPropertyInteger('SollMax');
        $v = max($min, min($max, $v));
        return round($v * 2) / 2;
    }

    /** Samsung-Sollwert nur bei Änderung schreiben (jeder Write = Steuerbefehl). */
    private function WriteSamsungSoll(float $ss): void
    {
        if (abs($ss - $this->ReadAttributeFloat('LastSollWritten')) < 0.25) {
            return;
        }
        $this->WriteKNX('Setpoint', $ss);
        $this->WriteAttributeFloat('LastSollWritten', $ss);
    }

    /** Langsames I-Trim: treibt die Wandtemperatur auf den Wand-Soll (Sensor-Offset). */
    private function UpdateBias(float $ist): void
    {
        if (!$this->ReadPropertyBoolean('BiasEnabled')) {
            return;
        }
        $now  = time();
        $last = $this->ReadAttributeInteger('BiasLast');
        if ($last <= 0) {
            $this->WriteAttributeInteger('BiasLast', $now);
            return;
        }
        $dtMin = ($now - $last) / 60.0;
        if ($dtMin <= 0) {
            return;
        }
        $err  = $ist - $this->CurrentSoll();   // >0 = zu warm → Samsung-Soll absenken
        $bias = $this->ReadAttributeFloat('Bias') + $this->ReadPropertyFloat('BiasKi') * $err * $dtMin;
        $lim  = $this->ReadPropertyFloat('BiasLimit');
        // Bias nur nach unten (kälter als Ziel): [0, Limit] – nie über den Regelungs-Soll.
        $bias = max(0.0, min($lim, $bias));
        $this->WriteAttributeFloat('Bias', $bias);
        $this->WriteAttributeInteger('BiasLast', $now);
    }

    private function RegulateSwitch(bool $on): void
    {
        if ($on) {
            $this->WriteKNX('Mode', self::MODE_COOL);
            $this->SetValueIfChanged('Mode', self::MODE_COOL);
            $this->ApplyStartSettings();
            IPS_Sleep(250);
            $this->WriteKNX('Power', true);
            $this->SetValueIfChanged('Power', true);
        } else {
            $this->WriteKNX('Power', false);
            $this->SetValueIfChanged('Power', false);
            $this->WriteAttributeFloat('LastSollWritten', -999.0);
        }
        $this->MarkPowerPending($on);
        $this->WriteAttributeInteger('LastToggle', time());
        $this->LogMessage(sprintf('Thermostat: %s (Ist %.1f / Soll %.1f)',
            $on ? 'EIN' : 'AUS', $this->CurrentIst() ?? 0, $this->CurrentSoll()), KL_MESSAGE);
    }

    /** Vom Regler beim Einschalten gewünschte Start-Einstellungen setzen. */
    private function ApplyStartSettings(): void
    {
        if (!$this->ReadPropertyBoolean('StartApply')) {
            return;
        }
        $fan = $this->ReadPropertyInteger('StartFan');
        $this->WriteKNX('Fan', $fan);
        $this->SetValueIfChanged('Fan', $fan);

        $swing = $this->ReadPropertyBoolean('StartSwing');
        $this->WriteKNX('Swing', $swing ? 1 : 0);
        $this->SetValueIfChanged('Swing', $swing);

        $wf = $this->ReadPropertyBoolean('StartWindFree');
        $this->WriteKNX('WindFree', $wf ? self::WINDFREE_ON : self::WINDFREE_OFF);
        $this->SetValueIfChanged('WindFree', $wf);
    }

    /** Klima sicher ausschalten (Fenster/Freigabe), ohne Anti-Takt-Sperre. */
    private function EnsureOff(): void
    {
        if ((bool) $this->GetValueSafe('Power', false)) {
            $this->WriteKNX('Power', false);
            $this->SetValueIfChanged('Power', false);
            $this->MarkPowerPending(false);
            $this->WriteAttributeInteger('AutoArmed', 1); // Sperre-Aus: nach Freigabe wieder aufnehmen
            $this->WriteAttributeInteger('LastToggle', time());
            $this->WriteAttributeFloat('LastSollWritten', -999.0);
        }
    }

    /**
     * Fenster-Wächter – unabhängig vom Thermostat. Fenster offen → Klima aus.
     * Fenster zu → im Thermostatbetrieb Regelung wieder aufnehmen (im Handbetrieb
     * bleibt die Klima aus, bis der Nutzer sie wieder einschaltet).
     */
    public function WindowGuard()
    {
        if ($this->WindowOpen()) {
            // Unbedingt AUS – auch wenn der interne Power-Status (noch) falsch/veraltet
            // ist. Wiederhol-Sperre (30 s) gegen Bus-Spam bei zyklischen Kontakt-Telegrammen.
            $now = time();
            if ((bool) $this->GetValueSafe('Power', false)
                || ($now - $this->ReadAttributeInteger('LastWindowOff')) > 30) {
                $this->WriteKNX('Power', false);
                $this->SetValueIfChanged('Power', false);
                $this->MarkPowerPending(false);
                $this->WriteAttributeInteger('AutoArmed', 1); // Fenster-Aus: nach Schließen wieder aufnehmen
                $this->WriteAttributeInteger('LastWindowOff', $now);
                $this->WriteAttributeInteger('LastToggle', $now);
            }
        } else {
            $this->Regulate();
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
        $soll = $this->CurrentSoll();
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

    /** Ziel-Soll: Soll vom KNX-Wandthermostat (falls gewählt), sonst Modul-Sollwert. */
    private function CurrentSoll(): float
    {
        $ext = $this->ReadPropertyInteger('ExtSetpointVarID');
        if ($ext > 0 && IPS_VariableExists($ext)) {
            $v = (float) GetValue($ext);
            if ($v >= 5 && $v <= 40) {
                return $v;
            }
        }
        return (float) $this->GetValueSafe('Setpoint', 24.0);
    }

    /** Regel-Timer je nach "Regelung aktiv" + Intervall/PV setzen. */
    private function UpdateTimer(): void
    {
        $iv  = $this->ReadPropertyInteger('RegInterval');
        $reg = (bool) $this->GetValueSafe('RegActive', false);
        $need = $reg && ($iv > 0 || $this->ReadPropertyBoolean('PVEnabled'));
        $sec = $iv > 0 ? $iv : 60;
        $this->SetTimerInterval('RegTimer', $need ? $sec * 1000 : 0);
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
        $soll = $this->CurrentSoll();
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
                $b = $this->toBool($value);
                $pp = $this->ReadAttributeInteger('PendingPower');
                if ($pp !== -1 && time() < $this->ReadAttributeInteger('PendingUntil')) {
                    if ($b === ($pp === 1)) {
                        $this->WriteAttributeInteger('PendingPower', -1); // Befehl bestätigt
                    } else {
                        break; // verspätete Gegenmeldung im Settle-Fenster → Anzeige NICHT umwerfen
                    }
                }
                $this->SetValueIfChanged('Power', $b);
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
        return @$this->GetIDForIdent('RegActive') > 0;
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

        $this->RegisterVariableBoolean('RegActive', 'Regelung aktiv', '~Switch', 80);
        $this->EnableAction('RegActive');

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

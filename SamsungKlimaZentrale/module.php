<?php

declare(strict_types=1);

/**
 * FACE Samsung Klima – Zentrale. Standalone Device (Type 3), Prefix SAMKZ.
 *
 * Stellt eine globale Kühlfreigabe für alle Raum-Instanzen bereit (Variable
 * "Freigabe"). Da die Samsung-FJM sich einen Kältekreis teilt, ist der Modus
 * global. Modus: Aus / Ein (manuell) / Auto (über Aussentemperatur mit
 * Hysterese). Optional Anwesenheits-Sperre.
 *
 * @author  FACE GmbH
 * @version 0.1
 */
class SamsungKlimaZentrale extends IPSModule
{
    private const VM_UPDATE = 10603;

    private const MODE_OFF  = 0;
    private const MODE_ON   = 1;
    private const MODE_AUTO = 2;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('OutTempVarID', 0);
        $this->RegisterPropertyFloat('OutThreshold', 22.0);
        $this->RegisterPropertyFloat('OutHysteresis', 1.0);

        $this->RegisterPropertyInteger('PresenceVarID', 0);
        $this->RegisterPropertyBoolean('PresenceInverted', false);
        $this->RegisterPropertyBoolean('BlockWhenAway', true);

        $this->RegisterPropertyInteger('CheckInterval', 300);

        $this->RegisterAttributeString('WatchedVars', '[]');

        $this->RegisterTimer('CheckTimer', 0, 'SAMKZ_Evaluate($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();

        $this->RegisterVariableInteger('Modus', 'Modus', 'SAMKZ.Mode', 10);
        $this->EnableAction('Modus');
        $this->RegisterVariableBoolean('Freigabe', 'Freigabe', '~Switch', 20);

        foreach (json_decode($this->ReadAttributeString('WatchedVars'), true) ?: [] as $vid) {
            $this->UnregisterMessage((int) $vid, self::VM_UPDATE);
        }
        $watched = [];
        foreach ([$this->ReadPropertyInteger('OutTempVarID'), $this->ReadPropertyInteger('PresenceVarID')] as $vid) {
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, self::VM_UPDATE);
                $watched[] = $vid;
            }
        }
        $this->WriteAttributeString('WatchedVars', json_encode($watched));

        $iv = $this->ReadPropertyInteger('CheckInterval');
        $this->SetTimerInterval('CheckTimer', $iv > 0 ? $iv * 1000 : 0);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Evaluate();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === self::VM_UPDATE) {
            $this->Evaluate();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Modus') {
            SetValue($this->GetIDForIdent('Modus'), (int) $Value);
            $this->Evaluate();
            return;
        }
        throw new Exception('Unbekannte Aktion: ' . $Ident);
    }

    public function Evaluate()
    {
        $mode = (int) GetValue($this->GetIDForIdent('Modus'));
        $present = $this->IsPresent();

        switch ($mode) {
            case self::MODE_OFF:
                $rel = false;
                break;
            case self::MODE_ON:
                $rel = $present;
                break;
            case self::MODE_AUTO:
            default:
                $rel = $this->AutoTemp() && $present;
                break;
        }

        $id = $this->GetIDForIdent('Freigabe');
        if (GetValue($id) !== $rel) {
            SetValue($id, $rel);
        }
    }

    /** Anwesend? (Sperre nur wenn konfiguriert). */
    private function IsPresent(): bool
    {
        if (!$this->ReadPropertyBoolean('BlockWhenAway')) {
            return true;
        }
        $p = $this->ReadPropertyInteger('PresenceVarID');
        if ($p <= 0 || !IPS_VariableExists($p)) {
            return true;
        }
        $present = (bool) GetValue($p);
        return $this->ReadPropertyBoolean('PresenceInverted') ? !$present : $present;
    }

    /** Aussentemperatur-Automatik mit Hysterese um die aktuelle Freigabe. */
    private function AutoTemp(): bool
    {
        $v = $this->ReadPropertyInteger('OutTempVarID');
        if ($v <= 0 || !IPS_VariableExists($v)) {
            return true; // ohne Sensor keine Sperre
        }
        $out = (float) GetValue($v);
        $thr = $this->ReadPropertyFloat('OutThreshold');
        $hys = $this->ReadPropertyFloat('OutHysteresis');
        $cur = (bool) GetValue($this->GetIDForIdent('Freigabe'));

        if ($out >= $thr + $hys) {
            return true;
        }
        if ($out <= $thr - $hys) {
            return false;
        }
        return $cur;
    }

    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists('SAMKZ.Mode')) {
            IPS_CreateVariableProfile('SAMKZ.Mode', 1);
            IPS_SetVariableProfileIcon('SAMKZ.Mode', 'Climate');
            IPS_SetVariableProfileAssociation('SAMKZ.Mode', 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('SAMKZ.Mode', 1, 'Ein', '', 0x00AAFF);
            IPS_SetVariableProfileAssociation('SAMKZ.Mode', 2, 'Auto', '', 0x33CC33);
        }
    }
}

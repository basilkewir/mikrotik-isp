<?php

namespace App\Enums;

/**
 * Typology of every device that can appear in a fixed/wireless ISP network.
 */
enum DeviceType: string
{
    case Cpe = 'cpe';
    case HotspotClient = 'hotspot_client';
    case BridgeClient = 'bridge_client';
    case AccessPoint = 'access_point';
    case Router = 'router';
    case Switch = 'switch';
    case Onu = 'onu';
    case Sector = 'sector';
    case Backhaul = 'backhaul';
    case Iot = 'iot';
    case Server = 'server';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Cpe => 'Customer CPE',
            self::HotspotClient => 'Hotspot Client',
            self::BridgeClient => 'Bridge Client',
            self::AccessPoint => 'Access Point',
            self::Router => 'Router / Gateway',
            self::Switch => 'Switch',
            self::Onu => 'ONU / ONT',
            self::Sector => 'Sector Antenna',
            self::Backhaul => 'Backhaul',
            self::Iot => 'Camera / IoT',
            self::Server => 'Server / Infrastructure',
            self::Unknown => 'Unknown / Guest',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cpe => 'emerald',
            self::HotspotClient => 'sky',
            self::BridgeClient => 'teal',
            self::AccessPoint => 'indigo',
            self::Router => 'rose',
            self::Switch => 'violet',
            self::Onu => 'cyan',
            self::Sector => 'amber',
            self::Backhaul => 'orange',
            self::Iot => 'fuchsia',
            self::Server => 'slate',
            self::Unknown => 'zinc',
        };
    }

    /**
     * Provider-owned network equipment (not tied to a customer).
     */
    public function isInfrastructure(): bool
    {
        return in_array($this, [
            self::AccessPoint,
            self::Router,
            self::Switch,
            self::Sector,
            self::Backhaul,
            self::Server,
        ], true);
    }

    /**
     * Devices that belong to a customer and are subject to MAC authorization.
     */
    public function isCustomerEdge(): bool
    {
        return in_array($this, [
            self::Cpe,
            self::HotspotClient,
            self::BridgeClient,
            self::Onu,
        ], true);
    }

    /**
     * value => label pairs for form dropdowns.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}

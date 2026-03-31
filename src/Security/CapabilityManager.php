<?php

namespace abcnorio\CustomFunc\Security;

final class CapabilityManager
{
    private const OPTION_KEY = 'custom_func_capability_schema_version';
    // Bump when role capability assignments in Security/capabilities.php change.
    private const SCHEMA_VERSION = '2';

    public static function maybeMigrateCapabilities(): void
    {
        $storedVersion = (string) get_option(self::OPTION_KEY, '0');

        if ($storedVersion === self::SCHEMA_VERSION) {
            return;
        }

        self::syncRoleCapabilities();
        update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
    }

    public static function forceMigrateCapabilities(): void
    {
        self::syncRoleCapabilities();
        update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
    }

    private static function syncRoleCapabilities(): void
    {
        $roleCapabilities = require __DIR__ . '/capabilities.php';
        $managedCapabilities = self::collectManagedCapabilities($roleCapabilities);

        foreach ($roleCapabilities as $roleName => $capabilities) {
            $role = get_role((string) $roleName);

            if (! $role) {
                continue;
            }

            $desired = array_map('strval', (array) $capabilities);

            foreach ($managedCapabilities as $capability) {
                if (! in_array($capability, $desired, true)) {
                    $role->remove_cap($capability);
                }
            }

            foreach ($desired as $capability) {
                $role->add_cap((string) $capability);
            }
        }
    }

    private static function collectManagedCapabilities(array $roleCapabilities): array
    {
        $managed = [];

        foreach ($roleCapabilities as $capabilities) {
            foreach ((array) $capabilities as $capability) {
                $managed[(string) $capability] = true;
            }
        }

        return array_keys($managed);
    }
}

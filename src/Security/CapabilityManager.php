<?php

namespace abcnorio\CustomFunc\Security;

final class CapabilityManager
{
    private const OPTION_KEY = 'custom_func_capability_schema_version';
    // Bump when role capability assignments in Security/capabilities.php change.
    private const SCHEMA_VERSION = '5';
    private const DEPRECATED_CAPABILITIES = [
        'manage_navigation_menus',
        'edit_theme_options',
    ];

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

            foreach (self::DEPRECATED_CAPABILITIES as $capability) {
                $role->remove_cap($capability);
            }

            foreach ($managedCapabilities as $capability) {
                if (! in_array($capability, $desired, true)) {
                    $role->remove_cap($capability);
                }
            }

            foreach ($desired as $capability) {
                $role->add_cap((string) $capability);
            }
        }

        self::syncUserCapabilities();
    }

    private static function syncUserCapabilities(): void
    {
        $userCapabilitiesPath = __DIR__ . '/user-capabilities.php';

        if (! file_exists($userCapabilitiesPath)) {
            return;
        }

        $userCapabilities = require $userCapabilitiesPath;

        if (! is_array($userCapabilities) || $userCapabilities === []) {
            return;
        }

        $managedCapabilities = self::collectManagedCapabilities($userCapabilities);
        if ($managedCapabilities === []) {
            return;
        }

        $userIds = get_users([
            'fields' => 'ID',
        ]);

        foreach ($userIds as $userId) {
            $user = get_user_by('id', (int) $userId);
            if (! $user) {
                continue;
            }

            foreach ($managedCapabilities as $capability) {
                $user->remove_cap($capability);
            }
        }

        foreach ($userCapabilities as $username => $capabilities) {
            if (! is_string($username) || $username === '') {
                continue;
            }

            $user = get_user_by('login', $username);

            if (! $user) {
                continue;
            }

            $desired = array_values(array_unique(array_map('strval', (array) $capabilities)));

            foreach ($desired as $capability) {
                $user->add_cap($capability);
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

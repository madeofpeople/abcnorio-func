<?php

namespace abcnorio\CustomFunc\Security;

final class CapabilityManager
{
    private const OPTION_KEY = 'custom_func_capability_schema_version';
    // Bump when role capability assignment behavior changes.
    private const SCHEMA_VERSION = '10';

    public static function maybeMigrateCapabilities(): void
    {
        $storedVersion = (string) get_option(self::OPTION_KEY, '0');

        // Guarded sync: only writes role caps when schema changes.
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
        // This file composes editor/admin capability sets from the content model.
        $roleCapabilities = require __DIR__ . '/capabilities.php';

        foreach ($roleCapabilities as $roleName => $capabilities) {
            $role = get_role((string) $roleName);

            if (! $role) {
                continue;
            }

            $desired = array_map('strval', (array) $capabilities);

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
}

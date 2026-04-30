<?php

namespace abcnorio\CustomFunc\Dashboard;

final class Dashboard
{
    public static function registerHooks(): void
    {
        add_filter('default_hidden_meta_boxes', [self::class, 'setDefaultHiddenWidgets'], 10, 2);
    }

    /**
     * Hide all dashboard widgets by default except Site Health Status.
     * Users can re-enable widgets via Screen Options.
     */
    public static function setDefaultHiddenWidgets(array $hidden, \WP_Screen $screen): array
    {
        if ($screen->id !== 'dashboard') {
            return $hidden;
        }

        $hide = [
            'dashboard_right_now',
            'dashboard_activity',
            'dashboard_quick_press',
            'dashboard_primary',
        ];

        return array_unique(array_merge($hidden, $hide));
    }
}

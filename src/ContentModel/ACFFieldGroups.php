<?php

namespace abcnorio\CustomFunc\ContentModel;

final class ACFFieldGroups
{
    public static function registerHooks(): void
    {
        add_action('acf/init', [self::class, 'register']);
    }

    public static function register(): void
    {
        self::registerEventFields();
        self::registerCollectiveFields();
    }

    private static function registerEventFields(): void
    {
        acf_add_local_field_group([
            'key'      => 'group_event_details',
            'title'    => 'Event Details',
            'show_in_rest' => true,
            'position' => 'side',
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'event',
                    ],
                ],
            ],
            'fields' => [
                [
                    'key'           => 'field_event_timezone',
                    'name'          => 'event_timezone',
                    'label'         => __('Time Zone', 'abcnorio-func'),
                    'type'          => 'select',
                    'choices'       => self::timezones(),
                    'default_value' => 'America/New_York',
                    'allow_null'    => 0,
                    'return_format' => 'value',
                ],
                [
                    'key'             => 'field_event_start',
                    'name'            => 'event_start_date',
                    'label'           => __('Start', 'abcnorio-func'),
                    'type'            => 'date_time_picker',
                    'display_format'  => 'd/m/Y g:i a',
                    'return_format'   => 'Y-m-d H:i:s',
                    'first_day'       => 1,
                ],
                [
                    'key'             => 'field_event_end',
                    'name'            => 'event_end_date',
                    'label'           => __('End', 'abcnorio-func'),
                    'type'            => 'date_time_picker',
                    'display_format'  => 'd/m/Y g:i a',
                    'return_format'   => 'Y-m-d H:i:s',
                    'first_day'       => 1,
                ],
                [
                    'key'   => 'field_event_location',
                    'name'  => 'event_venue_name',
                    'label' => __('Venue / Location', 'abcnorio-func'),
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_event_organizer_name',
                    'name'  => 'event_organizer_name',
                    'label' => __('Organizer\'s Name', 'abcnorio-func'),
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_event_organizer_email',
                    'name'  => 'event_organizer_email',
                    'label' => __('Organizer\'s Email', 'abcnorio-func'),
                    'type'  => 'email',
                ],
            ],
        ]);
    }

    private static function registerCollectiveFields(): void
    {
        acf_add_local_field_group([
            'key' => 'group_collective_details',
            'title' => 'Collective Details',
            'show_in_rest' => true,
            'position' => 'side',
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'collective',
                    ],
                ],
            ],
            'fields' => [
                [
                    'key' => 'field_collective_city',
                    'name' => 'collective_city',
                    'label' => __('City', 'abcnorio-func'),
                    'type' => 'text',
                ],
                [
                    'key' => 'field_collective_email',
                    'name' => 'collective_email',
                    'label' => __('Email', 'abcnorio-func'),
                    'type' => 'email',
                ],
                [
                    'key' => 'field_collective_website',
                    'name' => 'collective_website',
                    'label' => __('Website', 'abcnorio-func'),
                    'type' => 'url',
                ],
                [
                    'key' => 'field_collective_since_year',
                    'name' => 'collective_since_year',
                    'label' => __('Active since (year)', 'abcnorio-func'),
                    'type' => 'number',
                    'default_value' => 0,
                    'min' => 0,
                    'step' => 1,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function timezones(): array
    {
        $identifiers = \DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }
}
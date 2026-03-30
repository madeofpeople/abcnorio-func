<?php

return [
    'event' => [
        'name' => 'Events',
        'singular_name' => 'Event',
        'supports' => [
            'title',
            'editor',
            'excerpt',
            'thumbnail',
            'custom-fields'
        ],
        'has_archive' => true,
        'menu_icon' => 'dashicons-calendar-alt',
        'taxonomies' => [
            'event_type',
            'collective_association'],
        'capability_type' => [
            'event',
            'events'],
        'map_meta_cap' => true,
        'fields' => [
            'event_start_date' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_end_date' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_venue_name' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_timezone' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_organizer_name' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_organizer_email' => [
                'type' => 'string',
                'show_in_rest' => false,
                'sanitize_callback' => 'sanitize_email',
            ],
        ],
    ],
    'collective' => [
        'name' => 'Collectives',
        'singular_name' => 'Collective',
        'rewrite_slug' => 'collectives',
        'supports' => [
            'title',
            'editor',
            'thumbnail',
            'custom-fields'
        ],
        'has_archive' => true,
        'menu_icon' => 'dashicons-groups',
        'taxonomies' => ['collective_association'],
        'capability_type' => [
            'collective',
            'collectives'
        ],
        'map_meta_cap' => true,
        'fields' => [
            'collective_city' => [
                'type' => 'string',
            ],
            'collective_email' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_email',
            ],
            'collective_website' => [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
            ],
            'collective_since_year' => [
                'type' => 'integer',
                'default' => 0,
            ],
        ],
    ],
    'news_item' => [
        'name' => 'News',
        'singular_name' => 'News Item',
        'rest_base' => 'news_items',
        'rewrite_slug' => 'news_items',
        'supports' => [
            'title',
            'editor',
            'excerpt',
            'thumbnail',
            'custom-fields'
        ],
        'has_archive' => true,
        'menu_icon' => 'dashicons-calendar-alt',
        'taxonomies' => [
            'event_type',
            'collective_association'],
        'capability_type' => [
            'news_item',
            'news_items'],
        'map_meta_cap' => true,
        'fields' => [
            'event_start_date' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_end_date' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_venue_name' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_timezone' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_organizer_name' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_organizer_email' => [
                'type' => 'string',
                'show_in_rest' => false,
                'sanitize_callback' => 'sanitize_email',
            ],
        ],
    ],
];

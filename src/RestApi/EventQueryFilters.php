<?php

namespace abcnorio\CustomFunc\RestApi;

final class EventQueryFilters
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerEventMeta']);
        add_action('save_post_event', [self::class, 'computeEffectiveEnd'], 10, 1);
        add_filter('rest_event_query', [self::class, 'applyMetaFilters'], 10, 2);
        add_filter('rest_event_collection_params', [self::class, 'addOrderbyParams']);
        add_filter('rest_event_query', [self::class, 'applyOrderby'], 10, 2);
    }

    public static function registerEventMeta(): void
    {
        register_meta('post', 'event_start_date', [
            'object_subtype' => 'event',
            'show_in_rest'   => true,
            'single'         => true,
            'type'           => 'string',
        ]);

        register_meta('post', 'event_end_date', [
            'object_subtype' => 'event',
            'show_in_rest'   => true,
            'single'         => true,
            'type'           => 'string',
        ]);

        // Computed field: event_end_date if set, otherwise midnight of event_start_date.
        // Exposed via REST so build-time collections can use it for date filtering
        // without re-implementing the computation in JS.
        register_meta('post', 'event_effective_end', [
            'object_subtype' => 'event',
            'show_in_rest'   => true,
            'single'         => true,
            'type'           => 'string',
        ]);
    }

    // On post save: compute and store event_effective_end.
    // If the event has an end date, use it.
    // If not, fall back to midnight of the start date (event is visible all day it starts).
    public static function computeEffectiveEnd(int $post_id): void
    {
        $end_date   = get_post_meta($post_id, 'event_end_date', true);
        $start_date = get_post_meta($post_id, 'event_start_date', true);

        if ($end_date) {
            $effective_end = $end_date;
        } elseif ($start_date) {
            $effective_end = substr($start_date, 0, 10) . ' 00:00:00';
        } else {
            return;
        }

        update_post_meta($post_id, 'event_effective_end', $effective_end);
    }

    public static function addOrderbyParams(array $params): array
    {
        $params['orderby']['enum'][] = 'event_start_date';

        $params['event_effective_end_after'] = [
            'description' => 'Return events whose effective end date is on or after this datetime (Y-m-d H:i:s). Matches ongoing events (end_date >= value) and events with no end date whose start date falls on or after the given date.',
            'type'        => 'string',
            'required'    => false,
        ];

        return $params;
    }

    public static function applyOrderby(array $args, \WP_REST_Request $request): array
    {
        if ($request->get_param('orderby') === 'event_start_date') {
            // Use a named meta_query clause for ordering so WP can resolve a single JOIN alias.
            // Top-level meta_key conflicts with meta_query clauses on the same key.
            $args['meta_query']['event_start_date_order'] = [
                'key'     => 'event_start_date',
                'compare' => 'EXISTS',
            ];
            $args['orderby'] = ['event_start_date_order' => strtoupper($request->get_param('order') ?? 'ASC')];
        }
        return $args;
    }

    public static function applyMetaFilters(array $args, \WP_REST_Request $request): array
    {
        $after               = $request->get_param('event_start_after');
        $before              = $request->get_param('event_start_before');
        $effective_end_after = $request->get_param('event_effective_end_after');

        $clauses = [];

        if ($after) {
            $clauses[] = self::buildEffectiveEndClause('>=', sanitize_text_field($after));
        }

        if ($before) {
            $clauses[] = self::buildEffectiveEndClause('<=', sanitize_text_field($before));
        }

        if ($effective_end_after) {
            $clauses[] = [
                'key'     => 'event_effective_end',
                'value'   => sanitize_text_field($effective_end_after),
                'compare' => '>=',
                'type'    => 'DATETIME',
            ];
        }

        if (empty($clauses)) {
            return $args;
        }

        $existingMetaQuery = isset($args['meta_query']) && is_array($args['meta_query'])
            ? $args['meta_query']
            : [];

        $existingClauses = [];
        foreach ($existingMetaQuery as $key => $value) {
            if ($key === 'relation') {
                continue;
            }
            $existingClauses[] = $value;
        }

        $args['meta_query'] = array_merge(['relation' => 'AND'], $existingClauses, $clauses);

        return $args;
    }

    private static function buildEffectiveEndClause(string $compare, string $value): array
    {
        return [
            'key'     => 'event_start_date',
            'value'   => $value,
            'compare' => $compare,
            'type'    => 'DATETIME',
        ];
    }
}
<?php

namespace abcnorio\CustomFunc\RestApi;

final class ArticleQueryFilters
{
    public static function registerHooks(): void
    {
        add_filter('rest_article_collection_params', [self::class, 'addDateParams']);
        add_filter('rest_article_query', [self::class, 'applyDateFilters'], 10, 2);
        add_filter('rest_article_query', [self::class, 'applyOrderby'], 10, 2);
    }

    public static function addDateParams(array $params): array
    {
        $params['orderby']['enum'][] = 'article_date';

        $params['article_date_after'] = [
            'description' => 'Return articles with article_date on or after this date (Y-m-d).',
            'type'        => 'string',
            'required'    => false,
        ];

        $params['article_date_before'] = [
            'description' => 'Return articles with article_date on or before this date (Y-m-d).',
            'type'        => 'string',
            'required'    => false,
        ];

        return $params;
    }

    public static function applyOrderby(array $args, \WP_REST_Request $request): array
    {
        if ($request->get_param('orderby') === 'article_date') {
            // Named meta_query clause so WP can resolve a single JOIN alias;
            // top-level meta_key conflicts with meta_query clauses on the same key.
            $args['meta_query']['article_date_order'] = [
                'key'     => 'article_date',
                'compare' => 'EXISTS',
            ];
            $args['orderby'] = ['article_date_order' => strtoupper($request->get_param('order') ?? 'DESC')];
        }
        return $args;
    }

    public static function applyDateFilters(array $args, \WP_REST_Request $request): array
    {
        $after  = $request->get_param('article_date_after');
        $before = $request->get_param('article_date_before');

        $clauses = [];

        if ($after) {
            $clauses[] = [
                'key'     => 'article_date',
                'value'   => sanitize_text_field($after),
                'compare' => '>=',
                'type'    => 'DATE',
            ];
        }

        if ($before) {
            $clauses[] = [
                'key'     => 'article_date',
                'value'   => sanitize_text_field($before),
                'compare' => '<=',
                'type'    => 'DATE',
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
}

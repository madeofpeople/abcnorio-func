<?php

namespace abcnorio\CustomFunc\RestApi;

final class DatePayloadNormalizer
{
    public static function registerHooks(): void
    {
        add_filter('rest_prepare_article', [self::class, 'normalizeArticleResponse'], 20, 3);
        add_filter('rest_prepare_event', [self::class, 'normalizeEventResponse'], 20, 3);
    }

    public static function normalizeArticleResponse($response, \WP_Post $post, \WP_REST_Request $request)
    {
        if (! $response instanceof \WP_REST_Response) {
            return $response;
        }

        $data = $response->get_data();
        if (! is_array($data)) {
            return $response;
        }

        if (isset($data['acf']) && is_array($data['acf']) && array_key_exists('article_date', $data['acf'])) {
            $data['acf']['article_date'] = self::normalizeArticleDate((string) $data['acf']['article_date']);
        }

        if (isset($data['meta']) && is_array($data['meta']) && array_key_exists('article_date', $data['meta'])) {
            $data['meta']['article_date'] = self::normalizeArticleDate((string) $data['meta']['article_date']);
        }

        $response->set_data($data);

        return $response;
    }

    public static function normalizeEventResponse($response, \WP_Post $post, \WP_REST_Request $request)
    {
        if (! $response instanceof \WP_REST_Response) {
            return $response;
        }

        $data = $response->get_data();
        if (! is_array($data)) {
            return $response;
        }

        $eventKeys = ['event_start_date', 'event_end_date', 'event_effective_end'];

        if (isset($data['acf']) && is_array($data['acf'])) {
            foreach ($eventKeys as $key) {
                if (! array_key_exists($key, $data['acf'])) {
                    continue;
                }
                $data['acf'][$key] = self::normalizeEventDateTime((string) $data['acf'][$key]);
            }
        }

        if (isset($data['meta']) && is_array($data['meta'])) {
            foreach ($eventKeys as $key) {
                if (! array_key_exists($key, $data['meta'])) {
                    continue;
                }
                $data['meta'][$key] = self::normalizeEventDateTime((string) $data['meta'][$key]);
            }
        }

        $response->set_data($data);

        return $response;
    }

    private static function normalizeArticleDate(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches) === 1) {
            return sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
        }

        return '';
    }

    private static function normalizeEventDateTime(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})$/', $value, $matches) === 1) {
            return sprintf('%s %s', $matches[1], $matches[2]);
        }

        return $value;
    }
}
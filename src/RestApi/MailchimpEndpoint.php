<?php

namespace abcnorio\CustomFunc\RestApi;

final class MailchimpEndpoint
{
    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route('abcnorio/v1', '/mailchimp/list-data/(?P<list_id>[a-zA-Z0-9]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'serveListData'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('abcnorio/v1', '/mailchimp/submit', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'serveSubmit'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function serveListData(\WP_REST_Request $request)
    {
        $listId = sanitize_text_field((string) $request->get_param('list_id'));
        if ($listId === '') {
            return self::errorResponse('Missing list ID.', 400);
        }

        $data = self::fetchListData($listId);
        if (is_wp_error($data)) {
            return self::errorResponse($data->get_error_message(), 400);
        }

        return rest_ensure_response([
            'success' => true,
            'list_id' => $listId,
            'merge_fields' => $data['merge_fields'] ?? [],
            'interest_groups' => $data['interest_groups'] ?? [],
        ]);
    }

    public static function serveSubmit(\WP_REST_Request $request)
    {
        $params = $request->get_params();
        $listId = sanitize_text_field((string) ($params['mailchimp_list_id'] ?? $params['list_id'] ?? ''));

        if ($listId === '') {
            return self::errorResponse('Missing list ID.', 400, ['mc_mv_EMAIL' => 'Please enter your email address.']);
        }

        $data = self::fetchListData($listId);
        if (is_wp_error($data)) {
            return self::errorResponse($data->get_error_message(), 400);
        }

        $mergeFields = is_array($data['merge_fields'] ?? null) ? $data['merge_fields'] : [];
        $visibleTags = self::sanitizeStringList($params['mailchimp_visible_fields'] ?? []);
        $template = sanitize_text_field((string) ($params['mailchimp_template'] ?? 'default'));

        $fieldErrors = self::validateFieldInputs($params, $mergeFields, $visibleTags, $template);
        if ($fieldErrors !== []) {
            return self::errorResponse('Please correct the highlighted fields and try again.', 400, $fieldErrors);
        }

        if (! class_exists('Mailchimp_Form_Submission')) {
            return self::errorResponse('Mailchimp plugin is not available.', 500);
        }

        $postData = self::buildSubmissionPostData($params, $listId, $mergeFields, $visibleTags, $template);

        $originalPost = $_POST;
        $_POST = $postData;

        try {
            $handler = new \Mailchimp_Form_Submission();
            $result = $handler->handle_form_submission();
        } finally {
            $_POST = $originalPost;
        }

        if (is_wp_error($result)) {
            return self::errorResponse(wp_strip_all_tags((string) $result->get_error_message()), 400);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => wp_strip_all_tags((string) $result),
            'field_errors' => (object) [],
            'form_error' => '',
        ]);
    }

    private static function fetchListData(string $listId)
    {
        if (! class_exists('Mailchimp_List_Subscribe_Form_Blocks')) {
            return new \WP_Error('mailchimp_missing', 'Mailchimp plugin is not available.');
        }

        $service = new \Mailchimp_List_Subscribe_Form_Blocks();
        $request = new \WP_REST_Request('GET', '/mailchimp/v1/list-data/' . $listId);
        $request->set_param('list_id', $listId);

        $response = $service->get_list_data($request);
        if (is_wp_error($response)) {
            return $response;
        }

        if ($response instanceof \WP_REST_Response) {
            $data = $response->get_data();
        } else {
            $data = $response;
        }

        if (! is_array($data)) {
            return new \WP_Error('mailchimp_invalid_data', 'Unable to load Mailchimp list data.');
        }

        return [
            'merge_fields' => is_array($data['merge_fields'] ?? null) ? $data['merge_fields'] : [],
            'interest_groups' => is_array($data['interest_groups'] ?? null) ? $data['interest_groups'] : [],
        ];
    }

    private static function buildSubmissionPostData(array $params, string $listId, array $mergeFields, array $visibleTags, string $template): array
    {
        $updateExisting = self::toBool($params['mailchimp_update_existing_subscribers'] ?? true);
        $doubleOptIn = self::toBool($params['mailchimp_double_opt_in'] ?? true);
        $skipMergeValidation = self::shouldSkipMergeValidation($template, $mergeFields, $visibleTags);

        $postData = [
            'mcsf_action' => 'mc_submit_signup_form',
            'mc_submit_type' => 'json',
            'mailchimp_sf_alt_email' => '',
            'mailchimp_sf_list_id' => $listId,
            'mailchimp_sf_update_existing_subscribers' => $updateExisting ? 'yes' : 'no',
            'mailchimp_sf_double_opt_in' => $doubleOptIn ? 'yes' : 'no',
            'mailchimp_sf_skip_merge_validation' => $skipMergeValidation ? 'yes' : 'no',
        ];

        $postData['mailchimp_sf_hash'] = wp_hash(
            serialize([
                'list_id' => $listId,
                'update_existing' => $postData['mailchimp_sf_update_existing_subscribers'],
                'double_opt_in' => $postData['mailchimp_sf_double_opt_in'],
                'skip_merge_validation' => $postData['mailchimp_sf_skip_merge_validation'],
            ])
        );

        foreach ($params as $key => $value) {
            if (str_starts_with((string) $key, 'mc_mv_')) {
                $postData[$key] = $value;
            }
        }

        if (isset($params['group']) && is_array($params['group'])) {
            $postData['group'] = $params['group'];
        }

        $emailType = sanitize_text_field((string) ($params['email_type'] ?? 'html'));
        if (! in_array($emailType, ['html', 'text', 'mobile'], true)) {
            $emailType = 'html';
        }

        $postData['email_type'] = $emailType;

        return $postData;
    }

    private static function validateFieldInputs(array $params, array $mergeFields, array $visibleTags, string $template): array
    {
        $errors = [];

        $email = isset($params['mc_mv_EMAIL']) ? sanitize_email((string) $params['mc_mv_EMAIL']) : '';
        if ($email === '') {
            $errors['mc_mv_EMAIL'] = 'Please enter your email address.';
        } elseif (! is_email($email)) {
            $errors['mc_mv_EMAIL'] = 'Please enter a valid email address.';
        }

        foreach ($mergeFields as $field) {
            if (! is_array($field) || empty($field['tag']) || empty($field['required'])) {
                continue;
            }

            $tag = sanitize_text_field((string) $field['tag']);
            if ($tag === 'EMAIL') {
                continue;
            }

            if (! self::isFieldVisible($tag, $visibleTags, $template)) {
                continue;
            }

            $key = 'mc_mv_' . $tag;
            $value = $params[$key] ?? null;

            if (self::isEmptyValue($value)) {
                $name = sanitize_text_field((string) ($field['name'] ?? $tag));
                $errors[$key] = sprintf('You must fill in %s.', $name);
            }
        }

        return $errors;
    }

    private static function shouldSkipMergeValidation(string $template, array $mergeFields, array $visibleTags): bool
    {
        if ($template === 'default') {
            return false;
        }

        $requiredTags = [];
        foreach ($mergeFields as $field) {
            if (! is_array($field) || empty($field['required']) || empty($field['tag'])) {
                continue;
            }

            $requiredTags[] = sanitize_text_field((string) $field['tag']);
        }

        if ($requiredTags === []) {
            return false;
        }

        $visibleMap = array_fill_keys($visibleTags, true);
        foreach ($requiredTags as $tag) {
            if (! isset($visibleMap[$tag])) {
                return true;
            }
        }

        return false;
    }

    private static function isFieldVisible(string $tag, array $visibleTags, string $template): bool
    {
        if ($template === 'default') {
            return true;
        }

        if ($tag === 'EMAIL') {
            return true;
        }

        $visibleMap = array_fill_keys($visibleTags, true);
        return isset($visibleMap[$tag]);
    }

    private static function sanitizeStringList($raw): array
    {
        if (! is_array($raw)) {
            $raw = [$raw];
        }

        $out = [];
        foreach ($raw as $entry) {
            $value = sanitize_text_field((string) $entry);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    private static function isEmptyValue($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (! self::isEmptyValue($entry)) {
                    return false;
                }
            }

            return true;
        }

        return trim((string) $value) === '';
    }

    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private static function errorResponse(string $message, int $status, array $fieldErrors = []): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => false,
            'message' => '',
            'field_errors' => (object) $fieldErrors,
            'form_error' => $message,
        ], $status);
    }
}

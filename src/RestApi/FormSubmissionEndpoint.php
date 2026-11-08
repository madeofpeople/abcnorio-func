<?php

namespace abcnorio\CustomFunc\RestApi;

use abcnorio\CustomFunc\ContentModel\PublicFormGroups;
use abcnorio\CustomFunc\Settings\PublicFormSettings;

final class FormSubmissionEndpoint
{
    private const MIN_SUBMIT_MS = 2500;

    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route('abcnorio/v1', '/forms', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'serveForms'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('abcnorio/v1', '/forms/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'serveForm'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('abcnorio/v1', '/forms/submit', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'serveSubmit'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function serveForms(\WP_REST_Request $request)
    {
        $page = absint((int) $request->get_param('page'));
        if ($page < 1) {
            $page = 1;
        }

        $perPage = absint((int) $request->get_param('per_page'));
        if ($perPage < 1) {
            $perPage = 100;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        return new \WP_REST_Response(self::listPublicGroupForms($page, $perPage), 200);
    }

    public static function serveForm(\WP_REST_Request $request)
    {
        $formSlug = sanitize_key((string) $request->get_param('slug'));
        if ($formSlug === '') {
            return new \WP_REST_Response([
                'success' => false,
                'message' => PublicFormSettings::message('not_found_message'),
            ], 400);
        }

        $form = self::resolveForm($formSlug);
        if ($form === null) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => PublicFormSettings::message('not_found_message'),
            ], 404);
        }

        return new \WP_REST_Response([
            'success' => true,
            'form' => [
                'id' => $form['id'],
                'slug' => $form['slug'],
                'title' => $form['title'],
                'submit_label' => $form['submit_label'],
                'success_message' => $form['success_message'],
                'error_message' => $form['error_message'],
                'sending_message' => $form['sending_message'],
                'wait_message' => $form['wait_message'],
                'connection_message' => $form['connection_message'],
                'generic_error_message' => $form['generic_error_message'],
                'civi_endpoint_url' => $form['civi_endpoint_url'] ?? '',
                'to_email' => $form['to_email'] ?? '',
                'fields' => $form['fields'],
            ],
        ], 200);
    }

    public static function serveSubmit(\WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        $formSlug = isset($payload['form_slug']) ? sanitize_key((string) $payload['form_slug']) : '';
        $submitted = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];

        if (self::isHoneypotTriggered($submitted)) {
            return new \WP_REST_Response([
                'success' => true,
                'message' => PublicFormSettings::successMessage(),
            ], 200);
        }

        if (! self::passesSubmitTimingThreshold($submitted)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => PublicFormSettings::message('wait_message'),
            ], 429);
        }

        unset($submitted['_hp'], $submitted['_started_at']);

        $form = self::resolveForm($formSlug);
        if ($form === null) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => PublicFormSettings::message('not_found_message'),
            ], 404);
        }

        $errors = self::validateFields($submitted, $form['fields']);
        if ($errors !== []) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => PublicFormSettings::message('validation_message'),
                'errors' => $errors,
            ], 400);
        }

        $sanitized = self::sanitizeFields($submitted, $form['fields']);

        // Civi delivery is not implemented; request format and response handling depend on its endpoint.
        // if ($form['civi_endpoint_url'] !== '') {
        //     $response = wp_remote_post($form['civi_endpoint_url'], [
        //         'headers' => ['Content-Type' => 'application/json'],
        //         'body' => wp_json_encode($sanitized),
        //     ]);
        //     Handle errors and the Civi response before returning a result.
        // }

        $recipient = self::buildToEmail($form);
        if ($recipient === '') {
            error_log('[abcnorio][forms] Delivery is not configured for form ' . $formSlug);
            return new \WP_REST_Response([
                'success' => false,
                'message' => $form['error_message'],
            ], 500);
        }

        $subject = sprintf('New %s submission', $form['title']);
        $body = self::buildEmailBody($form, $sanitized);

        $sent = wp_mail($recipient, $subject, $body, [
            'Content-Type: text/plain; charset=UTF-8',
        ]);

        if (! $sent) {
            error_log('[abcnorio][forms] Mail delivery failed for form ' . $formSlug . '; check mail transport logs.');
            return new \WP_REST_Response([
                'success' => false,
                'message' => $form['error_message'],
            ], 500);
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => $form['success_message'],
            'form' => [
                'id' => $form['id'],
                'slug' => $form['slug'],
                'title' => $form['title'],
            ],
        ], 200);
    }

    private static function resolveForm(string $formSlug): ?array
    {
        if ($formSlug === '') {
            return null;
        }

        $groupForm = self::resolveGroupForm($formSlug);
        if ($groupForm !== null) {
            return $groupForm;
        }

        return null;
    }

    private static function resolveGroupForm(string $formSlug): ?array
    {
        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            return null;
        }

        $groups = get_posts([
            'post_type' => 'acf-field-group',
            'post_status' => ['publish', 'private'],
            'posts_per_page' => -1,
            'ignore_sticky_posts' => true,
            'meta_query' => [
                [
                    'key' => '_abcnorio_public_form_enabled',
                    'value' => '1',
                ],
                [
                    'key' => '_abcnorio_public_form_slug',
                    'value' => $formSlug,
                ],
            ],
        ]);

        if (! is_array($groups) || $groups === []) {
            return null;
        }

        foreach ($groups as $groupPost) {
            if (! $groupPost instanceof \WP_Post) {
                continue;
            }

            $groupId = (int) $groupPost->ID;
            if (! PublicFormGroups::isEnabled($groupId)) {
                continue;
            }

            $slug = PublicFormGroups::slug($groupId);
            if ($slug === '' || $slug !== $formSlug) {
                continue;
            }

            if (! PublicFormGroups::hasDeliveryTarget($groupId)) {
                continue;
            }

            $group = acf_get_field_group($groupId);
            if (! is_array($group)) {
                continue;
            }

            $groupKey = (string) ($group['key'] ?? '');
            if ($groupKey === '') {
                continue;
            }

            $fields = self::mapGroupFieldsToFormFields(acf_get_fields($groupKey));
            if ($fields === []) {
                continue;
            }

            return [
                'id' => 0,
                'slug' => $slug,
                'title' => (string) ($group['title'] ?? $slug),
                'success_message' => PublicFormSettings::successMessage(),
                'error_message' => PublicFormSettings::errorMessage(),
                'sending_message' => PublicFormSettings::message('sending_message'),
                'wait_message' => PublicFormSettings::message('wait_message'),
                'connection_message' => PublicFormSettings::message('connection_message'),
                'generic_error_message' => PublicFormSettings::message('generic_error_message'),
                'submit_label' => 'Send',
                'civi_endpoint_url' => PublicFormGroups::civiEndpointUrl($groupId),
                'to_email' => PublicFormGroups::toEmail($groupId),
                'fields' => $fields,
            ];
        }

        return null;
    }

    /**
     * @return array<int, array{id: int, slug: string, title: string, submit_label: string, success_message: string, error_message: string, fields: array<int, array{name: string, label: string, type: string, required: bool, placeholder: string, validation: string, min_length: int}>}>
     */
    private static function listPublicGroupForms(int $page, int $perPage): array
    {
        if (! function_exists('acf_get_field_group') || ! function_exists('acf_get_fields')) {
            return [];
        }

        $groups = get_posts([
            'post_type' => 'acf-field-group',
            'post_status' => ['publish', 'private'],
            'posts_per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'title',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'meta_query' => [
                [
                    'key' => '_abcnorio_public_form_enabled',
                    'value' => '1',
                ],
            ],
        ]);

        if (! is_array($groups) || $groups === []) {
            return [];
        }

        $forms = [];

        foreach ($groups as $groupPost) {
            if (! $groupPost instanceof \WP_Post) {
                continue;
            }

            $groupId = (int) $groupPost->ID;
            if (! PublicFormGroups::isEnabled($groupId)) {
                continue;
            }

            if (! PublicFormGroups::hasDeliveryTarget($groupId)) {
                continue;
            }

            $slug = PublicFormGroups::slug($groupId);
            if ($slug === '') {
                continue;
            }

            $group = acf_get_field_group($groupId);
            if (! is_array($group)) {
                continue;
            }

            $groupKey = (string) ($group['key'] ?? '');
            if ($groupKey === '') {
                continue;
            }

            $fields = self::mapGroupFieldsToFormFields(acf_get_fields($groupKey));
            if ($fields === []) {
                continue;
            }

            $forms[] = [
                'id' => 0,
                'slug' => $slug,
                'title' => (string) ($group['title'] ?? $slug),
                'submit_label' => 'Send',
                'success_message' => PublicFormSettings::successMessage(),
                'error_message' => PublicFormSettings::errorMessage(),
                'sending_message' => PublicFormSettings::message('sending_message'),
                'wait_message' => PublicFormSettings::message('wait_message'),
                'connection_message' => PublicFormSettings::message('connection_message'),
                'generic_error_message' => PublicFormSettings::message('generic_error_message'),
                'civi_endpoint_url' => PublicFormGroups::civiEndpointUrl($groupId),
                'to_email' => PublicFormGroups::toEmail($groupId),
                'fields' => $fields,
            ];
        }

        return $forms;
    }

    /**
     * @param mixed $rawFields
    * @return array<int, array{name: string, label: string, type: string, required: bool, placeholder: string, validation: string, min_length: int, options?: array<int, array{value: string, label: string}>, allow_null?: bool}>
     */
    private static function mapGroupFieldsToFormFields($rawFields, string $group = ''): array
    {
        $fields = [];

        if (! is_array($rawFields)) {
            return $fields;
        }

        foreach ($rawFields as $field) {
            if (! is_array($field)) {
                continue;
            }

            if (($field['type'] ?? '') === 'group') {
                $label = (string) ($field['label'] ?? '');
                $fields = array_merge($fields, self::mapGroupFieldsToFormFields($field['sub_fields'] ?? [], $label));
                continue;
            }

            $name = sanitize_key((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            if (! in_array($type, ['text', 'email', 'textarea', 'select'], true)) {
                continue;
            }
            if ($type === 'select' && ! empty($field['multiple'])) {
                continue;
            }

            $minLength = isset($field['minlength']) ? (int) $field['minlength'] : 0;

            $formField = [
                'name' => $name,
                'label' => (string) ($field['label'] ?? $name),
                'type' => $type,
                'required' => ! empty($field['required']),
                'placeholder' => (string) ($field['placeholder'] ?? ''),
                'validation' => $type === 'email' ? 'email' : ($minLength > 0 ? 'min_length' : ''),
                'min_length' => $minLength,
            ];

            if ($group !== '') {
                $formField['group'] = $group;
            }

            if ($type === 'select') {
                $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
                $options = [];
                foreach ($choices as $value => $label) {
                    $value = (string) $value;
                    if ($value === '') {
                        continue;
                    }
                    $options[] = ['value' => $value, 'label' => (string) $label];
                }
                if ($options === []) {
                    continue;
                }
                $formField['options'] = $options;
                $formField['allow_null'] = ! empty($field['allow_null']);
            }

            $fields[] = $formField;
        }

        return $fields;
    }

    private static function validateFields(array $submitted, array $fields): array
    {
        $errors = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $value = $submitted[$name] ?? null;
            $required = ! empty($field['required']);
            $type = (string) ($field['type'] ?? 'text');
            $validation = (string) ($field['validation'] ?? '');

            if ($required && self::isEmptyValue($value)) {
                $errors[$name] = strtr(PublicFormSettings::message('required_message'), ['{field}' => (string) ($field['label'] ?? $name)]);
                continue;
            }

            if ($type === 'select') {
                if ($value === null || $value === '') {
                    if (! $required && ! empty($field['allow_null'])) {
                        continue;
                    }
                    $errors[$name] = strtr(PublicFormSettings::message('selection_message'), ['{field}' => (string) ($field['label'] ?? $name)]);
                    continue;
                }

                $allowedValues = array_column($field['options'] ?? [], 'value');
                if (! is_string($value) || ! in_array($value, $allowedValues, true)) {
                    $errors[$name] = strtr(PublicFormSettings::message('selection_message'), ['{field}' => (string) ($field['label'] ?? $name)]);
                }
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if ($type === 'email' || $validation === 'email') {
                if (! is_email((string) $value)) {
                    $errors[$name] = strtr(PublicFormSettings::message('email_message'), ['{field}' => (string) ($field['label'] ?? $name)]);
                }
            }

            if ($validation === 'min_length' && is_string($value)) {
                $minLength = isset($field['min_length']) ? (int) $field['min_length'] : 30;
                if (mb_strlen($value) < $minLength) {
                    $errors[$name] = strtr(PublicFormSettings::message('min_length_message'), [
                        '{field}' => (string) ($field['label'] ?? $name),
                        '{min_length}' => (string) $minLength,
                    ]);
                }
            }
        }

        return $errors;
    }

    private static function sanitizeFields(array $submitted, array $fields): array
    {
        $sanitized = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $value = $submitted[$name] ?? '';
            $type = (string) ($field['type'] ?? 'text');
            $raw = is_array($value) ? '' : (string) $value;

            if ($type === 'email') {
                $sanitized[$name] = sanitize_email($raw);
                continue;
            }

            if ($type === 'textarea') {
                $sanitized[$name] = sanitize_textarea_field($raw);
                continue;
            }

            $sanitized[$name] = sanitize_text_field($raw);
        }

        return $sanitized;
    }

    private static function buildToEmail(array $form): string
    {
        if (! empty($form['to_email']) && is_email($form['to_email'])) {
            return $form['to_email'];
        }

        return '';
    }

    private static function buildEmailBody(array $form, array $values): string
    {
        $lines = [];
        $lines[] = sprintf('Form: %s', $form['title']);
        $lines[] = '';

        foreach ($values as $name => $value) {
            $lines[] = sprintf('%s: %s', ucfirst(str_replace('_', ' ', $name)), $value);
        }

        return implode("\n", $lines);
    }

    private static function isEmptyValue($value): bool
    {
        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return $value === null;
    }

    private static function isHoneypotTriggered(array $submitted): bool
    {
        $value = isset($submitted['_hp']) ? (string) $submitted['_hp'] : '';
        return trim($value) !== '';
    }

    /* 
        human vs script timing check
        keeps spam submissions at bay
     */
    private static function passesSubmitTimingThreshold(array $submitted): bool
    {
        $startedAt = isset($submitted['_started_at']) ? (int) $submitted['_started_at'] : 0;
        if ($startedAt <= 0) {
            return false;
        }

        $nowMs = (int) round(microtime(true) * 1000);
        return ($nowMs - $startedAt) >= self::MIN_SUBMIT_MS;
    }

}

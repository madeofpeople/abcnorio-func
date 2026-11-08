<?php

// Run in WordPress: wp eval-file path/to/public-form-messages-test.php

use abcnorio\CustomFunc\RestApi\FormSubmissionEndpoint;
use abcnorio\CustomFunc\Settings\PublicFormSettings;

$blank = static fn () => '';
add_filter('pre_option_' . PublicFormSettings::SUCCESS_MESSAGE, $blank);
add_filter('pre_option_' . PublicFormSettings::ERROR_MESSAGE, $blank);
if (PublicFormSettings::successMessage() === '' || PublicFormSettings::errorMessage() === '') {
    throw new RuntimeException('Blank settings must use default messages.');
}
remove_filter('pre_option_' . PublicFormSettings::SUCCESS_MESSAGE, $blank);
remove_filter('pre_option_' . PublicFormSettings::ERROR_MESSAGE, $blank);

add_filter('pre_option_' . PublicFormSettings::SUCCESS_MESSAGE, static fn () => 'Test success message.');
add_filter('pre_option_' . PublicFormSettings::ERROR_MESSAGE, static fn () => 'Test delivery failure message.');

$messages = [
    'sending_message' => 'Sending test.',
    'wait_message' => 'Wait test.',
    'validation_message' => 'Validation test.',
    'connection_message' => 'Connection test.',
    'not_found_message' => 'Not found test.',
    'generic_error_message' => 'Unexpected test.',
    'required_message' => 'Required: {field}.',
    'selection_message' => 'Selection: {field}.',
    'email_message' => 'Email: {field}.',
    'min_length_message' => 'Length: {field}, {min_length} characters.',
];
foreach ($messages as $key => $message) {
    add_filter('pre_option_abcnorio_public_form_' . $key, static fn () => $message);
}

PublicFormSettings::registerSettings();
foreach (array_merge([PublicFormSettings::SUCCESS_MESSAGE, PublicFormSettings::ERROR_MESSAGE], array_map(
    static fn ($key) => 'abcnorio_public_form_' . $key,
    array_keys($messages)
)) as $option) {
    if (! isset(get_registered_settings()[$option])) {
        throw new RuntimeException("Public form setting not registered: {$option}");
    }
}

$forms = FormSubmissionEndpoint::serveForms(new WP_REST_Request('GET'))->get_data();
$form = null;
foreach ($forms as $candidate) {
    $requiredFields = array_filter($candidate['fields'], static fn ($field) => ! empty($field['required']));
    if (! empty($candidate['to_email']) && $requiredFields !== []) {
        $form = $candidate;
        break;
    }
}
if ($form === null) {
    throw new RuntimeException('An email-configured public form with a required field is required for this test.');
}
if ($form['success_message'] !== 'Test success message.' || $form['error_message'] !== 'Test delivery failure message.') {
    throw new RuntimeException('Form list did not expose configured messages.');
}

$request = new WP_REST_Request('GET');
$request->set_param('slug', $form['slug']);
$single = FormSubmissionEndpoint::serveForm($request)->get_data()['form'];
if ($single['success_message'] !== $form['success_message'] || $single['error_message'] !== $form['error_message']) {
    throw new RuntimeException('Single form messages differ from form list.');
}
foreach (['sending_message', 'wait_message', 'connection_message', 'generic_error_message'] as $key) {
    if ($form[$key] !== $messages[$key] || $single[$key] !== $messages[$key]) {
        throw new RuntimeException("Configured {$key} was not exposed consistently.");
    }
}

$fields = ['_started_at' => (int) (microtime(true) * 1000) - 10000];
foreach ($form['fields'] as $field) {
    $value = 'Test input';
    if ($field['type'] === 'email') {
        $value = 'test@example.org';
    } elseif ($field['type'] === 'select') {
        $value = $field['options'][0]['value'];
    } elseif (! empty($field['min_length'])) {
        $value = str_repeat('x', max(10, (int) $field['min_length']));
    }
    $fields[$field['name']] = $value;
}

$submit = static function (array $values) use ($form): WP_REST_Response {
    $request = new WP_REST_Request('POST', '/abcnorio/v1/forms/submit');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode(['form_slug' => $form['slug'], 'fields' => $values]));
    return FormSubmissionEndpoint::serveSubmit($request);
};

$mailCalls = 0;
$preventDelivery = static function () use (&$mailCalls): bool {
    $mailCalls++;
    return false;
};
add_filter('pre_wp_mail', $preventDelivery, 1);
$response = $submit($fields);
if ($mailCalls !== 1 || $response->get_status() !== 500 || $response->get_data()['message'] !== 'Test delivery failure message.') {
    throw new RuntimeException('Delivery failure did not return the configured message.');
}
remove_filter('pre_wp_mail', $preventDelivery, 1);

$simulateSuccess = static function () use (&$mailCalls): bool {
    $mailCalls++;
    return true;
};
add_filter('pre_wp_mail', $simulateSuccess, 1);
$response = $submit($fields);
if ($mailCalls !== 2 || $response->get_status() !== 200 || $response->get_data()['message'] !== 'Test success message.') {
    throw new RuntimeException('Successful delivery did not return the configured message.');
}
remove_filter('pre_wp_mail', $simulateSuccess, 1);

$required = array_values(array_filter($form['fields'], static fn ($field) => ! empty($field['required'])));
if ($required === []) {
    throw new RuntimeException('The test form must include a required field.');
}
$fields[$required[0]['name']] = '';
$response = $submit($fields);
if ($response->get_status() !== 400 || $response->get_data()['message'] !== 'Validation test.'
    || $response->get_data()['errors'][$required[0]['name']] !== 'Required: ' . $required[0]['label'] . '.') {
    throw new RuntimeException('Required field validation did not return a specific error.');
}

$fields['_started_at'] = (int) (microtime(true) * 1000);
if ($submit($fields)->get_data()['message'] !== 'Wait test.') {
    throw new RuntimeException('Timing error did not use configured message.');
}
$missing = new WP_REST_Request('GET');
$missing->set_param('slug', 'no-such-form');
if (FormSubmissionEndpoint::serveForm($missing)->get_data()['message'] !== 'Not found test.') {
    throw new RuntimeException('Missing form did not use configured message.');
}

$validate = new ReflectionMethod(FormSubmissionEndpoint::class, 'validateFields');
$errors = $validate->invoke(null, ['choice' => 'bad', 'email' => 'bad', 'text' => 'abc'], [
    ['name' => 'choice', 'label' => 'Choice', 'type' => 'select', 'options' => [['value' => 'good']]],
    ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
    ['name' => 'text', 'label' => 'Text', 'type' => 'text', 'validation' => 'min_length', 'min_length' => 10],
]);
if ($errors !== ['choice' => 'Selection: Choice.', 'email' => 'Email: Email.', 'text' => 'Length: Text, 10 characters.']) {
    throw new RuntimeException('Field validation templates were not expanded correctly.');
}

echo "ok public form messages, delivery and validation (mail intercepted)\n";

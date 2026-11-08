<?php

namespace abcnorio\CustomFunc\Settings;

final class PublicFormSettings
{
    public const SUCCESS_MESSAGE = 'abcnorio_public_form_success_message';
    public const ERROR_MESSAGE = 'abcnorio_public_form_error_message';

    private const GROUP = 'abcnorio_public_form_messages';
    private const MESSAGES = [
        'success_message' => [self::SUCCESS_MESSAGE, 'Success', "Thanks for reaching out! We'll be in touch."],
        'error_message' => [self::ERROR_MESSAGE, 'Delivery failure', "We couldn't send your message. Please try again later."],
        'sending_message' => ['abcnorio_public_form_sending_message', 'Sending', 'Sending...'],
        'wait_message' => ['abcnorio_public_form_wait_message', 'Submitted too quickly', 'Please wait a moment and try again.'],
        'validation_message' => ['abcnorio_public_form_validation_message', 'Validation summary', 'Please check the form fields and try again.'],
        'connection_message' => ['abcnorio_public_form_connection_message', 'Connection failure', 'We could not confirm your submission because the connection failed. Please contact us before trying again.'],
        'not_found_message' => ['abcnorio_public_form_not_found_message', 'Form not found', 'Form not found.'],
        'generic_error_message' => ['abcnorio_public_form_generic_error_message', 'Unexpected error', 'Something went wrong. Please try again.'],
        'required_message' => ['abcnorio_public_form_required_message', 'Required field', '{field} is required.'],
        'selection_message' => ['abcnorio_public_form_selection_message', 'Invalid selection', '{field} must be a valid selection.'],
        'email_message' => ['abcnorio_public_form_email_message', 'Invalid email address', '{field} must be a valid email address.'],
        'min_length_message' => ['abcnorio_public_form_min_length_message', 'Minimum length', '{field} must be at least {min_length} characters.'],
    ];

    public static function registerHooks(): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    public static function registerSettings(): void
    {
        foreach (self::MESSAGES as [$option]) {
            register_setting(self::GROUP, $option, [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }
    }

    public static function message(string $key): string
    {
        [$option, , $default] = self::MESSAGES[$key];
        $value = trim((string) get_option($option, ''));
        return $value !== '' ? $value : $default;
    }

    public static function successMessage(): string
    {
        return self::message('success_message');
    }

    public static function errorMessage(): string
    {
        return self::message('error_message');
    }

    public static function renderSection(): void
    {
        ?>
        <section>
            <h2><?php esc_html_e('Public form messages', 'abcnorio-func'); ?></h2>
            <p class="description"><?php esc_html_e('Applies to all public forms. Leave blank to use the default message.', 'abcnorio-func'); ?></p>
            <p class="description"><?php esc_html_e('For field validation messages, use {field} for the field label and {min_length} for the minimum character count.', 'abcnorio-func'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <?php foreach (self::MESSAGES as [$option, $label, $default]) : ?>
                        <tr>
                            <th scope="row"><label for="<?php echo esc_attr($option); ?>"><?php echo esc_html__($label, 'abcnorio-func'); ?></label></th>
                            <td><input type="text" class="regular-text" id="<?php echo esc_attr($option); ?>" name="<?php echo esc_attr($option); ?>" value="<?php echo esc_attr((string) get_option($option, '')); ?>" placeholder="<?php echo esc_attr($default); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </section>
        <?php
    }
}

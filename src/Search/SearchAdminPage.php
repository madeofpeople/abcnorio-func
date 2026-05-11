<?php

namespace abcnorio\CustomFunc\Search;

final class SearchAdminPage
{
    const OPTION_KEY   = 'abcnorio_search_synonyms';
    const NONCE_ACTION = 'abcnorio_save_search_synonyms';

    public static function registerHooks(): void
    {
        add_action('admin_menu',   [self::class, 'addMenuPage']);
        add_action('admin_notices', [self::class, 'renderAdminNoticeIfSearchConfigPage']);
        add_action('admin_post_abcnorio_save_search_synonyms', [self::class, 'handleSave']);
    }

    public static function addMenuPage(): void
    {
        add_submenu_page(
            'tools.php',
            __('Search Config', 'abcnorio-func'),
            __('Search Config', 'abcnorio-func'),
            'manage_options',
            'abcnorio-search-config',
            [self::class, 'renderPage']
        );
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'abcnorio-func'), 403);
        }

        check_admin_referer(self::NONCE_ACTION);

        $raw  = $_POST['synonyms'] ?? [];
        $rows = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                $term     = sanitize_text_field((string) ($row['term'] ?? ''));
                $synonyms = sanitize_text_field((string) ($row['synonyms'] ?? ''));
                if ($term === '') {
                    continue;
                }
                $rows[] = ['term' => $term, 'synonyms' => $synonyms];
            }
        }

        update_option(self::OPTION_KEY, $rows, false);

        wp_safe_redirect(add_query_arg([
            'page'  => 'abcnorio-search-config',
            'saved' => '1',
        ], admin_url('tools.php')));
        exit;
    }

    public static function getSynonymsConfig(): array
    {
        $rows   = get_option(self::OPTION_KEY, []);
        $result = [];
        foreach ($rows as $row) {
            $term = strtolower(trim($row['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $synonyms = array_filter(array_map(
                fn($s) => strtolower(trim($s)),
                explode(',', $row['synonyms'] ?? '')
            ));
            $result[$term] = array_values($synonyms);
        }
        return $result;
    }

    public static function renderAdminNoticeIfSearchConfigPage(): void
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if ($page !== 'abcnorio-search-config') {
            return;
        }

        $saved = sanitize_key((string) ($_GET['saved'] ?? ''));
        if ($saved !== '') {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(__('Search config saved.', 'abcnorio-func'))
            );
        }
    }

    public static function renderPage(): void
    {
        $rows = get_option(self::OPTION_KEY, []);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Search Config', 'abcnorio-func'); ?></h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="abcnorio_save_search_synonyms">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <table class="widefat striped" id="abcnorio-synonyms-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Term', 'abcnorio-func'); ?></th>
                            <th><?php esc_html_e('Synonyms (comma-separated)', 'abcnorio-func'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="abcnorio-synonyms-body">
                        <?php foreach ($rows as $i => $row) : ?>
                        <tr>
                            <td><input type="text" name="synonyms[<?php echo $i; ?>][term]" value="<?php echo esc_attr($row['term'] ?? ''); ?>" class="regular-text"></td>
                            <td><input type="text" name="synonyms[<?php echo $i; ?>][synonyms]" value="<?php echo esc_attr($row['synonyms'] ?? ''); ?>" class="regular-text"></td>
                            <td><button type="button" class="button abcnorio-remove-synonym"><?php esc_html_e('Remove', 'abcnorio-func'); ?></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" id="abcnorio-add-synonym" class="button"><?php esc_html_e('Add Synonym', 'abcnorio-func'); ?></button>
                </p>

                <?php submit_button(__('Save Synonyms', 'abcnorio-func')); ?>
            </form>
        </div>

        <script>
        (function () {
            var body  = document.getElementById('abcnorio-synonyms-body');
            var addBtn = document.getElementById('abcnorio-add-synonym');

            addBtn.addEventListener('click', function () {
                var idx = body.querySelectorAll('tr').length;
                var tr  = document.createElement('tr');
                tr.innerHTML =
                    '<td><input type="text" name="synonyms[' + idx + '][term]" value="" class="regular-text"></td>' +
                    '<td><input type="text" name="synonyms[' + idx + '][synonyms]" value="" class="regular-text"></td>' +
                    '<td><button type="button" class="button abcnorio-remove-synonym"><?php echo esc_js(__('Remove', 'abcnorio-func')); ?></button></td>';
                body.appendChild(tr);
            });

            body.addEventListener('click', function (e) {
                if (e.target.classList.contains('abcnorio-remove-synonym')) {
                    e.target.closest('tr').remove();
                }
            });

            // Reindex on submit so indices are sequential.
            addBtn.closest('form').addEventListener('submit', function () {
                body.querySelectorAll('tr').forEach(function (tr, i) {
                    tr.querySelectorAll('input').forEach(function (input) {
                        input.name = input.name.replace(/synonyms\[\d+\]/, 'synonyms[' + i + ']');
                    });
                });
            });
        })();
        </script>
        <?php
    }
}

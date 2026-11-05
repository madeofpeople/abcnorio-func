<?php

namespace abcnorio\CustomFunc\Dashboard;

use abcnorio\CustomFunc\Events\VenueOverlapNotice;

final class NeedsAttention
{
    private const LIMIT = 8;

    private const UNPUBLISHED = ['draft', 'pending', 'future'];

    // The overlap check scans every published event, so its result is cached briefly.
    private const CONFLICTS_TRANSIENT = 'abcnorio_venue_conflicts';

    public static function registerHooks(): void
    {
        add_action('wp_dashboard_setup', [self::class, 'addWidget']);
        add_action('save_post_event', [self::class, 'clearConflictsCache']);
    }

    public static function clearConflictsCache(): void
    {
        delete_transient(self::CONFLICTS_TRANSIENT);
    }

    public static function addWidget(): void
    {
        if (! current_user_can('edit_events') && ! current_user_can('edit_articles')) {
            return;
        }

        wp_add_dashboard_widget('abcnorio_needs_attention', __('Needs attention', 'abcnorio-func'), [self::class, 'render']);
    }

    public static function render(): void
    {
        $canEvents = current_user_can('edit_events');
        $canArticles = current_user_can('edit_articles');
        $sections = [];

        if ($canEvents) {
            $sections[] = self::postSection(
                __('Events with no featured image', 'abcnorio-func'),
                ['post_type' => 'event', 'post_status' => ['publish', 'future', 'pending', 'draft'], 'meta_query' => [['key' => '_thumbnail_id', 'compare' => 'NOT EXISTS']]]
            );
        }

        if ($canArticles) {
            $sections[] = self::postSection(
                __('Articles missing a display date', 'abcnorio-func'),
                [
                    'post_type' => 'article',
                    'post_status' => ['publish', 'future', 'pending', 'draft'],
                    'meta_query' => [
                        'relation' => 'OR',
                        ['key' => 'article_date', 'compare' => 'NOT EXISTS'],
                        ['key' => 'article_date', 'value' => ''],
                    ],
                ]
            );
        }

        $sections[] = self::postSection(
            __('Pending review', 'abcnorio-func'),
            ['post_type' => array_filter([$canEvents ? 'event' : '', $canArticles ? 'article' : '']), 'post_status' => 'pending'],
            true
        );

        if ($canEvents) {
            $sections[] = self::postSection(
                __('Unpublished events', 'abcnorio-func'),
                ['post_type' => 'event', 'post_status' => self::UNPUBLISHED],
                true
            );
        }

        if ($canArticles) {
            $sections[] = self::postSection(
                __('Unpublished articles', 'abcnorio-func'),
                ['post_type' => 'article', 'post_status' => self::UNPUBLISHED],
                true
            );
        }

        $sections[] = self::openNotesSection($canEvents, $canArticles);

        if ($canEvents) {
            $sections[] = self::venueConflictsSection();
        }

        self::renderHelp();

        $sections = array_filter($sections);

        if ($sections === []) {
            echo '<p>' . esc_html__('Nothing needs attention right now.', 'abcnorio-func') . '</p>';
            return;
        }

        echo implode('', $sections);
    }

    private static function renderHelp(): void
    {
        ?>
        <details>
            <summary><?php esc_html_e('How do items get listed here?', 'abcnorio-func'); ?></summary>
            <ul>
                <li><?php esc_html_e('No featured image: set a featured image on the event.', 'abcnorio-func'); ?></li>
                <li><?php esc_html_e('Missing display date: fill in the article Date field.', 'abcnorio-func'); ?></li>
                <li><?php esc_html_e('Pending review: set the status to "Pending Review" in the editor to ask an editor to look at it.', 'abcnorio-func'); ?></li>
                <li><?php esc_html_e('Unpublished: events and articles saved as Draft, Pending Review or Scheduled.', 'abcnorio-func'); ?></li>
                <li><?php esc_html_e('Open notes: add a Note to a block in the editor. It stays listed until the note is marked resolved.', 'abcnorio-func'); ?></li>
                <li><?php esc_html_e('Venue conflicts: upcoming published events that overlap another event at the same venue, or exceed the daily limit.', 'abcnorio-func'); ?></li>
            </ul>
        </details>
        <?php
    }

    private static function postSection(string $title, array $args, bool $showStatus = false): string
    {
        $query = new \WP_Query($args + [
            'posts_per_page' => self::LIMIT,
            'orderby' => 'modified',
            'order' => 'DESC',
            'perm' => 'editable',
        ]);

        $items = [];

        foreach ($query->posts as $post) {
            $items[] = ['post' => $post, 'note' => $showStatus ? get_post_status_object($post->post_status)->label : ''];
        }

        return self::renderSection($title, $items, (int) $query->found_posts);
    }

    private static function openNotesSection(bool $canEvents, bool $canArticles): string
    {
        $postTypes = array_filter([$canEvents ? 'event' : '', $canArticles ? 'article' : '']);

        $notes = get_comments([
            'type' => 'note',
            'post_type' => $postTypes,
            'orderby' => 'comment_date_gmt',
            'order' => 'ASC',
        ]);

        // A thread is open unless its latest status is "resolved"; resolving or reopening is recorded as a reply with a status.
        $threadStatus = [];
        $threadPost = [];

        foreach ($notes as $note) {
            $threadId = (int) $note->comment_parent === 0 ? (int) $note->comment_ID : (int) $note->comment_parent;
            $status = (string) get_comment_meta((int) $note->comment_ID, '_wp_note_status', true);

            $threadPost[$threadId] = (int) $note->comment_post_ID;
            $threadStatus[$threadId] ??= '';

            if ($status !== '') {
                $threadStatus[$threadId] = $status;
            }
        }

        $openCounts = [];

        foreach ($threadStatus as $threadId => $status) {
            if ($status !== 'resolved') {
                $postId = $threadPost[$threadId];
                $openCounts[$postId] = ($openCounts[$postId] ?? 0) + 1;
            }
        }

        $items = [];

        foreach ($openCounts as $postId => $count) {
            $post = get_post($postId);

            if (! $post || $post->post_status === 'trash' || ! current_user_can('edit_post', $postId)) {
                continue;
            }

            $items[] = ['post' => $post, 'note' => sprintf(_n('%d open note', '%d open notes', $count, 'abcnorio-func'), $count)];
        }

        return self::renderSection(__('Open notes', 'abcnorio-func'), array_slice($items, 0, self::LIMIT), count($items));
    }

    private static function venueConflictsSection(): string
    {
        $conflicts = get_transient(self::CONFLICTS_TRANSIENT);

        if ($conflicts === false) {
            $conflicts = [];

            $upcomingIds = get_posts([
                'post_type' => 'event',
                'post_status' => 'publish',
                'numberposts' => 30,
                'fields' => 'ids',
                'meta_key' => 'event_start_date',
                'orderby' => 'meta_value',
                'order' => 'ASC',
                'meta_query' => [['key' => 'event_start_date', 'value' => current_time('Y-m-d H:i:s'), 'compare' => '>=', 'type' => 'CHAR']],
            ]);

            foreach ($upcomingIds as $eventId) {
                $warnings = VenueOverlapNotice::warnings($eventId);

                if ($warnings !== []) {
                    $conflicts[$eventId] = $warnings[0];
                }
            }

            set_transient(self::CONFLICTS_TRANSIENT, $conflicts, 5 * MINUTE_IN_SECONDS);
        }

        $items = [];

        foreach ($conflicts as $eventId => $message) {
            if (current_user_can('edit_post', $eventId)) {
                $items[] = ['post' => get_post($eventId), 'note' => $message];
            }
        }

        return self::renderSection(__('Upcoming venue conflicts', 'abcnorio-func'), array_slice($items, 0, self::LIMIT), count($items));
    }

    /**
     * @param list<array{post: \WP_Post, note: string}> $items
     */
    private static function renderSection(string $title, array $items, int $total): string
    {
        if ($items === []) {
            return '';
        }

        $html = sprintf('<h3>%s <span class="count">(%d)</span></h3>', esc_html($title), $total);
        $html .= sprintf(
            '<table class="widefat striped"><thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>',
            esc_html__('Title', 'abcnorio-func'),
            esc_html__('Issue', 'abcnorio-func'),
            esc_html__('Date added', 'abcnorio-func')
        );

        foreach ($items as $item) {
            $post = $item['post'];
            $editLink = get_edit_post_link($post->ID);

            if (! $editLink) {
                continue;
            }

            $html .= sprintf(
                '<tr><td><a href="%s">%s</a> <em>(%s)</em></td><td>%s</td><td><time datetime="%s">%s</time></td></tr>',
                esc_url($editLink),
                esc_html(get_the_title($post)),
                esc_html(get_post_type_object($post->post_type)->labels->singular_name),
                esc_html($item['note'] === '' ? $title : $item['note']),
                esc_attr(get_the_date(DATE_W3C, $post)),
                esc_html(get_the_date('d/m/Y', $post))
            );
        }

        $html .= '</tbody></table>';

        if ($total > count($items)) {
            $html .= sprintf('<p>%s</p>', esc_html(sprintf(__('…and %d more', 'abcnorio-func'), $total - count($items))));
        }

        return $html;
    }
}

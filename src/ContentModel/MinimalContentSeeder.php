<?php

namespace abcnorio\CustomFunc\ContentModel;

final class MinimalContentSeeder
{
    private const PRIMARY_MENU_LOCATION = 'primary_navigation';
    private const FOOTER_MENU_LOCATION = 'footer';

    public static function registerCliCommand(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        \WP_CLI::add_command('abcnorio seed-minimal', [self::class, 'runCliSeedMinimal']);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, mixed> $assocArgs
     */
    public static function runCliSeedMinimal(array $args, array $assocArgs): void
    {
        self::seedMinimal();
        \WP_CLI::success('Minimal content ready.');
    }

    public static function seedMinimal(): void
    {
        $aboutId = self::ensurePage('About', 'about', 'Seed about page.');
        $programmingId = self::ensurePage('Programming', 'programming', 'Seed programming page.');

        self::ensurePage('About History', 'about-history', 'Seed about child page.', $aboutId);
        self::ensurePage('Programming Overview', 'programming-overview', 'Seed programming child page.', $programmingId);

        $collectiveAssociationId = self::ensureTerm('collective_association', 'Seed Collective', 'seed-collective');
        $eventTypeId = self::ensureTerm('event_type', 'Workshops', 'workshops');
        $eventTagId = self::ensureTerm('event_tag', 'Featured', 'featured');

        $collectiveId = self::ensurePost('collective', 'Seed Collective', 'seed-collective', 'Seed collective entry.');
        self::setTerms($collectiveId, 'collective_association', [$collectiveAssociationId]);

        $collectivePageId = self::ensurePage('Seed Collective Page', 'seed-collective-page', 'Seed collective-associated page.');
        self::setTerms($collectivePageId, 'collective_association', [$collectiveAssociationId]);

        $eventId = self::ensurePost('event', 'Seed Event', 'seed-event', 'Seed event entry.');
        self::setTerms($eventId, 'collective_association', [$collectiveAssociationId]);
        self::setTerms($eventId, 'event_type', [$eventTypeId]);
        self::setTerms($eventId, 'event_tag', [$eventTagId]);
        self::seedEventMeta($eventId);

        self::deleteLegacySeedPost('news_item', 'seed-article');
        $articleId = self::ensurePost('article', 'Seed Article', 'seed-article', 'Seed article entry.');
        self::setTerms($articleId, 'collective_association', [$collectiveAssociationId]);
        update_post_meta($articleId, 'item_date', gmdate('Y-m-d'));

        $pressItemId = self::ensurePost('press_item', 'Seed Press Item', 'seed-press-item', 'Seed press item entry.');
        update_post_meta($pressItemId, 'press_item_source', 'Seed Source');
        update_post_meta($pressItemId, 'press_item_url', home_url('/articles/seed-article/'));
        update_post_meta($pressItemId, 'press_item_date', gmdate('Y-m-d'));

        self::ensureMenu(
            'Primary Navigation',
            self::PRIMARY_MENU_LOCATION,
            [
                ['object_id' => $aboutId, 'title' => 'About'],
                ['object_id' => $programmingId, 'title' => 'Programming'],
            ]
        );

        self::ensureMenu(
            'Footer Navigation',
            self::FOOTER_MENU_LOCATION,
            [
                ['object_id' => $aboutId, 'title' => 'About'],
                ['object_id' => $collectivePageId, 'title' => 'Seed Collective'],
            ]
        );
    }

    private static function ensureMenu(string $menuName, string $location, array $items): void
    {
        $menu = wp_get_nav_menu_object($menuName);

        if (! $menu) {
            $menuId = wp_create_nav_menu($menuName);
            if (is_wp_error($menuId)) {
                return;
            }
            $menu = wp_get_nav_menu_object((int) $menuId);
        }

        if (! $menu) {
            return;
        }

        $existingItems = wp_get_nav_menu_items($menu->term_id) ?: [];

        foreach ($items as $item) {
            $objectId = (int) ($item['object_id'] ?? 0);
            $title = (string) ($item['title'] ?? '');

            if ($objectId <= 0 || $title === '') {
                continue;
            }

            $alreadyPresent = false;

            foreach ($existingItems as $existingItem) {
                if ((int) $existingItem->object_id === $objectId) {
                    $alreadyPresent = true;
                    break;
                }
            }

            if ($alreadyPresent) {
                continue;
            }

            wp_update_nav_menu_item($menu->term_id, 0, [
                'menu-item-title' => $title,
                'menu-item-object' => 'page',
                'menu-item-object-id' => $objectId,
                'menu-item-type' => 'post_type',
                'menu-item-status' => 'publish',
            ]);
        }

        $locations = get_theme_mod('nav_menu_locations', []);
        if (! is_array($locations)) {
            $locations = [];
        }
        $locations[$location] = (int) $menu->term_id;
        set_theme_mod('nav_menu_locations', $locations);
    }

    private static function ensurePage(string $title, string $slug, string $content, int $parentId = 0): int
    {
        $existing = get_page_by_path($slug, OBJECT, 'page');

        if ($existing instanceof \WP_Post) {
            if ((int) $existing->post_parent !== $parentId) {
                wp_update_post([
                    'ID' => $existing->ID,
                    'post_parent' => $parentId,
                ]);
            }

            return (int) $existing->ID;
        }

        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_parent' => $parentId,
            'post_content' => $content,
        ]);

        return is_wp_error($postId) ? 0 : (int) $postId;
    }

    private static function ensurePost(string $postType, string $title, string $slug, string $content): int
    {
        $existing = get_page_by_path($slug, OBJECT, $postType);

        if ($existing instanceof \WP_Post) {
            return (int) $existing->ID;
        }

        $postId = wp_insert_post([
            'post_type' => $postType,
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_content' => $content,
        ]);

        return is_wp_error($postId) ? 0 : (int) $postId;
    }

    private static function deleteLegacySeedPost(string $postType, string $slug): void
    {
        $existing = get_page_by_path($slug, OBJECT, $postType);

        if (! $existing instanceof \WP_Post) {
            return;
        }

        wp_delete_post((int) $existing->ID, true);
    }

    private static function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);

        if ($existing instanceof \WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);

        if (is_wp_error($created)) {
            return 0;
        }

        return (int) ($created['term_id'] ?? 0);
    }

    private static function setTerms(int $postId, string $taxonomy, array $termIds): void
    {
        if ($postId <= 0 || $termIds === []) {
            return;
        }

        wp_set_object_terms($postId, array_values(array_filter($termIds)), $taxonomy, false);
    }

    private static function seedEventMeta(int $postId): void
    {
        if ($postId <= 0) {
            return;
        }

        $start = gmdate('Y-m-d 18:00:00', strtotime('+14 days'));
        $end = gmdate('Y-m-d 20:00:00', strtotime('+14 days'));

        update_post_meta($postId, 'event_timezone', 'America/New_York');
        update_post_meta($postId, 'event_start_date', $start);
        update_post_meta($postId, 'event_end_date', $end);
        update_post_meta($postId, 'event_effective_end', $end);
        update_post_meta($postId, 'event_venue_name', 'ABC No Rio');
        update_post_meta($postId, 'event_venue_address', '156 Rivington Street, New York, NY 10002');
    }
}
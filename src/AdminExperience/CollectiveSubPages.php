<?php

namespace abcnorio\CustomFunc\AdminExperience;

final class CollectiveSubPages
{
    public static function registerHooks(): void
    {
        add_action('add_meta_boxes', [self::class, 'addSubPagesMetaBox']);
        add_action('add_meta_boxes_collective', [self::class, 'relabelCollectiveAssociationMetaBox']);
        add_action('add_meta_boxes_page', [self::class, 'suppressCollectiveAssociationOnPage']);
    }

    public static function suppressCollectiveAssociationOnPage(): void
    {
        remove_meta_box('collective_associationdiv', 'page', 'side');
    }

    public static function relabelCollectiveAssociationMetaBox(): void
    {
        remove_meta_box('collective_associationdiv', 'collective', 'side');
        add_meta_box(
            'collective_associationdiv',
            __('Collective Identity', 'abcnorio-func'),
            'post_categories_meta_box',
            'collective',
            'side',
            'default',
            ['taxonomy' => 'collective_association']
        );
    }

    public static function addSubPagesMetaBox(): void
    {
        add_meta_box(
            'collective_sub_pages',
            __('Sub Pages', 'abcnorio-func'),
            [self::class, 'renderSubPagesMetaBox'],
            'collective',
            'side',
            'default'
        );
    }

    public static function renderSubPagesMetaBox(\WP_Post $post): void
    {
        $term_ids = wp_get_object_terms($post->ID, 'collective_association', ['fields' => 'ids']);

        if (empty($term_ids) || is_wp_error($term_ids)) {
            echo '<p style="color:#777">' . esc_html__('No collective association term linked to this collective.', 'abcnorio-func') . '</p>';
            return;
        }

        $sub_pages = get_posts([
            'post_type'      => 'page',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft', 'pending'],
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
            'tax_query'      => [[
                'taxonomy' => 'collective_association',
                'field'    => 'term_id',
                'terms'    => $term_ids,
            ]],
        ]);

        $new_url = admin_url('post-new.php?post_type=page');

        if (empty($sub_pages)) {
            echo '<p style="color:#777">' . esc_html__('No sub pages yet.', 'abcnorio-func') . '</p>';
            echo '<a href="' . esc_url($new_url) . '">' . esc_html__('Add sub page', 'abcnorio-func') . '</a>';
            return;
        }

        echo '<ul style="margin:0;padding:0;list-style:none">';
        foreach ($sub_pages as $sub_page) {
            $status = $sub_page->post_status !== 'publish'
                ? ' <span style="color:#999;font-size:11px">— ' . esc_html($sub_page->post_status) . '</span>'
                : '';
            echo '<li style="padding:3px 0;border-bottom:1px solid #eee">';
            echo '<a href="' . esc_url(get_edit_post_link($sub_page->ID)) . '">' . esc_html($sub_page->post_title) . '</a>';
            echo $status;
            echo '</li>';
        }
        echo '</ul>';
        echo '<p style="margin-top:8px"><a href="' . esc_url($new_url) . '">' . esc_html__('Add sub page', 'abcnorio-func') . '</a></p>';
    }
}

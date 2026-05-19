<?php

namespace abcnorio\CustomFunc\Blocks;

final class Patterns
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerPatterns']);
    }

    public static function registerPatterns(): void
    {
        register_block_pattern(
            'abcnorio/announcement-tout',
            [
                'title'       => __('Announcement Tout', 'abcnorio'),
                'description' => __('Highlighted announcement with title, subtitle, details, and a call-to-action button.', 'abcnorio'),
                'categories'  => ['call-to-action'],
                'content'     => '<!-- wp:group {"className":"tout","layout":{"type":"constrained"}} -->
<div class="wp-block-group tout"><!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Announcement Title</h2>
<!-- /wp:heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Subtitle or date</h3>
<!-- /wp:heading -->

<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>Add details here.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Register Here</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->',
            ]
        );
    }
}

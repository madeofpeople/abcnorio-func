<?php
require_once dirname(__DIR__) . '/scripts/hero-content-migration.php';
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$wrap = static function (string $children, string $attributes = ''): string {
    return '<!-- wp:abcnorio/hero' . $attributes . ' -->' . "\n"
        . '<div class="abcnorio-hero__content-wrapper"><div class="abcnorio-hero__content">'
        . $children . '</div></div>' . "\n<!-- /wp:abcnorio/hero -->";
};
$children = "\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">A &amp; B</h2>\n<!-- /wp:heading -->\n\n"
    . "<!-- wp:paragraph -->\n<p>Keep me</p>\n<!-- /wp:paragraph -->\n";
$attrs = ' {"id":42,"url":"https://example.test/image.jpg","alt":"Description","variant":"thin"}';
$legacy = $wrap($children, $attrs);
foreach ([$legacy, $wrap(''), $wrap($legacy), '<!-- wp:group --><div class="wp-block-group">' . $legacy . '</div><!-- /wp:group -->'] as $content) {
    $result = HeroContentMigration::transform($content);
    $check($result['legacy'] >= 1, 'Expected legacy hero recognition.');
    $check(!str_contains($result['content'], 'abcnorio-hero__content'), 'Known wrappers must be removed.');
    $again = HeroContentMigration::transform($result['content']);
    $check($again['legacy'] === 0 && $again['content'] === $result['content'], 'Migration must be idempotent.');
}
$result = HeroContentMigration::transform($legacy);
$check(str_contains($result['content'], $children), 'Child markup must remain byte-identical.');
$check(str_contains($result['content'], $attrs), 'Hero attributes must remain byte-identical.');
$unrelated = "<!-- wp:paragraph -->\n<p>Unrelated</p>\n<!-- /wp:paragraph -->\n\n";
$check(HeroContentMigration::transform($unrelated . $legacy)['content'] === $unrelated . $result['content'], 'Unrelated content must remain byte-identical.');
$current = "<!-- wp:abcnorio/hero -->\n" . $children . "\n<!-- /wp:abcnorio/hero -->";
$check(HeroContentMigration::transform($current)['content'] === $current, 'Current hero must remain untouched.');
foreach ([
    str_replace('abcnorio-hero__content-wrapper', 'custom-wrapper', $legacy),
    str_replace('</div></div>', '<p>Unexpected literal content</p></div></div>', $legacy),
    str_replace('{"id":42,', '{ "id": 42,', $legacy),
    str_replace('</div></div>', '</div></div>' . $children, $legacy),
] as $unexpected) {
    try {
        HeroContentMigration::transform($unexpected);
        throw new LogicException('Unexpected markup must be rejected.');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'manual review required'), 'Expected explicit review error.');
    }
}
echo "Hero migration preservation, idempotence and rejection checks passed.\n";

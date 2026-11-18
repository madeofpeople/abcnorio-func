<?php

require_once dirname(__DIR__) . '/src/Blocks/HeadingAnchorAttribute.php';

use abcnorio\CustomFunc\Blocks\HeadingAnchorAttribute;

foreach (range(1, 6) as $level) {
    $block = ['attributes' => ['content' => 'Heading', 'level' => $level]];
    $result = HeadingAnchorAttribute::addAnchor(
        $block,
        'core/heading',
        0,
        ['innerHTML' => "<h{$level} class=\"example\" id=\"section-{$level}\">Heading</h{$level}>"]
    );
    if ($result['attributes']['anchor'] !== "section-{$level}"
        || $result['attributes']['content'] !== 'Heading'
        || isset($block['attributes']['anchor'])) {
        throw new RuntimeException('Heading anchor bridge must preserve the core attributes without mutating its input.');
    }
}
foreach ([
    ['core/paragraph', '<p id="not-a-heading">Text</p>'],
    ['core/heading', '<h2>Heading without anchor</h2>'],
    ['core/heading', ''],
] as [$name, $html]) {
    $block = ['attributes' => ['content' => 'Text']];
    if (HeadingAnchorAttribute::addAnchor($block, $name, 0, ['innerHTML' => $html]) !== $block) {
        throw new RuntimeException('Anchor bridge must leave other blocks and headings without anchors unchanged.');
    }
}
echo "Core heading anchor bridge passed for levels 1-6; unrelated block attributes remain unchanged.\n";

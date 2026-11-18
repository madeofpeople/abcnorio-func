<?php

require_once __DIR__ . '/../src/AdminExperience/AdminExperience.php';

use abcnorio\CustomFunc\AdminExperience\AdminExperience;

$input = [
    '__experimentalFeatures' => [
        'shadow' => ['presets' => ['default' => [['slug' => 'natural']]]],
        'color' => ['custom' => true],
        'blocks' => [
            'core/image' => ['shadow' => ['presets' => ['theme' => [['slug' => 'custom']]]], 'border' => ['radius' => true]],
        ],
    ],
];
$output = AdminExperience::disableEditorShadows($input);
if ($output['__experimentalFeatures']['shadow'] !== ['defaultPresets' => false, 'presets' => []]
    || $output['__experimentalFeatures']['blocks']['core/image']['shadow'] !== $output['__experimentalFeatures']['shadow']
    || $output['__experimentalFeatures']['color'] !== $input['__experimentalFeatures']['color']
    || $output['__experimentalFeatures']['blocks']['core/image']['border'] !== ['radius' => true]
    || AdminExperience::disableEditorShadows([])['__experimentalFeatures']['shadow']['presets'] !== []) {
    throw new RuntimeException('Editor shadow settings regression.');
}
echo "Editor shadow settings passed.\n";

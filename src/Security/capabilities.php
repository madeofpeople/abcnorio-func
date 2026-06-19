<?php
// Additive declaration: derive editor/admin post caps from CPT definitions,
// then layer explicit policy extras where needed.

$postTypeDefinitions = require __DIR__ . '/../ContentModel/post-types.php';
$taxonomyDefinitions = require __DIR__ . '/../ContentModel/taxonomies.php';

$editorCapabilities = [];
$administratorExtraCapabilities = [];

/**
 * @return array{editor: array<int, string>, admin: array<int, string>}
 */
$buildPostTypeCapabilities = static function (string $singular, string $plural): array {
    return [
        'editor' => [
            "edit_{$singular}",
            "read_{$singular}",
            "delete_{$singular}",
            "edit_{$plural}",
            "publish_{$plural}",
            "read_private_{$plural}",
            "edit_published_{$plural}",
        ],
        'admin' => [
            "edit_others_{$plural}",
            "delete_{$plural}",
            "delete_private_{$plural}",
            "delete_published_{$plural}",
            "delete_others_{$plural}",
            "edit_private_{$plural}",
        ],
    ];
};

foreach ($postTypeDefinitions as $definition) {
    $capabilityType = $definition['capability_type'] ?? null;

    if (! is_array($capabilityType) || count($capabilityType) !== 2) {
        continue;
    }

    $singular = (string) $capabilityType[0];
    $plural = (string) $capabilityType[1];

    if ($singular === '' || $plural === '') {
        continue;
    }

    // CPT capability_type is the canonical source for generated post caps.
    $generated = $buildPostTypeCapabilities($singular, $plural);
    $editorCapabilities = array_merge($editorCapabilities, $generated['editor']);
    $administratorExtraCapabilities = array_merge($administratorExtraCapabilities, $generated['admin']);
}

foreach ($taxonomyDefinitions as $definition) {
    $taxonomyCapabilities = $definition['capabilities'] ?? null;

    if (! is_array($taxonomyCapabilities)) {
        continue;
    }

    foreach (['manage_terms', 'edit_terms', 'delete_terms'] as $capabilityKey) {
        $capabilityName = (string) ($taxonomyCapabilities[$capabilityKey] ?? '');

        // Taxonomy term management stays admin-only. assign_terms is intentionally
        // excluded here so editors can assign existing terms without managing vocab.
        if ($capabilityName === '' || $capabilityName === 'manage_options') {
            continue;
        }

        $administratorExtraCapabilities[] = $capabilityName;
    }
}

return [
    'administrator' => array_values(array_unique(array_merge($editorCapabilities, $administratorExtraCapabilities))),
    'editor' => array_values(array_unique($editorCapabilities)),
];

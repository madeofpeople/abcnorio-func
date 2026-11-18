<?php
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Use wp eval-file to run the hero content migration.');
}
require_once __DIR__ . '/hero-content-migration.php';
$mode = $args[0] ?? 'dry-run';
if (in_array($mode, ['backup', 'apply', 'restore'], true)) {
    if (count($args) !== 2) WP_CLI::error('Specify exactly one absolute private backup path.');
    $path = $args[1];
    if ($mode === 'backup') {
        $report = HeroContentMigration::backup($path);
        WP_CLI::success("Private backup verified for {$report['legacy']} hero instances. No database changes made.");
    } else {
        $report = HeroContentMigration::applyBackup($path, $mode === 'restore');
        if ($report['changed'] > 0) {
            \abcnorio\CustomFunc\RestApi\ContentChangeEndpoint::touch();
        }
        WP_CLI::success("{$mode}: {$report['changed']} records changed. Post caches invalidated; no new revisions created.");
    }
    return;
}
if ($mode !== 'dry-run' || count($args) > 1) WP_CLI::error('Use dry-run, backup PATH, apply PATH or restore PATH.');
$inventory = HeroContentMigration::inventory();
WP_CLI::log(wp_json_encode($inventory['report'], JSON_PRETTY_PRINT));
HeroContentMigration::assertReady($inventory['report']);
WP_CLI::success(sprintf(
    'Dry run: %d records would change; %d legacy and %d current hero blocks. No database changes made.',
    count($inventory['changes']),
    $inventory['report']['legacy'],
    $inventory['report']['current']
));

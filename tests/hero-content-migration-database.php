<?php
require_once dirname(__DIR__) . '/scripts/hero-content-migration.php';
global $wpdb;
$originalPosts = $wpdb->posts;
$table = $wpdb->prefix . 'hero_migration_test_' . bin2hex(random_bytes(6));
$backup = sys_get_temp_dir() . '/hero-migration-test-' . bin2hex(random_bytes(6)) . '.json';
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$check($wpdb->query("CREATE TABLE {$table} LIKE {$originalPosts}") !== false, 'Could not create isolated test table.');
$wpdb->posts = $table;
try {
    $legacy = '<!-- wp:abcnorio/hero {"id":42,"variant":"thin"} -->'
        . '<div class="abcnorio-hero__content-wrapper"><div class="abcnorio-hero__content">'
        . '<!-- wp:paragraph --><p>Preserved child</p><!-- /wp:paragraph -->'
        . '</div></div><!-- /wp:abcnorio/hero -->';
    foreach ([
        900001 => ['page', 'publish'],
        900002 => ['wp_block', 'publish'],
        900003 => ['revision', 'inherit'],
        900004 => ['page', 'draft'],
        900005 => ['wp_block', 'trash'],
        900006 => ['page', 'auto-draft'],
    ] as $id => [$type, $status]) {
        $check($wpdb->insert($table, [
            'ID' => $id,
            'post_type' => $type,
            'post_status' => $status,
            'post_content' => $legacy,
            'post_title' => 'Synthetic migration fixture',
            'post_excerpt' => '',
            'to_ping' => '',
            'pinged' => '',
            'post_content_filtered' => '',
        ]) === 1, 'Could not insert isolated test fixture.');
    }
    $before = $wpdb->get_results("SELECT * FROM {$table} ORDER BY ID", ARRAY_A);
    HeroContentMigration::backup($backup);
    $check((fileperms($backup) & 0077) === 0, 'Backup must be private.');
    $check(array_column(HeroContentMigration::readBackup($backup)['records'], 'id') === [900001, 900002], 'Backup must include placed/pattern content but exclude revisions, drafts and trash.');
    $changedContent = $legacy . "\n<!-- User edit after backup -->";
    $wpdb->update($table, ['post_content' => $changedContent], ['ID' => 900002]);
    try {
        HeroContentMigration::applyBackup($backup);
        throw new LogicException('A concurrent edit must abort migration.');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'changed since backup'), 'Expected concurrent-edit rejection.');
    }
    $check($wpdb->get_var("SELECT post_content FROM {$table} WHERE ID = 900001") === $legacy, 'Earlier writes must roll back on later conflict.');
    $check($wpdb->get_var("SELECT post_content FROM {$table} WHERE ID = 900002") === $changedContent, 'Conflicting edit must not be overwritten.');
    $wpdb->update($table, ['post_content' => $legacy], ['ID' => 900002]);
    $check(HeroContentMigration::applyBackup($backup)['changed'] === 2, 'Both records must migrate.');
    $check(HeroContentMigration::inventory()['report']['legacy'] === 0, 'No legacy heroes may remain.');
    $check(HeroContentMigration::applyBackup($backup)['changed'] === 0, 'Apply must be idempotent.');
    $after = $wpdb->get_results("SELECT * FROM {$table} ORDER BY ID", ARRAY_A);
    foreach ($after as $index => $record) {
        $expected = $index < 2 ? HeroContentMigration::transform($before[$index]['post_content'])['content'] : $before[$index]['post_content'];
        $check($record['post_content'] === $expected, 'Stored content must match scoped transform; excluded records must be untouched.');
        unset($record['post_content']);
        $original = $before[$index];
        unset($original['post_content']);
        $check($record === $original, 'Only post_content may change; metadata/timestamps must be preserved.');
    }
    $laterEdit = $after[1]['post_content'] . "\n<!-- Later editor change -->";
    $wpdb->update($table, ['post_content' => $laterEdit], ['ID' => 900002]);
    try {
        HeroContentMigration::applyBackup($backup, true);
        throw new LogicException('Restore must not overwrite edits made after migration.');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'changed since backup'), 'Expected restore conflict rejection.');
    }
    $check($wpdb->get_var("SELECT post_content FROM {$table} WHERE ID = 900001") === $after[0]['post_content'], 'Partial restore must roll back on conflict.');
    $check($wpdb->get_var("SELECT post_content FROM {$table} WHERE ID = 900002") === $laterEdit, 'Restore must preserve later edits.');
    $wpdb->update($table, ['post_content' => $after[1]['post_content']], ['ID' => 900002]);
    $check(HeroContentMigration::applyBackup($backup, true)['changed'] === 2, 'Restore must recover all records.');
    $check($wpdb->get_results("SELECT * FROM {$table} ORDER BY ID", ARRAY_A) === $before, 'Restore must be byte-exact.');
    $check(HeroContentMigration::applyBackup($backup, true)['changed'] === 0, 'Restore must be idempotent.');
    echo "Isolated migration database apply/restore/conflict/rollback checks passed.\n";
} finally {
    $wpdb->posts = $originalPosts;
    if ($wpdb->query("DROP TABLE {$table}") === false) throw new RuntimeException('Could not clean isolated migration test table.');
    if (is_file($backup)) unlink($backup);
}

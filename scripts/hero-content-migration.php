<?php

final class HeroContentMigration
{
    public static function transform(string $content): array
    {
        $blocks = parse_blocks($content);
        $legacy = 0;
        $current = 0;
        $walk = static function (array $blocks) use (&$walk, &$legacy, &$current): array {
            foreach ($blocks as &$block) {
                $block['innerBlocks'] = $walk($block['innerBlocks']);
                if ($block['blockName'] !== 'abcnorio/hero') continue;

                $mask = implode('', array_map(
                    static fn ($part) => $part === null ? "\0" : $part,
                    $block['innerContent']
                ));
                if (preg_match('/\A[\s\x00]*\z/D', $mask)) {
                    $current++;
                    continue;
                }
                if (!preg_match(
                    '/\A\s*<div class="abcnorio-hero__content-wrapper">\s*<div class="abcnorio-hero__content">[\s\x00]*<\/div>\s*<\/div>\s*\z/D',
                    $mask
                )) {
                    throw new RuntimeException('Unexpected hero markup; manual review required.');
                }
                $block['innerContent'] = array_map(
                    static fn ($part) => $part === null ? null : str_replace([
                        '<div class="abcnorio-hero__content-wrapper">',
                        '<div class="abcnorio-hero__content">',
                        '</div>',
                    ], '', $part),
                    $block['innerContent']
                );
                $block['innerHTML'] = implode('', array_filter($block['innerContent'], 'is_string'));
                $legacy++;
            }
            unset($block);
            return $blocks;
        };
        $changed = $walk($blocks);
        if ($legacy === 0) {
            return ['content' => $content, 'legacy' => 0, 'current' => $current];
        }
        if (serialize_blocks($blocks) !== $content) {
            throw new RuntimeException('Block serialization would also alter existing content; manual review required.');
        }
        $result = serialize_blocks($changed);
        $signature = static function (array $blocks) use (&$signature): array {
            foreach ($blocks as &$block) {
                $block['innerBlocks'] = $signature($block['innerBlocks']);
                if ($block['blockName'] === 'abcnorio/hero') {
                    unset($block['innerHTML'], $block['innerContent']);
                }
            }
            unset($block);
            return $blocks;
        };
        if ($signature($blocks) !== $signature(parse_blocks($result))) {
            throw new RuntimeException('Migration changed block attributes, children or unrelated content.');
        }
        return ['content' => $result, 'legacy' => $legacy, 'current' => $current];
    }

    public static function inventory(): array
    {
        global $wpdb;
        if (is_multisite()) {
            throw new RuntimeException('Run an explicit multisite migration plan; this script targets one site only.');
        }
        $needle = '%' . $wpdb->esc_like('wp:abcnorio/hero') . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type, post_status, post_content FROM {$wpdb->posts}
             WHERE post_type <> 'revision' AND post_status NOT IN ('draft', 'auto-draft', 'trash')
             AND REPLACE(post_content, CHAR(92), '') LIKE %s ORDER BY ID",
            $needle
        ), ARRAY_A);
        if ($wpdb->last_error !== '') throw new RuntimeException('Could not inventory post content.');
        $report = ['records' => count($rows), 'legacy' => 0, 'current' => 0, 'groups' => [], 'blocked' => [], 'other_stores' => []];
        $changes = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            try {
                $result = self::transform($row['post_content']);
                if ($result['legacy'] + $result['current'] === 0) {
                    throw new RuntimeException('Hero marker found but no hero block parsed.');
                }
                if (self::transform($result['content'])['legacy'] !== 0) {
                    throw new RuntimeException('Migration is not idempotent.');
                }
                $report['legacy'] += $result['legacy'];
                $report['current'] += $result['current'];
                $group = $row['post_type'] . '/' . $row['post_status'];
                $report['groups'][$group] = ($report['groups'][$group] ?? 0) + 1;
                if ($result['legacy'] > 0) {
                    $changes[] = ['id' => $id, 'before' => $row['post_content'], 'after' => $result['content']];
                }
            } catch (RuntimeException $error) {
                $report['blocked'][] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }
        foreach ([
            [$wpdb->postmeta, 'meta_value'],
            [$wpdb->options, 'option_value'],
            [$wpdb->termmeta, 'meta_value'],
            [$wpdb->usermeta, 'meta_value'],
            [$wpdb->posts, 'post_content_filtered'],
        ] as [$table, $column]) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE REPLACE(`{$column}`, CHAR(92), '') LIKE %s",
                $needle
            ));
            if ($wpdb->last_error !== '') throw new RuntimeException('Could not inventory secondary content stores.');
            $report['other_stores'][$table . '.' . $column] = (int) $count;
        }
        return ['report' => $report, 'changes' => $changes];
    }

    public static function assertReady(array $report): void
    {
        if ($report['blocked'] !== [] || array_sum($report['other_stores']) > 0) {
            throw new RuntimeException('Inventory found content requiring review. No migration permitted.');
        }
    }

    private static function siteKey(): string
    {
        global $wpdb;
        return hash('sha256', home_url('/') . '|' . $wpdb->posts);
    }

    public static function backup(string $path): array
    {
        $inventory = self::inventory();
        self::assertReady($inventory['report']);
        if ($inventory['changes'] === []) throw new RuntimeException('No legacy hero content needs migration.');
        $directory = realpath(dirname($path));
        if (!str_starts_with($path, '/') || $directory === false) {
            throw new RuntimeException('Use an absolute backup path in an existing private directory.');
        }
        foreach ([ABSPATH, WP_CONTENT_DIR] as $publicPath) {
            $publicDirectory = realpath($publicPath);
            if ($publicDirectory !== false
                && ($directory === $publicDirectory || str_starts_with($directory, $publicDirectory . '/'))) {
                throw new RuntimeException('Backup must be outside WordPress publicly served directories.');
            }
        }
        $data = json_encode([
            'version' => 1,
            'site' => self::siteKey(),
            'created' => gmdate('c'),
            'report' => $inventory['report'],
            'records' => $inventory['changes'],
        ], JSON_THROW_ON_ERROR);
        $mask = umask(0077);
        try {
            $file = fopen($path, 'x');
        } finally {
            umask($mask);
        }
        if ($file === false) throw new RuntimeException('Could not create an exclusive private backup.');
        try {
            if (fwrite($file, $data) !== strlen($data) || !fflush($file)) {
                throw new RuntimeException('Could not write complete backup. No database changes made.');
            }
        } finally {
            fclose($file);
        }
        self::readBackup($path);
        return $inventory['report'];
    }

    public static function readBackup(string $path): array
    {
        if (!is_file($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Backup must exist with private permissions (0600).');
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($data['version'] ?? null) !== 1 || ($data['site'] ?? null) !== self::siteKey()
            || !is_array($data['records'] ?? null) || $data['records'] === []) {
            throw new RuntimeException('Backup version/site/records do not match this installation.');
        }
        $seen = [];
        foreach ($data['records'] as $record) {
            if (!is_int($record['id'] ?? null) || $record['id'] <= 0 || isset($seen[$record['id']])
                || !is_string($record['before'] ?? null) || !is_string($record['after'] ?? null)) {
                throw new RuntimeException('Invalid or duplicate backup record.');
            }
            $seen[$record['id']] = true;
            $result = self::transform($record['before']);
            if ($result['legacy'] === 0 || $result['content'] !== $record['after']) {
                throw new RuntimeException('Backup transformation verification failed.');
            }
        }
        return $data;
    }

    public static function applyBackup(string $path, bool $restore = false): array
    {
        global $wpdb;
        $data = self::readBackup($path);
        $engine = $wpdb->get_row($wpdb->prepare(
            'SHOW TABLE STATUS LIKE %s',
            $wpdb->esc_like($wpdb->posts)
        ), ARRAY_A);
        if (($engine['Engine'] ?? '') !== 'InnoDB') {
            throw new RuntimeException('Migration requires an InnoDB post table for transactional rollback.');
        }
        if (!$restore) self::assertReady(self::inventory()['report']);
        if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('Could not start migration transaction.');
        $changed = [];
        try {
            foreach ($data['records'] as $record) {
                $expected = $restore ? $record['after'] : $record['before'];
                $target = $restore ? $record['before'] : $record['after'];
                $actual = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE",
                    $record['id']
                ));
                if ($wpdb->last_error !== '') throw new RuntimeException('Could not lock migration record.');
                if ($actual === $target) continue;
                if ($actual !== $expected) {
                    throw new RuntimeException("Record {$record['id']} changed since backup; refusing to overwrite it.");
                }
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_content = %s WHERE ID = %d AND BINARY post_content = BINARY %s",
                    $target,
                    $record['id'],
                    $expected
                ));
                if ($updated !== 1) throw new RuntimeException("Could not migrate record {$record['id']}.");
                $stored = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d",
                    $record['id']
                ));
                if ($wpdb->last_error !== '' || $stored !== $target) {
                    throw new RuntimeException("Stored content verification failed for record {$record['id']}.");
                }
                $changed[] = $record['id'];
            }
            if (!$restore) {
                $report = self::inventory()['report'];
                self::assertReady($report);
                if ($report['legacy'] !== 0) throw new RuntimeException('Legacy heroes remain outside the verified backup.');
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('Could not commit migration.');
        } catch (Throwable $error) {
            if ($wpdb->query('ROLLBACK') === false) {
                throw new RuntimeException('Migration failed and transaction rollback also failed.', 0, $error);
            }
            throw $error;
        }
        foreach ($changed as $id) clean_post_cache($id);
        return ['changed' => count($changed), 'restored' => $restore];
    }
}

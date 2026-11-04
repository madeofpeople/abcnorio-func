<?php

namespace abcnorio\CustomFunc\Support;

use RuntimeException;

/**
 * Rewrites other environments' hosts in content to this environment's hosts.
 * Dry run unless $apply is true. Apply runs in one transaction and rolls back on any guard failure.
 */
final class UrlNormalizer
{
    private const TARGETS = [
        ['table' => 'posts', 'pk' => 'ID', 'column' => 'post_content'],
        ['table' => 'posts', 'pk' => 'ID', 'column' => 'post_excerpt'],
        ['table' => 'postmeta', 'pk' => 'meta_id', 'column' => 'meta_value'],
        ['table' => 'options', 'pk' => 'option_id', 'column' => 'option_value'],
        ['table' => 'termmeta', 'pk' => 'meta_id', 'column' => 'meta_value'],
    ];

    private const BATCH = 500;

    private const SAMPLE_IDS = 10;

    private function __construct(
        private readonly UrlRewriter $rewriter,
        private readonly array $foreignHosts,
    ) {
    }

    public static function fromEnv(): self
    {
        $own = self::hostOf((string) getenv('WP_HOME'));
        $scheme = (string) parse_url((string) getenv('WP_HOME'), PHP_URL_SCHEME);

        $wpHosts = self::hostsOf(['DEV_WP_URL', 'STAGING_WP_URL']);
        $frontendHosts = self::hostsOf(['DEV_FRONTEND_URL', 'STAGING_FRONTEND_URL', 'PREVIEW_FRONTEND_URL', 'PRODUCTION_FRONTEND_URL']);

        if ($own === '' || $scheme === '') {
            throw new RuntimeException('WP_HOME is not set; cannot determine own host.');
        }
        if ($wpHosts === [] || $frontendHosts === []) {
            throw new RuntimeException('Environment host lists are empty (DEV_WP_URL, STAGING_WP_URL, *_FRONTEND_URL).');
        }
        if (!in_array($own, $wpHosts, true)) {
            throw new RuntimeException('Own host is not in the WP host list; refusing to run.');
        }
        if (in_array($own, $frontendHosts, true)) {
            throw new RuntimeException('Own WP host is listed as a frontend host; refusing to run.');
        }

        $foreign = array_values(array_diff(array_merge($wpHosts, $frontendHosts), [$own]));

        return new self(new UrlRewriter($wpHosts, $frontendHosts, $own, $scheme), $foreign);
    }

    public function run(bool $apply): array
    {
        global $wpdb;

        if ($apply) {
            $this->requireInnoDb();
            $wpdb->query('START TRANSACTION');
        }

        try {
            $report = ['applied' => $apply, 'targets' => []];
            foreach (self::TARGETS as $target) {
                $report['targets'][] = $this->process($target, $apply);
            }
            if ($apply) {
                $this->assertClean();
                $wpdb->query('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($apply) {
                $wpdb->query('ROLLBACK');
            }
            throw new RuntimeException('Rolled back: ' . $e->getMessage(), 0, $e);
        }

        $report['rows_changed'] = array_sum(array_column($report['targets'], 'changed'));

        return $report;
    }

    private function process(array $target, bool $apply): array
    {
        global $wpdb;

        $table = $wpdb->{$target['table']};
        $pk = $target['pk'];
        $column = $target['column'];
        $result = ['table' => $target['table'], 'column' => $column, 'changed' => 0, 'ids' => []];
        $rowsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
        $lastId = 0;

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT `$pk` AS id, `$column` AS value FROM `$table` WHERE `$pk` > %d AND (" . $this->likeClause($column) . ") ORDER BY `$pk` LIMIT %d",
                array_merge([$lastId], $this->likeArgs(), [self::BATCH])
            ));
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $new = $this->rewriter->rewrite($row->value);
                if ($new === $row->value) {
                    continue;
                }
                if (!$this->rewriter->isEquivalent($row->value, $new)) {
                    throw new RuntimeException("Equivalence check failed: {$target['table']}.$column id {$row->id}");
                }
                $result['changed']++;
                if (count($result['ids']) < self::SAMPLE_IDS) {
                    $result['ids'][] = (int) $row->id;
                }
                if ($apply && $wpdb->update($table, [$column => $new], [$pk => $row->id]) === false) {
                    throw new RuntimeException("Update failed: {$target['table']}.$column id {$row->id}");
                }
            }
        }

        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`") !== $rowsBefore) {
            throw new RuntimeException("Row count changed in {$target['table']}");
        }

        return $result;
    }

    private function assertClean(): void
    {
        global $wpdb;

        foreach (self::TARGETS as $target) {
            $table = $wpdb->{$target['table']};
            $column = $target['column'];
            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT `$column` FROM `$table` WHERE " . $this->likeClause($column),
                $this->likeArgs()
            ));
            foreach ($values as $value) {
                if ($this->rewriter->containsForeign($value)) {
                    throw new RuntimeException("Foreign host still present in {$target['table']}.$column after rewrite");
                }
            }
        }
    }

    private function requireInnoDb(): void
    {
        global $wpdb;

        foreach (self::TARGETS as $target) {
            $table = $wpdb->{$target['table']};
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                throw new RuntimeException("Table $table is not InnoDB; transaction rollback unavailable.");
            }
        }
    }

    private function likeClause(string $column): string
    {
        return implode(' OR ', array_fill(0, count($this->foreignHosts), "`$column` LIKE %s"));
    }

    private function likeArgs(): array
    {
        global $wpdb;

        return array_map(fn (string $host) => '%' . $wpdb->esc_like($host) . '%', $this->foreignHosts);
    }

    private static function hostsOf(array $envNames): array
    {
        $hosts = [];
        foreach ($envNames as $name) {
            $host = self::hostOf((string) getenv($name));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function hostOf(string $url): string
    {
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return '';
        }

        return $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}

<?php

namespace abcnorio\CustomFunc\Support;

/**
 * Pure URL normalisation, no WordPress dependencies.
 *
 * - Other environments' WP hosts become this environment's WP host.
 * - Any environment's frontend host becomes a relative path.
 * Handles plain, JSON-escaped (https:\/\/host) and PHP-serialized values.
 */
final class UrlRewriter
{
    private const FRONTEND_TOKEN = '@FE@';
    private const WP_TOKEN = '@WP@';

    private string $foreignWpPattern;
    private string $allWpPattern;
    private string $frontendPattern;

    /**
     * @param string[] $wpHosts       every environment's WP host (host[:port]), including our own
     * @param string[] $frontendHosts every environment's frontend host (host[:port])
     */
    public function __construct(
        private array $wpHosts,
        private array $frontendHosts,
        private string $ownWpHost,
        private string $ownScheme = 'https'
    ) {
        $foreign = array_values(array_diff($wpHosts, [$ownWpHost]));
        $this->foreignWpPattern = self::origin($foreign);
        $this->allWpPattern = self::origin($wpHosts);
        $this->frontendPattern = self::origin($frontendHosts) . '(\\\\?/)?';
    }

    public function rewrite(string $value): string
    {
        $data = $this->unserializeIfNeeded($value);
        if ($data === null) {
            return $this->rewriteString($value);
        }

        return serialize($this->rewriteDeep($data));
    }

    public function containsForeign(string $value): bool
    {
        return $this->rewrite($value) !== $value;
    }

    /** True when $new differs from $old only by the host/origin substitutions this class makes. */
    public function isEquivalent(string $old, string $new): bool
    {
        $oldData = $this->unserializeIfNeeded($old);
        $newData = $this->unserializeIfNeeded($new);

        if ($oldData === null || $newData === null) {
            return $oldData === $newData && $this->maskOld($old) === $this->maskNew($new);
        }

        return $this->maskDeepOld($oldData) === $this->maskDeepNew($newData);
    }

    private static function origin(array $hosts): string
    {
        if ($hosts === []) {
            return '(?!)';
        }

        $alternatives = implode('|', array_map(static fn (string $host): string => preg_quote($host, '#'), $hosts));

        return 'https?:(?:\\\\?/){2}(?:' . $alternatives . ')(?![A-Za-z0-9_-]|\\.[A-Za-z0-9])';
    }

    private function rewriteString(string $value): string
    {
        $value = preg_replace_callback(
            '#' . $this->foreignWpPattern . '#',
            fn (array $m): string => str_contains($m[0], '\\')
                ? str_replace('/', '\\/', $this->ownScheme . '://' . $this->ownWpHost)
                : $this->ownScheme . '://' . $this->ownWpHost,
            $value
        );

        return preg_replace_callback(
            '#' . $this->frontendPattern . '#',
            static fn (array $m): string => $m[1] ?? '/',
            $value
        );
    }

    private function maskOld(string $value): string
    {
        $value = preg_replace('#' . $this->allWpPattern . '#', self::WP_TOKEN, $value);
        $value = preg_replace_callback(
            '#' . $this->frontendPattern . '#',
            static fn (array $m): string => self::FRONTEND_TOKEN . ($m[1] ?? '/'),
            $value
        );

        return str_replace(self::FRONTEND_TOKEN, '', $value);
    }

    private function maskNew(string $value): string
    {
        return preg_replace('#' . $this->allWpPattern . '#', self::WP_TOKEN, $value);
    }

    private function maskDeepOld(mixed $data): mixed
    {
        return $this->mapStrings($data, fn (string $s): string => $this->maskOld($s));
    }

    private function maskDeepNew(mixed $data): mixed
    {
        return $this->mapStrings($data, fn (string $s): string => $this->maskNew($s));
    }

    private function rewriteDeep(mixed $data): mixed
    {
        return $this->mapStrings($data, fn (string $s): string => $this->rewrite($s));
    }

    private function mapStrings(mixed $data, callable $fn): mixed
    {
        if (is_string($data)) {
            return $fn($data);
        }

        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $item) {
                $out[$key] = $this->mapStrings($item, $fn);
            }

            return $out;
        }

        return $data;
    }

    /** Returns the unserialized data, or null when the value is not serialized. */
    private function unserializeIfNeeded(string $value): mixed
    {
        if (!preg_match('/^(?:a|O|C|E):\d+:|^[sbid]:[^;]*;$/', $value)) {
            return null;
        }

        $data = @unserialize($value, ['allowed_classes' => false]);
        if ($data === false && $value !== 'b:0;') {
            return null;
        }

        if (self::containsObject($data)) {
            throw new \RuntimeException('Serialized object in value; refusing to rewrite.');
        }

        return $data;
    }

    private static function containsObject(mixed $data): bool
    {
        if (is_object($data)) {
            return true;
        }

        if (is_array($data)) {
            foreach ($data as $item) {
                if (self::containsObject($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}

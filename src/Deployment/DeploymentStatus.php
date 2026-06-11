<?php

namespace abcnorio\CustomFunc\Deployment;

final class DeploymentStatus
{
    /**
     * @return array<int, array{name: string, mtime: int}>
     */
    private static function listProductionBackupsFromArchiveDir(): array
    {
        $archiveDir = self::backupArchiveDir();
        if (!is_dir($archiveDir) || !is_readable($archiveDir)) {
            return [];
        }

        $entries = scandir($archiveDir);
        if (!is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $name) {
            if (!is_string($name) || $name === '' || $name === '.' || $name === '..') {
                continue;
            }

            if (!preg_match('/^abcnorio-astro-production-(?!candidate-).+\\.zip$/', $name)) {
                continue;
            }

            $fullPath = $archiveDir . '/' . $name;
            if (!is_file($fullPath)) {
                continue;
            }

            $mtime = filemtime($fullPath);
            $out[] = ['name' => $name, 'mtime' => is_int($mtime) ? $mtime : 0];
        }

        usort($out, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        $limit = max(1, (int) (getenv('MAX_BACKUPS') ?: 12));
        return array_slice($out, 0, $limit);
    }

    public static function backupArchiveDir(): string
    {
        $configured = trim((string) (getenv('ASTRO_BUILD_ARCHIVE_DIR') ?: ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $workspaceRoot = dirname(ABCNORIO_CUSTOM_FUNC_FILE, 4);
        return rtrim($workspaceRoot, '/') . '/astro/site/build-archives';
    }

    public static function deploymentStatusPath(): string
    {
        $configured = trim((string) (getenv('ASTRO_DEPLOYMENT_STATUS_FILE') ?: ''));
        return $configured !== '' ? $configured : self::backupArchiveDir() . '/deployment-status.json';
    }

    /**
     * @return array{ok: bool, message: string, status: array<string, mixed>}
     */
    public static function read(): array
    {
        $path = self::deploymentStatusPath();

        if (!is_file($path)) {
            return [
                'ok'      => false,
                'message' => sprintf(__('Deployment status file not found: %s', 'abcnorio-func'), $path),
                'status'  => [],
            ];
        }

        if (!is_readable($path)) {
            return [
                'ok'      => false,
                'message' => sprintf(__('Deployment status file is not readable: %s', 'abcnorio-func'), $path),
                'status'  => [],
            ];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'ok'      => false,
                'message' => __('Deployment status file is empty.', 'abcnorio-func'),
                'status'  => [],
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['envs']) || !is_array($decoded['envs'])) {
            return [
                'ok'      => false,
                'message' => __('Deployment status file is invalid or missing envs.', 'abcnorio-func'),
                'status'  => [],
            ];
        }

        return ['ok' => true, 'message' => '', 'status' => $decoded];
    }

    /**
     * @return array{ok: bool, message: string, names: array<int, string>}
     */
    public static function backupNamesForEnv(string $env): array
    {
        $status = self::read();
        if (!$status['ok'] && $env !== 'production') {
            return ['ok' => false, 'message' => $status['message'], 'names' => []];
        }

        $envStatus = $status['ok'] ? ($status['status']['envs'][$env] ?? null) : null;
        $backups = (is_array($envStatus) && isset($envStatus['backups']) && is_array($envStatus['backups']))
            ? $envStatus['backups']
            : [];

        if ($backups === [] && $env === 'production') {
            $fallback = self::listProductionBackupsFromArchiveDir();
            return [
                'ok' => true,
                'message' => '',
                'names' => array_map(static fn(array $entry): string => $entry['name'], $fallback),
            ];
        }

        if ($backups === []) {
            return [
                'ok'      => false,
                'message' => __('Backup metadata is missing for selected environment.', 'abcnorio-func'),
                'names'   => [],
            ];
        }

        $names = [];
        foreach ($backups as $entry) {
            $name = trim($entry['name']);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return ['ok' => true, 'message' => '', 'names' => array_values(array_unique($names))];
    }

    /**
     * @param mixed $value
     */
    private static function normalizeBackupMtime($value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param mixed $envStatus
     * @return array<int, array{name: string, mtime: int}>
     */
    public static function normalizeBackups($envStatus): array
    {
        if (!is_array($envStatus) || !isset($envStatus['backups']) || !is_array($envStatus['backups'])) {
            return [];
        }

        $out = [];
        foreach ($envStatus['backups'] as $entry) {
            $name = trim($entry['name']);
            if ($name === '') {
                continue;
            }

            $mtime = self::normalizeBackupMtime($entry['mtime']);
            if ($mtime === 0 && isset($entry['createdAt']) && is_string($entry['createdAt'])) {
                $parsed = strtotime($entry['createdAt']);
                $mtime = is_int($parsed) ? $parsed : 0;
            }

            $out[] = ['name' => $name, 'mtime' => $mtime];
        }

        usort($out, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        $limit = max(1, (int) (getenv('MAX_BACKUPS') ?: 12));
        return array_slice($out, 0, $limit);
    }

    /**
     * @param mixed $envStatus
     * @return array<int, array{name: string, mtime: int}>
     */
    public static function normalizeProductionBackups(string $env, $envStatus): array
    {
        $normalized = self::normalizeBackups($envStatus);
        if ($normalized !== [] || $env !== 'production') {
            return $normalized;
        }

        return self::listProductionBackupsFromArchiveDir();
    }
}

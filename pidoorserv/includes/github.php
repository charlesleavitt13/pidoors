<?php
/**
 * GitHub repo used for update checks and release downloads.
 *
 * The one-line file GITHUB_REPO (owner/name) is the source of truth so a
 * fork can retarget install/update without hunting hardcoded URLs.
 */

function pidoors_github_repo(?array $config = null): string {
    $candidates = [];
    if (is_array($config) && !empty($config['apppath'])) {
        $candidates[] = rtrim($config['apppath'], '/') . '/GITHUB_REPO';
    }
    $candidates[] = '/var/www/pidoors/GITHUB_REPO';
    $candidates[] = dirname(__DIR__) . '/GITHUB_REPO';
    $candidates[] = dirname(__DIR__, 2) . '/GITHUB_REPO';

    foreach ($candidates as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $value = trim((string)file_get_contents($path));
        if ($value !== '' && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value)) {
            return $value;
        }
    }

    return 'charlesleavitt13/pidoors';
}

function pidoors_github_release_latest_url(?array $config = null): string {
    return 'https://api.github.com/repos/' . pidoors_github_repo($config) . '/releases/latest';
}

function pidoors_github_release_tarball_urls(string $tag_with_v, ?array $config = null): array {
    $repo = pidoors_github_repo($config);
    return [
        "https://github.com/{$repo}/releases/download/{$tag_with_v}/{$tag_with_v}.tar.gz",
        "https://github.com/{$repo}/archive/refs/tags/{$tag_with_v}.tar.gz",
    ];
}

function pidoors_github_release_checksum_url(string $tag_with_v, ?array $config = null): string {
    $repo = pidoors_github_repo($config);
    return "https://github.com/{$repo}/releases/download/{$tag_with_v}/{$tag_with_v}.tar.gz.sha256";
}

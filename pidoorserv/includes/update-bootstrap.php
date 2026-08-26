<?php
/**
 * Server Update Bootstrap
 * PiDoors Access Control System
 *
 * Downloads the release tarball, extracts it, then loads the NEW release's
 * update-worker.php and delegates the actual deployment to it. This ensures
 * the latest update logic always runs, even when upgrading from an older version.
 *
 * Usage:
 *   $result = pidoors_bootstrap_update($config, $pdo_access, $pdo, $target_version);
 */

function pidoors_bootstrap_update(array $config, PDO $pdo_access, PDO $pdo, string $target_version): array {
    $tag_with_v = 'v' . $target_version;

    require_once __DIR__ . '/github.php';

    // Download URLs — try release asset first (has pre-built SPA), fall back to source archive
    $tarball_urls = pidoors_github_release_tarball_urls($tag_with_v, $config);
    $checksum_url = pidoors_github_release_checksum_url($tag_with_v, $config);

    $tmpdir = sys_get_temp_dir() . '/pidoors-server-update-' . uniqid();
    if (!mkdir($tmpdir, 0700, true)) {
        return ['ok' => false, 'msg' => 'Failed to create temporary directory.', 'details' => []];
    }
    $tarball = $tmpdir . '/release.tar.gz';

    // Download — try each URL until one succeeds
    $http_code = 0;
    foreach ($tarball_urls as $url) {
        $ch = curl_init($url);
        $fp = fopen($tarball, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['User-Agent: PiDoors-Update'],
        ]);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($http_code === 200 && file_exists($tarball) && filesize($tarball) >= 1000) {
            break;
        }
    }

    if ($http_code !== 200 || !file_exists($tarball) || filesize($tarball) < 1000) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => "Failed to download release tarball (HTTP $http_code).", 'details' => []];
    }

    // Verify SHA-256 against the published .sha256 asset (same as server-update.sh).
    $sum_file = $tarball . '.sha256';
    $sum_ch = curl_init($checksum_url);
    $sum_fp = fopen($sum_file, 'w');
    curl_setopt_array($sum_ch, [
        CURLOPT_FILE => $sum_fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['User-Agent: PiDoors-Update'],
    ]);
    curl_exec($sum_ch);
    $sum_code = curl_getinfo($sum_ch, CURLINFO_HTTP_CODE);
    curl_close($sum_ch);
    fclose($sum_fp);
    if ($sum_code !== 200 || !file_exists($sum_file) || filesize($sum_file) < 16) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => "Failed to download release checksum (HTTP $sum_code).", 'details' => []];
    }
    $sum_line = trim((string)file_get_contents($sum_file));
    $expected = strtolower(strtok($sum_line, " \t"));
    $actual = strtolower(hash_file('sha256', $tarball) ?: '');
    if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !hash_equals($expected, $actual)) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => 'Release checksum mismatch — aborting update.', 'details' => []];
    }

    // Extract
    try {
        $phar = new PharData($tarball);
        $phar->extractTo($tmpdir);
    } catch (Exception $e) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => 'Failed to extract release archive: ' . $e->getMessage(), 'details' => []];
    }

    // Find extracted directory
    $dirs = glob($tmpdir . '/pidoors-*', GLOB_ONLYDIR);
    if (empty($dirs)) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => 'Could not find extracted release directory.', 'details' => []];
    }

    $extracted = $dirs[0];

    // Load the NEW release's update worker (self-update pattern)
    // This ensures the latest deployment logic always runs
    $new_worker = $extracted . '/pidoorserv/includes/update-worker.php';
    if (file_exists($new_worker)) {
        include $new_worker;
    } else {
        // Fallback: use the currently installed worker (pre-3.0.1 releases)
        if (!function_exists('pidoors_deploy_update')) {
            require_once __DIR__ . '/update-worker.php';
        }
    }

    if (!function_exists('pidoors_deploy_update')) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => 'Update worker function not found in release.', 'details' => []];
    }

    // Run the deployment using the new release's logic
    $result = pidoors_deploy_update($config, $pdo_access, $pdo, $extracted);

    // Cleanup temp files
    @exec('rm -rf ' . escapeshellarg($tmpdir));

    return $result;
}

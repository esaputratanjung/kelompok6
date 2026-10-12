<?php
/**
 * Alternatif GRATIS untuk Render Cron Job: panggil URL ini tiap menit dari layanan
 * seperti cron-job.org, dengan header  X-Cron-Secret: <CRON_SECRET>
 * (atau /cron.php?key=<CRON_SECRET>). Jika CRON_SECRET kosong, endpoint ini nonaktif.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

run_api(function (): array {
    $secret = env('CRON_SECRET');
    $given = (string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['key'] ?? '');
    if (!$secret || strlen($secret) < 16 || !hash_equals($secret, $given)) {
        throw new ApiError('Forbidden.', 403);
    }
    return ['ok' => true] + auto_release();
});

<?php
/**
 * Auto release — dijalankan Render Cron Job setiap menit:
 *   php cron/auto_release.php
 *
 *  - booked tanpa check-in > 10 menit setelah jam mulai  -> expired (ruang dilepas)
 *  - checked_in yang sudah lewat jam selesai             -> completed
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya untuk CLI.');
}

try {
    $result = auto_release();
    printf("[%s] auto_release: %d expired, %d completed\n", date('Y-m-d H:i:s'), $result['expired'], $result['completed']);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] auto_release GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}

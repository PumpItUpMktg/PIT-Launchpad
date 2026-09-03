<?php

use Illuminate\Support\Facades\File;

test('the committed queue retry_after default exceeds every job $timeout, so a running job is never re-reserved', function () {
    // Read the COMMITTED default from config/queue.php source — the value that ships and that a
    // deployment sets DB_QUEUE_RETRY_AFTER to. Reading the source (not env-resolved config) makes
    // this guard deterministic regardless of any local/CI DB_QUEUE_RETRY_AFTER override.
    preg_match("/DB_QUEUE_RETRY_AFTER',\s*(\d+)\)/", File::get(base_path('config/queue.php')), $m);
    $retryAfter = (int) ($m[1] ?? 0);

    $max = 0;
    $offender = null;
    foreach (File::allFiles(app_path('Jobs')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $class = 'App\\Jobs\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
        if (! class_exists($class)) {
            continue;
        }
        $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;
        if (is_int($timeout) && $timeout > $max) {
            [$max, $offender] = [$timeout, $class];
        }
    }

    // Sanity: we parsed a default and actually scanned jobs that declare a $timeout.
    expect($retryAfter)->toBeGreaterThan(0)
        ->and($max)->toBeGreaterThan(0)
        // The invariant: retry_after must clear the longest job ({$offender}) so it is never
        // retried mid-run.
        ->and($retryAfter)->toBeGreaterThan($max);
});

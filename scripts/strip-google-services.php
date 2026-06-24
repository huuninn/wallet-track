<?php

declare(strict_types=1);

/*
 * Strips unused Google API service stubs from google/apiclient-services,
 * keeping only the Sheets service classes that the app actually uses.
 *
 * Reduces vendor/google/ from ~212 MB to ~13 MB (saves ~199 MB).
 *
 * Idempotent: safe to run multiple times.
 * Graceful: if the package isn't installed yet, does nothing.
 */

$servicesBase = dirname(__DIR__).'/vendor/google/apiclient-services/src';

if (! is_dir($servicesBase)) {
    echo "[strip-google-services] Package not installed, skipping.\n";

    exit(0);
}

$keep = ['Sheets.php', 'Sheets'];
$removed = 0;
$keptFiles = [];
$keptDirs = [];

$iterator = new FilesystemIterator($servicesBase, FilesystemIterator::SKIP_DOTS);

/** @var SplFileInfo $item */
foreach ($iterator as $item) {
    $basename = $item->getFilename();

    if (in_array($basename, $keep, true)) {
        if ($item->isDir()) {
            $keptDirs[] = $item->getPathname();
        } else {
            $keptFiles[] = $item->getPathname();
        }

        continue;
    }

    deleteRecursive($item->getPathname());
    $removed++;
}

echo '[strip-google-services] Kept '.count($keptFiles).' files + '.count($keptDirs)
    ." directories; removed {$removed} unused service stubs.\n";

/**
 * Recursively delete a file or directory.
 */
function deleteRecursive(string $path): void
{
    if (! file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }

    $dir = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
    $items = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
        $itemPath = $item->getPathname();
        if ($item->isDir()) {
            rmdir($itemPath);
        } else {
            unlink($itemPath);
        }
    }

    rmdir($path);
}

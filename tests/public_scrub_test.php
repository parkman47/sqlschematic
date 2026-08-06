<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbidden = array_map('hex2bin', [
    '7368697470696e617461',
    '7465616d62617463617665',
    '7465616d2062617463617665',
    '633a5c636f646532',
]);

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $item): bool => $item->getFilename() !== '.git'
    )
);

foreach ($iterator as $item) {
    if (!$item->isFile()) {
        continue;
    }
    $relative = strtolower(str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1)));
    $contents = strtolower((string) file_get_contents($item->getPathname()));
    foreach ($forbidden as $term) {
        assert(!str_contains($relative, $term), 'Private project identifier found in path: ' . $relative);
        assert(!str_contains($contents, $term), 'Private project identifier found in file: ' . $relative);
    }
}

echo "Public-source scrub regression passed.\n";

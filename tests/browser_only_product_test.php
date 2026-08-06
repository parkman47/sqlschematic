<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
assert(!array_key_exists('bin', $composer));
assert(!is_file($root . '/bin/sqlschematic'));

$documents = [
    $root . '/README.md',
    ...glob($root . '/docs/*.md') ?: [],
];
foreach ($documents as $document) {
    $text = (string) file_get_contents($document);
    assert(!preg_match('/\bCLI\b/i', $text), basename($document) . ' advertises a CLI');
    assert(!str_contains($text, 'bin/sqlschematic'), basename($document) . ' references a command-line executable');
}

echo "Browser-only product boundary passed.\n";

<?php

declare(strict_types=1);

$root = dirname(__DIR__);
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = substr($arg, 7);
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(1);
    }
}
$errors = [];
$names = ['theses', 'philosophy', 'core-boundary', 'layering-rules', 'structure', 'ownership', 'extension-axes', 'invariants', 'privacy', 'spi', 'glossary'];
$files = [];
foreach ($names as $name) {
    $files[] = "$root/docs/architecture/$name.md";
}
for ($i = 1; $i <= 16; $i++) {
    $files[] = sprintf('%s/docs/adr/%04d.md', $root, $i);
}
foreach ($files as $file) {
    if (! is_file($file) || trim((string) file_get_contents($file)) === '') {
        $errors[] = "Missing or empty: $file";

        continue;
    }
    $content = (string) file_get_contents($file);
    if (preg_match('~(?:/home/|\~/|Brain|\[\[|(?:\.\./)+(?:chatom|azguard|edufy|vaulter))~i', $content)) {
        $errors[] = "Forbidden filesystem reference: $file";
    }
    if (preg_match_all('/^## ([^\r\n]+)\R(.*?)(?=^#{1,2} |\z)/msu', $content, $sections, PREG_SET_ORDER)) {
        foreach ($sections as $section) {
            if (trim($section[2]) === '') {
                $errors[] = "Empty section {$section[1]}: $file";
            }
        }
    }
    if (str_contains($file, '/adr/')) {
        if (! preg_match('/^# ADR-\d{4}:\s*\S/m', $content)) {
            $errors[] = "Invalid ADR heading: $file";
        }
        foreach (['Статус', 'Контекст', 'Решение', 'Последствия', 'Альтернативы'] as $section) {
            if (! preg_match('/^## '.preg_quote($section, '/').'\s*\R+(?!\s*#)\s*\S/mu', $content)) {
                $errors[] = "Missing or empty ADR section $section: $file";
            }
        }
    } elseif (! preg_match('/^#\s+\S/mu', $content)) {
        $errors[] = "Missing heading: $file";
    }
}
$theses = @file_get_contents("$root/docs/architecture/theses.md");
if ($theses !== false) {
    for ($i = 1; $i <= 34; $i++) {
        $id = sprintf('T-%02d', $i);
        if (! preg_match('/^\|\s*'.preg_quote($id, '/').'\s*\|.+\|.+\|\s*$/mu', $theses)) {
            $errors[] = "Missing thesis or check: $id";
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}
echo "OK\n";

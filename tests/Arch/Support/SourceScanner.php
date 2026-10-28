<?php

declare(strict_types=1);

namespace SocKit\Tests\Arch\Support;

use RuntimeException;

/**
 * A source inventory, never a grep over comments or class bodies.
 *
 * @phpstan-type Token array{id: int, text: string, line: int}
 * @phpstan-type Declaration array{name: string, namespace: string, short: string, kind: int, line: int, attributes: list<string>, final: bool, readonly: bool}
 * @phpstan-type Reference array{name: string, namespace: string, line: int}
 * @phpstan-type Source array{file: string, types: list<Declaration>, references: list<Reference>, identifiers: list<array{text: string, line: int}>}
 */
final class SourceScanner
{
    /** @return list<string> */
    public static function php(string $directory): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException('Missing source directory: '.$directory);
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> */
    public static function fixture(string $path): array
    {
        $file = __DIR__.'/../fixtures/'.$path;
        if (! is_file($file)) {
            throw new RuntimeException('Missing fixture: '.$file);
        }

        return [$file];
    }

    public static function read(string $file): string
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('Unreadable source: '.$file);
        }

        return $source;
    }

    /** @return Source */
    public static function scan(string $file): array
    {
        $tokens = self::tokens(self::read($file));
        $namespace = '';
        $namespaceDepth = 0;
        $depth = 0;
        $imports = [];
        $types = [];
        $references = [];
        $identifiers = [];
        $attributes = [];
        $modifiers = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = $token['text'];
            $id = $token['id'];

            if ($id === T_NAMESPACE) {
                $namespace = '';
                while (++$i < $count && ! in_array($tokens[$i]['text'], [';', '{'], true)) {
                    $namespace .= $tokens[$i]['text'];
                }
                $imports = [];
                $attributes = [];
                $modifiers = [];
                if (($tokens[$i]['text'] ?? '') === '{') {
                    $depth++;
                }
                $namespaceDepth = $depth;

                continue;
            }

            // Namespace use only: trait use and closure captures are not imports.
            if ($id === T_USE && $depth === $namespaceDepth && ($tokens[$i + 1]['text'] ?? '') !== '(') {
                $statement = [];
                while (++$i < $count && $tokens[$i]['text'] !== ';') {
                    $statement[] = $tokens[$i];
                }
                foreach (self::imports($statement) as $import) {
                    $references[] = ['name' => $import['name'], 'namespace' => $namespace, 'line' => $import['line']];
                    if ($import['class']) {
                        $imports[strtolower($import['alias'])] = $import['name'];
                    }
                }

                continue;
            }

            if ($id === T_ATTRIBUTE) {
                $brackets = 1;
                $parentheses = 0;
                $expectName = true;
                while (++$i < $count) {
                    $part = $tokens[$i];
                    if ($part['text'] === '[') {
                        $brackets++;
                    } elseif ($part['text'] === ']') {
                        if (--$brackets === 0) {
                            break;
                        }
                    } elseif ($part['text'] === '(') {
                        $parentheses++;
                    } elseif ($part['text'] === ')') {
                        $parentheses--;
                    } elseif ($part['text'] === ',' && $parentheses === 0 && $brackets === 1) {
                        $expectName = true;
                    } elseif ($expectName && self::isName($part['id'])) {
                        $name = self::resolve($part['text'], $namespace, $imports);
                        $attributes[] = $name;
                        $references[] = ['name' => $name, 'namespace' => $namespace, 'line' => $part['line']];
                        $expectName = false;
                    }
                    self::identifier($part, $identifiers);
                    if (in_array($part['id'], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true)) {
                        $references[] = ['name' => self::resolve($part['text'], $namespace, $imports), 'namespace' => $namespace, 'line' => $part['line']];
                    }
                }

                continue;
            }

            if (in_array($id, [T_FINAL, T_READONLY, T_ABSTRACT], true)) {
                $modifiers[] = $id;

                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && ($tokens[$i + 1]['id'] ?? 0) === T_STRING) {
                $short = $tokens[$i + 1]['text'];
                $types[] = [
                    'name' => ltrim($namespace.'\\'.$short, '\\'), 'namespace' => $namespace,
                    'short' => $short, 'kind' => $id, 'line' => $token['line'],
                    'attributes' => $attributes, 'final' => in_array(T_FINAL, $modifiers, true),
                    'readonly' => in_array(T_READONLY, $modifiers, true),
                ];
            }
            $attributes = [];
            $modifiers = [];

            if ($text === '{' || in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth < $namespaceDepth) {
                    $namespace = '';
                    $imports = [];
                    $namespaceDepth = $depth;
                }
            }

            self::identifier($token, $identifiers);
            if (in_array($id, [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true)) {
                $references[] = ['name' => self::resolve($text, $namespace, $imports), 'namespace' => $namespace, 'line' => $token['line']];
            }
        }

        return ['file' => $file, 'types' => $types, 'references' => $references, 'identifiers' => $identifiers];
    }

    /** @return list<Token> */
    private static function tokens(string $source): array
    {
        $tokens = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;
            } else {
                $id = 0;
                $text = $token;
            }
            if (! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = ['id' => $id, 'text' => $text, 'line' => $line];
            }
            $line += substr_count($text, "\n");
        }

        return $tokens;
    }

    /**
     * @param  list<Token>  $tokens
     * @return list<array{name: string, alias: string, class: bool, line: int}>
     */
    private static function imports(array $tokens): array
    {
        $kind = 'class';
        if (in_array($tokens[0]['id'] ?? 0, [T_FUNCTION, T_CONST], true)) {
            $kind = $tokens[0]['text'];
            array_shift($tokens);
        }
        $prefix = '';
        foreach ($tokens as $index => $token) {
            if ($token['text'] === '{') {
                $prefix = implode('', array_column(array_slice($tokens, 0, $index), 'text'));
                $tokens = array_slice($tokens, $index + 1, -1);
                break;
            }
        }
        $parts = [[]];
        foreach ($tokens as $token) {
            if ($token['text'] === ',') {
                $parts[] = [];
            } else {
                $parts[array_key_last($parts)][] = $token;
            }
        }
        $result = [];
        foreach ($parts as $part) {
            $partKind = $kind;
            if (in_array($part[0]['id'] ?? 0, [T_FUNCTION, T_CONST], true)) {
                $partKind = $part[0]['text'];
                array_shift($part);
            }
            $name = '';
            $alias = '';
            $as = false;
            foreach ($part as $token) {
                if ($token['id'] === T_AS) {
                    $as = true;
                } elseif ($as) {
                    $alias .= $token['text'];
                } else {
                    $name .= $token['text'];
                }
            }
            $name = ltrim($prefix.$name, '\\');
            $segments = explode('\\', $name);
            $result[] = ['name' => $name, 'alias' => $alias !== '' ? $alias : end($segments), 'class' => $partKind === 'class', 'line' => $part[0]['line'] ?? 1];
        }

        return $result;
    }

    /** @param array<string, string> $imports */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return ltrim($namespace.'\\'.substr($name, 10), '\\');
        }
        $segments = explode('\\', $name);
        $first = array_shift($segments);
        $prefix = $imports[strtolower($first)] ?? ltrim($namespace.'\\'.$first, '\\');

        return $prefix.($segments === [] ? '' : '\\'.implode('\\', $segments));
    }

    private static function isName(int $id): bool
    {
        return in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true);
    }

    /** @param Token $token
     * @param  list<array{text: string, line: int}>  $identifiers
     */
    private static function identifier(array $token, array &$identifiers): void
    {
        if (in_array($token['id'], [T_STRING, T_VARIABLE, T_CONSTANT_ENCAPSED_STRING], true)) {
            $identifiers[] = ['text' => trim($token['text'], '$\'"'), 'line' => $token['line']];
        }
    }
}

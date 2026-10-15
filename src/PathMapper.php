<?php

declare(strict_types=1);

namespace Apache2Router;

/**
 * Normalises filesystem paths written in Apache configs (Unix or Windows
 * style) and rewrites them from where they lived on the original server to
 * where they live now, using "old=new" prefix maps.
 */
final class PathMapper
{
    /** @var list<array{0:string,1:string}> */
    private array $maps = [];

    /** @param list<array{0:string,1:string}> $maps old => new pairs */
    public function __construct(array $maps = [])
    {
        foreach ($maps as [$old, $new]) {
            $this->add($old, $new);
        }
    }

    public function add(string $old, string $new): void
    {
        $old = self::normalize($old);
        $new = self::normalize($new);
        foreach ($this->maps as [$o]) {
            if (self::samePath($o, $old)) {
                return;
            }
        }
        $this->maps[] = [$old, $new];
        usort($this->maps, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
    }

    /** @return list<array{0:string,1:string}> */
    public function maps(): array
    {
        return $this->maps;
    }

    /** Convert backslashes, collapse duplicate slashes and resolve "." and "..". */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        $prefix = '';
        if (preg_match('#^([A-Za-z]:)(/|$)#', $path, $m)) {
            $prefix = strtoupper($m[1][0]) . ':';
            $path = (string) substr($path, 2);
        } elseif (str_starts_with($path, '//')) {
            // UNC path: keep the leading double slash.
            $prefix = '/';
            $path = substr($path, 1);
        }
        $absolute = str_starts_with($path, '/');
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                } elseif (!$absolute) {
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $seg;
        }
        $joined = implode('/', $out);
        if ($absolute) {
            return $prefix . '/' . $joined;
        }
        return $prefix . ($joined === '' && $prefix === '' ? '.' : $joined);
    }

    public static function isAbsolute(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    public static function isWindows(string $path): bool
    {
        return (bool) preg_match('#^[A-Za-z]:([/\\\\]|$)#', $path);
    }

    /** Join a relative path onto a base directory; absolute paths pass through. */
    public static function join(string $base, string $path): string
    {
        if (self::isAbsolute($path) || $base === '') {
            return self::normalize($path);
        }
        return self::normalize(rtrim($base, '/\\') . '/' . $path);
    }

    /** Rewrite a path from its original location to its current one. */
    public function map(string $path): string
    {
        $norm = self::normalize($path);
        foreach ($this->maps as [$old, $new]) {
            $rest = self::stripPrefix($old, $norm);
            if ($rest !== null) {
                return self::normalize($new . $rest);
            }
        }
        return $norm;
    }

    /** Whether any map applies to the path. */
    public function isMapped(string $path): bool
    {
        $norm = self::normalize($path);
        foreach ($this->maps as [$old]) {
            if (self::stripPrefix($old, $norm) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Best-effort remap of a regular expression that embeds an old path,
     * as in <DirectoryMatch "^/var/www/(.+)/uploads">.
     */
    public function mapRegex(string $regex): string
    {
        foreach ($this->maps as [$old, $new]) {
            foreach ([$old, preg_quote($old, '#')] as $needle) {
                $pos = PathMapper::isWindows($old) ? stripos($regex, $needle) : strpos($regex, $needle);
                if ($pos !== false) {
                    $replacement = $needle === $old ? $new : preg_quote($new, '#');
                    return substr($regex, 0, $pos) . $replacement . substr($regex, $pos + strlen($needle));
                }
            }
        }
        return $regex;
    }

    /** Returns the remainder ("" or "/...") when $prefix is a path prefix of $path. */
    public static function stripPrefix(string $prefix, string $path): ?string
    {
        $ci = self::isWindows($prefix) || self::isWindows($path);
        $p = rtrim($prefix, '/');
        if ($p === '' || $p === '/' || preg_match('#^[A-Z]:$#i', $p)) {
            // Root of a filesystem or drive.
            if ($p === '' || $p === '/') {
                return str_starts_with($path, '/') ? ($path === '/' ? '' : $path) : null;
            }
            $head = substr($path, 0, 2);
            if (strcasecmp($head, $p) !== 0) {
                return null;
            }
            $rest = (string) substr($path, 2);
            return $rest === '/' ? '' : $rest;
        }
        $len = strlen($p);
        $head = substr($path, 0, $len);
        $equal = $ci ? strcasecmp($head, $p) === 0 : $head === $p;
        if (!$equal) {
            return null;
        }
        $rest = (string) substr($path, $len);
        if ($rest === '' || $rest[0] === '/') {
            return $rest;
        }
        return null;
    }

    public static function samePath(string $a, string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        return (self::isWindows($a) || self::isWindows($b)) ? strcasecmp($a, $b) === 0 : $a === $b;
    }
}

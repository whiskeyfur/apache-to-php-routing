<?php

declare(strict_types=1);

namespace Apache2Router;

/** What the rewrite engine needs from the request it runs against. */
interface RewriteHost
{
    /** Value of %{NAME} (NAME may be "ENV:x", "HTTP:x", ...). */
    public function rewriteVar(string $name, string $current): string;

    /** Value of ${map:key|default}. */
    public function rewriteMap(string $map, string $key, string $default): string;

    /** Whether a URL resolves to an existing file (the -U test). */
    public function rewriteUrlExists(string $url): bool;
}

/**
 * mod_rewrite: compiles RewriteRule/RewriteCond directives and runs them.
 */
final class Rewrite
{
    private const RULE_FLAG_ALIASES = [
        'last' => 'l', 'redirect' => 'r', 'nocase' => 'nc', 'qsappend' => 'qsa', 'qsdiscard' => 'qsd',
        'forbidden' => 'f', 'gone' => 'g', 'proxy' => 'p', 'passthrough' => 'pt', 'chain' => 'c',
        'skip' => 's', 'env' => 'e', 'type' => 't', 'handler' => 'h', 'next' => 'n', 'noescape' => 'ne',
        'cookie' => 'co', 'nosubreq' => 'ns', 'discardpath' => 'dpi', 'qslast' => 'qsl',
    ];

    private const COND_FLAG_ALIASES = ['nocase' => 'nc', 'ornext' => 'or', 'novary' => 'nv'];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(private RewriteHost $host)
    {
    }

    /**
     * Gathers the mod_rewrite directives in one context.
     *
     * @param list<array<string,mixed>> $nodes
     * @return array{present:bool,engine:?bool,base:?string,options:list<string>,rules:list<array<string,mixed>>,maps:array<string,array{type:string,source:string}>}
     */
    public static function collect(array $nodes): array
    {
        $rw = ['present' => false, 'engine' => null, 'base' => null, 'options' => [], 'rules' => [], 'maps' => []];
        $conds = [];
        foreach ($nodes as $n) {
            $args = $n['args'];
            switch ($n['d']) {
                case 'rewriteengine':
                    $rw['present'] = true;
                    $rw['engine'] = strtolower($args[0] ?? 'off') === 'on';
                    break;
                case 'rewritebase':
                    $rw['present'] = true;
                    $rw['base'] = $args[0] ?? null;
                    break;
                case 'rewriteoptions':
                    $rw['present'] = true;
                    foreach ($args as $o) {
                        $rw['options'][] = strtolower($o);
                    }
                    break;
                case 'rewritemap':
                    if (isset($args[0], $args[1])) {
                        $spec = $args[1];
                        $colon = strpos($spec, ':');
                        $rw['maps'][$args[0]] = [
                            'type' => strtolower($colon === false ? $spec : substr($spec, 0, $colon)),
                            'source' => $colon === false ? '' : substr($spec, $colon + 1),
                        ];
                    }
                    break;
                case 'rewritecond':
                    $rw['present'] = true;
                    $conds[] = self::compileCond($args, $n['file'], $n['line']);
                    break;
                case 'rewriterule':
                    $rw['present'] = true;
                    $rule = self::compileRule($args, $n['file'], $n['line']);
                    $rule['conds'] = $conds;
                    $conds = [];
                    $rw['rules'][] = $rule;
                    break;
            }
        }
        return $rw;
    }

    /** @param list<string> $args */
    public static function compileRule(array $args, string $file = '', int $line = 0): array
    {
        $pattern = $args[0] ?? '^';
        $negate = str_starts_with($pattern, '!');
        if ($negate) {
            $pattern = substr($pattern, 1);
        }
        $flags = self::parseFlags($args[2] ?? '', self::RULE_FLAG_ALIASES);
        return [
            'pattern' => $pattern,
            'regex' => "\x01" . $pattern . "\x01" . (isset($flags['nc']) ? 'i' : ''),
            'negate' => $negate,
            'subst' => $args[1] ?? '-',
            'flags' => $flags,
            'conds' => [],
            'file' => $file,
            'line' => $line,
            'text' => 'RewriteRule ' . implode(' ', $args),
        ];
    }

    /** @param list<string> $args */
    public static function compileCond(array $args, string $file = '', int $line = 0): array
    {
        $pattern = $args[1] ?? '';
        $flagArg = $args[2] ?? '';
        // Integer comparisons may be written "-gt 5" as two words.
        if (preg_match('/^!?-(eq|ne|lt|le|gt|ge)$/', $pattern) && $flagArg !== '' && $flagArg[0] !== '[') {
            $pattern .= $flagArg;
            $flagArg = $args[3] ?? '';
        }
        return [
            'test' => $args[0] ?? '',
            'pattern' => $pattern,
            'flags' => self::parseFlags($flagArg, self::COND_FLAG_ALIASES),
            'file' => $file,
            'line' => $line,
        ];
    }

    /**
     * @param array<string,string> $aliases
     * @return array<string,mixed>
     */
    public static function parseFlags(string $raw, array $aliases): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw[0] !== '[') {
            return [];
        }
        $raw = trim($raw, '[]');
        $flags = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            $key = strtolower($eq === false ? $part : substr($part, 0, $eq));
            $val = $eq === false ? true : substr($part, $eq + 1);
            $key = $aliases[$key] ?? $key;
            if ($key === 'e' || $key === 'co') {
                $flags[$key][] = $val;
            } else {
                $flags[$key] = $val;
            }
        }
        return $flags;
    }

    /**
     * Runs a rule set against a subject.
     *
     * Result keys: type (none|rewrite|redirect|proxy|forbidden|gone), uri,
     * query, status, end, env, cookies, mime, handler, noescape, rule.
     *
     * @param list<array<string,mixed>> $rules
     * @return array<string,mixed>
     */
    public function run(array $rules, string $subject, string $query): array
    {
        $result = [
            'type' => 'none', 'uri' => $subject, 'query' => $query, 'status' => 0, 'end' => false,
            'env' => [], 'cookies' => [], 'mime' => null, 'handler' => null, 'noescape' => false, 'rule' => null,
            'pt' => false,
        ];
        $current = $subject;
        $count = count($rules);
        $skipChain = false;
        $restarts = 0;
        for ($i = 0; $i < $count; $i++) {
            $rule = $rules[$i];
            $flags = $rule['flags'];
            if ($skipChain) {
                $skipChain = isset($flags['c']);
                continue;
            }
            $m = [];
            $ok = @preg_match($rule['regex'], $current, $m);
            if ($ok === false) {
                $this->warnings[] = "{$rule['file']}:{$rule['line']}: invalid pattern in {$rule['text']}";
                $ok = 0;
            }
            $matched = $rule['negate'] ? $ok === 0 : $ok === 1;
            if (!$matched) {
                $skipChain = isset($flags['c']);
                continue;
            }
            if ($rule['negate']) {
                $m = [];
            }
            $cm = [];
            if (!$this->conditionsPass($rule['conds'], $m, $cm, $current)) {
                $skipChain = isset($flags['c']);
                continue;
            }

            $result['rule'] = $rule;
            $escape = isset($flags['b']);
            foreach ($flags['e'] ?? [] as $e) {
                $e = $this->expand((string) $e, $m, $cm, $current, false);
                if (str_starts_with($e, '!')) {
                    $result['env'][substr($e, 1)] = null;
                } else {
                    $parts = explode(':', $e, 2);
                    $result['env'][$parts[0]] = $parts[1] ?? '';
                }
            }
            foreach ($flags['co'] ?? [] as $co) {
                $result['cookies'][] = $this->expand((string) $co, $m, $cm, $current, false);
            }
            if (isset($flags['t'])) {
                $result['mime'] = $this->expand((string) $flags['t'], $m, $cm, $current, false);
            }
            if (isset($flags['h'])) {
                $result['handler'] = $this->expand((string) $flags['h'], $m, $cm, $current, false);
            }
            if (isset($flags['f'])) {
                $result['type'] = 'forbidden';
                return $result;
            }
            if (isset($flags['g'])) {
                $result['type'] = 'gone';
                return $result;
            }

            if ($rule['subst'] !== '-') {
                $new = $this->expand($rule['subst'], $m, $cm, $current, $escape);
                $qpos = isset($flags['qsl']) ? strrpos($new, '?') : strpos($new, '?');
                if ($qpos !== false) {
                    $newQuery = substr($new, $qpos + 1);
                    $new = substr($new, 0, $qpos);
                    if (isset($flags['qsa']) && $query !== '' && !isset($flags['qsd'])) {
                        $query = $newQuery === '' ? $query : $newQuery . '&' . $query;
                    } else {
                        $query = $newQuery;
                    }
                } elseif (isset($flags['qsd'])) {
                    $query = '';
                }
                $current = $new;
                $result['type'] = 'rewrite';
            } elseif (isset($flags['qsd'])) {
                $query = '';
                $result['type'] = 'rewrite';
            }
            $result['uri'] = $current;
            $result['query'] = $query;
            $result['noescape'] = $result['noescape'] || isset($flags['ne']);
            $result['pt'] = $result['pt'] || isset($flags['pt']);

            if (isset($flags['r'])) {
                $result['type'] = 'redirect';
                $result['status'] = self::redirectStatus($flags['r']);
                return $result;
            }
            if (isset($flags['p'])) {
                $result['type'] = 'proxy';
                return $result;
            }
            if (isset($flags['end'])) {
                $result['end'] = true;
                return $result;
            }
            if (isset($flags['l'])) {
                break;
            }
            if (isset($flags['n'])) {
                if (++$restarts > 32000) {
                    $this->warnings[] = "{$rule['file']}:{$rule['line']}: [N] loop limit reached";
                    break;
                }
                $i = -1;
                continue;
            }
            if (isset($flags['s']) && is_string($flags['s'])) {
                $i += max(0, (int) $flags['s']);
            }
        }
        return $result;
    }

    public static function redirectStatus(mixed $flag): int
    {
        if ($flag === true || $flag === '') {
            return 302;
        }
        $flag = strtolower((string) $flag);
        return match ($flag) {
            'permanent' => 301,
            'temp' => 302,
            'seeother' => 303,
            default => ctype_digit($flag) ? (int) $flag : 302,
        };
    }

    /**
     * @param list<array<string,mixed>> $conds
     * @param array<int|string,string> $rm rule captures
     * @param array<int|string,string> $cm receives the last matched condition's captures
     */
    private function conditionsPass(array $conds, array $rm, array &$cm, string $current): bool
    {
        $n = count($conds);
        for ($i = 0; $i < $n; $i++) {
            $c = $conds[$i];
            $rc = $this->conditionMatches($c, $rm, $cm, $current);
            if (isset($c['flags']['or'])) {
                if (!$rc) {
                    continue;
                }
                // Skip the rest of this OR group.
                while ($i < $n && isset($conds[$i]['flags']['or'])) {
                    $i++;
                }
                continue;
            }
            if (!$rc) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $c
     * @param array<int|string,string> $rm
     * @param array<int|string,string> $cm
     */
    private function conditionMatches(array $c, array $rm, array &$cm, string $current): bool
    {
        if (strtolower($c['test']) === 'expr') {
            $this->warnings[] = "{$c['file']}:{$c['line']}: RewriteCond expr is not supported; treated as false";
            return false;
        }
        $test = $this->expand($c['test'], $rm, $cm, $current, false);
        $p = $c['pattern'];
        $negate = str_starts_with($p, '!');
        if ($negate) {
            $p = substr($p, 1);
        }
        $nc = isset($c['flags']['nc']);

        if (preg_match('/^-(eq|ne|lt|le|gt|ge)(.*)$/', $p, $mm)) {
            $a = (int) $test;
            $b = (int) $mm[2];
            $res = match ($mm[1]) {
                'eq' => $a === $b, 'ne' => $a !== $b, 'lt' => $a < $b,
                'le' => $a <= $b, 'gt' => $a > $b, 'ge' => $a >= $b,
            };
            return $res !== $negate;
        }
        if (in_array($p, ['-d', '-f', '-F', '-s', '-l', '-L', '-h', '-x', '-U'], true)) {
            $res = match ($p) {
                '-d' => $test !== '' && is_dir($test),
                '-f', '-F' => $test !== '' && is_file($test),
                '-s' => $test !== '' && is_file($test) && filesize($test) > 0,
                '-l', '-L', '-h' => $test !== '' && is_link($test),
                '-x' => $test !== '' && is_executable($test),
                '-U' => $this->host->rewriteUrlExists($test),
            };
            return $res !== $negate;
        }
        if (preg_match('/^(<=|>=|<|>|=)(.*)$/s', $p, $mm)) {
            $rhs = $mm[2];
            if ($rhs === '""') {
                $rhs = '';
            }
            $cmp = $nc ? strcasecmp($test, $rhs) : strcmp($test, $rhs);
            $res = match ($mm[1]) {
                '=' => $cmp === 0, '<' => $cmp < 0, '>' => $cmp > 0, '<=' => $cmp <= 0, '>=' => $cmp >= 0,
            };
            return $res !== $negate;
        }

        $m = [];
        $ok = @preg_match("\x01" . $p . "\x01" . ($nc ? 'i' : ''), $test, $m);
        if ($ok === false) {
            $this->warnings[] = "{$c['file']}:{$c['line']}: invalid RewriteCond pattern $p";
            return false;
        }
        if ($ok === 1 && !$negate) {
            $cm = $m;
        }
        return ($ok === 1) !== $negate;
    }

    /**
     * Expands $N, %N, %{VAR} and ${map:key|default} in a string.
     *
     * @param array<int|string,string> $rm
     * @param array<int|string,string> $cm
     */
    public function expand(string $s, array $rm, array $cm, string $current, bool $escapeBackrefs): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            $next = $s[$i + 1] ?? '';
            if ($ch === '\\' && ($next === '$' || $next === '%' || $next === '\\')) {
                $out .= $next;
                $i++;
                continue;
            }
            if ($ch === '$' && ctype_digit($next)) {
                $v = (string) ($rm[(int) $next] ?? '');
                $out .= $escapeBackrefs ? self::escapeBackref($v) : $v;
                $i++;
                continue;
            }
            if ($ch === '%' && ctype_digit($next)) {
                $v = (string) ($cm[(int) $next] ?? '');
                $out .= $escapeBackrefs ? self::escapeBackref($v) : $v;
                $i++;
                continue;
            }
            if (($ch === '%' || $ch === '$') && $next === '{') {
                $end = self::matchingBrace($s, $i + 1);
                if ($end === null) {
                    $out .= $ch;
                    continue;
                }
                $inner = substr($s, $i + 2, $end - $i - 2);
                if ($ch === '%') {
                    $out .= $this->host->rewriteVar($inner, $current);
                } else {
                    $colon = strpos($inner, ':');
                    if ($colon === false) {
                        $out .= '${' . $inner . '}';
                    } else {
                        $map = substr($inner, 0, $colon);
                        $rest = substr($inner, $colon + 1);
                        $pipe = self::topLevelPipe($rest);
                        $key = $pipe === null ? $rest : substr($rest, 0, $pipe);
                        $default = $pipe === null ? '' : substr($rest, $pipe + 1);
                        $out .= $this->host->rewriteMap(
                            $map,
                            $this->expand($key, $rm, $cm, $current, $escapeBackrefs),
                            $this->expand($default, $rm, $cm, $current, $escapeBackrefs),
                        );
                    }
                }
                $i = $end;
                continue;
            }
            $out .= $ch;
        }
        return $out;
    }

    private static function matchingBrace(string $s, int $open): ?int
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = $open; $i < $len; $i++) {
            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    private static function topLevelPipe(string $s): ?int
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}') {
                $depth--;
            } elseif ($s[$i] === '|' && $depth === 0) {
                return $i;
            }
        }
        return null;
    }

    private static function escapeBackref(string $v): string
    {
        return (string) preg_replace_callback('/[^A-Za-z0-9_.~-]/', static function (array $m): string {
            return $m[0] === ' ' ? '+' : sprintf('%%%02X', ord($m[0]));
        }, $v);
    }
}

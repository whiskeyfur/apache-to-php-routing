<?php

declare(strict_types=1);

namespace Apache2Router;

/**
 * Reads Apache configuration text into a tree of directives.
 *
 * Each node is an array:
 *   ['d' => lower-case name, 'name' => original name, 'args' => list<string>,
 *    'file' => source file, 'line' => line number, 'children' => list<node>|null]
 *
 * Parse-time constructs (Include, Define, ${VAR}, <IfModule>, <IfDefine>,
 * <IfVersion>, <IfFile>, <Macro>/Use) are resolved here so later stages only
 * see the effective configuration. The same class parses .htaccess files at
 * request time, with includes disabled.
 */
final class ConfigParser
{
    /** Version reported to <IfVersion>. */
    public const APACHE_VERSION = '2.4.62';

    /** Modules that are compiled into every httpd or that PHP's server stands in for. */
    private const BUILTIN_MODULES = ['core', 'so', 'http', 'mpm_event', 'mpm_prefork', 'mpm_worker',
        'unixd', 'log_config', 'logio', 'version', 'watchdog', 'php', 'php5', 'php7', 'php8'];

    /** Directives that do not matter to the router, so unset ${VARS} in them are not worth a warning. */
    private const IGNORED_FOR_VAR_WARNINGS = ['pidfile', 'defaultruntimedir', 'errorlog', 'customlog', 'transferlog',
        'user', 'group', 'mutex', 'lockfile', 'scoreboardfile', 'sslcertificatefile', 'sslcertificatekeyfile',
        'sslcertificatechainfile', 'sslcacertificatefile', 'sslsessioncache', 'globallog', 'loglevel'];

    /** @var array<string,string> */
    public array $defines = [];
    /** @var array<string,bool> modules seen in LoadModule */
    public array $modules = [];
    /** Whether any LoadModule was seen; until then every module counts as loaded. */
    public bool $sawLoadModule = false;
    /** Original (pre-remap) ServerRoot. */
    public string $serverRoot = '';
    /** Where the server root lives now, when given on the command line. */
    public ?string $serverRootOverride = null;
    /** Directory of the first config file, the fallback server root. */
    public string $configDir = '';
    private bool $serverRootFromConfig = false;
    /** @var list<string> */
    public array $warnings = [];
    /** @var list<string> files read, in order */
    public array $sources = [];

    /** @var array<string,array{params:list<string>,lines:list<array{0:string,1:int}>,file:string}> */
    private array $macros = [];
    private int $includeDepth = 0;
    /** @var array<string,bool> */
    private array $warnedVars = [];

    public function __construct(
        private PathMapper $mapper,
        private bool $allowIncludes = true,
    ) {
    }

    public function mapper(): PathMapper
    {
        return $this->mapper;
    }

    /** Effective (current-location) ServerRoot. */
    public function currentServerRoot(): string
    {
        return $this->serverRoot === '' ? '' : $this->mapper->map($this->serverRoot);
    }

    /** @return list<array<string,mixed>> */
    public function parseFile(string $path): array
    {
        $root = new ParseNode('', [], $path, 0);
        $root->children = [];
        $this->includeFile($path, $root, false);
        return $root->toArray()['children'];
    }

    /** @return list<array<string,mixed>> */
    public function parseString(string $text, string $file = '(string)'): array
    {
        $root = new ParseNode('', [], $file, 0);
        $root->children = [];
        $this->parseLines(self::logicalLines($text), $file, $root);
        return $root->toArray()['children'];
    }

    public function isModuleLoaded(string $name): bool
    {
        $mod = self::moduleName($name);
        if (in_array($mod, self::BUILTIN_MODULES, true) || str_starts_with($mod, 'php')) {
            return true;
        }
        return !$this->sawLoadModule || isset($this->modules[$mod]);
    }

    /** "mod_rewrite.c", "rewrite_module" and "mod_rewrite" all become "rewrite". */
    public static function moduleName(string $name): string
    {
        $n = strtolower(trim($name));
        $n = (string) preg_replace('/\.(c|so)$/', '', basename(str_replace('\\', '/', $n)));
        $n = (string) preg_replace('/^mod_/', '', $n);
        return (string) preg_replace('/_module$/', '', $n);
    }

    /**
     * Splits text into logical lines (joining backslash continuations and
     * dropping comments and blanks).
     *
     * @return list<array{0:string,1:int}>
     */
    public static function logicalLines(string $text): array
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $raw = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $out = [];
        $buf = '';
        $start = 0;
        foreach ($raw as $i => $line) {
            if ($buf === '') {
                $start = $i + 1;
            }
            $trimmed = rtrim($line);
            if (str_ends_with($trimmed, '\\') && !str_ends_with($trimmed, '\\\\')) {
                $buf .= substr($trimmed, 0, -1) . ' ';
                continue;
            }
            $buf .= $line;
            $t = trim($buf);
            $buf = '';
            if ($t === '' || $t[0] === '#') {
                continue;
            }
            $out[] = [$t, $start];
        }
        if (trim($buf) !== '' && trim($buf)[0] !== '#') {
            $out[] = [trim($buf), $start];
        }
        return $out;
    }

    /**
     * Splits a directive's argument string the way ap_getword_conf does:
     * whitespace separated, single or double quotes group words, and a
     * backslash only escapes the quote character inside quotes.
     *
     * @return list<string>
     */
    public static function splitArgs(string $s): array
    {
        $args = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ctype_space($s[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $q = $s[$i];
            if ($q === '"' || $q === "'") {
                $i++;
                $word = '';
                while ($i < $len && $s[$i] !== $q) {
                    if ($s[$i] === '\\' && $i + 1 < $len && ($s[$i + 1] === $q || $s[$i + 1] === '\\')) {
                        $word .= $s[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $word .= $s[$i++];
                }
                $i++; // closing quote
                $args[] = $word;
                continue;
            }
            $word = '';
            while ($i < $len && !ctype_space($s[$i])) {
                $word .= $s[$i++];
            }
            $args[] = $word;
        }
        return $args;
    }

    private function warn(string $msg): void
    {
        if (!in_array($msg, $this->warnings, true)) {
            $this->warnings[] = $msg;
        }
    }

    private function includeFile(string $path, ParseNode $target, bool $optional): void
    {
        if ($this->includeDepth > 64) {
            $this->warn("Include depth exceeded at $path; stopping.");
            return;
        }
        $text = @file_get_contents($path);
        if ($text === false) {
            if (!$optional) {
                $this->warn("Cannot read config file $path.");
            }
            return;
        }
        $this->sources[] = $path;
        $this->includeDepth++;
        $this->parseLines(self::logicalLines($text), $path, $target);
        $this->includeDepth--;
    }

    /** @param list<array{0:string,1:int}> $lines */
    private function parseLines(array $lines, string $file, ParseNode $root): void
    {
        /** @var list<ParseNode|string> $stack ParseNode for real sections, string for transparent conditionals */
        $stack = [];
        $current = $root;
        $nodes = [$root];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            [$text, $lineNo] = $lines[$i];
            $text = $this->substituteVars($text, $file, $lineNo);

            if (str_starts_with($text, '</')) {
                $name = strtolower(trim(substr($text, 2), " \t>"));
                if ($stack === []) {
                    $this->warn("$file:$lineNo: unexpected </$name>.");
                    continue;
                }
                $top = array_pop($stack);
                if ($top instanceof ParseNode) {
                    array_pop($nodes);
                    $current = end($nodes);
                }
                continue;
            }

            if (str_starts_with($text, '<')) {
                $inner = rtrim(substr($text, 1));
                if (str_ends_with($inner, '>')) {
                    $inner = substr($inner, 0, -1);
                }
                $parts = preg_split('/\s+/', trim($inner), 2) ?: [''];
                $name = $parts[0];
                $lname = strtolower($name);
                $args = self::splitArgs($parts[1] ?? '');

                if ($lname === 'macro') {
                    $i = $this->captureMacro($lines, $i, $args, $file);
                    continue;
                }

                $cond = $this->evaluateConditional($lname, $args, $file, $lineNo);
                if ($cond === false) {
                    $i = self::skipSection($lines, $i);
                    continue;
                }
                if ($cond === true) {
                    $stack[] = $lname;
                    continue;
                }

                $node = new ParseNode($name, $args, $file, $lineNo);
                $node->children = [];
                $current->children[] = $node;
                $stack[] = $node;
                $nodes[] = $node;
                $current = $node;
                continue;
            }

            $parts = preg_split('/\s+/', $text, 2) ?: [''];
            $name = $parts[0];
            $lname = strtolower($name);
            $args = self::splitArgs($parts[1] ?? '');

            if ($this->handleParseTimeDirective($lname, $args, $file, $lineNo, $current)) {
                continue;
            }
            $current->children[] = new ParseNode($name, $args, $file, $lineNo);
        }
        if ($stack !== []) {
            $this->warn("$file: " . count($stack) . ' section(s) left open at end of file.');
        }
    }

    /**
     * Returns true for a transparent conditional whose body applies, false
     * for one whose body is skipped, and null for an ordinary section.
     *
     * @param list<string> $args
     */
    private function evaluateConditional(string $name, array $args, string $file, int $line): ?bool
    {
        $arg = $args[0] ?? '';
        $negate = str_starts_with($arg, '!');
        if ($negate) {
            $arg = substr($arg, 1);
        }
        switch ($name) {
            case 'ifmodule':
                return $this->isModuleLoaded($arg) !== $negate;
            case 'ifdefine':
                return array_key_exists($arg, $this->defines) !== $negate;
            case 'iffile':
                $path = $this->resolveConfigPath($arg);
                return file_exists($path) !== $negate;
            case 'ifdirective':
            case 'ifsection':
                return !$negate;
            case 'ifversion':
                return $this->evaluateIfVersion($args);
            case 'if':
            case 'elseif':
            case 'else':
                $this->warn("$file:$line: <$name> expressions are evaluated per request by Apache and are not supported; its contents were skipped.");
                return false;
        }
        return null;
    }

    /** @param list<string> $args */
    private function evaluateIfVersion(array $args): bool
    {
        if ($args === []) {
            return true;
        }
        $negate = false;
        $op = '=';
        $ver = $args[0];
        if (count($args) >= 2) {
            $op = $args[0];
            $ver = $args[1];
        }
        if (str_starts_with($op, '!')) {
            $negate = true;
            $op = substr($op, 1) ?: '=';
        }
        if ($op === '~' || (str_starts_with($ver, '/') && str_ends_with($ver, '/') && strlen($ver) > 1)) {
            $re = trim($ver, '/');
            $res = (bool) @preg_match("\x01$re\x01", self::APACHE_VERSION);
        } else {
            $res = match ($op) {
                '=', '==' => version_compare(self::APACHE_VERSION, $ver, '=') || str_starts_with(self::APACHE_VERSION, $ver . '.'),
                '>' => version_compare(self::APACHE_VERSION, $ver, '>'),
                '>=' => version_compare(self::APACHE_VERSION, $ver, '>='),
                '<' => version_compare(self::APACHE_VERSION, $ver, '<'),
                '<=' => version_compare(self::APACHE_VERSION, $ver, '<='),
                default => true,
            };
        }
        return $res !== $negate;
    }

    /**
     * Returns the index of the line closing the section opened at $i.
     *
     * @param list<array{0:string,1:int}> $lines
     */
    private static function skipSection(array $lines, int $i): int
    {
        $depth = 0;
        $count = count($lines);
        for (; $i < $count; $i++) {
            $t = $lines[$i][0];
            if (str_starts_with($t, '</')) {
                $depth--;
            } elseif (str_starts_with($t, '<')) {
                $depth++;
            }
            if ($depth === 0) {
                return $i;
            }
        }
        return $count;
    }

    /**
     * @param list<array{0:string,1:int}> $lines
     * @param list<string> $args
     */
    private function captureMacro(array $lines, int $i, array $args, string $file): int
    {
        $end = self::skipSection($lines, $i);
        $name = strtolower(array_shift($args) ?? '');
        $this->macros[$name] = [
            'params' => $args,
            'lines' => array_slice($lines, $i + 1, max(0, $end - $i - 1)),
            'file' => $file,
        ];
        return $end;
    }

    /** @param list<string> $args */
    private function handleParseTimeDirective(string $name, array $args, string $file, int $line, ParseNode $target): bool
    {
        switch ($name) {
            case 'define':
                if (isset($args[0])) {
                    $this->defines[$args[0]] = $args[1] ?? '';
                }
                return true;
            case 'undefine':
                unset($this->defines[$args[0] ?? '']);
                return true;
            case 'serverroot':
                if (isset($args[0]) && !$this->serverRootFromConfig) {
                    $this->serverRootFromConfig = true;
                    $this->setServerRoot($args[0]);
                }
                return true;
            case 'loadmodule':
                $this->sawLoadModule = true;
                if (isset($args[0])) {
                    $this->modules[self::moduleName($args[0])] = true;
                }
                return true;
            case 'include':
            case 'includeoptional':
                if (!$this->allowIncludes) {
                    $this->warn("$file:$line: $name is not allowed here; ignored.");
                    return true;
                }
                foreach ($args as $pattern) {
                    $this->processInclude($pattern, $name === 'includeoptional', $file, $line, $target);
                }
                return true;
            case 'use':
                $this->expandMacro($args, $file, $line, $target);
                return true;
            case 'undefmacro':
                unset($this->macros[strtolower($args[0] ?? '')]);
                return true;
        }
        return false;
    }

    /**
     * Records the ServerRoot directive. When the old location does not exist
     * here, the server root is assumed to have moved alongside the config
     * files, and a map from the old location is added.
     */
    private function setServerRoot(string $old): void
    {
        $old = PathMapper::normalize($old);
        $this->serverRoot = $old;
        if ($this->serverRootOverride !== null) {
            $this->mapper->add($old, $this->serverRootOverride);
            return;
        }
        $mapped = $this->mapper->map($old);
        if (is_dir($mapped) && !PathMapper::isWindows($mapped)) {
            return;
        }
        $guess = $this->guessServerRoot($old);
        if ($guess !== null) {
            $this->mapper->add($old, $guess);
            $this->warn("ServerRoot $old does not exist here; using $guess (override with --server-root).");
        } else {
            $this->warn("ServerRoot $old does not exist here; relative paths may not resolve (use --server-root).");
        }
    }

    /** Finds the ancestor of the config directory with the same name as the old ServerRoot. */
    private function guessServerRoot(string $old): ?string
    {
        if ($this->configDir === '') {
            return null;
        }
        $want = strtolower(basename($old));
        $dir = $this->configDir;
        for ($i = 0; $i < 4; $i++) {
            if (strtolower(basename($dir)) === $want) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return $this->configDir;
    }

    /** Sets the root used before any ServerRoot directive is seen. */
    public function initServerRoot(string $firstConfigFile): void
    {
        $this->configDir = PathMapper::normalize((string) realpath(dirname($firstConfigFile)) ?: dirname($firstConfigFile));
        $this->serverRoot = $this->serverRootOverride ?? $this->configDir;
    }

    /** Resolves a path from the config: relative to ServerRoot, then remapped. */
    public function resolveConfigPath(string $path): string
    {
        $joined = PathMapper::join($this->serverRoot, $path);
        return $this->mapper->map($joined);
    }

    private function processInclude(string $pattern, bool $optional, string $file, int $line, ParseNode $target): void
    {
        $path = $this->resolveConfigPath($pattern);
        if (PathMapper::isWindows($path) && DIRECTORY_SEPARATOR === '/') {
            $this->warn("$file:$line: Include of Windows path $pattern has no --map; skipped.");
            return;
        }
        if (is_dir($path)) {
            $files = glob(rtrim($path, '/') . '/*') ?: [];
            $files = array_values(array_filter($files, 'is_file'));
        } elseif (strpbrk($path, '*?[') !== false) {
            $files = glob($path) ?: [];
            $files = array_values(array_filter($files, 'is_file'));
            if ($files === [] && !$optional && strpbrk(basename($path), '*?[') === false) {
                $this->warn("$file:$line: Include $pattern matched nothing (looked in $path).");
            }
        } elseif (is_file($path)) {
            $files = [$path];
        } else {
            if (!$optional) {
                $this->warn("$file:$line: Include $pattern not found (looked for $path). Use --map or --server-root.");
            }
            return;
        }
        sort($files, SORT_STRING);
        foreach ($files as $f) {
            $this->includeFile($f, $target, $optional);
        }
    }

    /** @param list<string> $args */
    private function expandMacro(array $args, string $file, int $line, ParseNode $target): void
    {
        $name = strtolower(array_shift($args) ?? '');
        if (!isset($this->macros[$name])) {
            $this->warn("$file:$line: Use of undefined macro $name.");
            return;
        }
        $macro = $this->macros[$name];
        $params = $macro['params'];
        // Longest parameter names first so $domain does not clobber $domain_alias.
        $order = array_keys($params);
        usort($order, static fn($a, $b) => strlen($params[$b]) <=> strlen($params[$a]));
        $lines = [];
        foreach ($macro['lines'] as [$text, $no]) {
            foreach ($order as $idx) {
                $text = str_replace($params[$idx], $args[$idx] ?? '', $text);
            }
            $lines[] = [$text, $no];
        }
        $this->parseLines($lines, $macro['file'] . " (macro $name used at $file:$line)", $target);
    }

    private function substituteVars(string $text, string $file, int $line): string
    {
        if (!str_contains($text, '${')) {
            return $text;
        }
        return (string) preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_.]*)\}/', function (array $m) use ($file, $line, $text): string {
            if (array_key_exists($m[1], $this->defines)) {
                return $this->defines[$m[1]];
            }
            $env = getenv($m[1]);
            if ($env !== false) {
                return $env;
            }
            $directive = strtolower((string) strtok($text, " \t"));
            if ($this->allowIncludes && !isset($this->warnedVars[$m[1]]) && !in_array($directive, self::IGNORED_FOR_VAR_WARNINGS, true)) {
                $this->warnedVars[$m[1]] = true;
                $this->warn("$file:$line: \${{$m[1]}} is not defined (use -D {$m[1]}=value); left as is.");
            }
            return $m[0];
        }, $text);
    }
}

/** @internal mutable node used while parsing */
final class ParseNode
{
    /** @var list<ParseNode>|null */
    public ?array $children = null;

    /** @param list<string> $args */
    public function __construct(
        public string $name,
        public array $args,
        public string $file,
        public int $line,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'd' => strtolower($this->name),
            'name' => $this->name,
            'args' => $this->args,
            'file' => $this->file,
            'line' => $this->line,
            'children' => $this->children === null ? null : array_map(static fn(ParseNode $n) => $n->toArray(), $this->children),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Apache2Router;

/**
 * Turns a parsed Apache configuration into the data array the generated
 * router runs from: one entry per virtual host, with paths already moved to
 * their current locations.
 */
final class Compiler
{
    /**
     * Directives that also apply below the server level and are therefore
     * handed to the runtime as the base of every per-directory context.
     */
    public const BASE_DIRECTIVES = [
        'directoryindex', 'options', 'directoryslash', 'errordocument', 'addtype', 'addhandler',
        'removehandler', 'removetype', 'sethandler', 'forcetype', 'php_value', 'php_flag',
        'php_admin_value', 'php_admin_flag', 'setenv', 'unsetenv', 'setenvif', 'setenvifnocase',
        'browsermatch', 'acceptpathinfo', 'adddefaultcharset', 'require', 'requireall', 'requireany',
        'requirenone', 'order', 'allow', 'deny', 'header', 'files', 'filesmatch',
    ];

    private const AUTH_DIRECTIVES = ['authtype', 'authname', 'authuserfile', 'authgroupfile', 'authbasicprovider'];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param array{default_host?:?string,htaccess?:string,proxy_status?:int,keep_redirects?:bool} $options
     */
    public function __construct(
        private ConfigParser $parser,
        private PathMapper $mapper,
        private array $options = [],
    ) {
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return array<string,mixed>
     */
    public function compile(array $nodes): array
    {
        $global = $this->newServer('(main server)', '', 0);
        $vhostNodes = [];
        $this->collect($nodes, $global, $vhostNodes);

        $vhosts = [];
        foreach ($vhostNodes as $vn) {
            $vh = $this->newServer('', $vn['file'], $vn['line']);
            $vh['addr'] = $vn['args'];
            $vh['port'] = self::portOf($vn['args']);
            $ignored = [];
            $this->collect($vn['children'] ?? [], $vh, $ignored);
            if ($vh['port'] === 443) {
                $vh['ssl'] = true;
            }
            $vhosts[] = $this->finalize($vh, $global, true);
        }
        if ($vhosts === []) {
            $global['name'] = $global['name'] ?? 'localhost';
            $vhosts[] = $this->finalize($global, $global, false);
        }

        $default = 0;
        $wanted = $this->options['default_host'] ?? null;
        if ($wanted !== null && $wanted !== '') {
            $found = false;
            foreach ($vhosts as $i => $vh) {
                if (Router::hostMatches($vh, strtolower($wanted))) {
                    $default = $i;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->warnings[] = "--default-host $wanted matches no virtual host; using the first one.";
            }
        }

        $htaccess = $this->options['htaccess'] ?? 'auto';
        $sawAllowOverride = self::containsDirective($nodes, ['allowoverride']);
        if ($htaccess === 'auto' && !$sawAllowOverride) {
            $this->warnings[] = 'No AllowOverride found in the config; .htaccess files will be honoured everywhere (use --htaccess=never to disable).';
        }
        if (self::containsDirective($nodes, self::AUTH_DIRECTIVES)) {
            $this->warnings[] = 'Authentication (AuthType, Require valid-user/user/group) is not enforced by the generated router.';
        }

        foreach ($vhosts as $vh) {
            $label = $vh['name'] ?? '(unnamed)';
            if ($vh['docroot_set'] && !is_dir($vh['docroot'])) {
                $this->warnings[] = "DocumentRoot {$vh['docroot']} for $label does not exist here; add --map OLD=NEW.";
            }
            foreach ($vh['aliases_map'] as $a) {
                if ($a['type'] === 'prefix' && !file_exists($a['path'])) {
                    $this->warnings[] = "Alias {$a['url']} -> {$a['path']} for $label does not exist here; add --map OLD=NEW.";
                }
            }
        }

        return [
            'version' => 1,
            'vhosts' => $vhosts,
            'default_vhost' => $default,
            'maps' => $this->mapper->maps(),
            'htaccess' => $htaccess,
            'allowoverride_default' => $htaccess === 'always' || ($htaccess === 'auto' && !$sawAllowOverride),
            'dirindex_default' => self::containsDirective($nodes, ['directoryindex']) ? ['index.html'] : ['index.html', 'index.php'],
            'modules' => $this->parser->sawLoadModule ? array_keys($this->parser->modules) : null,
            'proxy_status' => (int) ($this->options['proxy_status'] ?? 502),
            'keep_redirects' => (bool) ($this->options['keep_redirects'] ?? false),
        ];
    }

    /** @return array<string,mixed> */
    private function newServer(string $label, string $file, int $line): array
    {
        return [
            'label' => $label, 'name' => null, 'aliases' => [], 'docroot' => null, 'addr' => [], 'port' => null,
            'ssl' => false, 'base' => [], 'proxies' => [], 'redirects' => [], 'aliases_map' => [],
            'rewrite_nodes' => [], 'sections' => [], 'balancers' => [], 'accessfile' => null,
            'file' => $file, 'line' => $line,
        ];
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param array<string,mixed> $srv
     * @param list<array<string,mixed>> $vhostNodes
     */
    private function collect(array $nodes, array &$srv, array &$vhostNodes): void
    {
        foreach ($nodes as $n) {
            $d = $n['d'];
            $args = $n['args'];
            $where = "{$n['file']}:{$n['line']}";
            switch ($d) {
                case 'virtualhost':
                    if ($srv['label'] === '(main server)') {
                        $vhostNodes[] = $n;
                    } else {
                        $this->warnings[] = "$where: nested <VirtualHost> ignored.";
                    }
                    break;
                case 'directory':
                case 'directorymatch':
                case 'files':
                case 'filesmatch':
                case 'location':
                case 'locationmatch':
                    $srv['sections'][] = $this->section($n);
                    break;
                case 'proxy':
                case 'proxymatch':
                    $name = $args[0] ?? '';
                    if (str_starts_with(strtolower($name), 'balancer://')) {
                        foreach ($n['children'] ?? [] as $c) {
                            if ($c['d'] === 'balancermember' && isset($c['args'][0])) {
                                $srv['balancers'][strtolower(rtrim($name, '/'))][] = $c['args'][0];
                            }
                        }
                    }
                    break;
                case 'balancermember':
                    if (isset($args[0], $args[1]) && str_starts_with(strtolower($args[0]), 'balancer://')) {
                        $srv['balancers'][strtolower(rtrim($args[0], '/'))][] = $args[1];
                    }
                    break;
                case 'servername':
                    if (isset($args[0])) {
                        $srv['name'] = self::hostOnly($args[0]);
                    }
                    break;
                case 'serveralias':
                    foreach ($args as $a) {
                        $srv['aliases'][] = strtolower($a);
                    }
                    break;
                case 'documentroot':
                    if (isset($args[0])) {
                        $srv['docroot'] = $this->parser->resolveConfigPath($args[0]);
                    }
                    break;
                case 'alias':
                case 'scriptalias':
                    if (isset($args[0], $args[1])) {
                        $srv['aliases_map'][] = [
                            'type' => 'prefix', 'url' => $args[0],
                            'path' => $this->parser->resolveConfigPath($args[1]),
                            'cgi' => $d === 'scriptalias', 'where' => $where,
                        ];
                    }
                    break;
                case 'aliasmatch':
                case 'scriptaliasmatch':
                    if (isset($args[0], $args[1])) {
                        $srv['aliases_map'][] = [
                            'type' => 'regex', 'regex' => $args[0], 'path' => $this->mapper->map($args[1]),
                            'cgi' => $d === 'scriptaliasmatch', 'where' => $where,
                        ];
                    }
                    break;
                case 'redirect':
                case 'redirectmatch':
                case 'redirectpermanent':
                case 'redirecttemp':
                    $r = Router::redirectEntry($n);
                    if ($r !== null) {
                        $srv['redirects'][] = $r;
                    }
                    break;
                case 'proxypass':
                case 'proxypassmatch':
                    $p = $this->proxyEntry($n);
                    if ($p !== null) {
                        $srv['proxies'][] = $p;
                    }
                    break;
                case 'rewriteengine':
                case 'rewriterule':
                case 'rewritecond':
                case 'rewritemap':
                case 'rewriteoptions':
                case 'rewritebase':
                    $srv['rewrite_nodes'][] = $n;
                    break;
                case 'sslengine':
                    $srv['ssl'] = strtolower($args[0] ?? '') === 'on';
                    break;
                case 'accessfilename':
                    if (isset($args[0])) {
                        $srv['accessfile'] = $args[0];
                    }
                    break;
                default:
                    if (in_array($d, self::BASE_DIRECTIVES, true)) {
                        $srv['base'][] = $n;
                    }
            }
        }
    }

    /**
     * @param array<string,mixed> $vh
     * @param array<string,mixed> $global
     * @return array<string,mixed>
     */
    private function finalize(array $vh, array $global, bool $isVhost): array
    {
        $docroot = $vh['docroot'] ?? $global['docroot'] ?? $this->parser->resolveConfigPath('htdocs');
        $rw = Rewrite::collect($vh['rewrite_nodes']);
        $grw = $isVhost ? Rewrite::collect($global['rewrite_nodes']) : $rw;
        $rules = $rw['rules'];
        if ($isVhost) {
            if (in_array('inherit', $rw['options'], true)) {
                $rules = array_merge($rules, $grw['rules']);
            } elseif (in_array('inheritbefore', $rw['options'], true)) {
                $rules = array_merge($grw['rules'], $rules);
            }
        }
        $maps = [];
        foreach (array_merge($isVhost ? $grw['maps'] : [], $rw['maps']) as $name => $m) {
            if (in_array($m['type'], ['txt', 'rnd', 'dbm'], true)) {
                $m['source'] = $this->parser->resolveConfigPath($m['source']);
            }
            if (!in_array($m['type'], ['txt', 'rnd', 'int'], true)) {
                $this->warnings[] = "RewriteMap $name uses type {$m['type']}, which is not supported; lookups return the default value.";
            }
            $maps[$name] = $m;
        }

        $merge = static fn(string $k): array => $isVhost ? array_merge($vh[$k], $global[$k]) : $vh[$k];
        return [
            'name' => $vh['name'] !== null ? strtolower($vh['name']) : null,
            'aliases' => $vh['aliases'],
            'addr' => $vh['addr'],
            'port' => $vh['port'],
            'ssl' => $vh['ssl'],
            'file' => $vh['file'],
            'line' => $vh['line'],
            'docroot' => $docroot,
            'docroot_set' => $vh['docroot'] !== null || $global['docroot'] !== null,
            'base' => $isVhost ? array_merge($global['base'], $vh['base']) : $vh['base'],
            'proxies' => $merge('proxies'),
            'redirects' => $merge('redirects'),
            'aliases_map' => $merge('aliases_map'),
            'rewrite' => ['engine' => (bool) ($rw['engine'] ?? false), 'rules' => $rules, 'maps' => $maps],
            'sections' => $isVhost ? array_merge($global['sections'], $vh['sections']) : $vh['sections'],
            'balancers' => $isVhost ? array_merge($global['balancers'], $vh['balancers']) : $vh['balancers'],
            'accessfile' => $vh['accessfile'] ?? $global['accessfile'] ?? '.htaccess',
        ];
    }

    /**
     * @param array<string,mixed> $n
     * @return array<string,mixed>
     */
    private function section(array $n): array
    {
        $d = $n['d'];
        $args = $n['args'];
        $kind = str_starts_with($d, 'directory') ? 'directory' : (str_starts_with($d, 'files') ? 'files' : 'location');
        $regex = str_ends_with($d, 'match');
        $pattern = $args[0] ?? '';
        if (!$regex && $pattern === '~') {
            $regex = true;
            $pattern = $args[1] ?? '';
        }
        if ($kind === 'directory') {
            if ($regex) {
                $pattern = $this->mapper->mapRegex($pattern);
            } else {
                $pattern = $this->parser->resolveConfigPath($pattern);
                if ($pattern !== '/' && !preg_match('#^[A-Z]:/$#', $pattern)) {
                    $pattern = rtrim($pattern, '/');
                }
            }
        }
        return [
            'kind' => $kind,
            'regex' => $regex,
            'pattern' => $pattern,
            'nodes' => $n['children'] ?? [],
            'file' => $n['file'],
            'line' => $n['line'],
        ];
    }

    /**
     * @param array<string,mixed> $n
     * @return array<string,mixed>|null
     */
    private function proxyEntry(array $n): ?array
    {
        $args = $n['args'];
        if (!isset($args[0], $args[1])) {
            return null;
        }
        $to = $args[1];
        if (Router::isFastCgiTarget($to)) {
            $this->warnings[] = "{$n['file']}:{$n['line']}: {$n['name']} to $to hands PHP to PHP-FPM; ignored because php -S runs PHP itself.";
            return null;
        }
        return [
            'type' => $n['d'] === 'proxypassmatch' ? 'regex' : 'prefix',
            'from' => $args[0],
            'to' => $to,
            'where' => "{$n['file']}:{$n['line']}",
            'text' => $n['name'] . ' ' . implode(' ', $args),
        ];
    }

    /** "https://www.example.com:443" -> "www.example.com" */
    public static function hostOnly(string $name): string
    {
        $name = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $name);
        if (str_starts_with($name, '[')) {
            $end = strpos($name, ']');
            return strtolower($end === false ? $name : substr($name, 0, $end + 1));
        }
        return strtolower((string) preg_replace('/:\d+$/', '', $name));
    }

    /** @param list<string> $addrs */
    public static function portOf(array $addrs): ?int
    {
        foreach ($addrs as $a) {
            if (preg_match('/:(\d+)$/', $a, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param list<string> $names
     */
    public static function containsDirective(array $nodes, array $names): bool
    {
        foreach ($nodes as $n) {
            if (in_array($n['d'], $names, true)) {
                return true;
            }
            if (is_array($n['children']) && self::containsDirective($n['children'], $names)) {
                return true;
            }
        }
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Apache2Router;

/**
 * The request-time half of the tool: given the compiled configuration and
 * the current request, decides what Apache would have done and does the
 * closest thing PHP's built-in server can.
 *
 * resolve() only decides (and is what the tests exercise); handle() also
 * sends the response or prepares a PHP script for the generated router to
 * require at global scope.
 */
final class Router implements RewriteHost
{
    /** PHP script the generated router should require, if any. */
    public static ?string $script = null;

    private const MAX_ROUNDS = 10;

    private const MIME = [
        'html' => 'text/html', 'htm' => 'text/html', 'shtml' => 'text/html', 'xhtml' => 'application/xhtml+xml',
        'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'json' => 'application/json',
        'map' => 'application/json', 'xml' => 'application/xml', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'md' => 'text/markdown', 'ics' => 'text/calendar', 'rss' => 'application/rss+xml', 'atom' => 'application/atom+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'avif' => 'image/avif', 'svg' => 'image/svg+xml', 'svgz' => 'image/svg+xml', 'ico' => 'image/vnd.microsoft.icon',
        'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo', 'pdf' => 'application/pdf', 'zip' => 'application/zip', 'gz' => 'application/gzip',
        'tgz' => 'application/gzip', 'tar' => 'application/x-tar', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
        'wasm' => 'application/wasm', 'swf' => 'application/x-shockwave-flash', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'webmanifest' => 'application/manifest+json',
        'appcache' => 'text/cache-manifest', 'vtt' => 'text/vtt', 'yaml' => 'text/yaml', 'yml' => 'text/yaml',
    ];

    private const STATUS_TEXT = [
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 410 => 'Gone', 500 => 'Internal Server Error',
        501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
    ];

    /** @var array<string,mixed> */
    private array $cfg;
    /** @var array<string,mixed> */
    private array $vh;
    /** @var array<string,mixed> */
    private array $server;
    private PathMapper $mapper;
    private Rewrite $rewrite;
    private string $host = '';
    private string $hostHeader = '';
    private int $port = 80;

    private string $origPath = '/';
    private string $origQuery = '';
    private string $curUri = '/';
    private string $curQuery = '';
    private string $curPathInfo = '';
    private bool $perDir = false;
    private string $perDirDir = '';
    private string $perDirSubject = '';
    private string $perDirFilename = '';
    /** @var array<string,mixed>|null */
    private ?array $curMap = null;
    private bool $rewriteEnded = false;

    /** @var array<string,?string> environment set by SetEnv, SetEnvIf and [E=] */
    private array $env = [];
    /** @var list<string> */
    private array $cookies = [];
    private ?string $rewriteMime = null;
    private ?string $rewriteHandler = null;
    /** @var array<string,string> REDIRECT_* variables after an internal redirect */
    private array $redirectVars = [];
    private ?int $errorStatus = null;
    /** @var array<string,array<string,mixed>> */
    private array $htaccessCache = [];
    /** @var array<string,array<string,string>> */
    private array $mapFiles = [];
    /** @var list<string> */
    public array $notes = [];

    /**
     * @param array<string,mixed> $cfg
     * @param array<string,mixed>|null $server defaults to $_SERVER
     */
    public function __construct(array $cfg, ?array $server = null)
    {
        $this->cfg = $cfg;
        $this->server = $server ?? $_SERVER;
        $this->mapper = new PathMapper($cfg['maps'] ?? []);
        $this->rewrite = new Rewrite($this);

        $this->hostHeader = (string) ($this->server['HTTP_HOST'] ?? '');
        [$this->host, $port] = self::splitHostPort($this->hostHeader);
        $this->port = $port ?? (int) ($this->server['SERVER_PORT'] ?? 80);
        if ($this->hostHeader === '') {
            $this->host = strtolower((string) ($this->server['SERVER_NAME'] ?? 'localhost'));
            $this->hostHeader = $this->host . ':' . $this->port;
        }
        $this->vh = $cfg['vhosts'][$this->selectVhost()];
    }

    /** @return array{0:string,1:?int} */
    public static function splitHostPort(string $hostHeader): array
    {
        $h = strtolower(trim($hostHeader));
        if (preg_match('/^(\[[^\]]+\])(?::(\d+))?$/', $h, $m) || preg_match('/^([^:]+)(?::(\d+))?$/', $h, $m)) {
            return [rtrim($m[1], '.'), isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null];
        }
        return [$h, null];
    }

    /** @param array<string,mixed> $vh */
    public static function hostMatches(array $vh, string $host): bool
    {
        if ($host === '') {
            return false;
        }
        if (($vh['name'] ?? null) === $host) {
            return true;
        }
        foreach ($vh['aliases'] ?? [] as $alias) {
            if ($alias === $host || fnmatch($alias, $host, FNM_CASEFOLD)) {
                return true;
            }
        }
        return false;
    }

    private function selectVhost(): int
    {
        $candidates = [];
        foreach ($this->cfg['vhosts'] as $i => $vh) {
            if (self::hostMatches($vh, $this->host)) {
                $candidates[] = $i;
            }
        }
        if ($candidates === []) {
            return (int) ($this->cfg['default_vhost'] ?? 0);
        }
        foreach ($candidates as $i) {
            if ($this->cfg['vhosts'][$i]['port'] === $this->port) {
                return $i;
            }
        }
        // php -S speaks plain HTTP on some other port. When a site has both
        // an HTTP vhost (usually just redirecting) and an HTTPS one, the
        // HTTPS one holds the real configuration.
        foreach ($candidates as $i) {
            if ($this->cfg['vhosts'][$i]['ssl']) {
                return $i;
            }
        }
        return $candidates[0];
    }

    /** @return array<string,mixed> the selected virtual host */
    public function vhost(): array
    {
        return $this->vh;
    }

    // ------------------------------------------------------------------
    // Deciding
    // ------------------------------------------------------------------

    /**
     * Works out what to do with the request.
     *
     * @return array<string,mixed> with 'action' one of redirect, proxy, error, text, php, static, listing
     */
    public function resolve(): array
    {
        $requestUri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $qpos = strpos($requestUri, '?');
        $rawPath = $qpos === false ? $requestUri : substr($requestUri, 0, $qpos);
        $query = $qpos === false ? '' : substr($requestUri, $qpos + 1);
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*#i', $rawPath, $m)) {
            $rawPath = substr($rawPath, strlen($m[0]));
        }
        if (stripos($rawPath, '%2f') !== false) {
            return $this->error(404, null, 'Encoded slashes are not allowed (AllowEncodedSlashes Off).');
        }
        $path = rawurldecode($rawPath);
        if (str_contains($path, "\0")) {
            return $this->error(400, null);
        }
        $uri = self::normalizeUrlPath($path);
        $this->origPath = $uri;
        $this->origQuery = $query;

        $errorHandled = false;
        for ($round = 0; $round <= self::MAX_ROUNDS; $round++) {
            $a = $this->processUrl($uri, $query);
            if ($a['action'] === 'internal') {
                if (empty($a['quiet'])) {
                    $this->noteInternalRedirect($uri, $query);
                }
                $uri = self::normalizeUrlPath($a['uri']);
                $query = $a['query'];
                continue;
            }
            if ($a['action'] === 'error' && !$errorHandled) {
                $doc = $this->errorDocument((int) $a['status'], $a['ctx'] ?? null);
                if ($doc !== null) {
                    $errorHandled = true;
                    if (str_contains($doc, '://')) {
                        return ['action' => 'redirect', 'status' => 302, 'location' => $doc, 'why' => 'ErrorDocument'];
                    }
                    if (str_starts_with($doc, '/')) {
                        $this->errorStatus = (int) $a['status'];
                        $this->noteInternalRedirect($uri, $query);
                        $this->redirectVars['REDIRECT_STATUS'] = (string) $a['status'];
                        $dq = strpos($doc, '?');
                        $uri = self::normalizeUrlPath($dq === false ? $doc : substr($doc, 0, $dq));
                        $query = $dq === false ? '' : substr($doc, $dq + 1);
                        continue;
                    }
                    return ['action' => 'text', 'status' => (int) $a['status'], 'type' => 'text/html', 'body' => $doc];
                }
            }
            if ($this->errorStatus !== null && in_array($a['action'], ['php', 'static'], true)) {
                $a['status'] = $this->errorStatus;
            }
            return $a;
        }
        return $this->error(500, null, 'Request exceeded the limit of ' . self::MAX_ROUNDS . ' internal redirects; check the rewrite rules for a loop.');
    }

    private function noteInternalRedirect(string $uri, string $query): void
    {
        if (!isset($this->redirectVars['REDIRECT_URL'])) {
            $this->redirectVars['REDIRECT_URL'] = $uri;
            if ($query !== '') {
                $this->redirectVars['REDIRECT_QUERY_STRING'] = $query;
            }
            $this->redirectVars['REDIRECT_STATUS'] = '200';
        }
        foreach ($this->env as $k => $v) {
            if ($v !== null && !str_starts_with($k, 'REDIRECT_')) {
                $this->redirectVars['REDIRECT_' . $k] = $v;
            }
        }
    }

    /** @return array<string,mixed> */
    private function processUrl(string $uri, string $query): array
    {
        $this->curUri = $uri;
        $this->curQuery = $query;
        $this->curPathInfo = '';
        $this->perDir = false;
        $vh = $this->vh;
        $fsTarget = null;
        $translated = false; // server-level rewrite already decided the file

        // 1. Server-context mod_rewrite (runs before mod_proxy and mod_alias).
        if (!$this->rewriteEnded && $vh['rewrite']['engine'] && $vh['rewrite']['rules'] !== []) {
            $res = $this->rewrite->run($vh['rewrite']['rules'], $uri, $query);
            $this->absorbRewrite($res);
            switch ($res['type']) {
                case 'forbidden':
                    return $this->error(403, null, null, $res['rule']);
                case 'gone':
                    return $this->error(410, null, null, $res['rule']);
                case 'redirect':
                    $r = $this->redirectAction((string) $res['uri'], (string) $res['query'], (int) $res['status'], (bool) $res['noescape'], null, $res['rule']);
                    if ($r !== null) {
                        return $r;
                    }
                    break;
                case 'proxy':
                    return $this->proxyAction($this->withQuery((string) $res['uri'], (string) $res['query']), $res['rule']['text'], $res['rule']['file'] . ':' . $res['rule']['line']);
                case 'rewrite':
                    $new = (string) $res['uri'];
                    $query = (string) $res['query'];
                    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $new)) {
                        $local = $this->stripOwnHost($new);
                        if ($local === null) {
                            $r = $this->redirectAction($new, $query, 302, (bool) $res['noescape'], null, $res['rule']);
                            if ($r !== null) {
                                return $r;
                            }
                            break;
                        }
                        $new = $local;
                    }
                    if ($this->isFilesystemPath($new)) {
                        $fsTarget = $this->mapper->map($new);
                        $translated = true;
                    } else {
                        $uri = self::normalizeUrlPath($new);
                        $translated = !$res['pt'];
                    }
                    $this->curQuery = $query;
                    if ($res['end']) {
                        $this->rewriteEnded = true;
                    }
                    break;
            }
        }

        // 2. ProxyPass / ProxyPassMatch.
        if (!$translated) {
            $p = $this->matchProxy($uri, $query);
            if ($p !== null) {
                return $p;
            }
        }

        // 3. Redirect / RedirectMatch.
        if (!$translated) {
            $r = $this->matchRedirects($vh['redirects'], $uri, $query);
            if ($r !== null) {
                return $r;
            }
        }

        // 4. Alias / DocumentRoot.
        if ($fsTarget !== null) {
            $map = ['file' => $fsTarget, 'urlPrefix' => null, 'fsPrefix' => null, 'cgi' => false];
        } elseif ($translated) {
            $map = ['file' => PathMapper::normalize($vh['docroot'] . $uri), 'urlPrefix' => '', 'fsPrefix' => $vh['docroot'], 'cgi' => false];
        } else {
            $map = $this->translate($uri);
        }
        $this->curMap = $map;

        // 5. Directory walk: split off PATH_INFO.
        [$filename, $pathInfo] = self::splitPath($map['file']);
        $this->curPathInfo = $pathInfo;

        // 6. Per-directory configuration (<Directory>, .htaccess, <Files>, <Location>).
        $ctx = $this->buildContext($filename, $pathInfo, $uri);

        if ($ctx['proxy'] !== null && !$translated) {
            $px = $ctx['proxy'];
            $rest = (string) substr($uri, strlen(rtrim($px['prefix'], '/')));
            return $this->proxyAction($this->withQuery(rtrim($px['to'], '/') . self::encodePath($rest === '' ? '' : $rest), $query), $px['text'], $px['where']);
        }

        // 7. Access control.
        if (!$this->allowed($ctx) || preg_match('/^\.ht/i', basename($filename))) {
            return $this->error(403, $ctx);
        }

        // 8. Redirects from <Directory>, <Location> and .htaccess.
        $r = $this->matchRedirects($ctx['redirects'], $uri, $query);
        if ($r !== null) {
            return $r;
        }

        // 9. Per-directory mod_rewrite.
        $rw = $ctx['rewrite'];
        if (!$this->rewriteEnded && $rw !== null && $rw['engine'] && $rw['rules'] !== []) {
            $rest = PathMapper::stripPrefix($rw['dir'], $filename . $pathInfo);
            if ($rest !== null) {
                $subject = ltrim($rest, '/');
                $this->perDir = true;
                $this->perDirDir = $rw['dir'];
                $this->perDirSubject = $subject;
                $this->perDirFilename = $filename;
                $res = $this->rewrite->run($rw['rules'], $subject, $query);
                $this->perDir = false;
                $this->absorbRewrite($res);
                $base = $rw['base'] ?? $this->urlOfDir($rw['dir'], $map);
                $base = rtrim($base, '/') . '/';
                switch ($res['type']) {
                    case 'forbidden':
                        return $this->error(403, $ctx, null, $res['rule']);
                    case 'gone':
                        return $this->error(410, $ctx, null, $res['rule']);
                    case 'redirect':
                        $r = $this->redirectAction((string) $res['uri'], (string) $res['query'], (int) $res['status'], (bool) $res['noescape'], $base, $res['rule']);
                        if ($r !== null) {
                            return $r;
                        }
                        break;
                    case 'proxy':
                        $target = (string) $res['uri'];
                        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $target)) {
                            $target = 'http://' . $this->hostHeader . (str_starts_with($target, '/') ? $target : $base . $target);
                        }
                        return $this->proxyAction($this->withQuery($target, (string) $res['query']), $res['rule']['text'], $res['rule']['file'] . ':' . $res['rule']['line']);
                    case 'rewrite':
                        $new = (string) $res['uri'];
                        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $new)) {
                            $local = $this->stripOwnHost($new);
                            if ($local === null) {
                                $r = $this->redirectAction($new, (string) $res['query'], 302, (bool) $res['noescape'], null, $res['rule']);
                                if ($r !== null) {
                                    return $r;
                                }
                                break;
                            }
                            $new = $local;
                        } elseif (!str_starts_with($new, '/')) {
                            $new = $base . $new;
                        }
                        $new = self::normalizeUrlPath($new);
                        if ($res['end']) {
                            $this->rewriteEnded = true;
                        }
                        if ($new !== $uri || $res['query'] !== $query) {
                            return ['action' => 'internal', 'uri' => $new, 'query' => $res['query']];
                        }
                        break;
                }
            }
        }

        // 10. Hand the file to its handler.
        $isDir = $pathInfo === '' && is_dir($filename);
        if ($isDir) {
            if (!str_ends_with($uri, '/') && $ctx['dirslash']) {
                $r = $this->redirectAction($uri . '/', $query, 301, false, null, null);
                if ($r !== null) {
                    return $r;
                }
            }
            foreach ($ctx['dirindex'] as $index) {
                if (str_starts_with($index, '/')) {
                    if ($this->rewriteUrlExists($index)) {
                        return ['action' => 'internal', 'uri' => $index, 'query' => $query, 'quiet' => true];
                    }
                    continue;
                }
                if (is_file(rtrim($filename, '/') . '/' . $index)) {
                    return ['action' => 'internal', 'uri' => rtrim($uri, '/') . '/' . $index, 'query' => $query, 'quiet' => true];
                }
            }
            if ($ctx['indexes']) {
                return ['action' => 'listing', 'status' => 200, 'dir' => $filename, 'uri' => $uri, 'ctx' => $ctx];
            }
            return $this->error(403, $ctx, 'Directory listing is disabled (no DirectoryIndex file and Options -Indexes).');
        }
        $handler = $this->handlerFor($filename, $ctx, $map);
        if (!file_exists($filename) && !in_array($handler['kind'], ['proxy', 'status'], true)) {
            return $this->error(404, $ctx);
        }
        if ($pathInfo !== '') {
            $accept = $ctx['acceptpathinfo'] ?? ($handler['kind'] === 'php');
            if (!$accept) {
                return $this->error(404, $ctx);
            }
        }
        $scriptName = $uri;
        if ($pathInfo !== '' && str_ends_with($uri, $pathInfo)) {
            $scriptName = substr($uri, 0, -strlen($pathInfo));
        }
        $base = [
            'status' => 200, 'file' => $filename, 'path_info' => $pathInfo, 'uri' => $uri, 'script_name' => $scriptName,
            'query' => $query, 'ctx' => $ctx, 'map' => $map,
        ];
        switch ($handler['kind']) {
            case 'php':
                return ['action' => 'php'] + $base;
            case 'proxy':
                return $this->proxyAction($this->withQuery(rtrim($handler['target'], '/') . self::encodePath($uri), $query), 'SetHandler ' . $handler['raw'], 'per-directory config');
            case 'cgi':
                return ['action' => 'text', 'status' => 501, 'type' => 'text/html', 'body' => self::page(
                    'CGI not supported',
                    '<p>Apache would have run <code>' . self::h($filename) . '</code> as a CGI script. PHP\'s built-in server cannot run CGI programs, so it was not executed.</p>'
                )];
            case 'status':
                return ['action' => 'text', 'status' => 501, 'type' => 'text/html', 'body' => self::page(
                    'Handler not available',
                    '<p>This URL is served by Apache\'s <code>' . self::h($handler['raw']) . '</code> handler, which has no equivalent under PHP\'s built-in server.</p>'
                )];
            case 'source':
                return ['action' => 'static', 'mime' => 'text/plain'] + $base;
        }
        return ['action' => 'static', 'mime' => null] + $base;
    }

    /** @param array<string,mixed> $res */
    private function absorbRewrite(array $res): void
    {
        foreach ($res['env'] as $k => $v) {
            $this->env[$k] = $v;
        }
        foreach ($res['cookies'] as $c) {
            $this->cookies[] = $c;
        }
        if ($res['mime'] !== null) {
            $this->rewriteMime = $res['mime'];
        }
        if ($res['handler'] !== null) {
            $this->rewriteHandler = $res['handler'];
        }
    }

    /** @return array<string,mixed>|null */
    private function matchProxy(string $uri, string $query): ?array
    {
        foreach ($this->vh['proxies'] as $p) {
            if ($p['type'] === 'prefix') {
                if (!str_starts_with($uri, $p['from'])) {
                    continue;
                }
                if ($p['to'] === '!') {
                    return null;
                }
                $target = $p['to'] . self::encodePath((string) substr($uri, strlen($p['from'])));
            } else {
                $m = [];
                if (@preg_match("\x01" . $p['from'] . "\x01", $uri, $m) !== 1) {
                    continue;
                }
                if ($p['to'] === '!') {
                    return null;
                }
                $target = self::substituteRefs($p['to'], $m);
            }
            return $this->proxyAction($this->withQuery($target, $query), $p['text'], $p['where']);
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $redirects
     * @return array<string,mixed>|null
     */
    private function matchRedirects(array $redirects, string $uri, string $query): ?array
    {
        foreach ($redirects as $r) {
            if ($r['type'] === 'prefix') {
                $rest = self::aliasRest($r['from'], $uri);
                if ($rest === null) {
                    continue;
                }
                if ($r['status'] === 410 || $r['to'] === null) {
                    return $this->error($r['status'] === 410 ? 410 : 404, null, null);
                }
                $to = $r['to'] . self::encodePath(str_ends_with($r['from'], '/') ? ltrim($rest, '/') : $rest);
                $action = $this->redirectAction($to, str_contains($to, '?') ? '' : $query, $r['status'], true, null, null, $r['where']);
            } else {
                $m = [];
                if (@preg_match("\x01" . $r['from'] . "\x01", $uri, $m) !== 1) {
                    continue;
                }
                if ($r['status'] === 410 || $r['to'] === null) {
                    return $this->error(410, null, null);
                }
                $action = $this->redirectAction(self::substituteRefs($r['to'], $m), '', $r['status'], true, null, null, $r['where']);
            }
            if ($action !== null) {
                return $action;
            }
        }
        return null;
    }

    /** @return array{file:string,urlPrefix:?string,fsPrefix:?string,cgi:bool} */
    private function translate(string $uri): array
    {
        foreach ($this->vh['aliases_map'] as $a) {
            if ($a['type'] === 'prefix') {
                $rest = self::aliasRest($a['url'], $uri);
                if ($rest === null) {
                    continue;
                }
                return [
                    'file' => PathMapper::normalize(rtrim($a['path'], '/') . '/' . ltrim($rest, '/')),
                    'urlPrefix' => rtrim($a['url'], '/'),
                    'fsPrefix' => PathMapper::normalize($a['path']),
                    'cgi' => $a['cgi'],
                ];
            }
            $m = [];
            if (@preg_match("\x01" . $a['regex'] . "\x01", $uri, $m) === 1) {
                return ['file' => PathMapper::normalize(self::substituteRefs($a['path'], $m)), 'urlPrefix' => null, 'fsPrefix' => null, 'cgi' => $a['cgi']];
            }
        }
        $docroot = $this->vh['docroot'];
        return ['file' => PathMapper::normalize($docroot . '/' . ltrim($uri, '/')), 'urlPrefix' => '', 'fsPrefix' => $docroot, 'cgi' => false];
    }

    /**
     * mod_alias prefix matching: "/icons" matches "/icons" and "/icons/x"
     * but not "/iconsfoo". Returns the remainder, or null.
     */
    public static function aliasRest(string $alias, string $uri): ?string
    {
        if ($alias === '' ) {
            return null;
        }
        if (str_ends_with($alias, '/')) {
            if (str_starts_with($uri, $alias)) {
                return '/' . substr($uri, strlen($alias));
            }
            if ($uri === rtrim($alias, '/') && $alias !== '/') {
                return null;
            }
            return null;
        }
        if ($uri === $alias) {
            return '';
        }
        if (str_starts_with($uri, $alias . '/')) {
            return substr($uri, strlen($alias));
        }
        return null;
    }

    /** Substitutes $0-$9 in a target string. @param array<int|string,string> $m */
    public static function substituteRefs(string $target, array $m): string
    {
        return (string) preg_replace_callback('/\\\\?\$(\d)/', static function (array $x) use ($m): string {
            if ($x[0][0] === '\\') {
                return substr($x[0], 1);
            }
            return (string) ($m[(int) $x[1]] ?? '');
        }, $target);
    }

    /**
     * Apache's directory walk: returns the file (or first missing path
     * component) and the PATH_INFO that follows it.
     *
     * @return array{0:string,1:string}
     */
    public static function splitPath(string $file): array
    {
        if (file_exists($file)) {
            return [$file, ''];
        }
        $prefix = '';
        $rest = $file;
        if (preg_match('#^([A-Z]:)?/#i', $file, $m)) {
            $prefix = $m[0];
            $rest = substr($file, strlen($m[0]));
        }
        $segments = explode('/', $rest);
        $path = rtrim($prefix, '/');
        foreach ($segments as $i => $seg) {
            $path .= '/' . $seg;
            if (is_file($path) || !file_exists($path)) {
                $remaining = array_slice($segments, $i + 1);
                $pathInfo = $remaining === [] ? '' : '/' . implode('/', $remaining);
                return [$path, $pathInfo];
            }
        }
        return [$file, ''];
    }

    /** URL of a directory, for relative rewrite substitutions without RewriteBase. @param array<string,mixed> $map */
    private function urlOfDir(string $dir, array $map): string
    {
        if ($map['fsPrefix'] !== null && $map['urlPrefix'] !== null) {
            $rest = PathMapper::stripPrefix($map['fsPrefix'], $dir);
            if ($rest !== null) {
                return $map['urlPrefix'] . $rest . '/';
            }
        }
        $rest = PathMapper::stripPrefix($this->vh['docroot'], $dir);
        if ($rest !== null) {
            return $rest . '/';
        }
        foreach ($this->vh['aliases_map'] as $a) {
            if ($a['type'] === 'prefix') {
                $rest = PathMapper::stripPrefix($a['path'], $dir);
                if ($rest !== null) {
                    return rtrim($a['url'], '/') . $rest . '/';
                }
            }
        }
        return '/';
    }

    /**
     * Server-context substitutions are filesystem paths when they point at
     * the old or new location of something that exists.
     */
    private function isFilesystemPath(string $path): bool
    {
        if (PathMapper::isWindows($path)) {
            return true;
        }
        if ($this->mapper->isMapped($path) && !$this->mapper->isMapped('/')) {
            return true;
        }
        if (PathMapper::stripPrefix($this->vh['docroot'], $path) !== null) {
            return true;
        }
        $first = explode('/', ltrim($path, '/'))[0];
        return $first !== '' && file_exists($path) && !file_exists(rtrim($this->vh['docroot'], '/') . '/' . $first);
    }

    /** Returns the path of a URL on one of our own hosts, or null for a foreign URL. */
    private function stripOwnHost(string $url): ?string
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['host'])) {
            return null;
        }
        $host = strtolower($p['host']);
        if ($host !== $this->host && !self::hostMatches($this->vh, $host)) {
            return null;
        }
        return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    // ------------------------------------------------------------------
    // Per-directory configuration
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function defaultContext(): array
    {
        return [
            'dirindex' => $this->cfg['dirindex_default'] ?? ['index.html'],
            'indexes' => false,
            'allowoverride' => (bool) ($this->cfg['allowoverride_default'] ?? false),
            'dirslash' => true,
            'require' => null,
            'legacy' => null,
            'errordocs' => [],
            'types' => [],
            'handlers' => [],
            'sethandler' => null,
            'forcetype' => null,
            'php_ini' => [],
            'php_engine' => true,
            'env' => [],
            'redirects' => [],
            'rewrite' => null,
            'acceptpathinfo' => null,
            'files' => [],
            'proxy' => null,
            'charset' => null,
            'headers' => [],
            'htaccess' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function buildContext(string $filename, string $pathInfo, string $uri): array
    {
        $ctx = $this->defaultContext();
        $this->applyNodes($ctx, $this->vh['base'], null, 'server', $uri);

        $isDir = $pathInfo === '' && is_dir($filename);
        $dir = $isDir ? $filename : PathMapper::normalize(dirname($filename));
        $htmode = $this->cfg['htaccess'] ?? 'auto';

        $plain = [];
        $regex = [];
        $files = [];
        $locations = [];
        foreach ($this->vh['sections'] as $s) {
            if ($s['kind'] === 'directory') {
                if ($s['regex']) {
                    $regex[] = $s;
                } else {
                    $plain[] = $s;
                }
            } elseif ($s['kind'] === 'files') {
                $files[] = $s;
            } else {
                $locations[] = $s;
            }
        }

        foreach (self::ancestors($dir) as $d) {
            foreach ($plain as $s) {
                if (self::dirPatternMatches($s['pattern'], $d)) {
                    $this->applyNodes($ctx, $s['nodes'], $d, 'directory', $uri);
                }
            }
            $allowed = $htmode === 'always' || ($htmode !== 'never' && $ctx['allowoverride']);
            if ($allowed) {
                $ht = $this->loadHtaccess(rtrim($d, '/') . '/' . $this->vh['accessfile']);
                if ($ht !== null) {
                    $ctx['htaccess'][] = rtrim($d, '/') . '/' . $this->vh['accessfile'];
                    $this->applyNodes($ctx, $ht, $d, 'htaccess', $uri);
                }
            }
        }
        foreach ($regex as $s) {
            if (@preg_match("\x01" . $s['pattern'] . "\x01", $dir) === 1 || @preg_match("\x01" . $s['pattern'] . "\x01", $dir . '/') === 1) {
                $this->applyNodes($ctx, $s['nodes'], $dir, 'directory', $uri);
            }
        }
        $base = basename($filename);
        $nested = $ctx['files'];
        foreach (array_merge($files, $nested) as $s) {
            if (self::filesPatternMatches($s, $base)) {
                $this->applyNodes($ctx, $s['nodes'], $s['dir'] ?? $dir, 'files', $uri);
            }
        }
        foreach ($locations as $s) {
            $hit = $s['regex']
                ? @preg_match("\x01" . $s['pattern'] . "\x01", $uri) === 1
                : (strpbrk($s['pattern'], '*?[') !== false ? fnmatch($s['pattern'] . '*', $uri) : str_starts_with($uri, $s['pattern']));
            if ($hit) {
                $this->applyNodes($ctx, $s['nodes'], null, 'location', $uri, $s);
            }
        }
        return $ctx;
    }

    /** @return list<string> "/", "/var", "/var/www", ... */
    public static function ancestors(string $dir): array
    {
        $dir = PathMapper::normalize($dir);
        $prefix = '/';
        $rest = $dir;
        if (preg_match('#^([A-Z]:)/?#i', $dir, $m)) {
            $prefix = $m[1] . '/';
            $rest = substr($dir, strlen($m[0]));
        }
        $out = [$prefix];
        $cur = rtrim($prefix, '/');
        foreach (explode('/', trim($rest, '/')) as $seg) {
            if ($seg === '') {
                continue;
            }
            $cur .= '/' . $seg;
            $out[] = $cur;
        }
        return $out;
    }

    public static function dirPatternMatches(string $pattern, string $dir): bool
    {
        if (strpbrk($pattern, '*?[') === false) {
            return PathMapper::samePath($pattern, $dir);
        }
        return fnmatch(rtrim($pattern, '/'), $dir, FNM_PATHNAME);
    }

    /** @param array<string,mixed> $s */
    private static function filesPatternMatches(array $s, string $base): bool
    {
        if ($s['regex']) {
            return @preg_match("\x01" . $s['pattern'] . "\x01", $base) === 1;
        }
        return fnmatch($s['pattern'], $base);
    }

    /** @return list<array<string,mixed>>|null */
    private function loadHtaccess(string $path): ?array
    {
        if (array_key_exists($path, $this->htaccessCache)) {
            return $this->htaccessCache[$path];
        }
        $nodes = null;
        if (is_file($path) && is_readable($path)) {
            $parser = new ConfigParser($this->mapper, false);
            if ($this->cfg['modules'] !== null) {
                $parser->sawLoadModule = true;
                foreach ($this->cfg['modules'] as $mod) {
                    $parser->modules[$mod] = true;
                }
            }
            $nodes = $parser->parseString((string) file_get_contents($path), $path);
            foreach ($parser->warnings as $w) {
                $this->notes[] = $w;
            }
        }
        return $this->htaccessCache[$path] = $nodes;
    }

    /**
     * Applies one configuration section (or .htaccess file) to a context.
     *
     * @param array<string,mixed> $ctx
     * @param list<array<string,mixed>> $nodes
     * @param array<string,mixed>|null $section the <Location> being applied
     */
    private function applyNodes(array &$ctx, array $nodes, ?string $dir, string $kind, string $uri, ?array $section = null): void
    {
        $require = null;
        $legacy = null;
        $rwNodes = [];
        foreach ($nodes as $n) {
            $args = $n['args'];
            $a0 = $args[0] ?? '';
            switch ($n['d']) {
                case 'directoryindex':
                    $ctx['dirindex'] = (count($args) === 1 && strtolower($a0) === 'disabled') ? [] : $args;
                    break;
                case 'options':
                    $this->applyOptions($ctx, $args);
                    break;
                case 'allowoverride':
                    if ($kind === 'directory' || $kind === 'server') {
                        $ctx['allowoverride'] = strtolower($a0) !== 'none';
                    }
                    break;
                case 'directoryslash':
                    $ctx['dirslash'] = strtolower($a0) !== 'off';
                    break;
                case 'require':
                case 'requireall':
                case 'requireany':
                case 'requirenone':
                    $require[] = self::requireItem($n);
                    break;
                case 'order':
                    $legacy['order'] = strtolower(str_replace(' ', '', implode('', $args)));
                    break;
                case 'allow':
                case 'deny':
                    $legacy[$n['d']] = array_merge($legacy[$n['d']] ?? [], strtolower($a0) === 'from' ? array_slice($args, 1) : $args);
                    break;
                case 'errordocument':
                    if (isset($args[1])) {
                        $ctx['errordocs'][(int) $a0] = $args[1];
                    }
                    break;
                case 'addtype':
                    foreach (array_slice($args, 1) as $ext) {
                        $ext = strtolower(ltrim($ext, '.'));
                        if (preg_match('/x-httpd-php(?!-source)/i', $a0) || stripos($a0, 'php-script') !== false) {
                            $ctx['handlers'][$ext] = 'application/x-httpd-php';
                        } else {
                            $ctx['types'][$ext] = $a0;
                        }
                    }
                    break;
                case 'addhandler':
                    foreach (array_slice($args, 1) as $ext) {
                        $ctx['handlers'][strtolower(ltrim($ext, '.'))] = $a0;
                    }
                    break;
                case 'removehandler':
                    foreach ($args as $ext) {
                        unset($ctx['handlers'][strtolower(ltrim($ext, '.'))]);
                        $ctx['handlers'][strtolower(ltrim($ext, '.'))] = 'none';
                    }
                    break;
                case 'removetype':
                    foreach ($args as $ext) {
                        unset($ctx['types'][strtolower(ltrim($ext, '.'))]);
                    }
                    break;
                case 'sethandler':
                    $ctx['sethandler'] = $a0;
                    break;
                case 'forcetype':
                    if (strtolower($a0) === 'none') {
                        $ctx['forcetype'] = null;
                    } elseif (preg_match('/x-httpd-php(?!-source)/i', $a0)) {
                        $ctx['sethandler'] = $a0;
                    } else {
                        $ctx['forcetype'] = $a0;
                    }
                    break;
                case 'php_value':
                case 'php_admin_value':
                    if (isset($args[1])) {
                        $ctx['php_ini'][$a0] = strtolower($args[1]) === 'none' ? '' : $this->mapIniValue($args[1]);
                    }
                    break;
                case 'php_flag':
                case 'php_admin_flag':
                    $on = in_array(strtolower($args[1] ?? ''), ['on', '1', 'true', 'yes'], true);
                    if (strtolower($a0) === 'engine') {
                        $ctx['php_engine'] = $on;
                    } else {
                        $ctx['php_ini'][$a0] = $on ? '1' : '0';
                    }
                    break;
                case 'setenv':
                    $ctx['env'][$a0] = $args[1] ?? '';
                    break;
                case 'unsetenv':
                    foreach ($args as $v) {
                        $ctx['env'][$v] = null;
                    }
                    break;
                case 'setenvif':
                case 'setenvifnocase':
                case 'browsermatch':
                case 'browsermatchnocase':
                    $this->applySetEnvIf($ctx, $n);
                    break;
                case 'acceptpathinfo':
                    $v = strtolower($a0);
                    $ctx['acceptpathinfo'] = $v === 'default' ? null : $v === 'on';
                    break;
                case 'adddefaultcharset':
                    $v = strtolower($a0);
                    $ctx['charset'] = $v === 'off' ? null : ($v === 'on' ? 'iso-8859-1' : $a0);
                    break;
                case 'header':
                    $ctx['headers'][] = $args;
                    break;
                case 'redirect':
                case 'redirectmatch':
                case 'redirectpermanent':
                case 'redirecttemp':
                    $r = self::redirectEntry($n);
                    if ($r !== null) {
                        if ($r['to'] === null && $section !== null && str_contains($r['from'], '://')) {
                            $r['to'] = $r['from'];
                            $r['from'] = $section['pattern'];
                        }
                        $ctx['redirects'][] = $r;
                    }
                    break;
                case 'rewriteengine':
                case 'rewriterule':
                case 'rewritecond':
                case 'rewritebase':
                case 'rewriteoptions':
                    $rwNodes[] = $n;
                    break;
                case 'files':
                case 'filesmatch':
                    $regex = $n['d'] === 'filesmatch' || $a0 === '~';
                    $ctx['files'][] = [
                        'kind' => 'files', 'regex' => $regex, 'pattern' => $a0 === '~' ? ($args[1] ?? '') : $a0,
                        'nodes' => $n['children'] ?? [], 'dir' => $dir,
                    ];
                    break;
                case 'limit':
                case 'limitexcept':
                    $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
                    $listed = in_array($method, array_map('strtoupper', $args), true) || ($method === 'HEAD' && in_array('GET', array_map('strtoupper', $args), true));
                    if ($listed === ($n['d'] === 'limit')) {
                        $this->applyNodes($ctx, $n['children'] ?? [], $dir, $kind, $uri, $section);
                    }
                    break;
                case 'proxypass':
                    if ($kind === 'location' && $section !== null && count($args) >= 1 && !str_starts_with($a0, '/')) {
                        if ($a0 === '!') {
                            $ctx['proxy'] = null;
                        } elseif (!self::isFastCgiTarget($a0)) {
                            $ctx['proxy'] = [
                                'to' => $a0, 'prefix' => $section['pattern'],
                                'text' => "<Location {$section['pattern']}> ProxyPass $a0",
                                'where' => "{$section['file']}:{$section['line']}",
                            ];
                        }
                    }
                    break;
            }
        }
        if ($require !== null) {
            $ctx['require'] = ['type' => 'requireany', 'children' => $require, 'negate' => false];
        }
        if ($legacy !== null) {
            $prev = $ctx['legacy'] ?? ['order' => 'deny,allow', 'allow' => [], 'deny' => []];
            $ctx['legacy'] = [
                'order' => $legacy['order'] ?? $prev['order'],
                'allow' => isset($legacy['allow']) || isset($legacy['deny']) ? ($legacy['allow'] ?? []) : $prev['allow'],
                'deny' => isset($legacy['allow']) || isset($legacy['deny']) ? ($legacy['deny'] ?? []) : $prev['deny'],
            ];
        }
        if ($rwNodes !== [] && ($kind === 'directory' || $kind === 'htaccess') && $dir !== null) {
            $rw = Rewrite::collect($rwNodes);
            $parent = $ctx['rewrite'];
            $rules = $rw['rules'];
            if ($parent !== null && in_array('inherit', $rw['options'], true)) {
                $rules = array_merge($rules, $parent['rules']);
            } elseif ($parent !== null && in_array('inheritbefore', $rw['options'], true)) {
                $rules = array_merge($parent['rules'], $rules);
            }
            $ctx['rewrite'] = [
                'engine' => $rw['engine'] ?? ($parent['engine'] ?? false),
                'rules' => $rules,
                'base' => $rw['base'],
                'dir' => $dir,
            ];
        }
    }

    /**
     * @param array<string,mixed> $ctx
     * @param list<string> $args
     */
    private function applyOptions(array &$ctx, array $args): void
    {
        $relative = $args !== [] && array_reduce($args, static fn(bool $c, string $a) => $c && ($a[0] === '+' || $a[0] === '-'), true);
        if (!$relative) {
            $ctx['indexes'] = false;
        }
        foreach ($args as $a) {
            $sign = $a[0] === '-' ? false : true;
            $name = strtolower(ltrim($a, '+-'));
            if ($name === 'indexes' || $name === 'all') {
                $ctx['indexes'] = $name === 'all' ? $sign : $sign;
            } elseif ($name === 'none') {
                $ctx['indexes'] = false;
            }
        }
    }

    private function mapIniValue(string $value): string
    {
        if (PathMapper::isAbsolute($value) && !str_contains($value, PATH_SEPARATOR === ':' ? ';' : ':')) {
            return $this->mapper->map($value);
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $n
     */
    private function applySetEnvIf(array &$ctx, array $n): void
    {
        $args = $n['args'];
        $nocase = str_ends_with($n['d'], 'nocase');
        if (str_starts_with($n['d'], 'browsermatch')) {
            array_unshift($args, 'User-Agent');
        }
        if (count($args) < 3) {
            return;
        }
        $attr = array_shift($args);
        $regex = array_shift($args);
        $value = match (strtolower($attr)) {
            'remote_host', 'remote_addr' => (string) ($this->server['REMOTE_ADDR'] ?? ''),
            'server_addr' => (string) ($this->server['SERVER_ADDR'] ?? ''),
            'request_method' => (string) ($this->server['REQUEST_METHOD'] ?? 'GET'),
            'request_protocol' => (string) ($this->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1'),
            'request_uri' => $this->curUri,
            default => $this->headerValue($attr) ?? (string) ($ctx['env'][$attr] ?? $this->env[$attr] ?? ''),
        };
        $m = [];
        if (@preg_match("\x01" . $regex . "\x01" . ($nocase ? 'i' : ''), $value, $m) !== 1) {
            return;
        }
        foreach ($args as $assign) {
            if (str_starts_with($assign, '!')) {
                $ctx['env'][substr($assign, 1)] = null;
                continue;
            }
            $parts = explode('=', $assign, 2);
            $ctx['env'][$parts[0]] = isset($parts[1]) ? self::substituteRefs($parts[1], $m) : '1';
        }
    }

    private function headerValue(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            return isset($this->server[$key]) ? (string) $this->server[$key] : null;
        }
        if (isset($this->server['HTTP_' . $key])) {
            return (string) $this->server['HTTP_' . $key];
        }
        if ($key === 'AUTHORIZATION' && isset($this->server['PHP_AUTH_USER'])) {
            return 'Basic ' . base64_encode($this->server['PHP_AUTH_USER'] . ':' . ($this->server['PHP_AUTH_PW'] ?? ''));
        }
        return null;
    }

    /**
     * Parses Redirect, RedirectMatch, RedirectPermanent and RedirectTemp.
     *
     * @param array<string,mixed> $n
     * @return array<string,mixed>|null
     */
    public static function redirectEntry(array $n): ?array
    {
        $args = $n['args'];
        $d = $n['d'];
        $status = $d === 'redirectpermanent' ? 301 : 302;
        $kw = strtolower($args[0] ?? '');
        if (ctype_digit($kw) || in_array($kw, ['permanent', 'temp', 'seeother', 'gone'], true)) {
            array_shift($args);
            $status = match ($kw) {
                'permanent' => 301, 'temp' => 302, 'seeother' => 303, 'gone' => 410,
                default => (int) $kw,
            };
        }
        if (!isset($args[0])) {
            return null;
        }
        return [
            'type' => $d === 'redirectmatch' ? 'regex' : 'prefix',
            'from' => $args[0],
            'to' => $args[1] ?? null,
            'status' => $status,
            'where' => "{$n['file']}:{$n['line']}",
        ];
    }

    // ------------------------------------------------------------------
    // Access control
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $n */
    private static function requireItem(array $n): array
    {
        if ($n['d'] === 'require') {
            $args = $n['args'];
            $negate = strtolower($args[0] ?? '') === 'not';
            if ($negate) {
                array_shift($args);
            }
            return ['type' => 'require', 'args' => $args, 'negate' => $negate];
        }
        $children = [];
        foreach ($n['children'] ?? [] as $c) {
            if (in_array($c['d'], ['require', 'requireall', 'requireany', 'requirenone'], true)) {
                $children[] = self::requireItem($c);
            }
        }
        return ['type' => $n['d'], 'children' => $children, 'negate' => false];
    }

    /** @param array<string,mixed> $ctx */
    private function allowed(array $ctx): bool
    {
        if ($ctx['require'] !== null) {
            return $this->evalRequire($ctx['require']) !== false;
        }
        if ($ctx['legacy'] !== null) {
            $ip = (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
            $allow = $this->hostListMatches($ctx['legacy']['allow'], $ip);
            $deny = $this->hostListMatches($ctx['legacy']['deny'], $ip);
            return $ctx['legacy']['order'] === 'allow,deny' ? ($allow && !$deny) : (!$deny || $allow);
        }
        return true;
    }

    /**
     * true = granted, false = denied, null = cannot tell (authentication
     * and other providers we do not implement count as granted).
     *
     * @param array<string,mixed> $item
     */
    private function evalRequire(array $item): ?bool
    {
        if ($item['type'] === 'require') {
            $args = $item['args'];
            $what = strtolower($args[0] ?? '');
            $rest = array_slice($args, 1);
            $ip = (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
            $res = match ($what) {
                'all' => strtolower($rest[0] ?? '') === 'granted',
                'local' => in_array($ip, ['127.0.0.1', '::1'], true) || str_starts_with($ip, '127.'),
                'ip' => $this->hostListMatches($rest, $ip),
                'method' => in_array(strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET')), array_map('strtoupper', $rest), true),
                'env' => (bool) array_filter($rest, fn(string $v) => ($this->env[$v] ?? null) !== null || getenv($v) !== false),
                default => null,
            };
            if ($res === null) {
                return null;
            }
            return $item['negate'] ? !$res : $res;
        }
        $results = array_map(fn(array $c) => $this->evalRequire($c), $item['children']);
        if ($results === []) {
            return null;
        }
        return match ($item['type']) {
            'requireall' => !in_array(false, $results, true),
            'requirenone' => !in_array(true, $results, true),
            default => in_array(true, $results, true) || in_array(null, $results, true),
        };
    }

    /** @param list<string> $list */
    private function hostListMatches(array $list, string $ip): bool
    {
        foreach ($list as $spec) {
            if (self::ipMatches($spec, $ip)) {
                return true;
            }
        }
        return false;
    }

    public static function ipMatches(string $spec, string $ip): bool
    {
        $spec = trim($spec);
        if (strtolower($spec) === 'all') {
            return true;
        }
        if ($spec === $ip) {
            return true;
        }
        if (str_contains($spec, '/')) {
            [$net, $bits] = explode('/', $spec, 2);
            $netBin = @inet_pton($net);
            $ipBin = @inet_pton($ip);
            if ($netBin === false || $ipBin === false || strlen($netBin) !== strlen($ipBin)) {
                return false;
            }
            if (str_contains($bits, '.')) {
                $maskBin = @inet_pton($bits);
                return $maskBin !== false && ($ipBin & $maskBin) === ($netBin & $maskBin);
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                return false;
            }
            $rem = $bits % 8;
            if ($rem === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
        }
        // Partial address: "10.1" matches 10.1.x.x.
        if (preg_match('/^[\d.]+$/', $spec)) {
            return str_starts_with($ip, rtrim($spec, '.') . '.');
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Handlers
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $map
     * @return array{kind:string,raw:string,target?:string}
     */
    private function handlerFor(string $file, array $ctx, array $map): array
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $h = $this->rewriteHandler ?? $ctx['sethandler'] ?? ($ctx['handlers'][$ext] ?? null);
        if ($h === null) {
            if ($ext === 'php') {
                $h = 'application/x-httpd-php';
            } elseif ($map['cgi']) {
                $h = 'cgi-script';
            }
        }
        $raw = (string) $h;
        $l = strtolower($raw);
        if ($h === null || $l === 'none' || $l === 'default-handler') {
            $kind = 'static';
        } elseif (str_starts_with($l, 'proxy:')) {
            if (self::isFastCgiTarget($l)) {
                $kind = 'php';
            } else {
                $target = substr($raw, 6);
                if (str_contains($target, '|')) {
                    $target = substr($target, strpos($target, '|') + 1);
                }
                return ['kind' => 'proxy', 'raw' => $raw, 'target' => $target];
            }
        } elseif (str_contains($l, 'php')) {
            $kind = str_contains($l, 'source') ? 'source' : 'php';
        } elseif ($l === 'cgi-script') {
            $kind = 'cgi';
        } elseif (in_array($l, ['server-status', 'server-info', 'balancer-manager', 'ldap-status'], true)) {
            $kind = 'status';
        } else {
            $kind = 'static';
        }
        if ($kind === 'php' && !$ctx['php_engine']) {
            $kind = 'static';
        }
        return ['kind' => $kind, 'raw' => $raw];
    }

    /** Whether a proxy target is PHP-FPM, which php -S replaces by running PHP itself. */
    public static function isFastCgiTarget(string $target): bool
    {
        $t = strtolower($target);
        return str_starts_with($t, 'fcgi://') || (str_starts_with($t, 'unix:') && str_contains($t, '|fcgi://'))
            || (str_starts_with($t, 'proxy:') && str_contains($t, 'fcgi://'));
    }

    /** @param array<string,mixed>|null $ctx */
    private function errorDocument(int $status, ?array $ctx): ?string
    {
        if ($ctx !== null && isset($ctx['errordocs'][$status])) {
            $doc = $ctx['errordocs'][$status];
        } else {
            $base = $this->defaultContext();
            $this->applyNodes($base, $this->vh['base'], null, 'server', $this->curUri);
            $doc = $base['errordocs'][$status] ?? null;
        }
        if ($doc === null || strtolower($doc) === 'default') {
            return null;
        }
        return $doc;
    }

    // ------------------------------------------------------------------
    // Building actions
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $ctx
     * @param array<string,mixed>|null $rule
     * @return array<string,mixed>
     */
    private function error(int $status, ?array $ctx, ?string $detail = null, ?array $rule = null): array
    {
        if ($rule !== null) {
            $detail = ($detail ? $detail . ' ' : '') . "Matched {$rule['text']} at {$rule['file']}:{$rule['line']}.";
        }
        return ['action' => 'error', 'status' => $status, 'ctx' => $ctx, 'detail' => $detail];
    }

    /** @return array<string,mixed> */
    private function proxyAction(string $target, string $directive, string $where): array
    {
        $members = [];
        if (preg_match('#^(balancer://[^/]+)#i', $target, $m)) {
            $members = $this->vh['balancers'][strtolower($m[1])] ?? [];
        }
        return [
            'action' => 'proxy',
            'status' => (int) ($this->cfg['proxy_status'] ?? 502),
            'target' => $target,
            'directive' => $directive,
            'where' => $where,
            'members' => $members,
        ];
    }

    /**
     * Builds an external redirect. Redirects to one of the hosts this router
     * serves are pointed back at php -S over plain HTTP; one that would land
     * on the very URL being requested (typically "upgrade to HTTPS") is
     * dropped, since following it would loop. Returns null when dropped.
     *
     * @param array<string,mixed>|null $rule
     * @return array<string,mixed>|null
     */
    private function redirectAction(string $target, string $query, int $status, bool $noescape, ?string $perDirBase, ?array $rule, ?string $where = null): ?array
    {
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $target)) {
            $path = str_starts_with($target, '/') ? $target : ($perDirBase ?? '/') . $target;
            if (!$noescape) {
                $path = self::encodePath($path);
            }
            $target = 'http://' . $this->hostHeader . $path;
        }
        if ($query !== '' && !str_contains($target, '?')) {
            $target .= '?' . $query;
        }
        $original = $target;
        if (empty($this->cfg['keep_redirects'])) {
            $p = parse_url($target);
            if ($p !== false && isset($p['host'])) {
                $host = strtolower($p['host']);
                if ($host === $this->host || $this->servesHost($host)) {
                    $hostPart = $host === $this->host ? $this->hostHeader : $host . ':' . $this->port;
                    $target = 'http://' . $hostPart . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '') . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
                    if ($host === $this->host) {
                        $path = rawurldecode($p['path'] ?? '/');
                        $q = $p['query'] ?? '';
                        $loops = ($path === $this->origPath && $q === $this->origQuery)
                            || ($path === $this->curUri && $q === $this->curQuery);
                        if ($loops) {
                            $this->notes[] = "Skipped redirect to $original (it points back at this URL, usually an HTTPS upgrade).";
                            return null;
                        }
                    }
                }
            }
        }
        $why = $rule !== null ? "{$rule['text']} at {$rule['file']}:{$rule['line']}" : ($where ?? '');
        return ['action' => 'redirect', 'status' => $status ?: 302, 'location' => $target, 'why' => $why];
    }

    private function servesHost(string $host): bool
    {
        foreach ($this->cfg['vhosts'] as $vh) {
            if (self::hostMatches($vh, $host)) {
                return true;
            }
        }
        return false;
    }

    private function withQuery(string $url, string $query): string
    {
        if ($query === '') {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    // ------------------------------------------------------------------
    // RewriteHost
    // ------------------------------------------------------------------

    public function rewriteVar(string $name, string $current): string
    {
        $u = strtoupper($name);
        if (str_starts_with($u, 'ENV:')) {
            $k = substr($name, 4);
            if (isset($this->env[$k])) {
                return (string) $this->env[$k];
            }
            if (isset($this->redirectVars[$k])) {
                return $this->redirectVars[$k];
            }
            $v = getenv($k);
            return $v === false ? (string) ($this->server[$k] ?? '') : $v;
        }
        if (str_starts_with($u, 'HTTP:')) {
            return $this->headerValue(substr($name, 5)) ?? '';
        }
        if (str_starts_with($u, 'SSL:')) {
            return '';
        }
        $now = (int) ($this->server['REQUEST_TIME'] ?? time());
        switch ($u) {
            case 'REQUEST_URI':
                return $this->curUri;
            case 'QUERY_STRING':
                return $this->curQuery;
            case 'REQUEST_FILENAME':
            case 'SCRIPT_FILENAME':
                if ($this->perDir) {
                    return $current === $this->perDirSubject ? $this->perDirFilename : rtrim($this->perDirDir, '/') . '/' . $current;
                }
                return $current;
            case 'DOCUMENT_ROOT':
                return $this->vh['docroot'];
            case 'CONTEXT_DOCUMENT_ROOT':
                return (string) ($this->curMap['fsPrefix'] ?? $this->vh['docroot']);
            case 'CONTEXT_PREFIX':
                return (string) ($this->curMap['urlPrefix'] ?? '');
            case 'SERVER_NAME':
                return $this->vh['name'] ?? $this->host;
            case 'SERVER_PORT':
                return (string) $this->port;
            case 'HTTPS':
                // As the original vhost would see it, so "force HTTPS" rules
                // in an SSL vhost stay quiet.
                return $this->vh['ssl'] ? 'on' : 'off';
            case 'REQUEST_SCHEME':
                return 'http';
            case 'THE_REQUEST':
                return ($this->server['REQUEST_METHOD'] ?? 'GET') . ' ' . ($this->server['REQUEST_URI'] ?? '/') . ' ' . ($this->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
            case 'REMOTE_HOST':
                return (string) ($this->server['REMOTE_ADDR'] ?? '');
            case 'IS_SUBREQ':
                return 'false';
            case 'API_VERSION':
                return '20120211:0';
            case 'SERVER_SOFTWARE':
                return 'Apache/' . ConfigParser::APACHE_VERSION . ' (apache2router)';
            case 'PATH_INFO':
                return $this->curPathInfo;
            case 'REMOTE_USER':
                return (string) ($this->server['PHP_AUTH_USER'] ?? '');
            case 'AUTH_TYPE':
                return isset($this->server['PHP_AUTH_USER']) ? 'Basic' : '';
            case 'TIME_YEAR':
                return date('Y', $now);
            case 'TIME_MON':
                return date('m', $now);
            case 'TIME_DAY':
                return date('d', $now);
            case 'TIME_HOUR':
                return date('H', $now);
            case 'TIME_MIN':
                return date('i', $now);
            case 'TIME_SEC':
                return date('s', $now);
            case 'TIME_WDAY':
                return date('w', $now);
            case 'TIME':
                return date('YmdHis', $now);
        }
        if (isset($this->server[$u]) && is_scalar($this->server[$u])) {
            return (string) $this->server[$u];
        }
        if (isset($this->env[$name])) {
            return (string) $this->env[$name];
        }
        return '';
    }

    public function rewriteMap(string $map, string $key, string $default): string
    {
        $m = $this->vh['rewrite']['maps'][$map] ?? null;
        if ($m === null) {
            return $default;
        }
        switch ($m['type']) {
            case 'int':
                return match (strtolower($m['source'])) {
                    'toupper' => strtoupper($key),
                    'tolower' => strtolower($key),
                    'escape' => rawurlencode($key),
                    'unescape' => rawurldecode($key),
                    default => $default,
                };
            case 'txt':
            case 'rnd':
                if (!isset($this->mapFiles[$m['source']])) {
                    $entries = [];
                    foreach (@file($m['source'], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                        $line = trim($line);
                        if ($line === '' || $line[0] === '#') {
                            continue;
                        }
                        $parts = preg_split('/\s+/', $line, 3) ?: [];
                        if (isset($parts[1]) && !isset($entries[$parts[0]])) {
                            $entries[$parts[0]] = $parts[1];
                        }
                    }
                    $this->mapFiles[$m['source']] = $entries;
                }
                $v = $this->mapFiles[$m['source']][$key] ?? null;
                if ($v === null) {
                    return $default;
                }
                if ($m['type'] === 'rnd') {
                    $choices = explode('|', $v);
                    return $choices[array_rand($choices)];
                }
                return $v;
        }
        return $default;
    }

    public function rewriteUrlExists(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        [$file] = self::splitPath($this->translate(self::normalizeUrlPath(rawurldecode($path)))['file']);
        return file_exists($file);
    }

    // ------------------------------------------------------------------
    // Sending the response
    // ------------------------------------------------------------------

    /**
     * Resolves the request and sends the response, or prepares the PHP
     * script for the caller to require. Always returns true: the router
     * handles every request itself.
     */
    public function handle(): bool
    {
        $a = $this->resolve();
        foreach ($this->cookies as $c) {
            $this->sendCookie($c);
        }
        switch ($a['action']) {
            case 'redirect':
                http_response_code($a['status']);
                header('Location: ' . $a['location']);
                header('Content-Type: text/html; charset=utf-8');
                echo self::page(self::STATUS_TEXT[$a['status']] ?? 'Redirect', '<p>The document has moved <a href="' . self::h($a['location']) . '">here</a>.</p>');
                break;
            case 'proxy':
                $this->sendProxyPage($a);
                break;
            case 'error':
                http_response_code($a['status']);
                header('Content-Type: text/html; charset=utf-8');
                echo $this->errorPage($a['status'], $a['detail'] ?? null);
                break;
            case 'text':
                http_response_code($a['status']);
                header('Content-Type: ' . $a['type'] . (str_starts_with($a['type'], 'text/') ? '; charset=utf-8' : ''));
                echo $a['body'];
                break;
            case 'listing':
                $this->sendHeaders($a['ctx']);
                header('Content-Type: text/html; charset=utf-8');
                echo $this->listing($a['dir'], $a['uri']);
                break;
            case 'static':
                $this->sendStatic($a);
                break;
            case 'php':
                $this->preparePhp($a);
                break;
        }
        return true;
    }

    /** @param array<string,mixed> $a */
    private function preparePhp(array $a): void
    {
        $ctx = $a['ctx'];
        $map = $a['map'];
        $s = $this->server;
        $docroot = $this->vh['docroot'];
        foreach (['PATH_INFO', 'PATH_TRANSLATED', 'ORIG_PATH_INFO', 'ORIG_SCRIPT_NAME', 'ORIG_SCRIPT_FILENAME', 'ORIG_PATH_TRANSLATED'] as $k) {
            unset($s[$k]);
        }
        $s['DOCUMENT_ROOT'] = $docroot;
        $s['CONTEXT_DOCUMENT_ROOT'] = $map['fsPrefix'] ?? $docroot;
        $s['CONTEXT_PREFIX'] = $map['urlPrefix'] ?? '';
        $s['SCRIPT_FILENAME'] = $a['file'];
        $s['SCRIPT_NAME'] = $a['script_name'];
        $s['PHP_SELF'] = $a['script_name'] . $a['path_info'];
        if ($a['path_info'] !== '') {
            $s['PATH_INFO'] = $a['path_info'];
            $s['PATH_TRANSLATED'] = $docroot . $a['path_info'];
        }
        $s['QUERY_STRING'] = $a['query'];
        $s['SERVER_NAME'] = $this->host !== '' ? $this->host : ($this->vh['name'] ?? 'localhost');
        $s['REQUEST_SCHEME'] = 'http';
        $s['GATEWAY_INTERFACE'] = 'CGI/1.1';
        $s['SERVER_SOFTWARE'] = $s['SERVER_SOFTWARE'] ?? 'PHP built-in server';
        foreach ($this->redirectVars as $k => $v) {
            $s[$k] = $v;
        }
        foreach ($ctx['env'] + $this->env as $k => $v) {
            if ($v === null) {
                unset($s[$k]);
                putenv($k);
            } else {
                $s[$k] = $v;
                putenv("$k=$v");
            }
        }
        $_SERVER = $s;
        if ($a['query'] !== $this->origQuery || $this->redirectVars !== []) {
            parse_str($a['query'], $get);
            $_GET = $get;
            $_REQUEST = array_merge($_GET, $_POST);
        }
        foreach ($ctx['php_ini'] as $k => $v) {
            @ini_set((string) $k, (string) $v);
        }
        if (($a['status'] ?? 200) !== 200) {
            http_response_code($a['status']);
        }
        $this->sendHeaders($ctx);
        @chdir(dirname($a['file']));
        self::$script = $a['file'];
    }

    /** @param array<string,mixed> $a */
    private function sendStatic(array $a): void
    {
        $file = $a['file'];
        $ctx = $a['ctx'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = $this->rewriteMime ?? $a['mime'] ?? $ctx['forcetype'] ?? $ctx['types'][$ext] ?? self::MIME[$ext] ?? 'application/octet-stream';
        if ($ctx['charset'] !== null && (str_starts_with($mime, 'text/') || $mime === 'application/javascript') && !str_contains($mime, 'charset')) {
            $mime .= '; charset=' . $ctx['charset'];
        }
        $mtime = (int) @filemtime($file);
        $size = (int) @filesize($file);
        $status = (int) ($a['status'] ?? 200);
        $ims = $this->server['HTTP_IF_MODIFIED_SINCE'] ?? null;
        if ($status === 200 && is_string($ims) && ($t = strtotime($ims)) !== false && $t >= $mtime) {
            http_response_code(304);
            return;
        }
        http_response_code($status);
        $this->sendHeaders($ctx);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        if (strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            return;
        }
        readfile($file);
    }

    /** @param array<string,mixed> $ctx */
    private function sendHeaders(array $ctx): void
    {
        foreach ($ctx['headers'] as $args) {
            if (isset($args[0]) && in_array(strtolower($args[0]), ['always', 'onsuccess'], true)) {
                array_shift($args);
            }
            $op = strtolower($args[0] ?? '');
            $name = $args[1] ?? '';
            $value = $args[2] ?? '';
            if ($name === '' || (isset($args[3]) && str_starts_with(strtolower($args[3]), 'env='))) {
                continue;
            }
            match ($op) {
                'set' => header("$name: $value"),
                'append', 'add', 'merge' => header("$name: $value", false),
                'unset' => header_remove($name),
                default => null,
            };
        }
    }

    private function sendCookie(string $spec): void
    {
        // CO=NAME:VALUE:DOMAIN[:lifetime[:path[:secure[:httponly[:samesite]]]]]
        $p = explode(':', $spec);
        if (count($p) < 2) {
            return;
        }
        $lifetime = isset($p[3]) && $p[3] !== '' ? (int) $p[3] : 0;
        setcookie($p[0], $p[1], [
            'expires' => $lifetime > 0 ? time() + $lifetime * 60 : 0,
            'path' => $p[4] ?? '/',
            'domain' => '',
            'secure' => false,
            'httponly' => in_array(strtolower($p[6] ?? ''), ['true', 'httponly'], true),
        ]);
    }

    /** @param array<string,mixed> $a */
    private function sendProxyPage(array $a): void
    {
        http_response_code($a['status']);
        header('X-Apache2Router-Proxy-Target: ' . str_replace(["\r", "\n"], '', $a['target']));
        $method = (string) ($this->server['REQUEST_METHOD'] ?? 'GET');
        $requested = 'http://' . $this->hostHeader . ($this->server['REQUEST_URI'] ?? '/');
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');
        if (stripos($accept, 'text/html') === false && stripos($accept, 'json') !== false) {
            header('Content-Type: application/json');
            echo json_encode([
                'proxied' => true,
                'message' => 'Apache would have proxied this request; PHP\'s built-in server cannot.',
                'method' => $method,
                'requested' => $requested,
                'target' => $a['target'],
                'balancer_members' => $a['members'],
                'directive' => $a['directive'],
                'config' => $a['where'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $rows = [
            'Request' => $method . ' ' . $requested,
            'Would be sent to' => $a['target'],
            'Directive' => $a['directive'],
            'Configured at' => $a['where'],
        ];
        $body = '<p>Apache would have <strong>proxied</strong> this request to another server. '
            . 'PHP\'s built-in server cannot forward requests, so this page shows where it would have gone.</p><table>';
        foreach ($rows as $k => $v) {
            $body .= '<tr><th>' . self::h($k) . '</th><td><code>' . self::h($v) . '</code></td></tr>';
        }
        if ($a['members'] !== []) {
            $body .= '<tr><th>Balancer members</th><td><code>' . implode('<br>', array_map([self::class, 'h'], $a['members'])) . '</code></td></tr>';
        }
        $body .= '</table>';
        echo self::page('Proxied request', $body);
    }

    private function errorPage(int $status, ?string $detail): string
    {
        $title = self::STATUS_TEXT[$status] ?? 'Error';
        $msg = match ($status) {
            403 => 'You don\'t have permission to access this resource.',
            404 => 'The requested URL was not found on this server.',
            410 => 'The requested resource is no longer available on this server.',
            default => 'The server could not complete the request.',
        };
        $body = '<p>' . $msg . '</p>';
        if ($detail !== null && $detail !== '') {
            $body .= '<p><small>' . self::h($detail) . '</small></p>';
        }
        return self::page($title, $body);
    }

    private function listing(string $dir, string $uri): string
    {
        $entries = @scandir($dir) ?: [];
        $base = rtrim(self::encodePath($uri), '/') . '/';
        $rows = '';
        if ($uri !== '/') {
            $rows .= '<li><a href="../">Parent Directory</a></li>';
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..' || str_starts_with($e, '.ht')) {
                continue;
            }
            $slash = is_dir($dir . '/' . $e) ? '/' : '';
            $rows .= '<li><a href="' . self::h($base . rawurlencode($e) . $slash) . '">' . self::h($e . $slash) . '</a></li>';
        }
        return self::page('Index of ' . $uri, '<ul>' . $rows . '</ul>');
    }

    private static function page(string $title, string $body): string
    {
        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>" . self::h($title) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;line-height:1.5}'
            . 'th{text-align:left;padding-right:1rem;vertical-align:top}code{word-break:break-all}</style>'
            . '</head><body><h1>' . self::h($title) . '</h1>' . $body
            . '<hr><address>apache2router (PHP built-in server)</address></body></html>' . "\n";
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ------------------------------------------------------------------
    // URL helpers
    // ------------------------------------------------------------------

    /** Leading slash, merged slashes, no dot segments; a trailing slash is kept. */
    public static function normalizeUrlPath(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $trailing = str_ends_with($path, '/') || str_ends_with($path, '/.') || str_ends_with($path, '/..');
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        $result = '/' . implode('/', $out);
        if ($trailing && $result !== '/') {
            $result .= '/';
        }
        return $result;
    }

    /** Percent-encodes a decoded URL path for use in a Location header. */
    public static function encodePath(string $path): string
    {
        return (string) preg_replace_callback(
            "#[^A-Za-z0-9\\-._~!\$&'()*+,;=:@/]#",
            static fn(array $m): string => sprintf('%%%02X', ord($m[0])),
            $path
        );
    }
}

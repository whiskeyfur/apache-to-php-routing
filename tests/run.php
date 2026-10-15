<?php

declare(strict_types=1);

/*
 * Test runner with no dependencies: php tests/run.php
 *
 * Unit tests cover the parser, path mapper and rewrite engine. The
 * integration tests generate a router from tests/fixtures (a Debian-style
 * /etc/apache2 plus sites whose paths are remapped), start php -S with it,
 * and make real HTTP requests.
 */

namespace Apache2Router\Tests;

use Apache2Router\ConfigParser;
use Apache2Router\PathMapper;
use Apache2Router\Rewrite;
use Apache2Router\RewriteHost;
use Apache2Router\Router;

foreach (['PathMapper', 'ConfigParser', 'Rewrite', 'Router', 'Compiler', 'Generator'] as $class) {
    require_once __DIR__ . '/../src/' . $class . '.php';
}

$passed = 0;
$failed = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo '.';
    } else {
        $failed[] = $name . ($detail !== '' ? "\n    $detail" : '');
        echo 'F';
    }
}

function same(string $name, mixed $expected, mixed $actual): void
{
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// ---------------------------------------------------------------------------
// Unit tests
// ---------------------------------------------------------------------------

same('normalize windows', 'C:/xampp/htdocs', PathMapper::normalize('c:\\xampp\\htdocs\\'));
same('normalize dots', '/var/www/b', PathMapper::normalize('/var//www/a/../b/.'));
$m = new PathMapper([['/var/www', '/home/me/www'], ['/var/www/special', '/opt/special'], ['C:/xampp', '/opt/xampp']]);
same('map longest prefix', '/opt/special/x', $m->map('/var/www/special/x'));
same('map prefix', '/home/me/www/site', $m->map('/var/www/site'));
same('map segment boundary', '/var/wwwx', $m->map('/var/wwwx'));
same('map windows case-insensitive', '/opt/xampp/htdocs', $m->map('c:\\XAMPP\\htdocs'));
same('mapRegex', '^/home/me/www/(.+)/uploads', $m->mapRegex('^/var/www/(.+)/uploads'));

same('splitArgs quotes', ['a b', 'c', '\\.php$', 'say "hi"'], ConfigParser::splitArgs('"a b" c \\.php$ "say \\"hi\\""'));
same('module names', ['rewrite', 'rewrite', 'rewrite'], array_map([ConfigParser::class, 'moduleName'], ['mod_rewrite.c', 'rewrite_module', 'mod_rewrite']));

$p = new ConfigParser(new PathMapper(), false);
$nodes = $p->parseString("Define FOO bar\n<IfDefine FOO>\nA \${FOO} \\\n  continued\n</IfDefine>\n<IfDefine !FOO>\nB\n</IfDefine>\n<IfModule !mod_rewrite.c>\nC\n</IfModule>\n<IfVersion >= 2.4>\nD\n</IfVersion>\n<IfVersion < 2.4>\nE\n</IfVersion>\n# comment\n<Directory /x>\nF 1\n</Directory>\n");
same('parser conditionals', ['a', 'd', 'directory'], array_column($nodes, 'd'));
same('parser vars and continuation', ['bar', 'continued'], $nodes[0]['args']);
same('parser section children', 'f', $nodes[2]['children'][0]['d']);

final class FakeHost implements RewriteHost
{
    /** @param array<string,string> $vars */
    public function __construct(public array $vars = [])
    {
    }

    public function rewriteVar(string $name, string $current): string
    {
        return $this->vars[$name] ?? '';
    }

    public function rewriteMap(string $map, string $key, string $default): string
    {
        return $map === 'up' ? strtoupper($key) : $default;
    }

    public function rewriteUrlExists(string $url): bool
    {
        return false;
    }
}

$rules = static function (string $text): array {
    $p = new ConfigParser(new PathMapper(), false);
    return Rewrite::collect($p->parseString($text))['rules'];
};
$rw = new Rewrite(new FakeHost(['HTTP_HOST' => 'www.example.com', 'QUERY_STRING' => 'a=1']));

$r = $rw->run($rules("RewriteRule ^/blog/(\\d+)$ /post.php?id=$1 [QSA,L]"), '/blog/42', 'a=1');
same('rewrite QSA', ['rewrite', '/post.php', 'id=42&a=1'], [$r['type'], $r['uri'], $r['query']]);

$r = $rw->run($rules("RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]\nRewriteRule ^/(.*)$ http://%1/$1 [R=301,L]"), '/x', '');
same('rewrite cond backref redirect', ['redirect', 'http://example.com/x', 301], [$r['type'], $r['uri'], $r['status']]);

$r = $rw->run($rules("RewriteCond %{HTTP_HOST} =nope [OR]\nRewriteCond %{HTTP_HOST} =www.example.com\nRewriteRule ^ /ok [L]"), '/', '');
same('rewrite OR conditions', '/ok', $r['uri']);

$r = $rw->run($rules("RewriteCond %{HTTP_HOST} =nope [OR]\nRewriteCond %{HTTP_HOST} =nope2\nRewriteRule ^ /ok [L]"), '/', '');
same('rewrite failing OR group', 'none', $r['type']);

$r = $rw->run($rules("RewriteRule ^a$ b [C]\nRewriteRule ^b$ c\nRewriteRule ^x$ y [C]\nRewriteRule ^.*$ z"), 'a', '');
same('rewrite chain', 'c', $r['uri']);
$r = $rw->run($rules("RewriteRule ^nomatch$ b [C]\nRewriteRule ^.*$ c\nRewriteRule ^(.*)$ $1-d"), 'a', '');
same('rewrite chain skipped', 'a-d', $r['uri']);

$r = $rw->run($rules("RewriteRule ^(.*)$ /\${up:$1}?"), 'abc', 'q=1');
same('rewrite map and query discard', ['/ABC', ''], [$r['uri'], $r['query']]);

$r = $rw->run($rules("RewriteRule ^old - [G]"), 'old', '');
same('rewrite gone', 'gone', $r['type']);

$r = $rw->run($rules("RewriteRule !^keep /other"), 'x', '');
same('rewrite negated pattern', '/other', $r['uri']);

$r = $rw->run($rules("RewriteRule ^(.*)$ /s?q=$1 [B]"), 'a b&c', '');
same('rewrite B flag', 'q=a+b%26c', $r['query']);

$r = $rw->run($rules("RewriteRule ^a$ b [S=1]\nRewriteRule ^b$ skipped\nRewriteRule ^b$ c"), 'a', '');
same('rewrite skip', 'c', $r['uri']);

$r = $rw->run($rules("RewriteRule ^ - [E=FOO:bar,E=BAZ:1]"), 'x', '');
same('rewrite env', ['FOO' => 'bar', 'BAZ' => '1'], $r['env']);

same('aliasRest exact', '', Router::aliasRest('/icons', '/icons'));
same('aliasRest boundary', null, Router::aliasRest('/icons', '/iconsx'));
same('aliasRest slash', '/a.png', Router::aliasRest('/icons/', '/icons/a.png'));
check('ipMatches cidr', Router::ipMatches('10.0.0.0/8', '10.2.3.4') && !Router::ipMatches('10.0.0.0/8', '11.0.0.1'));
check('ipMatches partial', Router::ipMatches('192.168', '192.168.1.5') && !Router::ipMatches('192.168', '192.1680.1.5'));
same('normalizeUrlPath', '/a/c/', Router::normalizeUrlPath('//a/./b/../c/'));

// ---------------------------------------------------------------------------
// Integration tests against php -S
// ---------------------------------------------------------------------------

$root = dirname(__DIR__);
$fx = $root . '/tests/fixtures';
$tmp = sys_get_temp_dir() . '/apache2router-test-' . getmypid();
@mkdir($tmp);
$routerFile = $tmp . '/router.php';

$cmd = array_merge([PHP_BINARY, $root . '/bin/apache2router', '-q', '-o', $routerFile,
    '--map', "/var/www=$fx/www", '--map', "/srv/assets=$fx/assets", '--map', "/etc/apache2=$fx/apache2",
    '--map', "C:/xampp=$fx/xampp", "$fx/apache2/apache2.conf"]);
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$code = proc_close($proc);
check('generator exits 0', $code === 0, $out);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($routerFile) . ' 2>&1', $lint, $lintCode);
check('generated router lints', $lintCode === 0, implode("\n", $lint));

$port = 0;
$sock = stream_socket_server('tcp://127.0.0.1:0');
if ($sock !== false) {
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
}
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $routerFile], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $tmp . '/server.log', 'w']], $spipes, $tmp);
$ready = false;
for ($i = 0; $i < 100 && !$ready; $i++) {
    usleep(50000);
    $c = @fsockopen('127.0.0.1', $port);
    if ($c !== false) {
        fclose($c);
        $ready = true;
    }
}
check('php -S started', $ready);

/** @return array{status:int,headers:array<string,string>,body:string} */
function req(string $host, string $path, array $headers = [], string $method = 'GET'): array
{
    global $port;
    $c = fsockopen('127.0.0.1', $port, $errno, $errstr, 5);
    if ($c === false) {
        return ['status' => 0, 'headers' => [], 'body' => $errstr];
    }
    $h = "$method $path HTTP/1.0\r\nHost: $host\r\nConnection: close\r\n";
    foreach ($headers as $k => $v) {
        $h .= "$k: $v\r\n";
    }
    fwrite($c, $h . "\r\n");
    $raw = stream_get_contents($c);
    fclose($c);
    [$head, $body] = explode("\r\n\r\n", (string) $raw, 2) + [1 => ''];
    $lines = explode("\r\n", $head);
    $status = (int) (explode(' ', array_shift($lines))[1] ?? 0);
    $hs = [];
    foreach ($lines as $l) {
        [$k, $v] = explode(':', $l, 2) + [1 => ''];
        $hs[strtolower(trim($k))] = trim($v);
    }
    return ['status' => $status, 'headers' => $hs, 'body' => $body];
}

function json(array $res): array
{
    return json_decode($res['body'], true) ?: [];
}

if ($ready) {
    $ex = "example.test:$port";

    // The HTTPS vhost is chosen over the redirect-only HTTP one, and the
    // "force HTTPS" rewrite is skipped instead of looping.
    $r = req($ex, '/');
    $j = json($r);
    same('front page status', 200, $r['status']);
    same('front page via DirectoryIndex', 'example/index.php', $j['script'] ?? null);
    same('globals work in required script', 'example', $j['global'] ?? null);
    same('SCRIPT_NAME', '/index.php', $j['SCRIPT_NAME'] ?? null);
    same('SetEnv reaches getenv', 'staging', $j['APP_ENV'] ?? null);
    same('php_value from .htaccess', 'Pacific/Auckland', $j['timezone'] ?? null);
    same('cwd is script dir', 'public', $j['cwd'] ?? null);
    same('Header set', 'example', $r['headers']['x-site'] ?? null);

    $r = req($ex, '/2024/hello-world/?utm=1', ['Authorization' => 'Bearer t0k']);
    $j = json($r);
    same('front controller', 'example/index.php', $j['script'] ?? null);
    same('front controller REQUEST_URI', '/2024/hello-world/?utm=1', $j['REQUEST_URI'] ?? null);
    same('front controller REDIRECT_URL', '/2024/hello-world/', $j['REDIRECT_URL'] ?? null);
    same('front controller query', ['utm' => '1'], $j['get'] ?? null);
    same('E= flag becomes REDIRECT_ var', 'Bearer t0k', $j['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

    $r = req($ex, '/index.php/extra/path');
    $j = json($r);
    same('PATH_INFO', '/extra/path', $j['PATH_INFO'] ?? null);
    same('PATH_INFO SCRIPT_NAME', '/index.php', $j['SCRIPT_NAME'] ?? null);

    $r = req($ex, '/style.css');
    same('static file', [200, 'body { color: red; }'], [$r['status'], trim($r['body'])]);
    check('static mime', str_starts_with($r['headers']['content-type'] ?? '', 'text/css'), $r['headers']['content-type'] ?? '');

    $r = req($ex, '/sub');
    same('DirectorySlash redirect', [301, "http://$ex/sub/"], [$r['status'], $r['headers']['location'] ?? null]);
    $r = req($ex, '/sub/');
    same('directory index html', '<p>sub index</p>', trim($r['body']));

    same('Directory deny', 403, req($ex, '/private/secret.txt')['status']);
    same('.ht files denied', 403, req($ex, '/.htpasswd')['status']);
    $r = req($ex, '/uploads/shell.php');
    same('engine off serves source', [200, '<?php echo "should not run";'], [$r['status'], trim($r['body'])]);

    $r = req($ex, '/static/logo.txt');
    same('Alias', [200, 'logo'], [$r['status'], trim($r['body'])]);
    $r = req($ex, '/static/missing.txt');
    same('ErrorDocument 404', [404, 'custom 404 for /static/missing.txt status 404'], [$r['status'], trim($r['body'])]);

    $r = req($ex, '/legacy/my-post?x=1');
    same('server RewriteRule R=301', [301, "http://$ex/blog/my-post?x=1"], [$r['status'], $r['headers']['location'] ?? null]);
    $r = req($ex, '/old-page');
    same('Redirect', [301, "http://$ex/new-page"], [$r['status'], $r['headers']['location'] ?? null]);

    $r = req($ex, '/feed');
    $j = json($r);
    same('rewrite PT to script with query', ['example/index.php', ['feed' => 'rss']], [$j['script'] ?? null, $j['get'] ?? null]);

    $r = req($ex, '/api/users?id=7');
    same('ProxyPass page status', 502, $r['status']);
    same('ProxyPass target header', 'http://127.0.0.1:9000/api/users?id=7', $r['headers']['x-apache2router-proxy-target'] ?? null);
    check('ProxyPass page explains', str_contains($r['body'], 'http://127.0.0.1:9000/api/users?id=7') && str_contains($r['body'], 'proxied'));
    $r = req($ex, '/api/users', ['Accept' => 'application/json']);
    same('proxy page JSON', 'http://127.0.0.1:9000/api/users', json($r)['target'] ?? null);
    $r = req($ex, '/api/health');
    same('ProxyPass ! exclusion falls through to front controller', 'example/index.php', json($r)['script'] ?? null);
    $r = req($ex, '/ws/chat');
    same('RewriteRule [P]', 'ws://127.0.0.1:8081/chat', $r['headers']['x-apache2router-proxy-target'] ?? null);

    $r = req("www.example.test:$port", '/style.css');
    same('ServerAlias', 200, $r['status']);
    $r = req("shop.example.test:$port", '/style.css');
    same('wildcard ServerAlias', 200, $r['status']);

    $ot = "other.test:$port";
    same('other index', '<h1>other</h1>', trim(req($ot, '/')['body']));
    same('htaccess rewrite with QSA', 'page hello 9', trim(req($ot, '/pretty/hello?x=9')['body']));
    check('Options +Indexes listing', str_contains(req($ot, '/files/')['body'], 'a.txt'));
    $r = req($ot, '/app/dashboard');
    same('balancer proxy target', 'balancer://cluster/app/dashboard', $r['headers']['x-apache2router-proxy-target'] ?? null);
    check('balancer members listed', str_contains($r['body'], '10.0.0.2:8080'));
    $r = req($ot, '/legacy-api/items');
    same('Location ProxyPass', 'http://legacy.internal:8000/v1/items', $r['headers']['x-apache2router-proxy-target'] ?? null);
    $r = req($ot, '/go/docs');
    same('RewriteMap txt redirect', [302, 'https://docs.example.org/'], [$r['status'], $r['headers']['location'] ?? null]);
    same('RewriteMap miss', 404, req($ot, '/go/nothing')['status']);
    $r = req($ot, '/UPPER/MiXeD');
    same('RewriteMap int:tolower', "http://$ot/lower/mixed", $r['headers']['location'] ?? null);
    same('RedirectMatch 410', 410, req($ot, '/removed/thing')['status']);
    same('Location SetHandler server-status', 501, req($ot, '/server-status')['status']);

    same('Windows paths remapped', 'windows site at htdocs', trim(req("win.test:$port", '/')['body']));
    check('Macro vhost', str_contains(req("macro.test:$port", '/')['body'], 'a.txt'));

    // Unknown host -> first vhost (example.test:80), which only redirects to HTTPS
    $r = req("localhost:$port", '/x');
    same('default vhost redirect pointed at php -S', [301, "http://example.test:$port/x"], [$r['status'], $r['headers']['location'] ?? null]);
}

proc_terminate($server);
proc_close($server);
$log = (string) @file_get_contents($tmp . '/server.log');
if (preg_match('/(PHP (Fatal|Warning|Notice|Deprecated)[^\n]*)/', $log, $mm)) {
    check('no PHP errors in server log', false, $mm[1]);
}
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

echo "\n\n$passed passed, " . count($failed) . " failed\n";
foreach ($failed as $f) {
    echo "  FAIL: $f\n";
}
exit($failed === [] ? 0 : 1);

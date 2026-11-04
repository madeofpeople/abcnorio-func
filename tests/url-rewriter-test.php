<?php

// Run: php tests/url-rewriter-test.php   (exit code 0 = all passed)

require __DIR__ . '/../src/Support/UrlRewriter.php';

use abcnorio\CustomFunc\Support\UrlRewriter;

$failures = 0;

function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) {
        echo "ok    $name\n";
        return;
    }
    $failures++;
    echo "FAIL  $name\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

$wpHosts = ['abcwpdev.example.org', 'abcwp.example.org'];
$frontendHosts = ['dev.example.org', 'staging.example.org', 'preview.example.org', 'abcnorio.example.org'];
$rewriter = new UrlRewriter($wpHosts, $frontendHosts, 'abcwp.example.org');

// WP host: other env -> own
check('wp host plain', $rewriter->rewrite('<img src="https://abcwpdev.example.org/app/uploads/a.webp">'), '<img src="https://abcwp.example.org/app/uploads/a.webp">');
check('wp host http scheme', $rewriter->rewrite('http://abcwpdev.example.org/x'), 'https://abcwp.example.org/x');
check('wp host json-escaped', $rewriter->rewrite('{"url":"https:\/\/abcwpdev.example.org\/app\/uploads\/a.jpg"}'), '{"url":"https:\/\/abcwp.example.org\/app\/uploads\/a.jpg"}');
check('own wp host untouched', $rewriter->rewrite('https://abcwp.example.org/x'), 'https://abcwp.example.org/x');

// Frontend host -> relative
check('frontend plain', $rewriter->rewrite('<a href="https://dev.example.org/156-rising/">x</a>'), '<a href="/156-rising/">x</a>');
check('frontend other env', $rewriter->rewrite('https://staging.example.org/a/b?c=1#d'), '/a/b?c=1#d');
check('frontend bare origin', $rewriter->rewrite('<a href="https://dev.example.org">x</a>'), '<a href="/">x</a>');
check('frontend json-escaped', $rewriter->rewrite('{"href":"https:\/\/dev.example.org\/events\/"}'), '{"href":"\/events\/"}');
check('frontend production', $rewriter->rewrite('https://abcnorio.example.org/about/'), '/about/');

// Look-alikes and non-origin text must not change
check('lookalike prefix host', $rewriter->rewrite('https://notdev.example.org/x'), 'https://notdev.example.org/x');
check('lookalike suffix host', $rewriter->rewrite('https://dev.example.org.evil.com/x'), 'https://dev.example.org.evil.com/x');
check('lookalike hyphen host', $rewriter->rewrite('https://dev.example.org-old/x'), 'https://dev.example.org-old/x');
check('subdomain of env host', $rewriter->rewrite('https://sub.dev.example.org/x'), 'https://sub.dev.example.org/x');
check('trailing sentence dot', $rewriter->rewrite('See https://dev.example.org/page.'), 'See /page.');
check('bare hostname text', $rewriter->rewrite('Email us at info@dev.example.org'), 'Email us at info@dev.example.org');
check('external link untouched', $rewriter->rewrite('https://old.abcnorio.org/x'), 'https://old.abcnorio.org/x');
check('empty string', $rewriter->rewrite(''), '');
check('idempotent', $rewriter->rewrite($rewriter->rewrite('https://abcwpdev.example.org/a https://dev.example.org/b')), 'https://abcwp.example.org/a /b');

// Serialized: length prefixes must be recomputed
$serialized = serialize(['url' => 'https://abcwpdev.example.org/x', 'nested' => ['link' => 'https://dev.example.org/y'], 'n' => 5]);
$expected = serialize(['url' => 'https://abcwp.example.org/x', 'nested' => ['link' => '/y'], 'n' => 5]);
check('serialized array', $rewriter->rewrite($serialized), $expected);
check('serialized round-trips', unserialize($rewriter->rewrite($serialized)), ['url' => 'https://abcwp.example.org/x', 'nested' => ['link' => '/y'], 'n' => 5]);
check('serialized unchanged stays byte-identical', $rewriter->rewrite(serialize(['a' => 'b'])), serialize(['a' => 'b']));
check('serialized string scalar', $rewriter->rewrite(serialize('https://abcwpdev.example.org/z')), serialize('https://abcwp.example.org/z'));
check('text that only looks serialized', $rewriter->rewrite('a:1 is not serialized https://dev.example.org/q'), 'a:1 is not serialized /q');

$object = serialize(new ArrayObject(['u' => 'https://abcwpdev.example.org/x']));
try {
    $rewriter->rewrite($object);
    check('serialized object is refused', 'no exception', 'exception');
} catch (RuntimeException $e) {
    check('serialized object is refused', 'exception', 'exception');
}

// Equivalence invariant (used as a guard before writing)
$mixed = '<a href="https://dev.example.org/p">x</a> https://abcwpdev.example.org/u';
check('equivalent plain', $rewriter->isEquivalent($mixed, $rewriter->rewrite($mixed)), true);
check('equivalent serialized', $rewriter->isEquivalent($serialized, $rewriter->rewrite($serialized)), true);
check('not equivalent when text changed', $rewriter->isEquivalent('https://abcwpdev.example.org/a', 'https://abcwp.example.org/b'), false);
check('not equivalent when content dropped', $rewriter->isEquivalent('hello https://dev.example.org/a', '/a'), false);

// containsForeign
check('foreign detected', $rewriter->containsForeign('https://abcwpdev.example.org/x'), true);
check('foreign detected in serialized', $rewriter->containsForeign($serialized), true);
check('clean value not flagged', $rewriter->containsForeign('https://abcwp.example.org/x /relative'), false);

// Local-style hosts with ports
$local = new UrlRewriter(['localhost:8201', 'abcwp.example.org'], ['localhost:3133', 'dev.example.org'], 'localhost:8201', 'http');
check('port host wp', $local->rewrite('https://abcwp.example.org/a.jpg'), 'http://localhost:8201/a.jpg');
check('port host frontend', $local->rewrite('http://localhost:3133/page/'), '/page/');
check('port host lookalike', $local->rewrite('http://localhost:31330/page/'), 'http://localhost:31330/page/');

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);

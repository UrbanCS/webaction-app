<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/content.php';

function assert_sanitized(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
        exit(1);
    }
}

$unsafe = <<<'HTML'
<div class="layout" data-test="value">
  <p onclick=alert(1)>Bonjour <strong>Webaction</strong></p>
  <script>alert('script')</script>
  <img src="https://webaction.ca/images/example.jpg" onerror=alert(1) style="display:none">
  <a href="jav&#x61;script:alert(1)" target="_blank">Lien dangereux</a>
  <a href="https://example.com/path" title="Exemple">Lien sûr</a>
  <svg onload="alert(1)"><circle></circle></svg>
</div>
HTML;

$sanitized = sanitize_detail_html($unsafe);

assert_sanitized(strpos($sanitized, '<strong>Webaction</strong>') !== false, 'safe formatting should be preserved');
assert_sanitized(strpos($sanitized, 'https://webaction.ca/images/example.jpg') !== false, 'safe image URL should be preserved');
assert_sanitized(strpos($sanitized, 'https://example.com/path') !== false, 'safe link URL should be preserved');
assert_sanitized(stripos($sanitized, '<script') === false, 'script elements must be removed');
assert_sanitized(stripos($sanitized, '<svg') === false, 'SVG elements must be removed');
assert_sanitized(stripos($sanitized, 'javascript:') === false, 'javascript URLs must be removed');
assert_sanitized(stripos($sanitized, 'onerror') === false, 'unquoted event handlers must be removed');
assert_sanitized(stripos($sanitized, 'onclick') === false, 'event handlers must be removed');
assert_sanitized(stripos($sanitized, 'style=') === false, 'inline styles must be removed');
assert_sanitized(stripos($sanitized, 'class=') === false, 'CSS classes must be removed');
assert_sanitized(stripos($sanitized, 'data-test') === false, 'data attributes must be removed');
assert_sanitized(stripos($sanitized, 'target=') === false, 'unapproved link attributes must be removed');

$unsafeUrls = sanitize_detail_html(
    '<a href="java&#10;script:alert(1)">bad</a><img src="data:image/svg+xml,bad"><a href="mailto:info@webaction.ca">email</a>'
);
assert_sanitized(stripos($unsafeUrls, 'javascript:') === false, 'control characters must not bypass URL checks');
assert_sanitized(stripos($unsafeUrls, 'data:') === false, 'data image URLs must be removed');
assert_sanitized(strpos($unsafeUrls, 'mailto:info@webaction.ca') !== false, 'mailto links should be preserved');

echo 'sanitize_detail_html tests passed' . PHP_EOL;

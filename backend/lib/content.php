<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function fetch_source(string $url): string
{
    $scraper = app_config()['scraper'] ?? [];
    $separator = strpos($url, '?') === false ? '?' : '&';
    $requestUrl = $url . $separator . '_pwa_scan=' . rawurlencode((string) microtime(true));
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => (int) ($scraper['timeout'] ?? 15),
            'header' => "User-Agent: " . ($scraper['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36 WebactionPWA/1.0') . "\r\nAccept: text/html,application/xhtml+xml\r\nCache-Control: no-cache\r\nPragma: no-cache\r\n",
        ],
    ]);

    $html = @file_get_contents($requestUrl, false, $context);
    if (!is_string($html) || $html === '') {
        throw new RuntimeException("Unable to fetch source: {$url}");
    }
    return $html;
}

function dom_xpath(string $html): DOMXPath
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

function extract_realisations(string $html): array
{
    $xpath = dom_xpath($html);
    $max = (int) ((app_config()['scraper']['max_realisations'] ?? 24));
    $items = [];
    $nodes = $xpath->query("//*[@id='portfolio']//a[contains(concat(' ', normalize-space(@class), ' '), ' el-item ')]");

    foreach ($nodes as $node) {
        if (count($items) >= $max) {
            break;
        }
        $titleNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' el-title ')]", $node)->item(0);
        $title = $titleNode ? normalize_text($titleNode->textContent) : '';
        if ($title === '') {
            continue;
        }
        $metaNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' el-meta ')]", $node)->item(0);
        $linkNode = $node instanceof DOMElement && $node->hasAttribute('href') ? $node : $xpath->query(".//a[@href]", $node)->item(0);
        $imageNode = $xpath->query(".//img", $node)->item(0);
        $url = $linkNode instanceof DOMElement ? $linkNode->getAttribute('href') : app_config()['sources']['home'];
        $image = '';
        if ($imageNode instanceof DOMElement) {
            $image = $imageNode->getAttribute('data-src') ?: $imageNode->getAttribute('src');
        }

        $items[] = normalize_item([
            'type' => 'realisation',
            'source_id' => slug_id('realisation', $title, $url),
            'title' => $title,
            'excerpt' => $metaNode ? normalize_text($metaNode->textContent) : '',
            'url' => absolute_url($url),
            'image' => absolute_url($image),
        ]);
    }
    return $items;
}

function extract_watch_items(string $html): array
{
    $xpath = dom_xpath($html);
    $max = (int) ((app_config()['scraper']['max_watch'] ?? 12));
    $items = [];
    $nodes = $xpath->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' uk-article ')]");

    foreach ($nodes as $node) {
        if (count($items) >= $max) {
            break;
        }
        $titleNode = $xpath->query(".//*[@property='headline']//a|.//*[@property='headline']", $node)->item(0);
        $title = $titleNode ? normalize_text($titleNode->textContent) : '';
        if ($title === '') {
            continue;
        }
        $linkNode = $xpath->query(".//*[@property='headline']//a[@href]|.//a[contains(., 'Lire la suite')][@href]", $node)->item(0);
        $textNode = $xpath->query(".//*[@property='text']", $node)->item(0);
        $imageNode = $xpath->query(".//*[@property='image']//img", $node)->item(0);
        $articleId = $node instanceof DOMElement ? $node->getAttribute('id') : '';
        $url = $linkNode instanceof DOMElement ? $linkNode->getAttribute('href') : app_config()['sources']['watch'];
        $image = $imageNode instanceof DOMElement ? ($imageNode->getAttribute('data-src') ?: $imageNode->getAttribute('src')) : '';

        $items[] = normalize_item([
            'type' => 'watch',
            'source_id' => $articleId !== '' ? $articleId : slug_id('watch', $title, $url),
            'title' => $title,
            'excerpt' => $textNode ? normalize_text($textNode->textContent) : '',
            'url' => absolute_url($url),
            'image' => absolute_url($image),
        ]);
    }
    return $items;
}

function extract_detail_from_url(string $url): array
{
    $url = absolute_url($url);
    $host = parse_url($url, PHP_URL_HOST);
    $siteHost = parse_url(app_config()['site_url'] ?? 'https://webaction.ca', PHP_URL_HOST);

    if (!$host || !$siteHost || strtolower($host) !== strtolower($siteHost)) {
        throw new RuntimeException('Only Webaction detail pages can be fetched.');
    }

    $html = fetch_source($url);
    $xpath = dom_xpath($html);
    $titleNode = $xpath->query("//*[@property='headline']")->item(0);
    $contentNode = $xpath->query("//*[@property='text']")->item(0);
    $imageNode = $xpath->query("//*[@property='image']//img|//article//img")->item(0);

    $detailHtml = '';
    if ($contentNode instanceof DOMNode) {
        $detailHtml = sanitize_detail_html(inner_html($contentNode));
    }

    $image = '';
    if ($imageNode instanceof DOMElement) {
        $image = $imageNode->getAttribute('data-src') ?: $imageNode->getAttribute('src');
    }

    return [
        'title' => $titleNode ? normalize_text($titleNode->textContent) : '',
        'html' => $detailHtml,
        'image' => absolute_url($image),
        'url' => $url,
    ];
}

function inner_html(DOMNode $node): string
{
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}

function sanitize_detail_html(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#</?(div|span|section|article)[^>]*>#i', '', $html) ?? $html;
    $html = preg_replace('/\s(on[a-z]+|style|class|id|data-[a-z0-9_-]+)="[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace("/\s(on[a-z]+|style|class|id|data-[a-z0-9_-]+)='[^']*'/i", '', $html) ?? $html;
    $html = preg_replace_callback('/\s(href|src)="([^"]*)"/i', function (array $matches): string {
        $url = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($url, 'javascript:') === 0 || strpos($url, 'data:') === 0) {
            return '';
        }
        return ' ' . strtolower($matches[1]) . '="' . htmlspecialchars(absolute_url($url), ENT_QUOTES, 'UTF-8') . '"';
    }, $html) ?? $html;

    return trim($html);
}

function normalize_item(array $item): array
{
    $item['hash'] = hash('sha256', implode('|', [
        $item['type'],
        $item['source_id'],
        $item['title'],
        $item['excerpt'],
        $item['url'],
        $item['image'],
    ]));
    return $item;
}

function slug_id(string $type, string $title, string $url): string
{
    return substr(hash('sha256', $type . '|' . strtolower($title) . '|' . $url), 0, 24);
}

function ensure_content_tracking_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo = db();
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM detected_contents') as $column) {
        $columns[(string) $column['Field']] = true;
    }

    $changes = [];
    if (!isset($columns['source_position'])) {
        $changes[] = 'ADD COLUMN source_position SMALLINT UNSIGNED NOT NULL DEFAULT 65535 AFTER content_hash';
    }
    if (!isset($columns['active'])) {
        $changes[] = 'ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER source_position';
    }
    if ($changes) {
        $pdo->exec('ALTER TABLE detected_contents ' . implode(', ', $changes));
    }

    $ready = true;
}

function mark_source_items_inactive(string $sourceType): void
{
    ensure_content_tracking_schema();
    $stmt = db()->prepare('UPDATE detected_contents SET active = 0 WHERE source_type = ?');
    $stmt->execute([$sourceType]);
}

function upsert_detected_item(array $item, int $sourcePosition = 0): string
{
    ensure_content_tracking_schema();
    $pdo = db();
    $select = $pdo->prepare('SELECT id, content_hash, active FROM detected_contents WHERE source_type = ? AND source_id = ? LIMIT 1');
    $select->execute([$item['type'], $item['source_id']]);
    $existing = $select->fetch();

    if (!$existing) {
        $insert = $pdo->prepare('INSERT INTO detected_contents (source_type, source_id, title, excerpt, url, image_url, content_hash, source_position, active, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())');
        $insert->execute([$item['type'], $item['source_id'], $item['title'], $item['excerpt'], $item['url'], $item['image'], $item['hash'], $sourcePosition]);
        return 'created';
    }

    $update = $pdo->prepare('UPDATE detected_contents SET title = ?, excerpt = ?, url = ?, image_url = ?, content_hash = ?, source_position = ?, active = 1, last_seen_at = NOW() WHERE id = ?');
    $update->execute([$item['title'], $item['excerpt'], $item['url'], $item['image'], $item['hash'], $sourcePosition, $existing['id']]);
    if (!hash_equals((string) $existing['content_hash'], (string) $item['hash'])) {
        return 'updated';
    }
    return (int) $existing['active'] === 0 ? 'reactivated' : 'unchanged';
}

function latest_items(): array
{
    ensure_content_tracking_schema();
    $stmt = db()->query("SELECT source_type, source_id, title, excerpt, url, image_url, content_hash, last_seen_at FROM detected_contents WHERE active = 1 ORDER BY source_type ASC, source_position ASC, id ASC");
    $groups = ['realisations' => [], 'watch' => []];
    $allRealisations = [];
    $allWatch = [];
    foreach ($stmt as $row) {
        $item = [
            'id' => $row['source_id'],
            'type' => $row['source_type'],
            'title' => $row['title'],
            'excerpt' => $row['excerpt'] ?? '',
            'url' => $row['url'] ?? '',
            'image' => $row['image_url'] ?? '',
            'hash' => $row['content_hash'],
            'detected_at' => $row['last_seen_at'],
        ];
        if ($row['source_type'] === 'realisation') {
            $allRealisations[] = $item;
        }
        if ($row['source_type'] === 'watch') {
            $allWatch[] = $item;
        }
    }
    $maxRealisations = (int) (app_config()['scraper']['max_realisations'] ?? 24);
    $maxWatch = (int) (app_config()['scraper']['max_watch'] ?? 12);
    $groups['realisations'] = array_slice($allRealisations, 0, $maxRealisations);
    $groups['watch'] = array_slice($allWatch, 0, $maxWatch);
    return $groups;
}

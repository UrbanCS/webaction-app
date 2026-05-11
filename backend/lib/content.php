<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function fetch_source(string $url): string
{
    $scraper = app_config()['scraper'] ?? [];
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => (int) ($scraper['timeout'] ?? 15),
            'header' => "User-Agent: " . ($scraper['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36 WebactionPWA/1.0') . "\r\nAccept: text/html,application/xhtml+xml\r\n",
        ],
    ]);

    $html = @file_get_contents($url, false, $context);
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
    $nodes = $xpath->query("//*[@id='portfolio']//div[contains(concat(' ', normalize-space(@class), ' '), ' el-item ')]");

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
        $linkNode = $xpath->query(".//a[@href]", $node)->item(0);
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

function upsert_detected_item(array $item): bool
{
    $pdo = db();
    $select = $pdo->prepare('SELECT id, content_hash FROM detected_contents WHERE source_type = ? AND source_id = ? LIMIT 1');
    $select->execute([$item['type'], $item['source_id']]);
    $existing = $select->fetch();

    if (!$existing) {
        $insert = $pdo->prepare('INSERT INTO detected_contents (source_type, source_id, title, excerpt, url, image_url, content_hash, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $insert->execute([$item['type'], $item['source_id'], $item['title'], $item['excerpt'], $item['url'], $item['image'], $item['hash']]);
        return true;
    }

    $update = $pdo->prepare('UPDATE detected_contents SET title = ?, excerpt = ?, url = ?, image_url = ?, content_hash = ?, last_seen_at = NOW() WHERE id = ?');
    $update->execute([$item['title'], $item['excerpt'], $item['url'], $item['image'], $item['hash'], $existing['id']]);
    return !hash_equals((string) $existing['content_hash'], (string) $item['hash']);
}

function latest_items(): array
{
    $stmt = db()->query("SELECT source_type, source_id, title, excerpt, url, image_url, content_hash, last_seen_at FROM detected_contents ORDER BY first_seen_at DESC, id DESC LIMIT 80");
    $groups = ['realisations' => [], 'watch' => []];
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
        if ($row['source_type'] === 'realisation' && count($groups['realisations']) < 24) {
            $groups['realisations'][] = $item;
        }
        if ($row['source_type'] === 'watch' && count($groups['watch']) < 12) {
            $groups['watch'][] = $item;
        }
    }
    return $groups;
}

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
    $seenUrls = [];
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
            'image' => absolute_url(clean_image_url($image)),
        ]);
        $seenUrls[absolute_url($url)] = true;
    }

    // Current Joomla/SP Page Builder portfolio cards (August 2026).
    $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' uk-panel ')][.//a[contains(@href, '/portfolio-webaction/')]]");
    foreach ($nodes as $node) {
        if (count($items) >= $max) {
            break;
        }

        $linkNode = $xpath->query(".//a[contains(@href, '/portfolio-webaction/')][1]", $node)->item(0);
        if (!$linkNode instanceof DOMElement) {
            continue;
        }
        $url = absolute_url($linkNode->getAttribute('href'));
        if ($url === '' || isset($seenUrls[$url])) {
            continue;
        }

        $titleNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' uk-h2 ')][1]", $node)->item(0);
        $imageNode = $xpath->query('.//img[1]', $node)->item(0);
        $title = $titleNode ? normalize_text($titleNode->textContent) : '';
        if ($title === '' && $imageNode instanceof DOMElement) {
            $title = normalize_text($imageNode->getAttribute('alt'));
        }
        if ($title === '') {
            continue;
        }

        $metaNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' uk-overlay ')]//h1[1]", $node)->item(0);
        $image = '';
        if ($imageNode instanceof DOMElement) {
            $image = $imageNode->getAttribute('data-src') ?: $imageNode->getAttribute('src');
        }

        $items[] = normalize_item([
            'type' => 'realisation',
            'source_id' => stable_source_id('realisation', $url),
            'title' => $title,
            'excerpt' => $metaNode ? normalize_text($metaNode->textContent) : '',
            'url' => $url,
            'image' => absolute_url(clean_image_url($image)),
        ]);
        $seenUrls[$url] = true;
    }
    return $items;
}

function extract_watch_items(string $html): array
{
    $xpath = dom_xpath($html);
    $max = (int) ((app_config()['scraper']['max_watch'] ?? 12));
    $items = [];
    $seenUrls = [];
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
            'image' => absolute_url(clean_image_url($image)),
        ]);
        $seenUrls[absolute_url($url)] = true;
    }

    // Current Joomla/SP Page Builder article cards (August 2026).
    $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' sppb-addon-article ')][.//a[contains(@href, '/blogue/')]]");
    foreach ($nodes as $node) {
        if (count($items) >= $max) {
            break;
        }

        $linkNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' sppb-article-info-wrap ')]//h3//a[contains(@href, '/blogue/')][1]", $node)->item(0);
        if (!$linkNode instanceof DOMElement) {
            continue;
        }
        $url = absolute_url($linkNode->getAttribute('href'));
        if ($url === '' || isset($seenUrls[$url])) {
            continue;
        }

        $title = normalize_text($linkNode->textContent);
        if ($title === '') {
            continue;
        }
        $textNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' sppb-article-introtext ')][1]", $node)->item(0);
        $imageNode = $xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' sppb-article-img-wrap ')]//img[1]", $node)->item(0);
        $image = '';
        if ($imageNode instanceof DOMElement) {
            $image = $imageNode->getAttribute('data-src') ?: $imageNode->getAttribute('src');
        }

        $items[] = normalize_item([
            'type' => 'watch',
            'source_id' => stable_source_id('watch', $url),
            'title' => $title,
            'excerpt' => $textNode ? normalize_text($textNode->textContent) : '',
            'url' => $url,
            'image' => absolute_url(clean_image_url($image)),
        ]);
        $seenUrls[$url] = true;
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

    return extract_detail_from_html(fetch_source($url), $url);
}

function extract_detail_from_html(string $html, string $url): array
{
    $xpath = dom_xpath($html);
    $titleNode = $xpath->query("//*[@property='headline' or @itemprop='headline']")->item(0);
    $contentNode = $xpath->query("//*[@property='text' or @itemprop='articleBody']")->item(0);
    $imageNode = $xpath->query("//*[@property='image']//img|//*[@itemprop='image'][self::img]|//*[@itemprop='image']//img|//article//img")->item(0);

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
        'image' => absolute_url(clean_image_url($image)),
        'url' => $url,
    ];
}

function clean_image_url(string $url): string
{
    $markerPosition = strpos($url, '#joomlaImage:');
    return $markerPosition === false ? $url : substr($url, 0, $markerPosition);
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
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML(
        '<?xml encoding="utf-8" ?><!DOCTYPE html><html><body><div id="pwa-detail-root">' . $html . '</div></body></html>'
    );
    libxml_clear_errors();

    if (!$loaded) {
        return '';
    }

    $xpath = new DOMXPath($dom);
    $root = $xpath->query("//*[@id='pwa-detail-root']")->item(0);
    if (!$root instanceof DOMElement) {
        return '';
    }

    sanitize_detail_node($root);
    return trim(inner_html($root));
}

function sanitize_detail_node(DOMNode $parent): void
{
    $allowedTags = [
        'a', 'b', 'blockquote', 'br', 'em', 'figcaption', 'figure', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li', 'ol', 'p', 'small', 'strong',
        'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];
    $discardTags = [
        'audio', 'base', 'button', 'canvas', 'embed', 'form', 'iframe', 'input', 'link',
        'math', 'meta', 'noscript', 'object', 'option', 'script', 'select', 'source',
        'style', 'svg', 'template', 'textarea', 'track', 'video',
    ];
    $children = [];

    foreach ($parent->childNodes as $child) {
        $children[] = $child;
    }

    foreach ($children as $child) {
        if ($child instanceof DOMComment) {
            $parent->removeChild($child);
            continue;
        }
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->tagName);
        if (in_array($tag, $discardTags, true)) {
            $parent->removeChild($child);
            continue;
        }

        sanitize_detail_node($child);
        if (!in_array($tag, $allowedTags, true)) {
            unwrap_detail_element($child);
            continue;
        }

        sanitize_detail_attributes($child, $tag);
    }
}

function unwrap_detail_element(DOMElement $element): void
{
    $parent = $element->parentNode;
    if (!$parent) {
        return;
    }

    while ($element->firstChild) {
        $parent->insertBefore($element->firstChild, $element);
    }
    $parent->removeChild($element);
}

function sanitize_detail_attributes(DOMElement $element, string $tag): void
{
    $allowedAttributes = [];
    if ($tag === 'a') {
        $allowedAttributes = ['href', 'title'];
    } elseif ($tag === 'img') {
        $allowedAttributes = ['alt', 'src', 'title'];
    } elseif ($tag === 'td' || $tag === 'th') {
        $allowedAttributes = ['colspan', 'rowspan'];
    } elseif ($tag === 'ol') {
        $allowedAttributes = ['start'];
    }

    $attributeNames = [];
    foreach ($element->attributes as $attribute) {
        $attributeNames[] = $attribute->name;
    }

    foreach ($attributeNames as $attributeName) {
        $normalizedName = strtolower($attributeName);
        if (!in_array($normalizedName, $allowedAttributes, true)) {
            $element->removeAttribute($attributeName);
        }
    }

    foreach (['href', 'src'] as $urlAttribute) {
        if (!$element->hasAttribute($urlAttribute)) {
            continue;
        }
        $safeUrl = sanitize_detail_url($element->getAttribute($urlAttribute), $urlAttribute === 'href');
        if ($safeUrl === '') {
            $element->removeAttribute($urlAttribute);
        } else {
            $element->setAttribute($urlAttribute, $safeUrl);
        }
    }

    foreach (['colspan', 'rowspan', 'start'] as $numericAttribute) {
        if ($element->hasAttribute($numericAttribute)
            && !preg_match('/^\d{1,3}$/', $element->getAttribute($numericAttribute))) {
            $element->removeAttribute($numericAttribute);
        }
    }
}

function sanitize_detail_url(string $url, bool $allowContactLinks): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $url = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';
    if ($url === '') {
        return '';
    }
    if ($allowContactLinks && strpos($url, '#') === 0) {
        return $url;
    }
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== '') {
        if ($scheme === 'http' || $scheme === 'https') {
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
        }
        if ($allowContactLinks && ($scheme === 'mailto' || $scheme === 'tel')) {
            return $url;
        }
        return '';
    }

    return absolute_url($url);
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

function stable_source_id(string $type, string $url): string
{
    return substr(hash('sha256', $type . '|' . strtolower($url)), 0, 24);
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

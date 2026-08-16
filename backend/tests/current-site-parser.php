<?php

declare(strict_types=1);

putenv('WEBACTION_CONFIG_PATH=' . realpath(__DIR__ . '/../config/config.example.php'));
require_once __DIR__ . '/../lib/content.php';

function assert_parser(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
        exit(1);
    }
}

$portfolioHtml = <<<'HTML'
<div class="uk-panel">
  <div class="uk-inline-clip uk-transition-toggle">
    <img src="/images/project.jpg#joomlaImage://local-images/project.jpg?width=1200&amp;height=800" alt="PROJET EXEMPLE">
    <div class="uk-overlay uk-position-cover"><div><h1>Site Web réactif, production vidéo</h1></div></div>
    <a class="uk-position-cover" href="/index.php/fr/portfolio-webaction/projet-exemple"></a>
  </div>
  <h1 class="uk-h2 uk-margin-remove-bottom">PROJET EXEMPLE</h1>
</div>
HTML;

$realisations = extract_realisations($portfolioHtml);
assert_parser(count($realisations) === 1, 'the current portfolio card should be parsed');
assert_parser($realisations[0]['title'] === 'PROJET EXEMPLE', 'the portfolio title should be parsed');
assert_parser($realisations[0]['excerpt'] === 'Site Web réactif, production vidéo', 'the portfolio excerpt should be parsed');
assert_parser($realisations[0]['url'] === 'https://webaction.ca/index.php/fr/portfolio-webaction/projet-exemple', 'the portfolio URL should be absolute');
assert_parser($realisations[0]['image'] === 'https://webaction.ca/images/project.jpg', 'Joomla image metadata should be removed from the image URL');

$renamedPortfolioHtml = str_replace('PROJET EXEMPLE', 'PROJET RENOMMÉ', $portfolioHtml);
$renamedRealisations = extract_realisations($renamedPortfolioHtml);
assert_parser($renamedRealisations[0]['source_id'] === $realisations[0]['source_id'], 'renaming a portfolio item should not create a new source ID');

$watchHtml = <<<'HTML'
<div class="sppb-addon-article sppb-addon-article-layout-content">
  <a class="sppb-article-img-wrap" href="/index.php/fr/blogue/nouvel-article">
    <img src="/images/article.jpg#joomlaImage://local-images/article.jpg?width=1600&amp;height=900" alt="Nouvel article">
  </a>
  <div class="sppb-article-info-wrap" role="article">
    <h3><a href="/index.php/fr/blogue/nouvel-article">Nouvel article</a></h3>
    <div class="sppb-article-introtext"><p>Une courte introduction à surveiller.</p></div>
  </div>
</div>
HTML;

$watchItems = extract_watch_items($watchHtml);
assert_parser(count($watchItems) === 1, 'the current watch card should be parsed');
assert_parser($watchItems[0]['title'] === 'Nouvel article', 'the watch title should be parsed');
assert_parser($watchItems[0]['excerpt'] === 'Une courte introduction à surveiller.', 'the watch excerpt should be parsed');
assert_parser($watchItems[0]['url'] === 'https://webaction.ca/index.php/fr/blogue/nouvel-article', 'the watch URL should be absolute');
assert_parser($watchItems[0]['image'] === 'https://webaction.ca/images/article.jpg', 'the watch image should be normalized');

$detailHtml = <<<'HTML'
<div class="article-details" itemscope itemtype="https://schema.org/Article">
  <h1 itemprop="headline">Nouvel article</h1>
  <img src="/images/article.jpg#joomlaImage://local-images/article.jpg?width=1600" itemprop="image" alt="Nouvel article">
  <div itemprop="articleBody">
    <p>Contenu <strong>complet</strong>.</p>
    <script>alert('unsafe')</script>
  </div>
</div>
HTML;

$detail = extract_detail_from_html($detailHtml, 'https://webaction.ca/index.php/fr/blogue/nouvel-article');
assert_parser($detail['title'] === 'Nouvel article', 'the Joomla detail headline should be parsed');
assert_parser(strpos($detail['html'], '<strong>complet</strong>') !== false, 'safe detail formatting should be retained');
assert_parser(stripos($detail['html'], '<script') === false, 'unsafe detail elements should be removed');
assert_parser($detail['image'] === 'https://webaction.ca/images/article.jpg', 'the detail image should be normalized');

echo 'current site parser tests passed' . PHP_EOL;

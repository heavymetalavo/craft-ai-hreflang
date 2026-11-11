<?php

namespace heavymetalavo\aihreflang\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use craft\models\Site;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\errors\FetchException;
use heavymetalavo\aihreflang\helpers\HttpClient;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Fetches pages and reads the text visitors see in their main content.
 *
 * @package heavymetalavo\aihreflang\services
 */
class PageReader extends Component
{
    /**
     * Elements whose text isn't read, as they aren't shown, aren't the page's own content, or are form controls.
     */
    private const SKIPPED_ELEMENTS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'nav', 'dialog', 'select', 'button'];

    /**
     * Elements whose text is separated from the text around them, so words either side of them aren't joined.
     * Other elements, such as `<b>` and `<a>`, are part of the text around them.
     */
    private const BLOCK_ELEMENTS = [
        'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'details', 'div', 'dl', 'dt', 'figcaption', 'figure',
        'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'ol', 'p', 'pre', 'section',
        'summary', 'table', 'td', 'th', 'tr', 'ul',
    ];

    private ?Client $client = null;

    /**
     * The text of a page on its site, with whitespace collapsed, cut to the `maxCharacters` setting.
     *
     * @return string|null Null when the element has no live page, or its site has no public pages
     * @throws FetchException If the page can't be fetched
     */
    public function getText(ElementInterface $element): ?string
    {
        if (
            !$this->isSitePublic($element->getSite())
            || $element->getStatus() !== AiHreflang::getInstance()->detector->getLiveStatus($element::class)
            || ($url = $element->getUrl()) === null
        ) {
            return null;
        }

        $text = preg_replace('/\s+/u', ' ', $this->fetchText($url));

        return mb_substr(trim($text), 0, AiHreflang::getInstance()->getSettings()->maxCharacters);
    }

    /**
     * Whether a site has public pages: it's enabled and has a base URL. A site without a base URL gives its pages
     * root-relative URLs, which would be fetched from the primary site's host, so another site's page would be read.
     */
    public function isSitePublic(Site $site): bool
    {
        return $site->getEnabled() && ($site->getBaseUrl() ?? '') !== '';
    }

    /**
     * Fetches a page and returns the text of the first element the `contentSelector` setting matches (`main` by
     * default), or of its `<body>` without the site's header and footer if none matches, leaving out
     * SKIPPED_ELEMENTS and hidden elements.
     *
     * @throws FetchException If the page can't be fetched
     */
    public function fetchText(string $url): string
    {
        // A site with a relative base URL, such as `/fr`, gives root-relative URLs outside a site request, so they're
        // on the primary site's host
        if (UrlHelper::isRootRelativeUrl($url)) {
            $url = UrlHelper::hostInfo(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '') . $url;
        }

        if (!UrlHelper::isAbsoluteUrl($url)) {
            throw new FetchException(sprintf('The page URL “%s” isn’t absolute, so it can’t be fetched. Check the site’s base URL is a full URL, such as `$PRIMARY_SITE_URL`, rather than `@web`, which queue jobs can’t resolve.', $url));
        }

        try {
            $html = (string)$this->getClient()
                ->get($url, ['headers' => ['Accept' => 'text/html']])
                ->getBody();
        } catch (GuzzleException $e) {
            throw new FetchException(sprintf('The page %s couldn’t be fetched: %s', $url, $e->getMessage()), 0, $e);
        }

        return $this->extractText($html);
    }

    /**
     * The text of a page's HTML, as fetchText() reads it.
     */
    public function extractText(string $html): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $selector = AiHreflang::getInstance()->getSettings()->contentSelector;
        $root = $selector !== '' ? $xpath->query((new CssSelectorConverter())->toXPath($selector))->item(0) : null;
        $skipped = [
            ...array_map(fn($name) => ".//$name", self::SKIPPED_ELEMENTS),
            './/*[@hidden]',
            './/*[@aria-hidden="true"]',
        ];

        if ($root === null) {
            $root = $xpath->query('//body')->item(0);
            // The site's header and footer, wherever the layout puts them, but not an article's own
            $skipped = [...$skipped, './/header[not(ancestor::article)]', './/footer[not(ancestor::article)]'];
        }

        if ($root === null) {
            return '';
        }

        foreach (iterator_to_array($xpath->query(implode('|', $skipped), $root)) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Block elements are separated from the text around them, so `<p>One</p><p>Two</p>` is two words, while
        // inline elements aren't, so `<b>W</b>ord` stays one
        $blocks = implode('|', array_map(fn($name) => ".//$name", self::BLOCK_ELEMENTS));
        foreach (iterator_to_array($xpath->query($blocks, $root)) as $node) {
            $node->parentNode?->insertBefore($doc->createTextNode(' '), $node);
            $node->parentNode?->insertBefore($doc->createTextNode(' '), $node->nextSibling);
        }

        return $root->textContent;
    }

    /**
     * The HTTP client pages are fetched with.
     */
    private function getClient(): Client
    {
        return $this->client ??= HttpClient::create();
    }
}

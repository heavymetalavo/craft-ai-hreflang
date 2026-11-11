<?php

namespace heavymetalavo\aihreflang\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use craft\models\Site;
use heavymetalavo\aihreflang\AiHreflang;
use nystudio107\seomatic\events\AddDynamicMetaEvent;
use nystudio107\seomatic\helpers\DynamicMeta;
use nystudio107\seomatic\models\MetaLink;
use nystudio107\seomatic\Seomatic;

/**
 * Builds hreflang values from each site's Content Language, and puts them in SEOmatic's alternate links.
 *
 * A page's copies on its sites must each have a different hreflang, so a copy in another language only gets that
 * language's hreflang when no other copy has it: an untranslated page on the `es-US` site can't be `en-US` when the
 * `en-US` site has the page too. It then keeps its site's own hreflang, and the clash is reported.
 *
 * @package heavymetalavo\aihreflang\services
 */
class Hreflang extends Component
{
    /**
     * @var string[]|null
     * @see getLanguageCodes()
     */
    private ?array $languageCodes = null;

    /**
     * The language part of a locale, e.g. `fr` for `fr-FR`.
     */
    public function getLanguageCode(string $locale): string
    {
        return strtolower(explode('-', str_replace('_', '-', $locale))[0]);
    }

    /**
     * The ISO 639-1 codes of the languages Craft knows, which are the codes hreflang accepts, e.g. `en`.
     *
     * @return string[]
     */
    public function getLanguageCodes(): array
    {
        return $this->languageCodes ??= array_values(array_filter(
            Craft::$app->getI18n()->getAllLocaleIds(),
            fn(string $id) => (bool)preg_match('/^[a-z]{2}$/', $id),
        ));
    }

    /**
     * The hreflang for a page on a site, written in the given language, in the format of the site's language setting
     * (e.g. `fr-FR`). The site's region is kept, so English content on the French site is `en-FR`, unless the
     * `hreflangFormat` setting is `language`, which gives the language alone (`en`). This doesn't check for clashes
     * with the page's other sites: see resolve().
     *
     * @param string|null $contentLanguage ISO 639-1 language code, e.g. `en`, or null to use the site's language
     */
    public function getHreflang(Site $site, ?string $contentLanguage): string
    {
        $siteHreflang = str_replace('_', '-', $site->language);
        $parts = explode('-', $siteHreflang);
        $siteLanguage = strtolower($parts[0]);

        if (AiHreflang::getInstance()->getSettings()->hreflangFormat === 'language') {
            return $contentLanguage ?? $siteLanguage;
        }

        if ($contentLanguage === null || $contentLanguage === $siteLanguage) {
            return $siteHreflang;
        }

        // The region is a 2-letter or 3-digit subtag. A script subtag, such as `Latn` in `sr-Latn`, belongs to the
        // site's language, so it isn't kept.
        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('/^([A-Za-z]{2}|\d{3})$/', $part)) {
                return $contentLanguage . '-' . $part;
            }
        }

        return $contentLanguage;
    }

    /**
     * A label for the hreflang of a page on a site, naming the language for the site's region, e.g.
     * "English (Germany) — en-DE".
     *
     * @param string|null $contentLanguage ISO 639-1 language code, e.g. `en`, or null to use the site's language
     */
    public function getLabel(Site $site, ?string $contentLanguage): string
    {
        $hreflang = $this->getHreflang($site, $contentLanguage);
        $name = Craft::$app->getI18n()->getLocaleById($hreflang)->getDisplayName(Craft::$app->language);

        return sprintf('%s — %s', $name, $hreflang);
    }

    /**
     * The hreflang of each of a page's copies, given the language each is written in. A copy whose hreflang would be
     * the same as another copy's keeps its site's own hreflang, as two URLs can't share one, and `clash` is the
     * hreflang it would have had, with `clashSites` naming the sites it clashed with.
     *
     * @param array<int, string|null> $languages Content languages (null if not detected), indexed by site ID
     * @return array<int, array{site: Site, hreflang: string, clash: string|null, clashSites: string[]}>
     */
    public function resolve(array $languages): array
    {
        $sites = Craft::$app->getSites();
        $resolved = [];
        $own = [];

        foreach ($languages as $siteId => $language) {
            $site = $sites->getSiteById($siteId, true);

            if ($site !== null) {
                $own[$siteId] = $this->getHreflang($site, null);
                $resolved[$siteId] = [
                    'site' => $site,
                    'hreflang' => $this->getHreflang($site, $language),
                    'clash' => null,
                    'clashSites' => [],
                ];
            }
        }

        // Each copy that clashes goes back to its site's own hreflang, which can clash with another copy in turn, so
        // this repeats until nothing changes. It ends, as a copy only changes once.
        do {
            $counts = array_count_values(array_map(fn(array $row) => strtolower($row['hreflang']), $resolved));
            $changed = false;

            foreach ($resolved as $siteId => $row) {
                if ($row['hreflang'] !== $own[$siteId] && $counts[strtolower($row['hreflang'])] > 1) {
                    $resolved[$siteId]['clash'] = $row['hreflang'];
                    $resolved[$siteId]['hreflang'] = $own[$siteId];
                    $changed = true;
                }
            }
        } while ($changed);

        foreach ($resolved as $siteId => $row) {
            if ($row['clash'] === null) {
                continue;
            }

            foreach ($resolved as $otherSiteId => $other) {
                if ($otherSiteId !== $siteId && in_array(strtolower($row['clash']), [strtolower($other['hreflang']), strtolower($other['clash'] ?? '')], true)) {
                    $resolved[$siteId]['clashSites'][] = $other['site']->getName();
                }
            }
        }

        return $resolved;
    }

    /**
     * The hreflang alternates of a page: its live copy on each enabled site that has a URL, with its Content Language
     * and its hreflang, clashes resolved.
     *
     * @return array<int, array{site: Site, element: ElementInterface, url: string, language: string|null, hreflang: string, clash: string|null, clashSites: string[]}> Indexed by site ID
     */
    public function getAlternates(ElementInterface $element): array
    {
        if (!$element->id) {
            return [];
        }

        $detector = AiHreflang::getInstance()->detector;
        $copies = [];
        $languages = [];

        $query = $element::find()
            ->id($element->getCanonicalId())
            ->site('*')
            ->unique(false)
            ->status($detector->getLiveStatus($element::class));

        foreach ($query->all() as $copy) {
            if ($copy->getSite()->getEnabled() && $copy->getUrl() !== null) {
                $copies[$copy->siteId] = $copy;
                $languages[$copy->siteId] = $detector->getValue($copy)?->language;
            }
        }

        $alternates = [];

        foreach ($this->resolve($languages) as $siteId => $row) {
            $alternates[$siteId] = $row + [
                'element' => $copies[$siteId],
                'url' => $this->absoluteUrl($copies[$siteId]->getUrl()),
                'language' => $languages[$siteId],
            ];
        }

        return $alternates;
    }

    /**
     * An absolute URL for a page, as hreflang links need one. A site with a relative base URL, such as `/fr/`, gives
     * root-relative URLs, which are on the current request's host, or outside a web request, the primary site's.
     */
    private function absoluteUrl(string $url): string
    {
        if (!UrlHelper::isRootRelativeUrl($url)) {
            return $url;
        }

        $request = Craft::$app->getRequest();
        $hostInfo = $request->getIsConsoleRequest()
            ? UrlHelper::hostInfo(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '')
            : $request->getHostInfo();

        return $hostInfo . $url;
    }

    /**
     * Rewrites the hreflang values of SEOmatic's alternate links in the format of the sites' language settings
     * (`en-GB` rather than SEOmatic's `en-gb`). For a page, each site's value comes from the Content Language of its
     * element's copy on that site, with clashes resolved. SEOmatic caches the result with the rest of the page's
     * meta, and clears that cache when the element is saved.
     */
    public function updateSeomaticAlternates(AddDynamicMetaEvent $event): void
    {
        /** @var MetaLink|null $alternate */
        $alternate = Seomatic::$plugin->link->get('alternate');

        if ($alternate === null) {
            return;
        }

        // The matched element, or for SEOmatic's meta container API, the element at the URI it was asked for, found
        // the way SEOmatic finds it
        $element = Craft::$app->getUrlManager()->getMatchedElement();

        if (!$element instanceof ElementInterface) {
            $uri = $event->uri ?? Craft::$app->getRequest()->getPathInfo();
            $siteId = $event->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
            $element = Craft::$app->getElements()->getElementByUri($uri, $siteId, true);
        }

        $languages = $element instanceof ElementInterface ? $this->getContentLanguages($element) : [];

        // SEOmatic adds an alternate for each of these localized URLs, in this order, with an `x-default` after the
        // primary site's. Each alternate is checked against the hreflang SEOmatic gave its site before it's replaced,
        // so a list built any other way is left as it is.
        $localizedUrls = DynamicMeta::getLocalizedUrls($event->uri, $event->siteId);
        $resolved = $this->resolve(array_combine(
            array_column($localizedUrls, 'id'),
            array_map(fn(array $url) => $languages[$url['id']] ?? null, $localizedUrls),
        ));
        $hreflangs = $alternate->hreflang;
        $i = 0;

        foreach ($alternate->hreflang as $index => $hreflang) {
            if ($hreflang === 'x-default') {
                continue;
            }

            $localizedUrl = $localizedUrls[$i++] ?? null;

            if ($localizedUrl === null || $hreflang !== $localizedUrl['hreflangLanguage']) {
                Craft::warning('SEOmatic’s alternate links don’t match its localized URLs, so their hreflang values aren’t changed.', __METHOD__);
                return;
            }

            $hreflangs[$index] = $resolved[$localizedUrl['id']]['hreflang'] ?? $hreflang;
        }

        if ($i !== count($localizedUrls)) {
            Craft::warning('SEOmatic’s alternate links don’t match its localized URLs, so their hreflang values aren’t changed.', __METHOD__);
            return;
        }

        $alternate->hreflang = $hreflangs;
    }

    /**
     * The Content Language of each site's copy of an element.
     *
     * @return array<int, string|null> Language codes, indexed by site ID
     */
    public function getContentLanguages(ElementInterface $element): array
    {
        $detector = AiHreflang::getInstance()->detector;
        $languages = [];

        $siteElements = $element::find()
            ->id($element->getCanonicalId())
            ->site('*')
            ->unique(false)
            ->status(null)
            ->all();

        foreach ($siteElements as $siteElement) {
            $value = $detector->getValue($siteElement);

            if ($value !== null) {
                $languages[$siteElement->siteId] = $value->language;
            }
        }

        return $languages;
    }
}

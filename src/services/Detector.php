<?php

namespace heavymetalavo\aihreflang\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\enums\MenuItemType;
use craft\events\DefineMenuItemsEvent;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\models\Site;
use DateTime;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\CssSelector\CssSelectorConverter;
use heavymetalavo\aihreflang\fields\data\ContentLanguageData;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\jobs\DetectElementLanguage;
use Throwable;

/**
 * Detects the language of pages' text and saves it to their Content Language field.
 *
 * A page is an element of a type that can have URLs, such as an entry, category or Commerce product, whose field
 * layout has the Content Language field.
 *
 * @package heavymetalavo\aihreflang\services
 */
class Detector extends Component
{
    public const RESULT_DETECTED = 'detected';
    public const RESULT_UNCHANGED = 'unchanged';
    public const RESULT_CHOSEN = 'chosen';
    public const RESULT_TOO_SHORT = 'tooShort';
    public const RESULT_UNDETERMINED = 'undetermined';
    public const RESULT_NO_PAGE = 'noPage';

    /**
     * How many words a page needs before it is sent. A page with fewer keeps its site's own hreflang, as there isn't
     * enough text to tell its language reliably.
     */
    public const MINIMUM_WORDS = 10;

    /**
     * Elements whose text isn't read from a fetched page, as they aren't shown or aren't the page's own content.
     */
    private const SKIPPED_ELEMENTS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'nav'];

    /**
     * @var array<class-string<ElementInterface>, int[]>|null
     * @see getElementTypes()
     */
    private ?array $elementTypes = null;

    /**
     * Detection jobs for the pages saved during this request, keyed so a page saved more than once is queued once.
     *
     * @var array<string, DetectElementLanguage>
     */
    private array $pendingJobs = [];

    /**
     * Queues detection for an element that was just saved, if it has a page that should be detected: one job for each
     * enabled site the element is enabled on that the save could have changed. That's the site being saved, sites whose
     * copy Craft recorded changes to, such as from a field that isn't translated for each site, and sites that haven't
     * been detected yet. When the changes can't be told, every site is queued.
     *
     * The jobs are queued at the end of the request, with the `queuePriority` setting, so they run after jobs that
     * clear page caches. With Blitz refreshing its cache, nothing is queued here: handleBlitzRefresh() queues the
     * pages Blitz refreshes instead.
     */
    public function handleElementSave(ElementInterface $element): void
    {
        if (
            !$element::hasUris()
            || !AiHreflang::getInstance()->getSettings()->detectOnSave
            || $element->resaving
            || $element->propagating
            || $element->getIsDraft()
            || $element->getIsRevision()
            || !$this->hasField($element)
            || $this->usesBlitz()
        ) {
            return;
        }

        $siteIds = array_intersect(
            Craft::$app->getElements()->getEnabledSiteIdsForElement($element->id),
            Craft::$app->getSites()->getAllSiteIds(false),
        );

        $changedSiteIds = $this->getChangedSiteIds($element);
        if ($changedSiteIds !== null) {
            $siteIds = array_intersect($siteIds, [$element->siteId, ...$changedSiteIds, ...$this->getUndetectedSiteIds($element)]);
        }

        if ($siteIds && !$this->pendingJobs) {
            Craft::$app->onAfterRequest(fn() => $this->pushPendingJobs());
        }

        foreach ($siteIds as $siteId) {
            $this->pendingJobs["$element->id:$siteId"] = new DetectElementLanguage([
                'elementId' => $element->id,
                'elementType' => $element::class,
                'siteId' => $siteId,
            ]);
        }
    }

    /**
     * Whether Blitz refreshes its cache when elements are saved. Pages are then detected once Blitz has refreshed them,
     * so they're fetched after their caches are cleared, on the sites Blitz worked out a save affected, and also when
     * they're refreshed for other reasons, such as a related entry changing or a scheduled entry going live.
     */
    public function usesBlitz(): bool
    {
        /** @var \putyourlightson\blitz\Blitz|null $blitz */
        $blitz = Craft::$app->getPlugins()->getPlugin('blitz');

        return $blitz !== null && $blitz->getSettings()->refreshCacheEnabled;
    }

    /**
     * Queues detection for the pages Blitz just refreshed: one job for each page with the Content Language field, on an
     * enabled site. Runs inside Blitz's refresh, after it has cleared the pages, so they're queued straight away.
     *
     * @param \putyourlightson\blitz\models\SiteUriModel[] $siteUris
     */
    public function handleBlitzRefresh(array $siteUris): void
    {
        if (!AiHreflang::getInstance()->getSettings()->detectOnSave || !$this->usesBlitz()) {
            return;
        }

        $enabledSiteIds = Craft::$app->getSites()->getAllSiteIds(false);
        $priority = AiHreflang::getInstance()->getSettings()->queuePriority;

        foreach ($siteUris as $siteUri) {
            // Pages with a query string, such as pagination, aren't elements' own pages
            if (!in_array((int)$siteUri->siteId, $enabledSiteIds) || str_contains($siteUri->uri, '?')) {
                continue;
            }

            $element = Craft::$app->getElements()->getElementByUri($siteUri->uri, (int)$siteUri->siteId, true);

            if ($element !== null && $this->hasField($element)) {
                Craft::$app->getQueue()->priority($priority)->push(new DetectElementLanguage([
                    'elementId' => $element->id,
                    'elementType' => $element::class,
                    'siteId' => $element->siteId,
                ]));
            }
        }
    }

    /**
     * Queues the detection jobs for the pages saved during this request.
     */
    private function pushPendingJobs(): void
    {
        $priority = AiHreflang::getInstance()->getSettings()->queuePriority;

        foreach ($this->pendingJobs as $job) {
            Craft::$app->getQueue()->priority($priority)->push($job);
        }

        $this->pendingJobs = [];
    }

    /**
     * IDs of the sites whose copy of an element a save changed, from Craft's change tracking: for a draft being
     * applied, the changes it made on each site, otherwise the changes recorded since the save began. Null when that
     * can't be told: a new element or one new to its site, an element type that doesn't track changes, or a change to
     * nested entries (Matrix and the like), which are saved on their sites by their own rules.
     *
     * @return int[]|null
     */
    private function getChangedSiteIds(ElementInterface $element): ?array
    {
        if ($element->firstSave || $element->isNewForSite || !$element::trackChanges()) {
            return null;
        }

        $draft = $element->updatingFromDerivative ? $element->duplicateOf : null;
        $condition = $draft !== null
            ? ['elementId' => $draft->id]
            : ['and', ['elementId' => $element->id], ['>=', 'dateUpdated', Db::prepareDateForDb($element->dateUpdated)]];

        $fields = (new Query())->select(['siteId', 'fieldId'])->from(Table::CHANGEDFIELDS)->where($condition)->all();

        foreach ($fields as $row) {
            if (Craft::$app->getFields()->getFieldById($row['fieldId']) instanceof ElementContainerFieldInterface) {
                return null;
            }
        }

        $attributeSiteIds = (new Query())->select(['siteId'])->from(Table::CHANGEDATTRIBUTES)->where($condition)->column();

        return array_map('intval', array_unique([...array_column($fields, 'siteId'), ...$attributeSiteIds]));
    }

    /**
     * IDs of the sites where an element's page hasn't been detected yet: it's new to the site, or its last detection
     * failed. Craft records no changes for a site an element is new to.
     *
     * @return int[]
     */
    private function getUndetectedSiteIds(ElementInterface $element): array
    {
        $siteIds = [];

        foreach ($element::find()->id($element->id)->site('*')->unique(false)->status(null)->all() as $siteElement) {
            if ($this->hasField($siteElement)) {
                /** @var ContentLanguageData $value */
                $value = $siteElement->getFieldValue(AiHreflang::FIELD_HANDLE);

                if ($value->auto && $value->hash === null) {
                    $siteIds[] = $siteElement->siteId;
                }
            }
        }

        return $siteIds;
    }

    /**
     * Adds "Detect content language" to the action menu of a page's element. It detects the language on the site
     * being edited straight away, then reloads the page so the field shows it.
     */
    public function addActionMenuItem(ElementInterface $element, DefineMenuItemsEvent $event): void
    {
        if (
            !$element::hasUris()
            || !Craft::$app->getRequest()->getIsCpRequest()
            || !$this->hasField($element)
            || !$element->getCanonical()->canSave(Craft::$app->getUser()->getIdentity())
        ) {
            return;
        }

        $view = Craft::$app->getView();
        $id = sprintf('action-detect-content-language-%s', mt_rand());

        // Before the destructive items (Delete entry) and the divider above them
        $index = count($event->items);
        foreach ($event->items as $i => $item) {
            if ($item['destructive'] ?? false) {
                $index = ($event->items[$i - 1]['type'] ?? null) === MenuItemType::HR ? $i - 1 : $i;
                break;
            }
        }

        array_splice($event->items, $index, 0, [[
            'type' => MenuItemType::Button,
            'id' => $id,
            'icon' => 'language',
            'label' => Craft::t('ai-hreflang', 'Detect content language'),
        ]]);

        $view->registerJsWithVars(fn($id, $elementId, $siteId) => <<<JS
$('#' + $id).on('activate', () => {
  Craft.cp.displayNotice(Craft.t('ai-hreflang', 'Detecting the content language…'));
  Craft.sendActionRequest('POST', 'ai-hreflang/element/detect', {data: {elementId: $elementId, siteId: $siteId}})
    .then((response) => {
      Craft.cp.displayNotice(response.data.message);
      if (Craft.cp.elementIndex) {
        Craft.cp.elementIndex.updateElements();
      } else {
        window.location.reload();
      }
    })
    .catch(({response}) => {
      Craft.cp.displayError(response?.data?.message || Craft.t('ai-hreflang', 'The content language couldn’t be detected.'));
    });
});
JS, [
            $view->namespaceInputId($id),
            $element->id,
            $element->siteId,
        ]);
    }

    /**
     * Detects the language of a page on its site and saves it, unless an editor chose the language or the text hasn't
     * changed since it was last detected.
     *
     * @param bool $force Detect even if the text hasn't changed
     * @return string One of the RESULT_* constants
     * @throws Throwable If the AI provider fails or the element can't be saved
     */
    public function detect(ElementInterface $element, bool $force = false): string
    {
        /** @var ContentLanguageData $value */
        $value = $element->getFieldValue(AiHreflang::FIELD_HANDLE);

        if (!$value->auto) {
            return self::RESULT_CHOSEN;
        }

        $text = $this->getText($element);

        if ($text === null) {
            return self::RESULT_NO_PAGE;
        }

        $hash = sha1($text);

        // Too little text to tell its language reliably isn't sent, and keeps the site's own hreflang. Its hash is
        // saved, so the page counts as detected and isn't queued again until its text changes.
        if (preg_match_all('/\p{L}+/u', $text) < self::MINIMUM_WORDS) {
            if ($value->language !== null || $value->hash !== $hash) {
                $this->save($element, new ContentLanguageData(['auto' => true, 'hash' => $hash, 'detectedAt' => new DateTime()]));
            }

            return self::RESULT_TOO_SHORT;
        }

        if (!$force && $value->hash === $hash) {
            return self::RESULT_UNCHANGED;
        }

        $language = AiHreflang::getInstance()->ai->detectLanguage($text);

        Craft::info(sprintf('Detected "%s" for %s %d on site %d.', $language ?? 'und', $element::lowerDisplayName(), $element->id, $element->siteId), __METHOD__);

        // An undetermined language is saved too, with the hash, so the same text isn't sent again
        $this->save($element, new ContentLanguageData([
            'language' => $language,
            'auto' => true,
            'hash' => $hash,
            'detectedAt' => new DateTime(),
        ]));

        return $language !== null ? self::RESULT_DETECTED : self::RESULT_UNDETERMINED;
    }

    /**
     * Saves a page's Content Language on its site.
     *
     * @throws Throwable
     */
    private function save(ElementInterface $element, ContentLanguageData $value): void
    {
        $element->setFieldValue(AiHreflang::FIELD_HANDLE, $value);

        // Saved as a resave, so no revision is made and handleElementSave() ignores it, for this site only, without
        // validation, which an unrelated required field could fail. Blitz and SEOmatic still clear their caches.
        $element->resaving = true;

        if (!Craft::$app->getElements()->saveElement($element, false, false, false)) {
            throw new \Exception(sprintf('%s %d couldn’t be saved on site %d.', $element::displayName(), $element->id, $element->siteId));
        }

    }

    /**
     * The text of a page on its site that's sent to the AI provider, cut to the `maxCharacters` setting.
     *
     * @return string|null Null when the element has no live page, or its site has no public pages
     * @throws \Exception If the page can't be fetched
     */
    public function getText(ElementInterface $element): ?string
    {
        if (
            !$this->isSitePublic($element->getSite())
            || $element->getStatus() !== $this->getLiveStatus($element::class)
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
     * Whether an element's field layout has the Content Language field.
     */
    public function hasField(ElementInterface $element): bool
    {
        return $element->getFieldLayout()?->getFieldByHandle(AiHreflang::FIELD_HANDLE) !== null;
    }

    /**
     * The element types that can have pages and have the Content Language field in at least one of their field
     * layouts, with the IDs of those layouts.
     *
     * @return array<class-string<ElementInterface>, int[]>
     */
    public function getElementTypes(): array
    {
        if ($this->elementTypes !== null) {
            return $this->elementTypes;
        }

        $this->elementTypes = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
            /** @var class-string<ElementInterface> $elementType */
            if (!$elementType::hasUris()) {
                continue;
            }

            foreach (Craft::$app->getFields()->getLayoutsByType($elementType) as $layout) {
                if ($layout->id !== null && $layout->getFieldByHandle(AiHreflang::FIELD_HANDLE) !== null) {
                    $this->elementTypes[$elementType][] = $layout->id;
                }
            }
        }

        return $this->elementTypes;
    }

    /**
     * The status of an element type's public elements: `live` for types with post and expiry dates, such as entries,
     * or `enabled` for those without, such as categories. Only they can be fetched.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public function getLiveStatus(string $elementType): string
    {
        return isset($elementType::statuses()[Entry::STATUS_LIVE]) ? Entry::STATUS_LIVE : Element::STATUS_ENABLED;
    }

    /**
     * A query for the pages of an element type that are detected on a site.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public function pagesQuery(string $elementType, int $siteId): ElementQuery
    {
        /** @var ElementQuery $query */
        $query = $elementType::find();

        return $query
            ->siteId($siteId)
            ->status($this->getLiveStatus($elementType))
            ->andWhere(['elements.fieldLayoutId' => $this->getElementTypes()[$elementType] ?? [0]]);
    }

    /**
     * Counts of each site's pages: how many there are, how many have been detected, how many an editor chose, and how
     * many use each hreflang, the site's own first.
     *
     * @return array<int, array{site: Site, siteHreflang: string, total: int, detected: int, chosen: int, coverage: float|null, hreflangs: array<string, int>}>
     */
    public function getStats(): array
    {
        $handle = AiHreflang::FIELD_HANDLE;
        $hreflang = AiHreflang::getInstance()->hreflang;
        $stats = [];

        foreach (Craft::$app->getSites()->getAllSites(false) as $site) {
            $siteHreflang = $hreflang->getHreflang($site, null);
            $hreflangs = [];
            $chosen = 0;
            $total = 0;

            foreach (array_keys($this->getElementTypes()) as $elementType) {
                foreach ($this->pagesQuery($elementType, $site->id)->$handle(':notempty:')->each() as $element) {
                    /** @var ContentLanguageData $value */
                    $value = $element->getFieldValue($handle);
                    $code = $hreflang->getHreflang($site, $value->language);
                    $hreflangs[$code] = ($hreflangs[$code] ?? 0) + 1;
                    $chosen += $value->auto ? 0 : 1;
                }

                $total += $this->pagesQuery($elementType, $site->id)->count();
            }
            arsort($hreflangs);
            $hreflangs = [$siteHreflang => $hreflangs[$siteHreflang] ?? 0] + $hreflangs;

            $detected = array_sum($hreflangs);

            $stats[$site->id] = [
                'site' => $site,
                'siteHreflang' => $siteHreflang,
                'total' => $total,
                'detected' => $detected,
                'chosen' => $chosen,
                'coverage' => $total ? $detected / $total * 100 : null,
                'hreflangs' => $hreflangs,
            ];
        }

        return $stats;
    }

    /**
     * A page of the pages in a language other than their site's on at least one site, most recently detected first.
     * Each row has the page on every site it's on, so a page that's still the English copy on several sites shows on
     * one row.
     *
     * @return array{total: int, rows: array<int, array{element: ElementInterface, sites: array<int, array{element: ElementInterface, hreflang: string, detected: bool, other: bool, chosen: bool}>}>}
     */
    public function getOtherLanguagePages(int $offset, int $limit): array
    {
        $handle = AiHreflang::FIELD_HANDLE;
        $hreflang = AiHreflang::getInstance()->hreflang;
        $detectedAt = [];
        $elementTypes = [];

        foreach (Craft::$app->getSites()->getAllSites(false) as $site) {
            $siteLanguage = $hreflang->getLanguageCode($site->language);

            foreach (array_keys($this->getElementTypes()) as $elementType) {
                $query = $this->pagesQuery($elementType, $site->id)->$handle(['and', ':notempty:', 'not ' . $siteLanguage]);

                foreach ($query->each() as $element) {
                    $time = $element->getFieldValue($handle)->detectedAt?->getTimestamp() ?? 0;
                    $detectedAt[$element->id] = max($detectedAt[$element->id] ?? 0, $time);
                    $elementTypes[$element->id] = $elementType;
                }
            }
        }

        arsort($detectedAt);
        $ids = array_slice(array_keys($detectedAt), $offset, $limit);
        $rows = array_fill_keys($ids, null);

        // Each row's element on every site, queried by element type
        $idsByType = [];
        foreach ($ids as $id) {
            $idsByType[$elementTypes[$id]][] = $id;
        }

        $siteElements = [];
        foreach ($idsByType as $elementType => $typeIds) {
            $siteElements = [...$siteElements, ...$elementType::find()
                ->id($typeIds)
                ->site('*')
                ->unique(false)
                ->status($this->getLiveStatus($elementType))
                ->all()];
        }

        foreach ($siteElements as $siteElement) {
            /** @var ContentLanguageData $value */
            $value = $siteElement->getFieldValue($handle);
            $site = $siteElement->getSite();
            $rows[$siteElement->id] ??= ['element' => $siteElement, 'sites' => []];
            $rows[$siteElement->id]['sites'][$site->id] = [
                'element' => $siteElement,
                'hreflang' => $hreflang->getHreflang($site, $value->language),
                'detected' => $value->language !== null,
                'other' => $value->language !== null && $value->language !== $hreflang->getLanguageCode($site->language),
                'chosen' => !$value->auto,
            ];

            if ($site->primary) {
                $rows[$siteElement->id]['element'] = $siteElement;
            }
        }

        return ['total' => count($detectedAt), 'rows' => array_filter($rows)];
    }

    /**
     * Fetches a page and returns the text of the first element the `contentSelector` setting matches (`main` by
     * default), or of its `<body>` without the site's header and footer if none matches, leaving out SKIPPED_ELEMENTS.
     *
     * @throws \Exception If the page can't be fetched
     */
    private function fetchText(string $url): string
    {
        // A site with a relative base URL, such as `/fr`, gives root-relative URLs outside a site request, so they're
        // on the primary site's host
        if (UrlHelper::isRootRelativeUrl($url)) {
            $url = UrlHelper::hostInfo(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '') . $url;
        }

        try {
            $html = (string)AiHreflang::getInstance()->ai->getClient()
                ->get($url, ['headers' => ['Accept' => 'text/html']])
                ->getBody();
        } catch (GuzzleException $e) {
            throw new \Exception(sprintf('The page %s couldn’t be fetched: %s', $url, $e->getMessage()), 0, $e);
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $selector = AiHreflang::getInstance()->getSettings()->contentSelector;
        $root = $selector !== '' ? $xpath->query((new CssSelectorConverter())->toXPath($selector))->item(0) : null;
        $skipped = array_map(fn($name) => ".//$name", self::SKIPPED_ELEMENTS);

        if ($root === null) {
            $root = $xpath->query('//body')->item(0);
            $skipped = [...$skipped, './header', './footer'];
        }

        if ($root === null) {
            return '';
        }

        foreach (iterator_to_array($xpath->query(implode('|', $skipped), $root)) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $parts = [];
        foreach ($xpath->query('.//text()', $root) as $node) {
            $parts[] = $node->nodeValue;
        }

        return implode(' ', $parts);
    }
}

<?php

namespace heavymetalavo\aihreflang\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\Json;
use craft\models\Site;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\fields\data\ContentLanguageData;

/**
 * The utility's figures: each site's coverage and hreflang values, and the pages in another language.
 *
 * Pages are read as raw rows rather than elements, so a site with thousands of pages doesn't load thousands of
 * elements. Only the pages shown in the list of pages in another language are loaded as elements.
 *
 * @package heavymetalavo\aihreflang\services
 */
class Report extends Component
{
    /**
     * Each page's Content Language on each site it's live on, by element ID then site ID, and each page's element type.
     *
     * @var array{values: array<int, array<int, ContentLanguageData>>, types: array<int, class-string<ElementInterface>>}|null
     */
    private ?array $pages = null;

    /**
     * Counts of each site's pages: how many there are, how many have been detected, how many of those couldn't be
     * told or were too short, how many an editor chose, how many clash with another site's hreflang, and how many use
     * each hreflang (clashes resolved), the site's own first.
     *
     * @return array<int, array{site: Site, siteHreflang: string, total: int, detected: int, undetermined: int, chosen: int, clashes: int, coverage: float|null, hreflangs: array<string, int>}>
     */
    public function getStats(): array
    {
        $hreflang = AiHreflang::getInstance()->hreflang;
        $sites = Craft::$app->getSites()->getAllSites(false);
        $counts = array_fill_keys(array_map(fn(Site $site) => $site->id, $sites), [
            'total' => 0,
            'detected' => 0,
            'undetermined' => 0,
            'chosen' => 0,
            'clashes' => 0,
        ]);
        $hreflangs = array_fill_keys(array_keys($counts), []);

        foreach ($this->getPages()['values'] as $siteValues) {
            $resolved = $hreflang->resolve(array_map(fn(ContentLanguageData $value) => $value->language, $siteValues));

            foreach ($siteValues as $siteId => $value) {
                if (!isset($counts[$siteId])) {
                    continue;
                }

                $counts[$siteId]['total']++;

                // A page with neither a language nor a hash hasn't been detected yet. One with a hash but no language
                // was detected, but couldn't be told or was too short, so it keeps its site's hreflang.
                if ($value->language === null && $value->hash === null) {
                    continue;
                }

                $code = $resolved[$siteId]['hreflang'];
                $counts[$siteId]['detected']++;
                $counts[$siteId]['undetermined'] += $value->language === null ? 1 : 0;
                $counts[$siteId]['chosen'] += $value->auto ? 0 : 1;
                $counts[$siteId]['clashes'] += $resolved[$siteId]['clash'] !== null ? 1 : 0;
                $hreflangs[$siteId][$code] = ($hreflangs[$siteId][$code] ?? 0) + 1;
            }
        }

        $stats = [];

        foreach ($sites as $site) {
            $siteHreflang = $hreflang->getHreflang($site, null);
            $siteHreflangs = $hreflangs[$site->id];
            arsort($siteHreflangs);

            $stats[$site->id] = [
                'site' => $site,
                'siteHreflang' => $siteHreflang,
                ...$counts[$site->id],
                'coverage' => $counts[$site->id]['total'] ? $counts[$site->id]['detected'] / $counts[$site->id]['total'] * 100 : null,
                'hreflangs' => [$siteHreflang => $siteHreflangs[$siteHreflang] ?? 0] + $siteHreflangs,
            ];
        }

        return $stats;
    }

    /**
     * A page of the pages in a language other than their site's on at least one site, most recently detected first.
     * Each row has the page on every site it's live on, so a page that's still the English copy on several sites
     * shows on one row.
     *
     * @return array{total: int, rows: array<int, array{element: ElementInterface, sites: array<int, array{element: ElementInterface, hreflang: string, detected: bool, other: bool, chosen: bool, clash: string|null, clashSites: string[]}>}>}
     */
    public function getOtherLanguagePages(int $offset, int $limit): array
    {
        $hreflang = AiHreflang::getInstance()->hreflang;
        $pages = $this->getPages();
        $sites = Craft::$app->getSites();
        $detectedAt = [];

        foreach ($pages['values'] as $elementId => $siteValues) {
            foreach ($siteValues as $siteId => $value) {
                $site = $sites->getSiteById($siteId);

                if ($site !== null && $value->language !== null && $value->language !== $hreflang->getLanguageCode($site->language)) {
                    $detectedAt[$elementId] = max($detectedAt[$elementId] ?? 0, $value->detectedAt?->getTimestamp() ?? 0);
                }
            }
        }

        arsort($detectedAt);
        $ids = array_slice(array_keys($detectedAt), $offset, $limit);

        // The elements of the pages shown, on every site, queried by element type
        $idsByType = [];
        foreach ($ids as $id) {
            $idsByType[$pages['types'][$id]][] = $id;
        }

        $siteElements = [];
        foreach ($idsByType as $elementType => $typeIds) {
            $query = $elementType::find()
                ->id($typeIds)
                ->site('*')
                ->unique(false)
                ->status(AiHreflang::getInstance()->detector->getLiveStatus($elementType));

            foreach ($query->all() as $siteElement) {
                $siteElements[$siteElement->id][$siteElement->siteId] = $siteElement;
            }
        }

        $rows = [];

        foreach ($ids as $id) {
            $siteValues = $pages['values'][$id];
            $resolved = $hreflang->resolve(array_map(fn(ContentLanguageData $value) => $value->language, $siteValues));
            $row = ['element' => null, 'sites' => []];

            foreach ($siteValues as $siteId => $value) {
                $siteElement = $siteElements[$id][$siteId] ?? null;

                if ($siteElement === null || !isset($resolved[$siteId])) {
                    continue;
                }

                $site = $resolved[$siteId]['site'];
                $row['element'] ??= $siteElement;
                $row['sites'][$siteId] = [
                    'element' => $siteElement,
                    'hreflang' => $resolved[$siteId]['hreflang'],
                    'detected' => $value->language !== null,
                    'other' => $value->language !== null && $value->language !== $hreflang->getLanguageCode($site->language),
                    'chosen' => !$value->auto,
                    'clash' => $resolved[$siteId]['clash'],
                    'clashSites' => $resolved[$siteId]['clashSites'],
                ];

                if ($site->primary) {
                    $row['element'] = $siteElement;
                }
            }

            if ($row['element'] !== null) {
                $rows[$id] = $row;
            }
        }

        return ['total' => count($detectedAt), 'rows' => $rows];
    }

    /**
     * Each page's Content Language on each enabled site it's live on, read once per request from raw rows.
     *
     * @return array{values: array<int, array<int, ContentLanguageData>>, types: array<int, class-string<ElementInterface>>}
     */
    private function getPages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        $detector = AiHreflang::getInstance()->detector;
        $this->pages = ['values' => [], 'types' => []];

        foreach (Craft::$app->getSites()->getAllSites(false) as $site) {
            foreach ($detector->getElementTypes() as $elementType => $fields) {
                foreach ($detector->pagesQuery($elementType, $site->id)->asArray()->each() as $row) {
                    $field = $fields[(int)$row['fieldLayoutId']] ?? null;

                    if ($field === null) {
                        continue;
                    }

                    $content = $row['content'] ?? [];
                    if (is_string($content)) {
                        $content = Json::decodeIfJson($content);
                    }

                    $id = (int)$row['id'];
                    $this->pages['values'][$id][$site->id] = $field->normalizeValue($content[$field->layoutElement->uid] ?? null, null);
                    $this->pages['types'][$id] = $elementType;
                }
            }
        }

        return $this->pages;
    }
}

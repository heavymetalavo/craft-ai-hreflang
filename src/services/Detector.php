<?php

namespace extreme\aihreflangdetector\services;

use Craft;
use craft\base\Component;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\enums\MenuItemType;
use craft\events\DefineMenuItemsEvent;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\models\Site;
use craft\htmlfield\HtmlFieldData;
use DateTime;
use extreme\aihreflangdetector\fields\data\ContentLanguageData;
use extreme\aihreflangdetector\AiHreflangDetector;
use extreme\aihreflangdetector\jobs\DetectEntryLanguage;
use Throwable;

/**
 * Detects the language of entries' text and saves it to their Content Language field.
 *
 * @package extreme\aihreflangdetector\services
 */
class Detector extends Component
{
    public const RESULT_DETECTED = 'detected';
    public const RESULT_UNCHANGED = 'unchanged';
    public const RESULT_CHOSEN = 'chosen';
    public const RESULT_TOO_SHORT = 'tooShort';
    public const RESULT_UNDETERMINED = 'undetermined';

    /**
     * Entry statuses whose pages are detected. Disabled entries aren't public, and are detected when they are enabled,
     * since that saves them.
     */
    public const STATUSES = [Entry::STATUS_LIVE, Entry::STATUS_PENDING, Entry::STATUS_EXPIRED];

    /**
     * How deep nested elements (Matrix entries, Neo blocks) are read for text.
     */
    private const MAX_DEPTH = 5;

    /**
     * Queues detection for an entry that was just saved, if it should be detected.
     */
    public function handleEntrySave(Entry $entry): void
    {
        if (
            !AiHreflangDetector::getInstance()->getSettings()->detectOnSave
            || $entry->resaving
            || $entry->propagating
            || $entry->getIsDraft()
            || $entry->getIsRevision()
            || !$this->hasField($entry)
        ) {
            return;
        }

        Craft::$app->getQueue()->push(new DetectEntryLanguage(['entryId' => $entry->id]));
    }

    /**
     * Adds "Detect content language" to the action menu of an entry that has the Content Language field. It detects
     * the language on the site being edited straight away, then reloads the page so the field shows it.
     */
    public function addActionMenuItem(Entry $entry, DefineMenuItemsEvent $event): void
    {
        if (
            !Craft::$app->getRequest()->getIsCpRequest()
            || !$this->hasField($entry)
            || !$entry->getCanonical()->canSave(Craft::$app->getUser()->getIdentity())
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
            'label' => Craft::t('ai-hreflang-detector', 'Detect content language'),
        ]]);

        $view->registerJsWithVars(fn($id, $entryId, $siteId) => <<<JS
$('#' + $id).on('activate', () => {
  Craft.cp.displayNotice(Craft.t('ai-hreflang-detector', 'Detecting the content language…'));
  Craft.sendActionRequest('POST', 'ai-hreflang-detector/entry/detect', {data: {entryId: $entryId, siteId: $siteId}})
    .then((response) => {
      Craft.cp.displayNotice(response.data.message);
      if (Craft.cp.elementIndex) {
        Craft.cp.elementIndex.updateElements();
      } else {
        window.location.reload();
      }
    })
    .catch(({response}) => {
      Craft.cp.displayError(response?.data?.message || Craft.t('ai-hreflang-detector', 'The content language couldn’t be detected.'));
    });
});
JS, [
            $view->namespaceInputId($id),
            $entry->id,
            $entry->siteId,
        ]);
    }

    /**
     * Detects the language of an entry on its site and saves it, unless an editor chose the language or the text
     * hasn't changed since it was last detected.
     *
     * @param bool $force Detect even if the text hasn't changed
     * @return string One of the RESULT_* constants
     * @throws Throwable If the AI provider fails or the entry can't be saved
     */
    public function detect(Entry $entry, bool $force = false): string
    {
        /** @var ContentLanguageData $value */
        $value = $entry->getFieldValue(AiHreflangDetector::FIELD_HANDLE);

        if (!$value->auto) {
            return self::RESULT_CHOSEN;
        }

        $text = $this->extractText($entry);

        // Too little text to tell its language reliably isn't sent, and keeps the site's own hreflang
        if (preg_match_all('/\p{L}+/u', $text) < AiHreflangDetector::getInstance()->getSettings()->minimumWords) {
            if ($value->language !== null || $value->hash !== null) {
                $this->save($entry, new ContentLanguageData());
            }

            return self::RESULT_TOO_SHORT;
        }

        $hash = sha1($text);

        if (!$force && $value->hash === $hash) {
            return self::RESULT_UNCHANGED;
        }

        $language = AiHreflangDetector::getInstance()->ai->detectLanguage($text);

        Craft::info(sprintf('Detected "%s" for entry %d on site %d.', $language ?? 'und', $entry->id, $entry->siteId), __METHOD__);

        // An undetermined language is saved too, with the hash, so the same text isn't sent again
        $this->save($entry, new ContentLanguageData([
            'language' => $language,
            'auto' => true,
            'hash' => $hash,
            'detectedAt' => new DateTime(),
        ]));

        return $language !== null ? self::RESULT_DETECTED : self::RESULT_UNDETERMINED;
    }

    /**
     * Saves an entry's Content Language on its site.
     *
     * @throws Throwable
     */
    private function save(Entry $entry, ContentLanguageData $value): void
    {
        $entry->setFieldValue(AiHreflangDetector::FIELD_HANDLE, $value);

        // Saved as a resave, so no revision is made and handleEntrySave() ignores it, for this site only, without
        // validation, which an unrelated required field could fail. Blitz and SEOmatic still clear their caches.
        $entry->resaving = true;

        if (!Craft::$app->getElements()->saveElement($entry, false, false, false)) {
            throw new \Exception(sprintf('Entry %d couldn’t be saved on site %d.', $entry->id, $entry->siteId));
        }

    }

    /**
     * The text of an entry that's sent to the AI provider: its title and text fields, including those of nested
     * entries and blocks, cut to the `maxCharacters` setting. Related elements aren't read, since they are other pages.
     */
    public function extractText(ElementInterface $element): string
    {
        $text = preg_replace('/\s+/u', ' ', $this->collectText($element, 0));

        return mb_substr(trim($text), 0, AiHreflangDetector::getInstance()->getSettings()->maxCharacters);
    }

    /**
     * Whether an entry's field layout has the Content Language field.
     */
    public function hasField(Entry $entry): bool
    {
        return $entry->getFieldLayout()?->getFieldByHandle(AiHreflangDetector::FIELD_HANDLE) !== null;
    }

    /**
     * IDs of the entry types whose field layouts have the Content Language field.
     *
     * @return int[]
     */
    public function getEntryTypeIds(): array
    {
        $ids = [];

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            if ($entryType->getFieldLayout()->getFieldByHandle(AiHreflangDetector::FIELD_HANDLE) !== null) {
                $ids[] = $entryType->id;
            }
        }

        return $ids;
    }

    /**
     * A query for the pages that are detected on a site.
     */
    public function pagesQuery(int $siteId): EntryQuery
    {
        return Entry::find()
            ->typeId($this->getEntryTypeIds() ?: [0])
            ->siteId($siteId)
            ->status(self::STATUSES);
    }

    /**
     * Counts of each site's pages: how many there are, how many have been detected, how many an editor chose, and how
     * many use each hreflang, the site's own first.
     *
     * @return array<int, array{site: Site, siteHreflang: string, total: int, detected: int, chosen: int, coverage: float|null, hreflangs: array<string, int>}>
     */
    public function getStats(): array
    {
        $handle = AiHreflangDetector::FIELD_HANDLE;
        $hreflang = AiHreflangDetector::getInstance()->hreflang;
        $stats = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteHreflang = $hreflang->getHreflang($site, null);
            $hreflangs = [];
            $chosen = 0;

            foreach ($this->pagesQuery($site->id)->$handle(':notempty:')->each() as $entry) {
                /** @var ContentLanguageData $value */
                $value = $entry->getFieldValue($handle);
                $code = $hreflang->getHreflang($site, $value->language);
                $hreflangs[$code] = ($hreflangs[$code] ?? 0) + 1;
                $chosen += $value->auto ? 0 : 1;
            }
            arsort($hreflangs);
            $hreflangs = [$siteHreflang => $hreflangs[$siteHreflang] ?? 0] + $hreflangs;

            $total = $this->pagesQuery($site->id)->count();
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
     * @return array{total: int, rows: array<int, array{entry: Entry, sites: array<int, array{entry: Entry, hreflang: string, detected: bool, other: bool, chosen: bool}>}>}
     */
    public function getOtherLanguagePages(int $offset, int $limit): array
    {
        $handle = AiHreflangDetector::FIELD_HANDLE;
        $hreflang = AiHreflangDetector::getInstance()->hreflang;
        $detectedAt = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteLanguage = $hreflang->getLanguageCode($site->language);
            $query = $this->pagesQuery($site->id)->$handle(['and', ':notempty:', 'not ' . $siteLanguage]);

            foreach ($query->each() as $entry) {
                $time = $entry->getFieldValue($handle)->detectedAt?->getTimestamp() ?? 0;
                $detectedAt[$entry->id] = max($detectedAt[$entry->id] ?? 0, $time);
            }
        }

        arsort($detectedAt);
        $ids = array_slice(array_keys($detectedAt), $offset, $limit);
        $rows = array_fill_keys($ids, null);

        $siteEntries = $ids ? Entry::find()->id($ids)->site('*')->unique(false)->status(self::STATUSES)->all() : [];

        foreach ($siteEntries as $siteEntry) {
            /** @var ContentLanguageData $value */
            $value = $siteEntry->getFieldValue($handle);
            $site = $siteEntry->getSite();
            $rows[$siteEntry->id] ??= ['entry' => $siteEntry, 'sites' => []];
            $rows[$siteEntry->id]['sites'][$site->id] = [
                'entry' => $siteEntry,
                'hreflang' => $hreflang->getHreflang($site, $value->language),
                'detected' => $value->language !== null,
                'other' => $value->language !== null && $value->language !== $hreflang->getLanguageCode($site->language),
                'chosen' => !$value->auto,
            ];

            if ($site->primary) {
                $rows[$siteEntry->id]['entry'] = $siteEntry;
            }
        }

        return ['total' => count($detectedAt), 'rows' => array_filter($rows)];
    }

    private function collectText(ElementInterface $element, int $depth): string
    {
        $parts = [];

        if ($element instanceof Entry && $element->getType()->hasTitleField) {
            $parts[] = (string)$element->title;
        }

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $value = $element->getFieldValue($field->handle);

            if ($field instanceof PlainText) {
                $parts[] = (string)$value;
            } elseif ($value instanceof HtmlFieldData) {
                // The raw content, so CKEditor's nested entries aren't rendered (they are references to other entries)
                $parts[] = html_entity_decode(strip_tags(str_replace('<', ' <', $value->getRawContent())), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif ($field instanceof Table && is_array($value)) {
                foreach ($value as $row) {
                    $parts[] = implode(' ', array_filter($row, 'is_string'));
                }
            } elseif ($field instanceof ElementContainerFieldInterface && $depth < self::MAX_DEPTH) {
                $nested = match (true) {
                    $value instanceof ElementQueryInterface => $value->all(),
                    $value instanceof ElementInterface => [$value],
                    is_iterable($value) => $value,
                    default => [],
                };

                foreach ($nested as $nestedElement) {
                    $parts[] = $this->collectText($nestedElement, $depth + 1);
                }
            }
        }

        return implode("\n", array_filter($parts, fn($part) => trim($part) !== ''));
    }
}

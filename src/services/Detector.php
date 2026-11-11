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
use craft\events\ElementEvent;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use craft\queue\jobs\UpdateElementSlugsAndUris;
use craft\services\Elements;
use DateTime;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\fields\ContentLanguage;
use heavymetalavo\aihreflang\fields\data\ContentLanguageData;
use heavymetalavo\aihreflang\jobs\DetectElementLanguage;
use heavymetalavo\aihreflang\jobs\DetectSiteUris;
use putyourlightson\blitz\Blitz;
use Throwable;
use yii\base\Event;
use yii\db\Expression;
use yii\queue\PushEvent;
use yii\queue\Queue;

/**
 * Detects the language of pages' text and saves it to their Content Language field.
 *
 * A page is an element of a type that can have URLs, such as an entry, category or Commerce product, whose field
 * layout has a Content Language field. The field is found by its type, so its handle can be anything.
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
     * How long, in seconds, the plugin's own detection save is remembered, so the Blitz refresh it causes doesn't
     * detect the element's pages again.
     *
     * @see wasJustSaved()
     */
    private const JUST_SAVED_DURATION = 600;

    /**
     * @var array<class-string<ElementInterface>, array<int, ContentLanguage>>|null
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
     * In a web request the jobs are queued at the end of it, so a page saved more than once is queued once. In a
     * console request, such as a queue worker running an import, they're queued straight away, as a long-running
     * worker's request doesn't end. They have the `queuePriority` setting, so they run after jobs that clear page
     * caches. With Blitz refreshing its cache, nothing is queued here: handleBlitzRefresh() queues the pages Blitz
     * refreshes instead.
     *
     * A save that only changed the Content Language field, such as an editor choosing a page's language, can't have
     * changed any page's text, so nothing is queued, and the Blitz refresh it causes is skipped too. Going back to
     * automatic is the exception, as that site's page then needs detecting.
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
        ) {
            return;
        }

        if ($this->onlyContentLanguageChanged($element)) {
            $this->markJustSaved($element);
            return;
        }

        if ($this->usesBlitz() || AiHreflang::getInstance()->ai->getConfigurationError() !== null) {
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

        $isConsole = Craft::$app->getRequest()->getIsConsoleRequest();

        if ($siteIds && !$this->pendingJobs && !$isConsole) {
            Craft::$app->onAfterRequest(fn() => $this->pushPendingJobs());
        }

        foreach ($siteIds as $siteId) {
            $this->pendingJobs["$element->id:$siteId"] = new DetectElementLanguage([
                'elementId' => $element->id,
                'elementType' => $element::class,
                'siteId' => $siteId,
            ]);
        }

        if ($isConsole) {
            $this->pushPendingJobs();
        }
    }

    /**
     * Whether Blitz refreshes its cache when elements are saved. Pages are then detected once Blitz has refreshed them,
     * so they're fetched after their caches are cleared, on the sites Blitz worked out a save affected, and also when
     * they're refreshed for other reasons, such as a related entry changing or a scheduled entry going live.
     */
    public function usesBlitz(): bool
    {
        $blitz = Craft::$app->getPlugins()->getPlugin('blitz');

        return $blitz instanceof Blitz && $blitz->settings->refreshCacheEnabled;
    }

    /**
     * Queues detection for the pages Blitz just refreshed, in one job, which finds each page's element. Runs inside
     * Blitz's refresh, after it has cleared the pages, so the job is queued straight away. A refresh of a whole site
     * can list thousands of URIs, so nothing is looked up here.
     *
     * @param \putyourlightson\blitz\models\SiteUriModel[] $siteUris
     */
    public function handleBlitzRefresh(array $siteUris): void
    {
        $plugin = AiHreflang::getInstance();

        if (!$plugin->getSettings()->detectOnSave || !$this->usesBlitz() || $plugin->ai->getConfigurationError() !== null) {
            return;
        }

        $enabledSiteIds = Craft::$app->getSites()->getAllSiteIds(false);
        $pages = [];

        foreach ($siteUris as $siteUri) {
            // Pages with a query string, such as pagination, aren't elements' own pages
            if (in_array((int)$siteUri->siteId, $enabledSiteIds) && !str_contains($siteUri->uri, '?')) {
                $pages[] = ['siteId' => (int)$siteUri->siteId, 'uri' => $siteUri->uri];
            }
        }

        if ($pages) {
            QueueHelper::push(new DetectSiteUris(['siteUris' => $pages]), $plugin->getSettings()->queuePriority);
        }
    }

    /**
     * Whether an element's last change was this plugin saving a detection, on any of its sites, or a save that only
     * changed its Content Language, either of which makes Blitz refresh its pages on every site. None of their text
     * has changed, so they needn't be fetched again. It only holds while nothing else has saved the element since,
     * which would change its `dateUpdated`, and until the job for that refresh calls forgetJustSaved().
     */
    public function wasJustSaved(ElementInterface $element): bool
    {
        $dateUpdated = Craft::$app->getCache()->get($this->justSavedKey($element->id));

        return $dateUpdated !== false && $dateUpdated === $element->dateUpdated?->getTimestamp();
    }

    /**
     * Forgets that the plugin just saved an element's detection, once the Blitz refresh it caused has been handled,
     * so a later refresh for another reason, such as a related entry changing, detects its pages.
     */
    public function forgetJustSaved(int $elementId): void
    {
        Craft::$app->getCache()->delete($this->justSavedKey($elementId));
    }

    /**
     * Remembers that an element was just saved without changing any page's text.
     *
     * @see wasJustSaved()
     */
    private function markJustSaved(ElementInterface $element): void
    {
        Craft::$app->getCache()->set($this->justSavedKey($element->id), $element->dateUpdated?->getTimestamp(), self::JUST_SAVED_DURATION);
    }

    private function justSavedKey(int $elementId): string
    {
        return sprintf('ai-hreflang:saved:%d', $elementId);
    }

    /**
     * Queues the detection jobs for the pages saved during this request.
     */
    private function pushPendingJobs(): void
    {
        $priority = AiHreflang::getInstance()->getSettings()->queuePriority;

        foreach ($this->pendingJobs as $job) {
            QueueHelper::push($job, $priority);
        }

        $this->pendingJobs = [];
    }

    /**
     * IDs of the sites whose copy of an element a save changed. Null when that can't be told: see getChanges(), and
     * a change to nested entries (Matrix and the like), which are saved on their sites by their own rules.
     *
     * @return int[]|null
     */
    private function getChangedSiteIds(ElementInterface $element): ?array
    {
        $changes = $this->getChanges($element);

        if ($changes === null) {
            return null;
        }

        foreach ($changes['fields'] as $row) {
            if (Craft::$app->getFields()->getFieldById($row['fieldId']) instanceof ElementContainerFieldInterface) {
                return null;
            }
        }

        return array_values(array_unique([...array_column($changes['fields'], 'siteId'), ...$changes['attributeSiteIds']]));
    }

    /**
     * Whether a save only changed an element's Content Language field, on sites that don't need detecting: an editor
     * chose a language, rather than going back to automatic.
     */
    private function onlyContentLanguageChanged(ElementInterface $element): bool
    {
        $changes = $this->getChanges($element);

        if ($changes === null || $changes['attributeSiteIds'] || !$changes['fields']) {
            return false;
        }

        foreach ($changes['fields'] as $row) {
            if (!Craft::$app->getFields()->getFieldById($row['fieldId']) instanceof ContentLanguage) {
                return false;
            }
        }

        return !array_intersect(array_column($changes['fields'], 'siteId'), $this->getUndetectedSiteIds($element));
    }

    /**
     * The changes a save made: for a draft being applied, the changes it made on each site, otherwise the changes
     * recorded since the save began, and the site being saved's own. Null when they can't be told: a new element or one new to its site,
     * or an element type that doesn't track changes.
     *
     * @return array{fields: array<int, array{siteId: int, fieldId: int}>, attributeSiteIds: int[]}|null
     */
    private function getChanges(ElementInterface $element): ?array
    {
        if ($element->firstSave || $element->isNewForSite || !$element::trackChanges()) {
            return null;
        }

        $draft = $element->updatingFromDerivative ? $element->duplicateOf : null;
        $condition = $draft !== null
            ? ['elementId' => $draft->id]
            : ['and', ['elementId' => $element->id], ['>=', 'dateUpdated', Db::prepareDateForDb($element->dateUpdated)]];

        $fields = array_map(
            fn(array $row) => ['siteId' => (int)$row['siteId'], 'fieldId' => (int)$row['fieldId']],
            (new Query())->select(['siteId', 'fieldId'])->from(Table::CHANGEDFIELDS)->where($condition)->all(),
        );
        $attributeSiteIds = array_map('intval', (new Query())->select(['siteId'])->from(Table::CHANGEDATTRIBUTES)->where($condition)->column());

        // Craft records the site being saved's own changes only after this runs (the other sites' are recorded as they
        // propagate), so they're read from the element. A draft's changes are all recorded already.
        if ($draft === null) {
            foreach ($element->getDirtyFields() as $handle) {
                $field = $element->getFieldLayout()?->getFieldByHandle($handle);

                if ($field !== null) {
                    $fields[] = ['siteId' => $element->siteId, 'fieldId' => $field->id];
                }
            }

            if ($element->getDirtyAttributes()) {
                $attributeSiteIds[] = $element->siteId;
            }
        }

        return ['fields' => $fields, 'attributeSiteIds' => array_values(array_unique($attributeSiteIds))];
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
            $value = $this->getValue($siteElement);

            if ($value !== null && $value->auto && $value->hash === null) {
                $siteIds[] = $siteElement->siteId;
            }
        }

        return $siteIds;
    }

    /**
     * Adds "Detect content language" to the action menu of a page's element. It detects the language of the
     * published page on the site being edited straight away, then reloads the page so the field shows it.
     */
    public function addActionMenuItem(ElementInterface $element, DefineMenuItemsEvent $event): void
    {
        $user = Craft::$app->getUser()->getIdentity();

        if (
            !$element::hasUris()
            || $user === null
            || !Craft::$app->getRequest()->getIsCpRequest()
            || !$this->hasField($element)
            || !$element->getCanonical()->canSave($user)
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
  Craft.cp.displayNotice(Craft.t('ai-hreflang', 'Detecting the content language of the published page…'));
  Craft.sendActionRequest('POST', 'ai-hreflang/element/detect', {data: {elementId: $elementId, siteId: $siteId}})
    .then((response) => {
      Craft.cp.displayNotice(response.data.message);
      if (!response.data.detected) {
        return;
      }
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
     * changed since it was last detected. With `$includeChosen`, a language an editor chose is replaced too, and the
     * page goes back to automatic, unless its page can't be fetched, which keeps the choice.
     *
     * The saved hash covers the text and what the detection depends on (the provider, model and prompt), so changing
     * any of them makes the page count as changed. Only the first `maxCharacters` characters of the text are read, so
     * changes after them don't.
     *
     * @param bool $force Detect even if the text hasn't changed
     * @param bool $includeChosen Replace a language an editor chose
     * @return string One of the RESULT_* constants
     * @throws Throwable If the page can't be fetched, the AI provider fails or the element can't be saved
     */
    public function detect(ElementInterface $element, bool $force = false, bool $includeChosen = false): string
    {
        $plugin = AiHreflang::getInstance();
        $value = $this->getValue($element);

        if ($value === null) {
            return self::RESULT_NO_PAGE;
        }

        if (!$value->auto && !$includeChosen) {
            return self::RESULT_CHOSEN;
        }

        $text = $plugin->pageReader->getText($element);

        if ($text === null) {
            return self::RESULT_NO_PAGE;
        }

        // A chosen language being replaced counts as never detected, so the page is sent and goes back to automatic
        if (!$value->auto) {
            $value = new ContentLanguageData();
        }

        $hash = sha1($plugin->ai->getFingerprint() . "\n" . $text);

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

        $language = $plugin->ai->detectLanguage($text);

        Craft::info(sprintf('Detected "%s" for %s %d on site %d, with %s %s.', $language ?? 'und', $element::lowerDisplayName(), $element->id, $element->siteId, $plugin->ai->getProviderLabel(), $plugin->ai->getModel()), __METHOD__);

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
        $element->setFieldValue($this->getField($element)->handle, $value);

        // Saved as a resave, so no revision is made, its `dateUpdated` is kept and handleElementSave() ignores it, for
        // this site only, without validation, which an unrelated required field could fail. Blitz and SEOmatic still
        // clear their caches, as the page's hreflang has changed.
        $element->resaving = true;

        // Saving an element in a structure makes Craft queue a job that updates the slugs and URIs of its descendants, on
        // every site, and each one that is updated is announced to other plugins, such as a search indexer queueing
        // each page. This save changes no slug or URI, so for a page with many descendants that is a lot of jobs for
        // nothing, and it's skipped.
        //
        // A resave also counts every field as changed, so fields holding nested elements, such as Matrix, Neo and
        // Content Block, save all of them again, and each one's save is announced too. Plugins that queue a job for each
        // save, such as Scout queueing the page's index job once for each of its blocks, would queue a dozen jobs for
        // a page whose blocks haven't changed. Jobs queued while a nested element is saving are skipped, and the page's
        // own save still queues its jobs.
        $nestedSaving = 0;

        $nestedSaveStarted = function(ElementEvent $event) use ($element, &$nestedSaving) {
            if ($event->element !== $element) {
                $nestedSaving++;
            }
        };

        // Plugins listening before this one queue their jobs first, so the count drops only after they have
        $nestedSaveEnded = function(ElementEvent $event) use ($element, &$nestedSaving) {
            if ($event->element !== $element) {
                $nestedSaving = max(0, $nestedSaving - 1);
            }
        };

        $skipJobs = function(PushEvent $event) use ($element, &$nestedSaving) {
            if (
                $nestedSaving > 0
                || (
                    $event->job instanceof UpdateElementSlugsAndUris
                    && $event->job->elementType === $element::class
                    && $event->job->siteId === $element->siteId
                )
            ) {
                $event->handled = true;
            }
        };

        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, $nestedSaveStarted);
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $nestedSaveEnded);
        Event::on(Queue::class, Queue::EVENT_BEFORE_PUSH, $skipJobs);

        try {
            $saved = Craft::$app->getElements()->saveElement($element, false, false, false);
        } finally {
            Event::off(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, $nestedSaveStarted);
            Event::off(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $nestedSaveEnded);
            Event::off(Queue::class, Queue::EVENT_BEFORE_PUSH, $skipJobs);
        }

        if (!$saved) {
            throw new \Exception(sprintf('%s %d couldn’t be saved on site %d.', $element::displayName(), $element->id, $element->siteId));
        }

        $this->markJustSaved($element);
    }

    /**
     * The Content Language field in an element's field layout, or null if it has none. If a layout has more than
     * one, the first is used.
     */
    public function getField(ElementInterface $element): ?ContentLanguage
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof ContentLanguage) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The value of an element's Content Language field, or null if its field layout has none.
     */
    public function getValue(ElementInterface $element): ?ContentLanguageData
    {
        $field = $this->getField($element);

        return $field ? $element->getFieldValue($field->handle) : null;
    }

    /**
     * Whether an element's field layout has a Content Language field.
     */
    public function hasField(ElementInterface $element): bool
    {
        return $this->getField($element) !== null;
    }

    /**
     * The element types that can have pages and have a Content Language field in at least one of their field
     * layouts, with the field in each of those layouts, by layout ID.
     *
     * @return array<class-string<ElementInterface>, array<int, ContentLanguage>>
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
                if ($layout->id === null) {
                    continue;
                }

                foreach ($layout->getCustomFields() as $field) {
                    if ($field instanceof ContentLanguage) {
                        $this->elementTypes[$elementType][$layout->id] = $field;
                        break;
                    }
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
     * A query for the pages of an element type that are detected on a site. Elements without a URL, such as entries in
     * a section without URLs, aren't pages, so they're left out.
     *
     * @param class-string<ElementInterface> $elementType
     * @param bool $undetectedOnly Only pages that haven't been detected: with neither a language nor a hash, as Report
     * counts them. Pages an editor chose a language for, and pages detected without a language, have one or the other.
     */
    public function pagesQuery(string $elementType, int $siteId, bool $undetectedOnly = false): ElementQuery
    {
        /** @var ElementQuery $query */
        $query = $elementType::find();
        $fields = $this->getElementTypes()[$elementType] ?? [];

        $query
            ->siteId($siteId)
            ->uri(':notempty:')
            ->status($this->getLiveStatus($elementType))
            ->andWhere(['elements.fieldLayoutId' => array_keys($fields) ?: [0]]);

        if ($undetectedOnly && $fields) {
            $queryBuilder = Craft::$app->getDb()->getQueryBuilder();
            $conditions = ['or'];

            // The field's value is saved in the `content` column under its layout element's UID. Nothing is saved for a
            // page that hasn't been detected, so its path is missing, and where the database returns a JSON null as
            // text, 'null'.
            foreach ($fields as $layoutId => $field) {
                $uid = $field->layoutElement->uid;
                $language = $queryBuilder->jsonExtract('elements_sites.content', [$uid, 'language']);
                $hash = $queryBuilder->jsonExtract('elements_sites.content', [$uid, 'hash']);

                $conditions[] = [
                    'and',
                    ['elements.fieldLayoutId' => $layoutId],
                    new Expression("NULLIF($language, 'null') IS NULL AND NULLIF($hash, 'null') IS NULL"),
                ];
            }

            $query->andWhere($conditions);
        }

        return $query;
    }
}

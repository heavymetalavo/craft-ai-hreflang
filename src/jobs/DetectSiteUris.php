<?php

namespace heavymetalavo\aihreflang\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\ElementInterface;
use heavymetalavo\aihreflang\AiHreflang;

/**
 * Detects the language of the pages Blitz refreshed, by their URIs. Each URI's element is found here rather than
 * during Blitz's refresh, which can list every page of a site. Blitz refreshes an element's pages on every site, so
 * when the refresh was caused by the plugin saving a detection, on any site, all of the element's pages are skipped,
 * as their text hasn't changed.
 *
 * @package heavymetalavo\aihreflang\jobs
 */
class DetectSiteUris extends BaseDetectJob
{
    /**
     * @var array<int, array{siteId: int, uri: string}>
     */
    public array $siteUris = [];

    /**
     * IDs of the elements skipped because the refresh was caused by the plugin saving their detection, kept between
     * batches, and forgotten once the job ends.
     *
     * @var int[]
     */
    public array $justSavedIds = [];

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        return new class($this->siteUris) implements Batchable {
            public function __construct(private array $items)
            {
            }

            public function count(): int
            {
                return count($this->items);
            }

            public function getSlice(int $offset, int $limit): iterable
            {
                return array_slice($this->items, $offset, $limit);
            }
        };
    }

    /**
     * @inheritdoc
     */
    protected function element(mixed $item): ?ElementInterface
    {
        $detector = AiHreflang::getInstance()->detector;
        $element = Craft::$app->getElements()->getElementByUri($item['uri'], $item['siteId'], true);

        if ($element === null || !$detector->hasField($element)) {
            return null;
        }

        if ($detector->wasJustSaved($element)) {
            $this->justSavedIds[] = $element->id;
            return null;
        }

        return $element;
    }

    /**
     * @inheritdoc
     */
    protected function after(): void
    {
        foreach (array_unique($this->justSavedIds) as $elementId) {
            AiHreflang::getInstance()->detector->forgetJustSaved($elementId);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('ai-hreflang', 'Detecting the languages of {count, plural, =1{one refreshed page} other{# refreshed pages}}', [
            'count' => count($this->siteUris),
        ]);
    }
}

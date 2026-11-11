<?php

namespace heavymetalavo\aihreflang\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\ElementInterface;
use heavymetalavo\aihreflang\AiHreflang;

/**
 * Detects the language of the pages Blitz refreshed, by their URIs. Each URI's element is found here rather than
 * during Blitz's refresh, which can list every page of a site. A page whose refresh was caused by the plugin saving
 * its own detection is skipped, as its text hasn't changed.
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

        if ($element === null || !$detector->hasField($element) || $detector->wasJustSaved($element)) {
            return null;
        }

        return $element;
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

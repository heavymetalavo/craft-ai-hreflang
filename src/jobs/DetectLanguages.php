<?php

namespace heavymetalavo\aihreflang\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\ElementInterface;
use craft\db\QueryBatcher;
use heavymetalavo\aihreflang\AiHreflang;

/**
 * Detects the language of the pages of one element type on a site, for pages saved before the plugin was installed,
 * or from the utility. Only the pages that haven't been detected, unless `force` is set, which goes through every page.
 *
 * @package heavymetalavo\aihreflang\jobs
 */
class DetectLanguages extends BaseDetectJob
{
    public int $siteId;

    /**
     * @var class-string<ElementInterface>
     */
    public string $elementType;

    /**
     * The ID of the last page handled, kept between batches. Each batch runs the query again, and without `force` a
     * page that was just detected no longer matches it, so counting pages by offset would skip as many pages as the
     * earlier batches detected. Starting after this ID instead means no page is skipped, and a page that failed isn't
     * tried again by the same job.
     */
    public int $lastId = 0;

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        // Without `force` this is "Detect missing", which leaves pages that have been detected alone, so they aren't
        // fetched or counted in the job's progress.
        $query = AiHreflang::getInstance()->detector
            ->pagesQuery($this->elementType, $this->siteId, !$this->force)
            ->andWhere(['>', 'elements.id', $this->lastId])
            ->orderBy(['elements.id' => SORT_ASC]);

        // The pages handled so far no longer match the query, or are before `lastId`, so they're added to the count to
        // keep the job's total, progress and end the same across batches.
        return new class(new QueryBatcher($query), $this->itemOffset) implements Batchable {
            public function __construct(private QueryBatcher $batcher, private int $handled)
            {
            }

            public function count(): int
            {
                return $this->handled + $this->batcher->count();
            }

            public function getSlice(int $offset, int $limit): iterable
            {
                // The offset is how many pages were handled, which the query already leaves out
                return $this->batcher->getSlice(0, $limit);
            }
        };
    }

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        try {
            parent::processItem($item);
        } finally {
            $this->lastId = $item->id;
        }
    }

    /**
     * @inheritdoc
     */
    protected function element(mixed $item): ?ElementInterface
    {
        return $item;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return self::describe($this->siteId, $this->elementType, $this->force, $this->includeChosen);
    }

    /**
     * The description of the job for an element type's pages on a site, which tells pending jobs for the same pages
     * apart from others.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public static function describe(int $siteId, string $elementType, bool $force, bool $includeChosen = false): string
    {
        $params = [
            'type' => $elementType::pluralLowerDisplayName(),
            'site' => Craft::$app->getSites()->getSiteById($siteId, true)?->getName() ?? $siteId,
        ];

        if ($includeChosen) {
            return Craft::t('ai-hreflang', 'Re-detecting the languages of all {type} on {site}, including chosen', $params);
        }

        return $force
            ? Craft::t('ai-hreflang', 'Re-detecting the languages of {type} on {site}, keeping chosen', $params)
            : Craft::t('ai-hreflang', 'Detecting the languages of {type} on {site}', $params);
    }
}

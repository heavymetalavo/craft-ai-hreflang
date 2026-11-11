<?php

namespace heavymetalavo\aihreflang\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\ElementInterface;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedJob;
use heavymetalavo\aihreflang\AiHreflang;
use Throwable;

/**
 * Detects the language of every page of one element type on a site, for pages saved before the plugin was installed.
 *
 * A page that can't be detected is logged and skipped, so one failure doesn't stop the rest. Several failures in a
 * row, such as from a wrong API key, stop the job rather than failing every page.
 *
 * @package heavymetalavo\aihreflang\jobs
 */
class DetectLanguages extends BaseBatchedJob
{
    public int $siteId;

    /**
     * @var class-string<ElementInterface>
     */
    public string $elementType;

    /**
     * Whether to detect pages whose text hasn't changed since they were last detected.
     */
    public bool $force = false;

    /**
     * How many pages in a row have failed, kept between batches.
     */
    public int $failuresInARow = 0;

    private const MAX_FAILURES_IN_A_ROW = 5;

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        $query = AiHreflang::getInstance()->detector
            ->pagesQuery($this->elementType, $this->siteId)
            ->orderBy(['elements.id' => SORT_ASC]);

        return new QueryBatcher($query);
    }

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        /** @var ElementInterface $item */
        try {
            AiHreflang::getInstance()->detector->detect($item, $this->force);
            $this->failuresInARow = 0;
        } catch (Throwable $e) {
            Craft::error(sprintf('Couldn’t detect the language of %s %d on site %d: %s', $item::lowerDisplayName(), $item->id, $item->siteId, $e->getMessage()), __METHOD__);

            if (++$this->failuresInARow >= self::MAX_FAILURES_IN_A_ROW) {
                throw new \Exception(sprintf('Stopped after %d pages failed in a row. The last failure: %s', $this->failuresInARow, $e->getMessage()), 0, $e);
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('ai-hreflang', 'Detecting the languages of {type} on {site}', [
            'type' => $this->elementType::pluralLowerDisplayName(),
            'site' => Craft::$app->getSites()->getSiteById($this->siteId)?->getName() ?? $this->siteId,
        ]);
    }
}

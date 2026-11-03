<?php

namespace extreme\aihreflangdetector\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Entry;
use craft\queue\BaseBatchedJob;
use extreme\aihreflangdetector\AiHreflangDetector;
use Throwable;

/**
 * Detects the language of every page on a site, for pages saved before the plugin was installed.
 *
 * A page that can't be detected is logged and skipped, so one failure doesn't stop the rest. Several failures in a
 * row, such as from a wrong API key, stop the job rather than failing every page.
 *
 * @package extreme\aihreflangdetector\jobs
 */
class DetectLanguages extends BaseBatchedJob
{
    public int $siteId;

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
        $query = AiHreflangDetector::getInstance()->detector
            ->pagesQuery($this->siteId)
            ->orderBy(['elements.id' => SORT_ASC]);

        return new QueryBatcher($query);
    }

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        /** @var Entry $item */
        try {
            AiHreflangDetector::getInstance()->detector->detect($item, $this->force);
            $this->failuresInARow = 0;
        } catch (Throwable $e) {
            Craft::error(sprintf('Couldn’t detect the language of entry %d on site %d: %s', $item->id, $item->siteId, $e->getMessage()), __METHOD__);

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
        return Craft::t('ai-hreflang-detector', 'Detecting page languages on {site}', [
            'site' => Craft::$app->getSites()->getSiteById($this->siteId)?->getName() ?? $this->siteId,
        ]);
    }
}

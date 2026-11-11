<?php

namespace heavymetalavo\aihreflang\jobs;

use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseBatchedJob;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\errors\ConfigurationException;
use Throwable;

/**
 * Detects the language of a batch of pages.
 *
 * A page that can't be detected is logged and skipped, so one failure doesn't stop the rest. Several failures in a
 * row, such as from a provider that's down, stop the job rather than failing every page, and incomplete provider
 * settings stop it at once.
 *
 * Batched jobs must be pushed with `craft\helpers\Queue::push()`, so their later batches keep the priority.
 *
 * @package heavymetalavo\aihreflang\jobs
 */
abstract class BaseDetectJob extends BaseBatchedJob
{
    /**
     * How many pages are detected in each batch, from the Batch Size setting when the job is created. Each page is
     * fetched and sent to the AI provider, which is slow, so the default is smaller than Craft's 100. Later batches keep
     * the size the first was queued with.
     *
     * @inheritdoc
     */
    public int $batchSize = 50;

    /**
     * Whether to detect pages whose text hasn't changed since they were last detected.
     */
    public bool $force = false;

    /**
     * Whether to replace languages editors chose, putting those pages back to automatic.
     */
    public bool $includeChosen = false;

    /**
     * How many pages in a row have failed, kept between batches.
     */
    public int $failuresInARow = 0;

    private const MAX_FAILURES_IN_A_ROW = 5;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->batchSize = AiHreflang::getInstance()?->getSettings()->batchSize ?? $this->batchSize;
    }

    /**
     * The page an item of the batch is, or null to skip it.
     */
    abstract protected function element(mixed $item): ?ElementInterface;

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        $element = $this->element($item);

        if ($element === null) {
            return;
        }

        try {
            AiHreflang::getInstance()->detector->detect($element, $this->force, $this->includeChosen);
            $this->failuresInARow = 0;
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Craft::error(sprintf('Couldn’t detect the language of %s %d on site %d: %s', $element::lowerDisplayName(), $element->id, $element->siteId, $e->getMessage()), __METHOD__);

            if (++$this->failuresInARow >= self::MAX_FAILURES_IN_A_ROW) {
                throw new \Exception(sprintf('Stopped after %d pages failed in a row. The last failure: %s', $this->failuresInARow, $e->getMessage()), 0, $e);
            }
        }
    }
}

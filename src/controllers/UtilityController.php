<?php

namespace heavymetalavo\aihreflang\controllers;

use Craft;
use craft\web\Controller;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\jobs\DetectLanguages;
use heavymetalavo\aihreflang\utilities\AiHreflangUtility;
use yii\web\Response;

/**
 * Actions of the AI Hreflang utility.
 *
 * @package heavymetalavo\aihreflang\controllers
 */
class UtilityController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('utility:' . AiHreflangUtility::id());

        return parent::beforeAction($action);
    }

    /**
     * Queues detection of the pages on one site, or on all sites.
     *
     * Posted params:
     * - `siteId`: the site, or blank for all sites
     * - `force`: whether to detect pages whose text hasn't changed since they were last detected
     */
    public function actionDetect(): ?Response
    {
        $this->requirePostRequest();

        $error = AiHreflang::getInstance()->ai->getConfigurationError();
        if ($error !== null) {
            return $this->asFailure($error);
        }

        $siteId = $this->request->getBodyParam('siteId');
        $sites = Craft::$app->getSites();
        $siteIds = $siteId ? [(int)$siteId] : $sites->getAllSiteIds(false);
        $force = (bool)$this->request->getBodyParam('force');

        foreach ($siteIds as $id) {
            if (!$sites->getSiteById($id)?->getEnabled()) {
                return $this->asFailure(Craft::t('ai-hreflang', 'Site {id} doesn’t exist or is disabled.', ['id' => $id]));
            }

            foreach (array_keys(AiHreflang::getInstance()->detector->getElementTypes()) as $elementType) {
                Craft::$app->getQueue()->priority(AiHreflang::getInstance()->getSettings()->queuePriority)->push(new DetectLanguages(['siteId' => $id, 'elementType' => $elementType, 'force' => $force]));
            }
        }

        return $this->asSuccess(Craft::t('ai-hreflang', 'Detection queued for {count, plural, =1{one site} other{# sites}}.', [
            'count' => count($siteIds),
        ]));
    }
}

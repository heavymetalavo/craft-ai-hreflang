<?php

namespace extreme\aihreflangdetector\controllers;

use Craft;
use craft\web\Controller;
use extreme\aihreflangdetector\AiHreflangDetector;
use extreme\aihreflangdetector\jobs\DetectLanguages;
use extreme\aihreflangdetector\utilities\AiHreflangDetectorUtility;
use yii\web\Response;

/**
 * Actions of the AI Hreflang Detector utility.
 *
 * @package extreme\aihreflangdetector\controllers
 */
class UtilityController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('utility:' . AiHreflangDetectorUtility::id());

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

        $error = AiHreflangDetector::getInstance()->ai->getConfigurationError();
        if ($error !== null) {
            return $this->asFailure($error);
        }

        $siteId = $this->request->getBodyParam('siteId');
        $sites = Craft::$app->getSites();
        $siteIds = $siteId ? [(int)$siteId] : $sites->getAllSiteIds();
        $force = (bool)$this->request->getBodyParam('force');

        foreach ($siteIds as $id) {
            if ($sites->getSiteById($id) === null) {
                return $this->asFailure(Craft::t('ai-hreflang-detector', 'Site {id} doesn’t exist.', ['id' => $id]));
            }

            Craft::$app->getQueue()->push(new DetectLanguages(['siteId' => $id, 'force' => $force]));
        }

        return $this->asSuccess(Craft::t('ai-hreflang-detector', 'Detection queued for {count, plural, =1{one site} other{# sites}}.', [
            'count' => count($siteIds),
        ]));
    }
}

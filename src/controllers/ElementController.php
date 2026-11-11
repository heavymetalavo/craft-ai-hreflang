<?php

namespace heavymetalavo\aihreflang\controllers;

use Craft;
use craft\web\Controller;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\services\Detector;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Detects the language of a single page, from its element's action menu.
 *
 * @package heavymetalavo\aihreflang\controllers
 */
class ElementController extends Controller
{
    /**
     * Detects the language of a page on one site now, even if its text hasn't changed. It's the published page that's
     * read, as drafts have no public URL. This runs in the request, which fetches the page from the site itself, so
     * the web server must be able to serve another request meanwhile (PHP's built-in server can't).
     *
     * Posted params:
     * - `elementId`: the page's element, or a draft of it, whose canonical element is detected
     * - `siteId`: the site
     */
    public function actionDetect(): ?Response
    {
        $this->requirePostRequest();

        $element = Craft::$app->getElements()->getElementById(
            (int)$this->request->getRequiredBodyParam('elementId'),
            null,
            (int)$this->request->getRequiredBodyParam('siteId'),
            ['drafts' => null, 'provisionalDrafts' => null, 'status' => null],
        );

        if ($element === null) {
            throw new NotFoundHttpException('Element not found');
        }

        $element = $element->getCanonical();

        if (!$element->canSave(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('User not permitted to save this element.');
        }

        $plugin = AiHreflang::getInstance();

        try {
            $result = $plugin->detector->detect($element, true);
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        // Outcomes other than a detection aren't errors, so they're notices too, without reloading the page
        $message = match ($result) {
            Detector::RESULT_CHOSEN => Craft::t('ai-hreflang', 'The language was chosen by an editor, so it isn’t detected.'),
            Detector::RESULT_TOO_SHORT => Craft::t('ai-hreflang', 'The published page has fewer than {count, number} words, so it keeps its site’s hreflang.', [
                'count' => Detector::MINIMUM_WORDS,
            ]),
            Detector::RESULT_NO_PAGE => Craft::t('ai-hreflang', 'The page isn’t live, so it can’t be fetched to detect its language.'),
            Detector::RESULT_UNDETERMINED => Craft::t('ai-hreflang', 'The language of the published page couldn’t be told, so it keeps its site’s hreflang.'),
            default => null,
        };

        if ($message !== null) {
            return $this->asSuccess($message, ['detected' => false]);
        }

        $value = $plugin->detector->getValue($element);
        $alternate = $plugin->hreflang->getAlternates($element)[$element->siteId] ?? null;

        if ($alternate !== null && $alternate['clash'] !== null) {
            return $this->asSuccess(Craft::t('ai-hreflang', 'Detected {language} on the published page. Its hreflang would be {clash}, but {sites} already uses it, so it keeps {hreflang}.', [
                'language' => $value?->getLanguageName(),
                'clash' => $alternate['clash'],
                'sites' => implode(', ', $alternate['clashSites']),
                'hreflang' => $alternate['hreflang'],
            ]), ['detected' => true]);
        }

        return $this->asSuccess(Craft::t('ai-hreflang', 'Detected {language} on the published page, so its hreflang is {hreflang}.', [
            'language' => $value?->getLanguageName(),
            'hreflang' => $alternate['hreflang'] ?? $plugin->hreflang->getHreflang($element->getSite(), $value?->language),
        ]), ['detected' => true]);
    }
}

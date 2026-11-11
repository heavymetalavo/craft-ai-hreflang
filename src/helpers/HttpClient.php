<?php

namespace heavymetalavo\aihreflang\helpers;

use Craft;
use GuzzleHttp\Client;

/**
 * Creates the HTTP clients the plugin sends requests with, to AI providers and to fetch pages.
 *
 * @package heavymetalavo\aihreflang\helpers
 */
class HttpClient
{
    /**
     * Request timeout in seconds, used unless the project sets its own in config/guzzle.php.
     */
    public const TIMEOUT = 30;

    /**
     * Connection timeout in seconds, so an unreachable host fails fast, unless the project sets its own.
     */
    public const CONNECT_TIMEOUT = 10;

    /**
     * A Guzzle client with Craft's settings, and the plugin's timeouts where config/guzzle.php doesn't set them, as
     * the options given here would override the project's.
     */
    public static function create(): Client
    {
        $guzzleConfig = Craft::$app->getConfig()->getConfigFromFile('guzzle');
        $config = [];

        if (!isset($guzzleConfig['timeout'])) {
            $config['timeout'] = self::TIMEOUT;
        }

        if (!isset($guzzleConfig['connect_timeout'])) {
            $config['connect_timeout'] = self::CONNECT_TIMEOUT;
        }

        return Craft::createGuzzleClient($config);
    }
}

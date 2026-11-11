<?php

namespace heavymetalavo\aihreflang\errors;

/**
 * The AI provider settings are incomplete, so no request can be sent. Every page would fail the same way, so bulk
 * detection stops at once.
 *
 * @package heavymetalavo\aihreflang\errors
 */
class ConfigurationException extends AiException
{
}

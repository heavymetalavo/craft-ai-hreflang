<?php

namespace heavymetalavo\aihreflang\variables;

use craft\base\ElementInterface;
use heavymetalavo\aihreflang\AiHreflang;

/**
 * `craft.aiHreflang`, for templates that output hreflang tags themselves rather than with SEOmatic.
 *
 * @package heavymetalavo\aihreflang\variables
 */
class AiHreflangVariable
{
    /**
     * A page's hreflang alternates: its live copy on each site with a URL, with `url`, `hreflang`, `site`, `language`
     * (its Content Language, or null) and `clash` (the hreflang it would have had if another copy didn't have it).
     *
     * ```twig
     * {% for alternate in craft.aiHreflang.alternates(entry) %}
     *   <link rel="alternate" hreflang="{{ alternate.hreflang }}" href="{{ alternate.url }}">
     * {% endfor %}
     * ```
     *
     * @return array<int, array>
     */
    public function alternates(ElementInterface $element): array
    {
        return array_values(AiHreflang::getInstance()->hreflang->getAlternates($element));
    }
}

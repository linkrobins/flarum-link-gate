<?php

namespace LinkRobins\LinkGate\Api;

use Flarum\Api\Serializer\AbstractSerializer;
use LinkRobins\LinkGate\Formatter\SwapGatedLinks;

/**
 * Hangs stage two off the post's contentHtml attribute.
 *
 * This is the one class that differs between release lines. Flarum 1.8 has no
 * API resources, so the swap runs as a serializer attribute mutator instead of
 * a resource field serializer. Everything it calls is shared, because the
 * rendering callback that does the security-critical work has an identical
 * signature on both majors.
 */
class GateContentHtml
{
    public function __construct(
        private SwapGatedLinks $swap
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function __invoke(AbstractSerializer $serializer, mixed $model, array $attributes): array
    {
        if (isset($attributes['contentHtml']) && is_string($attributes['contentHtml'])) {
            $attributes['contentHtml'] = $this->swap->swap($attributes['contentHtml']);
        }

        return $attributes;
    }
}

<?php

namespace LinkRobins\LinkGate\Api;

use LinkRobins\LinkGate\Formatter\SwapGatedLinks;
use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Schema\Field\Field;

/**
 * Hangs stage two off the post's contentHtml field.
 *
 * A serializer runs after the field's own getter, so core keeps its rendering,
 * its try/catch and its renderFailed flag, and this only rewrites the string it
 * produced. Overriding the getter instead would mean reimplementing all three.
 *
 * Flarum 1.8 has no resource fields; its line hangs the same swap off a
 * BasicPostSerializer attribute mutator instead.
 */
class GateContentHtml
{
    public function __construct(
        private readonly SwapGatedLinks $swap
    ) {
    }

    public function __invoke(Field $field): Field
    {
        return $field->serialize(function (mixed $value, Context $context): mixed {
            return is_string($value) ? $this->swap->swap($value) : $value;
        });
    }
}

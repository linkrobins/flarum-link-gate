<?php

namespace LinkRobins\LinkGate\Api;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Post\CommentPost;
use LinkRobins\LinkGate\SourceAccess;

/**
 * Gives the edit form back the source that unparsing now redacts.
 *
 * Redacting on unparse is what keeps gated addresses out of the plain half of
 * notification emails, but it hits every read of `$post->content`, including the
 * one the edit form makes. Core only serialises that attribute for someone who
 * can edit the post, so by the time it is here the reader is entitled to the
 * address, and leaving it redacted would be worse than useless: the author would
 * open their own post, find the wording where their link was, and save it over
 * the top.
 *
 * The 1.8 serializer has already built the attribute by the time a mutator runs,
 * so the value is read again rather than adjusted. The accessor is not cached,
 * so re-reading it inside SourceAccess::permitted() returns the real source.
 *
 * The 2.x line does the same job through an API resource field.
 */
class RevealSourceToEditor
{
    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function __invoke(AbstractSerializer $serializer, mixed $model, array $attributes): array
    {
        if (array_key_exists('content', $attributes) && $model instanceof CommentPost) {
            $attributes['content'] = SourceAccess::permitted(fn () => $model->content);
        }

        return $attributes;
    }
}

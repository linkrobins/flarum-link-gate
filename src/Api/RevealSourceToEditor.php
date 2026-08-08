<?php

namespace LinkRobins\LinkGate\Api;

use Flarum\Post\CommentPost;
use LinkRobins\LinkGate\SourceAccess;
use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Schema\Field\Field;

/**
 * Gives the edit form back the source that unparsing now redacts.
 *
 * Redacting on unparse is what keeps gated addresses out of the plain half of
 * notification emails, but it hits every read of `$post->content`, including
 * this field. Core already gates this field on `can('edit', $post)`, so by the
 * time it is read the reader is entitled to the address, and leaving it
 * redacted would be worse than useless: the author would open their own post to
 * edit it, find the wording where their link was, and save that over the top.
 *
 * Reading inside SourceAccess::permitted() is what tells the unparsing callback
 * this particular read is the authorised one.
 */
class RevealSourceToEditor
{
    public function __invoke(Field $field): Field
    {
        return $field->get(function (CommentPost $post, Context $context): ?string {
            return SourceAccess::permitted(fn () => $post->content);
        });
    }
}

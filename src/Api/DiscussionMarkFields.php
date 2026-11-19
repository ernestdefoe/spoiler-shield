<?php

namespace ErnestDefoe\SpoilerShield\Api;

use ErnestDefoe\SpoilerShield\MarkWriter;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;

/**
 * A discussion's mark as the Redactor reads it (`ssMark`, read from the row, so
 * free), and the attribute the composer writes (`spoilerShield`, handled by the
 * MarkWriter on the Saving event). The Redactor removes ssMark from every
 * response and puts a `spoilerShield` description in its place.
 */
final class DiscussionMarkFields
{
    /** @return array<int, mixed> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('ssMark')
                ->get(fn (Discussion $d) => [
                    $d->spoiler_work,
                    $d->spoiler_position === null ? null : (int) $d->spoiler_position,
                    $d->spoiler_label,
                    $d->user_id === null ? null : (int) $d->user_id,
                    $d->first_post_id === null ? null : (int) $d->first_post_id,
                    $d->last_post_id === null ? null : (int) $d->last_post_id,
                ]),
            Schema\Arr::make(MarkWriter::ATTRIBUTE)
                ->hidden()
                ->writable()
                ->nullable()
                ->set(fn () => null),
        ];
    }
}

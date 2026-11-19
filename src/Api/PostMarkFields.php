<?php

namespace ErnestDefoe\SpoilerShield\Api;

use ErnestDefoe\SpoilerShield\MarkWriter;
use Flarum\Api\Schema;
use Flarum\Post\Post;

/** A post's mark for the Redactor (`ssMark`) and the composer's `spoilerShield`, as for discussions. */
final class PostMarkFields
{
    /** @return array<int, mixed> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('ssMark')
                ->get(fn (Post $p) => [
                    $p->spoiler_position === null ? null : (int) $p->spoiler_position,
                    $p->spoiler_label,
                    $p->user_id === null ? null : (int) $p->user_id,
                    (int) $p->discussion_id,
                    $p->spoiler_work,
                ]),
            Schema\Arr::make(MarkWriter::ATTRIBUTE)
                ->hidden()
                ->writable()
                ->nullable()
                ->set(fn () => null),
        ];
    }
}

<?php

namespace ErnestDefoe\SpoilerShield\Integration;

use Ernestdefoe\Anthology\Chapter;
use Ernestdefoe\Anthology\Series;
use Flarum\Discussion\Discussion;

/**
 * An Anthology chapter's discussion sits at its chapter: added, moved or taken
 * out of a series, the mark follows (Anthology saves chapters as models, so
 * their events fire). A deleted series takes its chapters with it in the
 * database, without events, so its discussions are cleared first.
 */
final class AnthologyChapters
{
    public static function saved(Chapter $chapter): void
    {
        $discussion = Discussion::query()->find($chapter->discussion_id);

        // A discussion marked by hand at a work defined here keeps that.
        if (! $discussion || str_starts_with((string) $discussion->spoiler_work, 'w:')) {
            return;
        }

        $discussion->spoiler_work = 'an:'.(int) $chapter->series_id;
        $discussion->spoiler_position = max(1, (int) $chapter->position);
        $discussion->spoiler_label = null;
        $discussion->save();
    }

    public static function deleted(Chapter $chapter): void
    {
        self::clear(Discussion::query()->whereKey($chapter->discussion_id), 'an:'.(int) $chapter->series_id);
    }

    public static function seriesDeleting(Series $series): void
    {
        self::clear(Discussion::query(), 'an:'.(int) $series->id);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<Discussion> $query */
    private static function clear($query, string $work): void
    {
        $query->where('spoiler_work', $work)->each(function (Discussion $discussion) {
            $discussion->spoiler_work = $discussion->spoiler_position = $discussion->spoiler_label = null;
            $discussion->save();
        });
    }
}

<?php

namespace ErnestDefoe\SpoilerShield;

use ErnestDefoe\SpoilerShield\Integration\AnthologyChapters;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\AbstractServiceProvider;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        // One per request (or job): it remembers which discussions were marked by hand.
        $this->container->singleton(MarkWriter::class);
    }

    public function boot(): void
    {
        Discussion::saving(fn (Discussion $discussion) => $this->container->make(MarkWriter::class)->discussionModelSaving($discussion));

        // Anthology may be absent: only then are its classes missing, and nothing of it ever saves.
        if (class_exists(\Ernestdefoe\Anthology\Chapter::class)) {
            \Ernestdefoe\Anthology\Chapter::saved(fn ($chapter) => AnthologyChapters::saved($chapter));
            \Ernestdefoe\Anthology\Chapter::deleted(fn ($chapter) => AnthologyChapters::deleted($chapter));
        }
        if (class_exists(\Ernestdefoe\Anthology\Series::class)) {
            \Ernestdefoe\Anthology\Series::deleting(fn ($series) => AnthologyChapters::seriesDeleting($series));
        }
    }
}

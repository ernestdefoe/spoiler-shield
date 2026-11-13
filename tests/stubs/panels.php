<?php

// What Spoiler Shield uses of ernestdefoe/panels, for PHPStan: Panels isn't on
// Packagist yet, so it isn't a dev dependency. Never loaded at runtime.

namespace ErnestDefoe\Panels\Model;

/**
 * @property int $id
 * @property int $series_id
 * @property string $number
 * @property int|null $discussion_id
 */
class Chapter extends \Flarum\Database\AbstractModel
{
}

/**
 * @property int $id
 * @property string $title
 */
class Series extends \Flarum\Database\AbstractModel
{
}

namespace ErnestDefoe\Panels;

class Access
{
    /** @param \Illuminate\Database\Eloquent\Builder<\ErnestDefoe\Panels\Model\Series> $query */
    public function scopeSeries(\Illuminate\Database\Eloquent\Builder $query, \Flarum\User\User $actor): void
    {
    }
}

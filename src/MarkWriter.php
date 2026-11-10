<?php

namespace ErnestDefoe\SpoilerShield;

use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Saving as DiscussionSaving;
use Flarum\Foundation\ValidationException;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving as PostSaving;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Symfony\Contracts\Translation\TranslatorInterface;
use WeakMap;

/**
 * Puts the marks on discussions and posts, all on the row itself:
 *
 *  - a discussion marked by its author (or anyone who may rename it), else
 *    linked by Marquee to an episode or season, else in an Anthology series,
 *    else in a tag attached to a work (the work only, no position);
 *  - a reply inside a positioned discussion, stamped with its author's own
 *    position when that's further along, unless they chose otherwise.
 *
 * A positioned discussion's slug becomes its position ("s2e5"), so its title
 * never reaches a URL: links, sitemaps, emails.
 */
final class MarkWriter
{
    public const ATTRIBUTE = 'spoilerShield';

    /** @var WeakMap<Discussion, true> discussions marked by hand in this request (Marquee doesn't overrule them). */
    private WeakMap $explicit;

    public function __construct(
        private readonly Ladders $ladders,
        private readonly Positions $positions,
        private readonly Integrations $integrations,
        private readonly ConnectionInterface $db,
        private readonly SettingsRepositoryInterface $settings,
        private readonly TranslatorInterface $translator,
    ) {
        $this->explicit = new WeakMap();
    }

    public function discussionSaving(DiscussionSaving $event): void
    {
        $discussion = $event->discussion;
        $attributes = (array) ($event->data['attributes'] ?? []);

        if (array_key_exists(self::ATTRIBUTE, $attributes)) {
            if ($discussion->exists) {
                $event->actor->assertCan('rename', $discussion);
            }

            $value = $attributes[self::ATTRIBUTE];
            $this->explicit[$discussion] = true;

            if ($value === null) {
                $discussion->spoiler_work = $discussion->spoiler_position = $discussion->spoiler_label = null;

                return;
            }

            $work = is_array($value) ? ($value['work'] ?? null) : null;
            $position = is_array($value) && isset($value['position']) ? (int) $value['position'] : null;

            if (! Marks::parse($work) || str_starts_with((string) $work, 'an:')) {
                $this->invalid('work');
            }
            if ($position !== null) {
                $ladder = $this->ladders->get($work) ?? $this->invalid('work');
                Ladders::valid($ladder, $position, false) || $this->invalid('position');
            } elseif (! $this->ladders->get($work)) {
                $this->invalid('work');
            }

            $discussion->spoiler_work = $work;
            $discussion->spoiler_position = $position;
            $discussion->spoiler_label = $position === null ? null : $this->storedLabel($work, $position);

            return;
        }

        // A new discussion in a tag attached to a work belongs to it.
        if (! $discussion->exists && $discussion->spoiler_work === null && $this->integrations->tags()) {
            $tagIds = array_map(fn ($t) => (int) ($t['id'] ?? 0), (array) ($event->data['relationships']['tags']['data'] ?? []));
            if ($work = $this->workForTags($tagIds)) {
                $discussion->spoiler_work = $work;
            }
        }
    }

    /** A discussion whose tags changed later: it joins a tag's work if it has none. */
    public function retagged(Discussion $discussion): void
    {
        if ($discussion->spoiler_work !== null) {
            return;
        }

        if ($work = $this->workForTags($this->db->table('discussion_tag')->where('discussion_id', $discussion->id)->pluck('tag_id')->map(fn ($id) => (int) $id)->all())) {
            $discussion->spoiler_work = $work;
            $discussion->save();
        }
    }

    /**
     * Eloquent's `saving`, so it sees every save: Marquee's link (set by its
     * own API fields, before the save) and the slug.
     */
    public function discussionModelSaving(Discussion $discussion): void
    {
        if (! isset($this->explicit[$discussion])
            && $this->integrations->marquee()
            && $discussion->isDirty(['marquee_media_id', 'marquee_season', 'marquee_episode'])
            && ($discussion->spoiler_work === null || str_starts_with((string) $discussion->spoiler_work, 'mq:'))) {
            $media = $discussion->getAttribute('marquee_media_id');

            if ($media) {
                $discussion->spoiler_work = 'mq:'.(int) $media;
                $discussion->spoiler_position = Marks::marqueePosition($discussion->getAttribute('marquee_season'), $discussion->getAttribute('marquee_episode'));
            } else {
                $discussion->spoiler_work = $discussion->spoiler_position = null;
            }
            $discussion->spoiler_label = null;
        }

        $this->slug($discussion);
    }

    public function slug(Discussion $discussion): void
    {
        if (! $discussion->isDirty(['spoiler_position', 'spoiler_work', 'spoiler_label', 'slug', 'title'])) {
            return;
        }

        if ($discussion->spoiler_position !== null && $discussion->spoiler_work !== null) {
            $discussion->slug = Marks::slugFor($discussion->spoiler_work, (int) $discussion->spoiler_position, $discussion->spoiler_label);
        } elseif ($discussion->getOriginal('spoiler_position') !== null && $discussion->title) {
            $discussion->slug = Str::slug($discussion->title);
        }
    }

    public function postSaving(PostSaving $event): void
    {
        $post = $event->post;
        $discussion = $post->discussion;

        if (! $post instanceof CommentPost || ! $discussion) {
            return;
        }

        // The first post is the discussion's own, and shares its mark.
        if ($discussion->first_post_id === null || (int) $discussion->first_post_id === (int) $post->id) {
            return;
        }

        $attributes = (array) ($event->data['attributes'] ?? []);
        $floor = $discussion->spoiler_position === null ? -1 : (int) $discussion->spoiler_position;

        if (array_key_exists(self::ATTRIBUTE, $attributes)) {
            if ($post->exists) {
                $event->actor->assertCan('edit', $post);
            }
            if ($discussion->spoiler_work === null) {
                return;
            }

            $value = $attributes[self::ATTRIBUTE];
            $position = is_array($value) && isset($value['position']) ? (int) $value['position'] : null;

            if ($position !== null) {
                $ladder = $this->ladders->get($discussion->spoiler_work) ?? $this->invalid('work');
                Ladders::valid($ladder, $position, true) || $this->invalid('position');
            }

            // Nothing past the discussion's own position: no mark needed.
            $position = $position !== null && $position > $floor ? $position : null;
            $post->spoiler_position = $position;
            $post->spoiler_label = $position === null ? null : $this->storedLabel($discussion->spoiler_work, $position);

            return;
        }

        if (! $post->exists && $discussion->spoiler_work !== null && $discussion->spoiler_position !== null
            && $this->settings->get('ernestdefoe-spoiler-shield.stamp_replies')) {
            $mine = $this->positions->read($event->actor, $discussion->spoiler_work);

            if ($mine !== null && $mine > $floor) {
                $post->spoiler_position = $mine;
                $post->spoiler_label = $this->storedLabel($discussion->spoiler_work, $mine);
            }
        }
    }

    /** Only works defined here keep their step's name on the row; the others are made from the number. */
    public function storedLabel(string $work, int $position): ?string
    {
        if (! str_starts_with($work, 'w:') || $position === Marks::FINISHED) {
            return null;
        }

        $steps = Ladders::steps($this->db->table('spoiler_works')->where('id', (int) substr($work, 2))->value('steps'));

        return isset($steps[$position - 1]) ? mb_substr($steps[$position - 1], 0, 64) : null;
    }

    /** @param int[] $tagIds */
    private function workForTags(array $tagIds): ?string
    {
        $tagIds = array_filter($tagIds);
        if (! $tagIds) {
            return null;
        }

        $work = $this->db->table('spoiler_work_tags')->whereIn('tag_id', $tagIds)->orderBy('tag_id')->value('work_id');

        return $work ? 'w:'.$work : null;
    }

    private function invalid(string $what): never
    {
        throw new ValidationException([self::ATTRIBUTE => $this->translator->trans('ernestdefoe-spoiler-shield.api.invalid_'.$what)]);
    }
}

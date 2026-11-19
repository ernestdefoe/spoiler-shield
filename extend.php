<?php

/*
 * Spoiler Shield: discussions and posts gated to each member's progress
 * through a book, show or game.
 *
 * Marks live on the rows (discussions.spoiler_work/position, posts.spoiler_position),
 * put there by the MarkWriter: by hand from the composer, from Marquee's
 * episode link, from Anthology's chapters, from a tag attached to a work, and
 * on every reply in a positioned discussion (its author's own position).
 *
 * The Gate decides who may read what; nothing else does. Every API response
 * passes through RedactApi (Flarum's internal API client runs it too, so the
 * page's preload, title and no-JS content, and realtime pushes, are covered),
 * ScrubDocument cleans the page's head and links after SEO extensions, and
 * the email driver (wrapped in Provider::boot) leaves out recipients an email would spoil. Hidden words
 * never leave the server unless the member reveals them.
 */

use ErnestDefoe\SpoilerShield\Api;
use ErnestDefoe\SpoilerShield\Http\RedactApi;
use ErnestDefoe\SpoilerShield\Integration;
use ErnestDefoe\SpoilerShield\Http\ScrubDocument;
use ErnestDefoe\SpoilerShield\MarkWriter;
use ErnestDefoe\SpoilerShield\Provider;
use ErnestDefoe\SpoilerShield\Search\HiddenFromSearch;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Saving as DiscussionSaving;
use Flarum\Discussion\UserState;
use Flarum\Extend;
use Flarum\Post\Event\Saving as PostSaving;
use Flarum\Post\Post;
use Flarum\Tags\Event\DiscussionWasTagged;
use Flarum\User\User;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/spoilers', 'spoiler-shield')
        // Last, after SEO and link-preview extensions wrote the page's head.
        ->content(ScrubDocument::class, -1000),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ServiceProvider())->register(Provider::class),

    (new Extend\Middleware('api'))->add(RedactApi::class),

    // Only while a request searches by words (RedactApi opens that window): hidden items can't match.
    (new Extend\ModelVisibility(Discussion::class))
        ->scope(fn (User $actor, $query) => resolve(HiddenFromSearch::class)->discussions($actor, $query)),
    (new Extend\ModelVisibility(Post::class))
        ->scope(fn (User $actor, $query) => resolve(HiddenFromSearch::class)->posts($actor, $query)),

    (new Extend\Routes('api'))
        ->post('/spoiler-shield/reveals', 'spoiler-shield.reveal', Api\RevealController::class)
        ->get('/spoiler-shield/progress', 'spoiler-shield.progress', Api\ProgressController::class)
        ->post('/spoiler-shield/progress', 'spoiler-shield.progress.save', Api\ProgressController::class)
        ->get('/spoiler-shield/works', 'spoiler-shield.works', Api\WorksController::class)
        ->post('/spoiler-shield/works', 'spoiler-shield.works.create', Api\WorksController::class)
        ->get('/spoiler-shield/works/{key:(?:w|mq|an|pn):\d+}', 'spoiler-shield.works.show', Api\WorksController::class)
        ->patch('/spoiler-shield/works/{key:\d+}', 'spoiler-shield.works.update', Api\WorksController::class)
        ->delete('/spoiler-shield/works/{key:\d+}', 'spoiler-shield.works.delete', Api\WorksController::class),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(Api\DiscussionMarkFields::class),

    (new Extend\ApiResource(PostResource::class))
        ->fields(Api\PostMarkFields::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    (new Extend\Event())
        ->listen(DiscussionSaving::class, [MarkWriter::class, 'discussionSaving'])
        ->listen(PostSaving::class, [MarkWriter::class, 'postSaving']),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())->addType(\ErnestDefoe\SpoilerShield\Gdpr\SpoilerData::class),
        ])
        // fof/split moves posts with a bulk UPDATE, past the models' saving hooks.
        ->whenExtensionEnabled('fof-split', fn () => [
            (new Extend\Event())->listen('FoF\\Split\\Events\\DiscussionWasSplit', [MarkWriter::class, 'split']),
        ])
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\Event())->listen(DiscussionWasTagged::class, fn (DiscussionWasTagged $event) => resolve(MarkWriter::class)->retagged($event->discussion)),
        ])
        ->whenExtensionEnabled('flarum-realtime', fn () => [
            (new Extend\ServiceProvider())->register(Integration\RealtimeProvider::class),
        ])
        // An Anthology chapter's discussion follows the chapter (Anthology saves chapters as models).
        ->whenExtensionEnabled('ernestdefoe-anthology', fn () => [
            (new Extend\Event())
                ->listen('eloquent.saved: '.\Ernestdefoe\Anthology\Chapter::class, fn ($chapter) => Integration\AnthologyChapters::saved($chapter))
                ->listen('eloquent.deleted: '.\Ernestdefoe\Anthology\Chapter::class, fn ($chapter) => Integration\AnthologyChapters::deleted($chapter))
                ->listen('eloquent.deleting: '.\Ernestdefoe\Anthology\Series::class, fn ($series) => Integration\AnthologyChapters::seriesDeleting($series))
                ->listen('eloquent.saving: '.UserState::class, fn (UserState $state) => Integration\AnthologyChapters::reading($state)),
        ])
        // A Panels chapter's discussion follows the chapter too.
        ->whenExtensionEnabled('ernestdefoe-panels', fn () => [
            (new Extend\Event())
                ->listen('eloquent.saved: '.\ErnestDefoe\Panels\Model\Chapter::class, fn ($chapter) => Integration\PanelsChapters::saved($chapter))
                ->listen('eloquent.deleted: '.\ErnestDefoe\Panels\Model\Chapter::class, fn ($chapter) => Integration\PanelsChapters::deleted($chapter)),
        ]),

    // String literals, not class constants: extend.php runs at boot, and a class
    // lookup here fatals every request on a worker whose autoload map is stale mid-install.
    (new Extend\Settings())
        ->default('ernestdefoe-spoiler-shield.members', 'blur')
        ->default('ernestdefoe-spoiler-shield.guests', 'blur')
        ->default('ernestdefoe-spoiler-shield.stamp_replies', true),
];

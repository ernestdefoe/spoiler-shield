<?php

namespace ErnestDefoe\SpoilerShield\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\SpoilerShield\Marks;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Frontend\Document;
use Flarum\Locale\LocaleManager;
use Flarum\Notification\NotificationMailer;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The gate on its own: a work defined on the forum, members at different
 * places in it. The proof is in the raw bytes: the hidden words appear in no
 * response a reader who is behind can get.
 */
class GateTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const TITLE = 'Kael betrays the guild';
    private const BODY = 'Mira was the masked archer all along';
    private const REPLY = 'The ending in the final chapter made me cry';
    private const RENAMED = 'Who is the masked archer';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-mentions', 'ernestdefoe-spoiler-shield');

        // An SEO extension describing a discussion's page from the database, as fof/seo and og-image do.
        $this->extend((new Extend\Frontend('forum'))->content(function (Document $document, ServerRequestInterface $request) {
            if ($request->getAttribute('routeName') !== 'discussion') {
                return;
            }
            $d = Discussion::query()->find((int) $request->getAttribute('routeParameters')['id']);
            $document->head[] = '<meta property="og:description" content="'.e($d->title).' '.e($d->firstPost?->content).'">';
            $document->head[] = '<script type="application/ld+json">{"headline":"'.e($d->title).'"}</script>';
            $document->meta['description'] = 'About '.$d->title;
        }));

        $now = Carbon::now()->subHour();
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'bea', 'email' => 'bea@machine.local', 'is_email_confirmed' => 1, 'preferences' => json_encode(['notify_postMentioned_email' => true])],
                ['id' => 4, 'username' => 'ash', 'email' => 'ash@machine.local', 'is_email_confirmed' => 1, 'preferences' => json_encode(['notify_postMentioned_email' => true])],
                ['id' => 5, 'username' => 'una', 'email' => 'una@machine.local', 'is_email_confirmed' => 1],
            ],
            'spoiler_works' => [
                ['id' => 1, 'title' => 'The Long Road', 'kind' => 'book', 'steps' => json_encode(['Ch 1', 'Ch 2', 'Ch 3', 'Ch 4', 'Ch 5']), 'created_at' => $now, 'updated_at' => $now],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => self::TITLE, 'slug' => 'ch-4', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'first_post_id' => 1, 'last_post_id' => 1, 'last_post_number' => 1, 'spoiler_work' => 'w:1', 'spoiler_position' => 4, 'spoiler_label' => 'Ch 4'],
                ['id' => 2, 'title' => 'Opening chapters chat', 'slug' => 'ch-1', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 3, 'first_post_id' => 2, 'last_post_id' => 4, 'last_post_number' => 3, 'spoiler_work' => 'w:1', 'spoiler_position' => 1, 'spoiler_label' => 'Ch 1'],
                ['id' => 3, 'title' => 'Book club general', 'slug' => 'book-club-general', 'user_id' => 2, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'first_post_id' => 5, 'last_post_id' => 5, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>'.self::BODY.'</p></t>'],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>Who else loved the first chapter?</p></t>'],
                ['id' => 3, 'discussion_id' => 2, 'number' => 2, 'user_id' => 4, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>'.self::REPLY.'</p></t>', 'spoiler_position' => 5, 'spoiler_label' => 'Ch 5'],
                ['id' => 4, 'discussion_id' => 2, 'number' => 3, 'user_id' => 4, 'type' => 'discussionRenamed', 'created_at' => $now, 'content' => json_encode(['Old', self::RENAMED]), 'spoiler_position' => 5, 'spoiler_label' => 'Ch 5'],
                ['id' => 5, 'discussion_id' => 3, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'created_at' => $now, 'content' => '<r><p>See <URL url="http://localhost/d/1-ch-4">'.self::TITLE.'</URL></p></r>'],
            ],
            'spoiler_progress' => [
                ['user_id' => 3, 'work' => 'w:1', 'position' => 2, 'updated_at' => $now],
                ['user_id' => 4, 'work' => 'w:1', 'position' => Marks::FINISHED, 'updated_at' => $now],
            ],
        ]);
    }

    /** @return array{int, mixed, string} */
    private function api(string $method, string $path, ?int $as, array $body = [], array $query = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $request = $this->request($method, $path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($request);
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    /** Every place a discussion or post can be read, as raw bytes. */
    private function everything(?int $as): string
    {
        $out = '';
        foreach ([
            ['/api/discussions', []],
            ['/api/discussions', ['filter' => ['q' => 'Kael masked archer']]],
            ['/api/discussions/1', []],
            ['/api/discussions/2', []],
            ['/api/discussions/3', []],
            ['/api/posts', ['filter' => ['discussion' => '2']]],
            ['/api/posts/1', []],
            ['/api/posts/3', []],
            ['/api/posts/4', []],
            ['/api/users/4', []],
            ['/api/notifications', []],
            ['/', []],
            ['/d/1', []],
            ['/d/2', []],
            ['/d/3', []],
        ] as [$path, $query]) {
            if ($path === '/api/notifications' && ! $as) {
                continue;
            }
            [$status, , $raw] = $this->api('GET', $path, $as, [], $query);
            $out .= "\n--- $path ($status)\n".$raw;
        }

        return $out;
    }

    #[Test]
    public function hidden_words_never_reach_a_reader_who_is_behind(): void
    {
        // An alert for the reader who is behind, about the spoiling reply.
        $this->app();
        $this->database()->table('notifications')->insert([
            'user_id' => 3, 'from_user_id' => 4, 'type' => 'postMentioned', 'subject_id' => 3,
            'data' => json_encode(['replyNumber' => 2]), 'created_at' => Carbon::now(),
        ]);

        // Bea is at chapter 2; Una never said (members are blurred by default); and a guest.
        foreach ([3, 5, null] as $as) {
            $all = $this->everything($as);

            foreach ([self::TITLE, self::BODY, self::REPLY, self::RENAMED] as $secret) {
                foreach (explode("\n--- ", $all) as $page) {
                    $where = strtok($page, "\n");
                    $this->assertStringNotContainsString($secret, $page, $secret.' leaked to '.($as ?? 'a guest').' on '.$where);
                    $this->assertStringNotContainsString(htmlspecialchars($secret, ENT_QUOTES), $page, $secret.' leaked on '.$where);
                }
            }
            $this->assertStringContainsString('"label":"Ch 4"', $all);
            $this->assertStringNotContainsString('ssMark', $all);
        }

        // Ash finished the book: everything is there for her.
        $all = $this->everything(4);
        foreach ([self::TITLE, self::BODY, self::REPLY, self::RENAMED] as $secret) {
            $this->assertStringContainsString($secret, $all);
        }
    }

    #[Test]
    public function the_api_says_what_is_hidden_and_keeps_the_shape(): void
    {
        [, $doc] = $this->api('GET', '/api/discussions/2', 3);

        $shield = $doc['data']['attributes']['spoilerShield'];
        $this->assertSame('w:1', $shield['work']);
        $this->assertSame('The Long Road', $shield['title']);
        $this->assertFalse($shield['hidden'], 'Bea is past chapter 1');
        $this->assertSame(['position' => 2, 'label' => 'Ch 2'], $shield['viewer']);

        $posts = collect($this->api('GET', '/api/posts', 3, [], ['filter' => ['discussion' => '2']])[1]['data'])->keyBy('id');
        $this->assertTrue($posts['3']['attributes']['spoilerShield']['hidden']);
        $this->assertSame('Ch 5', $posts['3']['attributes']['spoilerShield']['label']);
        $this->assertStringContainsString('SpoilerShield-placeholder', $posts['3']['attributes']['contentHtml']);
        // An event post keeps the shape the page draws it from.
        $this->assertCount(2, $posts['4']['attributes']['content']);
        $this->assertArrayNotHasKey('spoilerShield', $posts['2']['attributes'], 'nothing past the discussion');
        $this->assertSame('1-ch-4', $this->api('GET', '/api/discussions/1', 3)[1]['data']['attributes']['slug']);
    }

    #[Test]
    public function the_author_always_sees_their_own(): void
    {
        $this->app();
        $this->database()->table('spoiler_progress')->where('user_id', 4)->delete();

        [, , $raw] = $this->api('GET', '/api/discussions/1', 4);
        $this->assertStringContainsString(self::TITLE, $raw);
        $this->assertStringContainsString(self::BODY, $raw);
    }

    #[Test]
    public function revealing_one_item_shows_that_one_and_is_remembered(): void
    {
        [$status, , $raw] = $this->api('POST', '/api/spoiler-shield/reveals', 3, ['type' => 'discussions', 'id' => 1]);
        $this->assertSame(200, $status);
        $this->assertStringContainsString(self::TITLE, $raw);
        $this->assertStringContainsString(self::BODY, $raw);

        [, , $raw] = $this->api('GET', '/api/discussions', 3);
        $this->assertStringContainsString(self::TITLE, $raw, 'remembered');

        // The reply further on in another discussion is still hidden.
        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 3)[2]);

        // Una can't reveal what she can't see at all.
        $this->assertSame(404, $this->api('POST', '/api/spoiler-shield/reveals', 5, ['type' => 'posts', 'id' => 999])[0]);
    }

    #[Test]
    public function reaching_the_position_opens_it(): void
    {
        $this->assertSame(422, $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'w:1', 'position' => 6])[0]);
        [$status, $doc] = $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'w:1', 'position' => 4]);
        $this->assertSame(200, $status);
        $this->assertSame('Ch 4', $doc['data']['label']);

        $raw = $this->api('GET', '/api/discussions/1', 3)[2];
        $this->assertStringContainsString(self::TITLE, $raw);
        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 3)[2], 'chapter 5 still ahead');

        $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'w:1', 'position' => 'finished']);
        $this->assertStringContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 3)[2]);
    }

    #[Test]
    public function admins_can_show_everything_to_members_who_never_said(): void
    {
        $this->setting('ernestdefoe-spoiler-shield.members', 'show');

        $this->assertStringContainsString(self::TITLE, $this->api('GET', '/api/discussions', 5)[2]);
        $this->assertStringNotContainsString(self::TITLE, $this->api('GET', '/api/discussions', null)[2], 'guests are still blurred');
        $this->assertStringNotContainsString(self::TITLE, $this->api('GET', '/api/discussions', 3)[2], 'members who said are gated');
    }

    #[Test]
    public function replies_are_stamped_with_their_authors_position(): void
    {
        [$status] = $this->api('POST', '/api/posts', 4, ['data' => ['type' => 'posts', 'attributes' => ['content' => 'What a twist at the very end'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]]);
        $this->assertSame(201, $status);

        $post = Post::query()->where('discussion_id', 2)->orderByDesc('id')->first();
        $this->assertSame(Marks::FINISHED, (int) $post->spoiler_position);
        $this->assertStringNotContainsString('very end', $this->api('GET', '/api/posts/'.$post->id, 3)[2]);

        // Choosing "nothing past the discussion" in the composer (an hour later: flood control).
        $post->created_at = Carbon::now()->subHour();
        $post->save();
        $this->api('POST', '/api/posts', 4, ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Welcome, new readers', 'spoilerShield' => null], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]]);
        $this->assertNull(Post::query()->where('discussion_id', 2)->orderByDesc('id')->first()->spoiler_position);
        $this->assertStringContainsString('Welcome, new readers', $this->api('GET', '/api/discussions/2', 3)[2]);
    }

    #[Test]
    public function a_new_discussion_is_marked_from_the_composer_and_its_slug_hides_the_title(): void
    {
        $create = fn (array $mark) => $this->api('POST', '/api/discussions', 2, ['data' => ['type' => 'discussions', 'attributes' => [
            'title' => 'Did the bridge collapse kill him', 'content' => 'I need to know', 'spoilerShield' => $mark,
        ]]]);

        $this->assertSame(422, $create(['work' => 'w:1', 'position' => 9])[0]);
        $this->assertSame(422, $create(['work' => 'w:99', 'position' => 1])[0]);

        [$status, $doc] = $create(['work' => 'w:1', 'position' => 3]);
        $this->assertSame(201, $status);
        $discussion = Discussion::query()->find($doc['data']['id']);
        $this->assertSame('w:1', $discussion->spoiler_work);
        $this->assertSame(3, (int) $discussion->spoiler_position);
        $this->assertSame('ch-3', $discussion->slug);
        $this->assertNull(Post::query()->find($discussion->first_post_id)->spoiler_position, 'the first post shares the discussion\'s mark');

        $this->assertStringNotContainsString('bridge collapse', $this->api('GET', '/api/discussions', 5)[2]);

        // Unmarked later by someone who may rename it: the title's slug is back.
        $this->assertSame(403, $this->api('PATCH', '/api/discussions/'.$discussion->id, 3, ['data' => ['type' => 'discussions', 'id' => (string) $discussion->id, 'attributes' => ['spoilerShield' => null]]])[0]);
        $this->api('PATCH', '/api/discussions/'.$discussion->id, 1, ['data' => ['type' => 'discussions', 'id' => (string) $discussion->id, 'attributes' => ['spoilerShield' => null]]]);
        $this->assertSame('did-the-bridge-collapse-kill-him', $discussion->fresh()->slug);
    }

    #[Test]
    public function emails_skip_the_readers_they_would_spoil(): void
    {
        $sent = [];
        $this->app()->getContainer()->instance(NotificationMailer::class, new class($sent) extends NotificationMailer {
            public function __construct(private array &$sent)
            {
            }

            public function send($blueprint, User $user): void
            {
                $this->sent[] = $user->id;
            }
        });

        // Ash's spoiling reply mentions a post of Bea's and one of Una's... Bea and Ash are told.
        $reply = Post::query()->find(3);
        $mentioned = Post::query()->find(2);
        $this->app()->getContainer()->make(NotificationSyncer::class)->sync(
            new \Flarum\Mentions\Notification\PostMentionedBlueprint($mentioned, $reply),
            [User::query()->find(3), User::query()->find(4)]
        );

        $this->assertSame([4], $sent, 'no email to Bea, who is at chapter 2');
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 3)->count(), 'her alert still arrives, gated');
    }

    #[Test]
    public function works_are_defined_by_members_and_edited_by_their_owner(): void
    {
        [$status, $doc] = $this->api('POST', '/api/spoiler-shield/works', 2, ['title' => 'Night Shift', 'kind' => 'show', 'steps' => ['Pilot', 'Episode 2', '']]);
        $this->assertSame(200, $status);
        $this->assertSame(['Pilot', 'Episode 2'], $doc['data']['ladder']['steps']);
        $id = (int) substr($doc['data']['work'], 2);

        $this->assertSame(403, $this->api('PATCH', "/api/spoiler-shield/works/$id", 3, ['title' => 'Mine now', 'steps' => ['A']])[0]);
        $this->assertSame(200, $this->api('PATCH', "/api/spoiler-shield/works/$id", 2, ['title' => 'Night Shift', 'steps' => ['Pilot', 'Episode 2', 'Episode 3']])[0]);

        // A step in use can't disappear.
        $this->assertSame(422, $this->api('PATCH', '/api/spoiler-shield/works/1', 1, ['title' => 'The Long Road', 'steps' => ['Ch 1', 'Ch 2']])[0]);

        $found = $this->api('GET', '/api/spoiler-shield/works', 3, [], ['q' => 'night'])[1]['data'];
        $this->assertSame('Night Shift', $found[0]['title']);
    }

    #[Test]
    public function a_search_does_not_confirm_a_guess(): void
    {
        $ids = fn (?int $as) => array_column($this->api('GET', '/api/discussions', $as, [], ['filter' => ['q' => 'Kael']])[1]['data'], 'id');

        $this->assertNotContains('1', $ids(3), 'behind: not even a blurred hit');
        $this->assertNotContains('1', $ids(null));
        $this->assertContains('1', $ids(4));
    }

    #[Test]
    public function a_works_name_is_escaped_where_the_page_head_is_rewritten(): void
    {
        // Another extension printing discussion titles into the head of every page.
        $this->extend((new Extend\Frontend('forum'))->content(function (Document $document) {
            foreach (Discussion::query()->pluck('title') as $title) {
                $document->head[] = '<meta name="x-titles" content="'.e($title).'">';
                $document->head[] = '<script type="application/ld+json">'.json_encode(['name' => $title]).'</script>';
            }
        }));

        $this->app();
        // The harness doesn't load extension locales; the placeholder must carry the work's name here.
        $this->app()->getContainer()->make(LocaleManager::class)->addTranslations('en', __DIR__.'/../../../locale/en.yml');
        $now = Carbon::now()->subHour();
        $this->database()->table('spoiler_works')->insert(['id' => 2, 'title' => '<img src=x onerror=alert(1)>', 'kind' => 'book', 'steps' => json_encode(['One', 'Two']), 'created_at' => $now, 'updated_at' => $now]);
        $this->database()->table('discussions')->where('id', 1)->update(['title' => "Kael's betrayal of the guild", 'spoiler_work' => 'w:2', 'spoiler_position' => 2, 'spoiler_label' => 'Two']);

        $raw = $this->api('GET', '/', 3)[2];
        $this->assertStringNotContainsString('<img src=x', $raw);
        $this->assertStringNotContainsString('betrayal of the guild', $raw);
        $this->assertStringContainsString('content="Spoiler for Two (&lt;img src=x onerror=alert(1)&gt;)"', $raw);
    }

    #[Test]
    public function another_extensions_json_listing_discussions_is_gated_too(): void
    {
        // Like Page Builder's widgets: plain JSON from the database, links and titles.
        $this->extend((new Extend\Routes('api'))->get('/test-widgets', 'test.widgets', WidgetController::class));

        foreach ([3, null] as $as) {
            [$status, $doc, $raw] = $this->api('GET', '/api/test-widgets', $as);
            $this->assertSame(200, $status);
            $this->assertStringNotContainsString(self::TITLE, $raw);
            $this->assertStringNotContainsString(self::BODY, $raw);
            $items = collect($doc['widgets'][0]['items'])->keyBy('id');
            $this->assertSame('http://localhost/d/1-ch-4', $items[1]['url']);
            $this->assertSame('Book club general', $items[3]['title'], 'the rest untouched');
        }

        $this->assertStringContainsString(self::TITLE, $this->api('GET', '/api/test-widgets', 4)[2]);
    }

    #[Test]
    public function another_extensions_email_driver_is_wrapped_not_replaced(): void
    {
        // Like Courier: an extension that puts its own email driver in core's place.
        RecordingEmailDriver::$sent = [];
        $this->extend((new Extend\Notification())->driver('email', RecordingEmailDriver::class));
        $this->app();

        $this->app()->getContainer()->make(NotificationSyncer::class)->sync(
            new \Flarum\Mentions\Notification\PostMentionedBlueprint(Post::query()->find(2), Post::query()->find(3)),
            [User::query()->find(3), User::query()->find(4)]
        );

        $this->assertSame([4], RecordingEmailDriver::$sent, 'its driver still sends, to Ash only');
    }
}

class WidgetController implements \Psr\Http\Server\RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $items = Discussion::query()->orderBy('id')->get()->map(fn ($d) => [
            'id' => $d->id, 'title' => $d->title, 'url' => 'http://localhost/d/'.$d->id.'-'.$d->slug, 'excerpt' => $d->firstPost?->content,
        ]);

        return new \Laminas\Diactoros\Response\JsonResponse(['widgets' => [['type' => 'recent', 'items' => $items->all()]]]);
    }
}

class RecordingEmailDriver implements \Flarum\Notification\Driver\NotificationDriverInterface
{
    /** @var int[] */
    public static array $sent = [];

    public function send(\Flarum\Notification\Blueprint\BlueprintInterface $blueprint, array $users): void
    {
        foreach ($users as $user) {
            self::$sent[] = (int) $user->id;
        }
    }

    public function registerType(string $blueprintClass, array $driversEnabledByDefault): void
    {
    }
}

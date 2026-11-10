<?php

namespace ErnestDefoe\SpoilerShield\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Anthology\Chapter;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The works other extensions bring: a Marquee show (episodes from its data),
 * an Anthology series (progress from what the member opened) and Curio's
 * "finished: unlock everything" offer.
 */
class IntegrationsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const EPISODE = 'The dragon burns the harbor city';
    private const SEASON = 'Queen Alys dies in the finale';
    private const CHAPTER = 'Starfall chapter three: the comet hits';
    private const REPLY = 'Chapter three made me gasp at the comet';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-marquee', 'ernestdefoe-anthology', 'ernestdefoe-curio', 'ernestdefoe-spoiler-shield');

        $then = Carbon::now()->subHour();
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'bea', 'email' => 'bea@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'ash', 'email' => 'ash@machine.local', 'is_email_confirmed' => 1],
            ],
            'marquee_media' => [
                ['id' => 1, 'kind' => 'tv', 'external_id' => '1399', 'title' => 'Dragon Throne', 'year' => 2011, 'fetched_at' => $then,
                    'data' => json_encode(['seasons' => [['number' => 1, 'episodes' => 10], ['number' => 2, 'episodes' => 10]]])],
            ],
            'anthology_series' => [
                ['id' => 1, 'user_id' => 4, 'title' => 'Starfall', 'slug' => 'starfall', 'last_chapter_at' => $then, 'created_at' => $then, 'updated_at' => $then],
            ],
            Discussion::class => [
                ['id' => 20, 'title' => 'Starfall chapter one', 'slug' => 'starfall-1', 'user_id' => 4, 'created_at' => $then, 'last_posted_at' => $then, 'comment_count' => 1, 'first_post_id' => 20, 'last_post_number' => 1],
                ['id' => 21, 'title' => 'Starfall chapter two', 'slug' => 'starfall-2', 'user_id' => 4, 'created_at' => $then, 'last_posted_at' => $then, 'comment_count' => 2, 'first_post_id' => 21, 'last_post_number' => 2],
                ['id' => 22, 'title' => self::CHAPTER, 'slug' => 'starfall-3', 'user_id' => 4, 'created_at' => $then, 'last_posted_at' => $then, 'comment_count' => 1, 'first_post_id' => 22, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 20, 'discussion_id' => 20, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $then, 'content' => '<t><p>One</p></t>'],
                ['id' => 21, 'discussion_id' => 21, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $then, 'content' => '<t><p>Two</p></t>'],
                ['id' => 22, 'discussion_id' => 22, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $then, 'content' => '<t><p>Three</p></t>'],
            ],
            // Bea opened chapter one; Ash read all three.
            'discussion_user' => [
                ['user_id' => 3, 'discussion_id' => 20, 'last_read_post_number' => 1],
                ['user_id' => 4, 'discussion_id' => 20, 'last_read_post_number' => 1],
                ['user_id' => 4, 'discussion_id' => 21, 'last_read_post_number' => 1],
                ['user_id' => 4, 'discussion_id' => 22, 'last_read_post_number' => 1],
            ],
        ]);
    }

    /** @return array{int, mixed, string} */
    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $response = $this->send($this->request($method, $path, $options));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    private function marqueeDiscussion(int $id, string $title, int $season, ?int $episode): Discussion
    {
        $discussion = new Discussion();
        $discussion->id = $id;
        $discussion->title = $title;
        $discussion->user_id = 4;
        $discussion->created_at = $discussion->last_posted_at = Carbon::now()->subHour();
        $discussion->setAttribute('marquee_media_id', 1);
        $discussion->setAttribute('marquee_season', $season);
        $discussion->setAttribute('marquee_episode', $episode);
        $discussion->save();

        return $discussion;
    }

    #[Test]
    public function marquee_episodes_are_the_ladder(): void
    {
        $this->app();
        $episode = $this->marqueeDiscussion(10, self::EPISODE, 2, 5);
        $season = $this->marqueeDiscussion(11, self::SEASON, 2, null);
        $early = $this->marqueeDiscussion(12, 'Pilot thoughts', 1, 2);

        $this->assertSame(['mq:1', 2005, 's2e5'], [$episode->spoiler_work, (int) $episode->spoiler_position, $episode->slug]);
        $this->assertSame(2999, (int) $season->spoiler_position, 'the whole season');

        // Bea finished season 1 (its last episode reaches every season 1 discussion).
        $this->assertSame(422, $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'mq:1', 'position' => 1011])[0]);
        [, $doc] = $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'mq:1', 'position' => 1010]);
        $this->assertSame(1999, $doc['data']['position']);

        $raw = $this->api('GET', '/api/discussions', 3)[2];
        $this->assertStringContainsString('Pilot thoughts', $raw);
        $this->assertStringNotContainsString(self::EPISODE, $raw);
        $this->assertStringNotContainsString(self::SEASON, $raw);
        $this->assertStringContainsString('"label":"S2E5"', $raw);

        // Then the whole of season 2.
        $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'mq:1', 'position' => 2010]);
        $raw = $this->api('GET', '/api/discussions', 3)[2];
        $this->assertStringContainsString(self::EPISODE, $raw);
        $this->assertStringContainsString(self::SEASON, $raw);

        // Unlinked in Marquee: the mark goes, and the title comes back to the slug.
        $episode->setAttribute('marquee_media_id', null);
        $episode->save();
        $this->assertNull($episode->fresh()->spoiler_work);
        $this->assertSame('the-dragon-burns-the-harbor-city', $episode->fresh()->slug);
        $this->assertNotNull($early->fresh()->spoiler_work);
    }

    #[Test]
    public function anthology_chapters_open_as_you_read(): void
    {
        $this->app();
        foreach ([20 => 1, 21 => 2, 22 => 3] as $discussion => $position) {
            $chapter = new Chapter();
            $chapter->discussion_id = $discussion;
            $chapter->series_id = 1;
            $chapter->position = $position;
            $chapter->created_at = Carbon::now();
            $chapter->save();
        }
        $this->assertSame(['an:1', 3, 'chapter-3'], [Discussion::query()->find(22)->spoiler_work, (int) Discussion::query()->find(22)->spoiler_position, Discussion::query()->find(22)->slug]);

        // Ash read everything: her reply in chapter two is stamped at chapter three.
        [$status] = $this->api('POST', '/api/posts', 4, ['data' => ['type' => 'posts', 'attributes' => ['content' => self::REPLY], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '21']]]]]);
        $this->assertSame(201, $status);
        $reply = Post::query()->where('discussion_id', 21)->orderByDesc('id')->first();
        $this->assertSame(3, (int) $reply->spoiler_position);

        // Bea read chapter one: chapter two is open to her (she has to be able to read it), three isn't.
        $raw = $this->api('GET', '/api/discussions', 3)[2];
        $this->assertStringContainsString('Starfall chapter two', $raw);
        $this->assertStringNotContainsString(self::CHAPTER, $raw);
        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/'.$reply->id, 3)[2]);
        $this->assertStringNotContainsString(self::CHAPTER, $this->api('GET', '/api/anthology/series/1', 3)[2], "Anthology's chapter list");
        $this->assertStringNotContainsString(self::CHAPTER, $this->api('GET', '/api/discussions/21', 3)[2], 'the next-chapter link');
        $this->assertStringNotContainsString(self::CHAPTER, $this->api('GET', '/api/anthology/series/1', null)[2]);

        // She reads chapter two and three: all open.
        $this->database()->table('discussion_user')->insert([['user_id' => 3, 'discussion_id' => 21, 'last_read_post_number' => 1], ['user_id' => 3, 'discussion_id' => 22, 'last_read_post_number' => 1]]);
        $raw = $this->api('GET', '/api/discussions', 3)[2];
        $this->assertStringContainsString(self::CHAPTER, $raw);
        $this->assertStringContainsString(self::REPLY, $this->api('GET', '/api/posts/'.$reply->id, 3)[2]);
    }

    #[Test]
    public function a_finished_title_in_curio_offers_to_unlock_everything(): void
    {
        $this->app();
        $this->marqueeDiscussion(10, self::EPISODE, 2, 5);
        $db = $this->database();
        $now = Carbon::now();
        $workId = $db->table('curio_works')->insertGetId(['source' => 'marquee-tv', 'source_id' => '1399', 'title' => 'Dragon Throne', 'refreshed_at' => $now, 'created_at' => $now]);
        $collection = $db->table('curio_collections')->insertGetId(['user_id' => 3, 'type_id' => $db->table('curio_types')->value('id'), 'name' => 'Shows', 'slug' => 'shows', 'created_at' => $now, 'updated_at' => $now]);
        $item = ['collection_id' => $collection, 'user_id' => 3, 'work_id' => $workId, 'title' => 'Dragon Throne', 'status' => 'wishlist', 'created_at' => $now, 'updated_at' => $now];

        $find = fn () => collect($this->api('GET', '/api/discussions', 3)[1]['data'])->firstWhere('id', '10')['attributes']['spoilerShield'];

        $db->table('curio_items')->insert($item + ['progress' => 'in_progress']);
        $this->assertFalse($find()['offer'], 'wishlist, still watching');

        $db->table('curio_items')->update(['progress' => 'completed']);
        $shield = $find();
        $this->assertTrue($shield['hidden']);
        $this->assertTrue($shield['offer']);

        $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'mq:1', 'position' => 'finished']);
        $this->assertStringContainsString(self::EPISODE, $this->api('GET', '/api/discussions', 3)[2]);
    }

    #[Test]
    public function the_discussion_list_costs_the_same_queries_for_3_or_30_gated_discussions(): void
    {
        $this->app();
        $count = function () {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->api('GET', '/api/discussions', 3);
            $db->disableQueryLog();


            return count($db->getQueryLog());
        };

        for ($i = 0; $i < 3; $i++) {
            $this->marqueeDiscussion(100 + $i, 'Episode talk '.$i, 2, $i + 1);
        }
        $count(); // the first request of a session also records the visit and loads permissions
        $few = $count();

        for ($i = 3; $i < 30; $i++) {
            $this->marqueeDiscussion(100 + $i, 'Episode talk '.$i, 2, $i % 10 + 1);
        }
        $many = $count();

        $this->assertSame($few, $many, "$few queries for 3, $many for 30");
    }
}

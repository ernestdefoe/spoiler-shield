<?php

namespace ErnestDefoe\SpoilerShield\Http;

use ErnestDefoe\SpoilerShield\Gate;
use ErnestDefoe\SpoilerShield\Labels;
use ErnestDefoe\SpoilerShield\MarkStore;
use ErnestDefoe\SpoilerShield\Works;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The forum page's own HTML, after every other extension wrote into it (this
 * runs last). The API already gated the title, the preloaded data and the
 * no-JS content; SEO and link-preview extensions read the database directly,
 * so on a discussion this viewer may not read, every description, Open Graph,
 * Twitter and structured-data tag goes. Anywhere on any page, a link to a
 * hidden discussion that spells out its title says "Spoiler for …" instead
 * (Anthology's series pages list chapters that way).
 */
class ScrubDocument
{
    public function __construct(
        private readonly MarkStore $store,
        private readonly Gate $gate,
        private readonly Works $works,
        private readonly Labels $labels,
        private readonly TranslatorInterface $translator,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $actor = RequestUtil::getActor($request);
        $session = $request->getAttribute('session');

        $current = $request->getAttribute('routeName') === 'discussion'
            ? (int) ($request->getAttribute('routeParameters')['id'] ?? 0) : 0;

        if ($document->content instanceof Renderable) {
            $document->content = $document->content->render();
        }

        $html = implode("\n", array_merge([(string) $document->content], $document->head, $document->meta));
        preg_match_all('#/d/(\d+)#', $html, $m);
        $ids = array_map('intval', $m[1]);
        if ($current) {
            $ids[] = $current;
        }
        if (! $ids) {
            return;
        }

        $discussions = $this->store->discussions($ids);
        $verdict = $this->gate->check($actor, $session, $discussions, []);

        if (! $verdict->hiddenDiscussions) {
            return;
        }

        $works = $this->works->describe(array_values(array_unique(array_filter(array_column($discussions, 'work')))));
        $placeholder = function (int $id) use ($discussions, $works): string {
            $d = $discussions[$id];

            return $this->translator->trans('ernestdefoe-spoiler-shield.lib.placeholder', [
                'label' => $this->labels->for((string) $d['work'], (int) $d['position'], $d['label']),
                'work' => $works[$d['work']]['title'] ?? '',
            ]);
        };

        if ($current && $verdict->discussionHidden($current)) {
            $document->title = $placeholder($current);
            $document->meta = array_filter(
                $document->meta,
                fn ($key) => ! preg_match('/^(description|keywords|og:|twitter:|article:)/i', (string) $key),
                ARRAY_FILTER_USE_KEY
            );
            $document->head = array_values(array_filter(
                $document->head,
                fn ($tag) => ! preg_match('/(property|name)="(description|keywords|og:|twitter:|article:)|application\/ld\+json/i', (string) $tag)
            ));
        }

        // A hidden discussion's own title, wherever it turns up as text: a description taken
        // from a post that links to it, a link's words. Two maps: plain text (meta values and
        // the title, escaped later by the page) and HTML (always the escaped placeholder, as
        // a work's name is a member's words).
        $text = $html = [];
        foreach ($this->db->table('discussions')->whereIn('id', array_keys($verdict->hiddenDiscussions))->pluck('title', 'id') as $id => $title) {
            if (mb_strlen((string) $title) >= 4) {
                $words = $placeholder((int) $id);
                $text[(string) $title] = $words;
                foreach ([(string) $title, e((string) $title), htmlspecialchars((string) $title, ENT_QUOTES | ENT_HTML5)] as $form) {
                    $html[$form] = e($words);
                }
            }
        }

        // In HTML, only text between tags and attribute values change, never the markup itself.
        $inText = fn (string $markup) => $html ? (string) preg_replace_callback(
            '#(>[^<]+<)|(="[^"]*")#',
            fn ($m) => strtr($m[0], $html),
            $markup
        ) : $markup;

        $rewrite = fn (string $markup) => $inText((string) preg_replace_callback(
            '#(<a\b[^>]*\bhref="[^"]*/d/(\d+)[^"]*"[^>]*>)(.*?)(</a>)#is',
            fn ($m) => $verdict->discussionHidden((int) $m[2]) ? $m[1].e($placeholder((int) $m[2])).$m[4] : $m[0],
            $markup
        ));

        $document->content = $rewrite((string) $document->content);
        $document->head = array_map(fn ($tag) => $rewrite((string) $tag), $document->head);
        $document->meta = array_map(fn ($value) => is_string($value) ? strtr($value, $text) : $value, $document->meta);
        if (is_string($document->title)) {
            $document->title = strtr($document->title, $text);
        }
    }
}

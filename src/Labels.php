<?php

namespace ErnestDefoe\SpoilerShield;

use Symfony\Contracts\Translation\TranslatorInterface;

/** A position in words. Works defined here store their own step names; the rest are made from the number. */
final class Labels
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function for(string $work, int $position, ?string $stored = null): string
    {
        if ($position === Marks::FINISHED) {
            return $this->translator->trans('ernestdefoe-spoiler-shield.lib.finished');
        }

        $source = Marks::parse($work)[0] ?? 'w';

        if ($source === 'mq') {
            $season = intdiv($position, 1000);
            $episode = $position % 1000;

            return $episode === Marks::SEASON_END
                ? $this->translator->trans('ernestdefoe-spoiler-shield.lib.season', ['number' => $season])
                : 'S'.$season.'E'.$episode;
        }

        if ($source === 'an') {
            return $this->translator->trans('ernestdefoe-spoiler-shield.lib.chapter', ['number' => $position]);
        }

        return $stored !== null && $stored !== '' ? $stored : '#'.$position;
    }
}

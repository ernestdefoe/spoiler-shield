<?php

namespace ErnestDefoe\SpoilerShield;

use Illuminate\Database\ConnectionInterface;

/** Marks read straight from the rows, for things that don't come from the API (one query per call). */
final class MarkStore
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * @param int[] $ids
     * @return array<int, array{work: ?string, position: ?int, label: ?string, user: ?int}>
     */
    public function discussions(array $ids): array
    {
        $out = [];
        if (! $ids) {
            return $out;
        }

        foreach ($this->db->table('discussions')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'user_id', 'spoiler_work', 'spoiler_position', 'spoiler_label']) as $row) {
            $out[(int) $row->id] = [
                'work' => $row->spoiler_work,
                'position' => $row->spoiler_position === null ? null : (int) $row->spoiler_position,
                'label' => $row->spoiler_label,
                'user' => $row->user_id === null ? null : (int) $row->user_id,
            ];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     * @return array<int, array{discussion: int, position: ?int, label: ?string, user: ?int}>
     */
    public function posts(array $ids): array
    {
        $out = [];
        if (! $ids) {
            return $out;
        }

        foreach ($this->db->table('posts')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'discussion_id', 'user_id', 'spoiler_position', 'spoiler_label']) as $row) {
            $out[(int) $row->id] = [
                'discussion' => (int) $row->discussion_id,
                'position' => $row->spoiler_position === null ? null : (int) $row->spoiler_position,
                'label' => $row->spoiler_label,
                'user' => $row->user_id === null ? null : (int) $row->user_id,
            ];
        }

        return $out;
    }
}

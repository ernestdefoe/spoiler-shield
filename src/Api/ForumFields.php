<?php

namespace ErnestDefoe\SpoilerShield\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;

/** The viewer's own permissions: already loaded, so no query. */
final class ForumFields
{
    /** @return array<int, mixed> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('spoilerShieldCanCreateWorks')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('spoiler-shield.createWorks')),
            Schema\Boolean::make('spoilerShieldCanManageWorks')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('spoiler-shield.manageWorks')),
        ];
    }
}

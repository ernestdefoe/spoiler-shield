<?php

namespace ErnestDefoe\SpoilerShield;

use Flarum\Discussion\Discussion;
use ErnestDefoe\SpoilerShield\Notification\GatedEmailDriver;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Notification\NotificationSyncer;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        // One per request (or job): it remembers which discussions were marked by hand.
        $this->container->singleton(MarkWriter::class);
        // One too: RedactApi opens a word search's window on it, the visibility scopes read it.
        $this->container->singleton(Search\HiddenFromSearch::class);
    }

    public function boot(): void
    {
        // Wrap the email driver that's there, whoever registered it: replacing it would undo
        // another extension's (Courier's reply-by-email). Core's notification drivers are
        // set when its provider boots, before any extension's.
        $drivers = NotificationSyncer::getNotificationDrivers();
        if (isset($drivers['email']) && ! $drivers['email'] instanceof GatedEmailDriver) {
            NotificationSyncer::addNotificationDriver('email', new GatedEmailDriver(
                $drivers['email'],
                $this->container->make(Gate::class),
                $this->container->make(MarkStore::class),
            ));
        }

        Discussion::saving(fn (Discussion $discussion) => $this->container->make(MarkWriter::class)->discussionModelSaving($discussion));
        // On the subclass: Eloquent fires a model's events under its own class, and replies are CommentPosts.
        \Flarum\Post\CommentPost::saving(fn (\Flarum\Post\Post $post) => $this->container->make(MarkWriter::class)->postMoving($post));
    }
}

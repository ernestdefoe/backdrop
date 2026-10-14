<?php

namespace Ernestdefoe\Backdrop\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class UserPolicy extends AbstractPolicy
{
    /**
     * Your own cover needs the extension's permission; anyone else's is a
     * moderation action and needs core's "edit users", like avatars.
     */
    public function editBackdrop(User $actor, User $user): ?string
    {
        if ($actor->isGuest()) {
            return $this->deny();
        }

        if ($actor->id !== $user->id) {
            return $actor->can('edit', $user) ? $this->allow() : $this->deny();
        }

        return $actor->hasPermission('ernestdefoe-backdrop.set') ? $this->allow() : $this->deny();
    }
}

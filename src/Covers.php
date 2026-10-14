<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Setting, reframing and removing a member's cover. Every write is checked
 * against the `editBackdrop` ability here, so no endpoint can forget to.
 */
class Covers
{
    public function __construct(
        protected CoverStore $store,
        protected CoverValidator $validator,
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function upload(User $actor, User $user, ?UploadedFileInterface $file, array $input): User
    {
        $actor->assertCan('editBackdrop', $user);

        if (! $file) {
            throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.no_file')]);
        }

        $this->validator->assertImageValid('cover', $file);
        $frame = Frame::fromInput($input);

        $previous = $user->backdrop_cover;
        $user->backdrop_cover = $this->store->store($file);
        $this->applyFrame($user, $frame);
        $user->save();

        $this->store->delete($previous);

        return $user;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function reframe(User $actor, User $user, array $input): User
    {
        $actor->assertCan('editBackdrop', $user);

        if (! $user->backdrop_cover) {
            throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.no_cover')]);
        }

        $this->applyFrame($user, Frame::fromInput($input));
        $user->save();

        return $user;
    }

    public function remove(User $actor, User $user): User
    {
        $actor->assertCan('editBackdrop', $user);

        $previous = $user->backdrop_cover;
        $user->backdrop_cover = null;
        $this->applyFrame($user, new Frame());
        $user->save();

        $this->store->delete($previous);

        return $user;
    }

    protected function applyFrame(User $user, Frame $frame): void
    {
        $user->backdrop_x = $frame->x;
        $user->backdrop_y = $frame->y;
        $user->backdrop_zoom = $frame->zoom;
    }
}

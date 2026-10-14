<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Foundation\AbstractImageValidator;
use Flarum\Settings\SettingsRepositoryInterface;

class CoverValidator extends AbstractImageValidator
{
    public function getMaxSize(): int
    {
        $kb = (int) resolve(SettingsRepositoryInterface::class)->get('ernestdefoe-backdrop.max_size');

        return $kb > 0 ? $kb : 4096;
    }

    /**
     * @return list<string>
     */
    protected function getAllowedTypes(): array
    {
        return ['jpeg', 'jpg', 'png', 'gif', 'webp'];
    }
}

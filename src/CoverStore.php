<?php

namespace Ernestdefoe\Backdrop;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The files behind covers, on the extension's own disk (public/assets/backdrop
 * unless a storage driver says otherwise).
 *
 * Each upload is stored twice: a full-width image for profiles and a small one
 * for hover cards and the member directory. A stored value is the full image's
 * file name; the small one is derived from it, so one column describes both.
 * A value containing "://" is an absolute URL brought over from another cover
 * extension, and is never deleted from here.
 */
class CoverStore
{
    public const DISK = 'ernestdefoe-backdrop';

    private const FULL_WIDTH = 1920;
    private const SMALL_WIDTH = 960;

    protected Cloud $disk;

    public function __construct(Factory $filesystems, protected ImageManager $images)
    {
        $this->disk = $filesystems->disk(self::DISK);
    }

    public function store(UploadedFileInterface $file): string
    {
        return $this->storePath($file->getStream()->getMetadata('uri'));
    }

    /**
     * Store a validated image from a local path; returns the stored name.
     */
    public function storePath(string $path): string
    {
        $image = $this->images->read($path);
        $animated = $image->isAnimated();
        $name = Str::random(20).'.'.($animated ? 'gif' : 'webp');

        foreach ([$name => self::FULL_WIDTH, self::small($name) => self::SMALL_WIDTH] as $path => $width) {
            $copy = clone $image;
            $copy->scaleDown(width: $width);

            $this->disk->put($path, $animated ? $copy->toGif() : $copy->toWebp(quality: 85));
        }

        return $name;
    }

    public function delete(?string $stored): void
    {
        if (! $stored || self::isExternal($stored)) {
            return;
        }

        $this->disk->delete([$stored, self::small($stored)]);
    }

    public function url(?string $stored, bool $small = false): ?string
    {
        if (! $stored) {
            return null;
        }

        if (self::isExternal($stored)) {
            return $stored;
        }

        return $this->disk->url($small ? self::small($stored) : $stored);
    }

    public static function isExternal(string $stored): bool
    {
        return str_contains($stored, '://');
    }

    protected static function small(string $name): string
    {
        $dot = strrpos($name, '.');

        return substr($name, 0, $dot).'-sm'.substr($name, $dot);
    }
}

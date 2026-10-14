<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The gallery: covers an admin uploads for members to choose from.
 *
 * Kept as one JSON setting, in order: a short list, read only when the editor
 * opens or a preset is chosen. Each file is stored once under presets/ and a
 * member who chooses it points their backdrop_cover at that same file, so
 * rendering a chosen preset costs exactly what an uploaded cover does.
 */
class Presets
{
    public const LIMIT = 24;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected CoverStore $store,
        protected CoverValidator $validator,
        protected TranslatorInterface $translator,
        protected ConnectionInterface $db,
    ) {
    }

    /**
     * @return list<array{id: string, file: string, x: float, y: float, zoom: float}>
     */
    public function all(): array
    {
        $list = json_decode((string) $this->settings->get(Settings::PRESETS), true);

        return is_array($list) ? array_values(array_filter($list, fn ($p) => is_array($p) && isset($p['id'], $p['file']))) : [];
    }

    /**
     * @return array{id: string, file: string, x: float, y: float, zoom: float}
     */
    public function find(mixed $id): array
    {
        foreach ($this->all() as $preset) {
            if ($preset['id'] === $id) {
                return $preset;
            }
        }

        throw new ValidationException(['preset' => $this->translator->trans('ernestdefoe-backdrop.api.unknown_preset')]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forClient(): array
    {
        return array_map(fn (array $preset) => [
            'id' => $preset['id'],
            'url' => $this->store->url($preset['file']),
            'small' => $this->store->url($preset['file'], true),
            'x' => (float) $preset['x'],
            'y' => (float) $preset['y'],
            'zoom' => (float) $preset['zoom'],
        ], $this->all());
    }

    /**
     * @param array<string, mixed> $input
     */
    public function add(?UploadedFileInterface $file, array $input): void
    {
        $list = $this->all();

        if (count($list) >= self::LIMIT) {
            throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.gallery_full', ['max' => self::LIMIT])]);
        }

        if (! $file) {
            throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.no_file')]);
        }

        $this->validator->assertImageValid('cover', $file);
        $frame = Frame::fromInput($input);
        $stored = $this->store->store($file, CoverStore::PRESETS);

        $list[] = ['id' => pathinfo($stored, PATHINFO_FILENAME), 'file' => $stored] + $frame->toArray();
        $this->save($list);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function reframe(string $id, array $input): void
    {
        $this->find($id);
        $frame = Frame::fromInput($input);

        $this->save(array_map(fn (array $p) => $p['id'] === $id ? array_merge($p, $frame->toArray()) : $p, $this->all()));
    }

    /**
     * Members using a deleted preset go back to the default cover.
     */
    public function remove(string $id): void
    {
        $preset = $this->find($id);

        $this->save(array_values(array_filter($this->all(), fn (array $p) => $p['id'] !== $id)));
        $this->db->table('users')->where('backdrop_cover', $preset['file'])->update(['backdrop_cover' => null]);
        $this->store->delete($preset['file'], true);
    }

    /**
     * @param array<mixed> $ids the new order; presets not named keep their place at the end
     */
    public function reorder(array $ids): void
    {
        $list = $this->all();
        $rank = array_flip(array_values(array_filter($ids, 'is_string')));

        usort($list, fn ($a, $b) => ($rank[$a['id']] ?? PHP_INT_MAX) <=> ($rank[$b['id']] ?? PHP_INT_MAX));
        $this->save($list);
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    protected function save(array $list): void
    {
        $this->settings->set(Settings::PRESETS, json_encode(array_values($list)));
    }
}

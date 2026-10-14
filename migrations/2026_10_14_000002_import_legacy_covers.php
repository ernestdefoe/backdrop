<?php

use Ernestdefoe\Backdrop\LegacyImport;
use Illuminate\Database\Schema\Builder;

// Covers from Cover Studio or sycho/flarum-profile-cover come over on enable,
// by URL. `php flarum backdrop:import` then copies their files across.
return [
    'up' => function (Builder $schema) {
        resolve(LegacyImport::class)->import();
    },
    'down' => function (Builder $schema) {
        // Nothing to undo: the old extensions' data was only read.
    },
];

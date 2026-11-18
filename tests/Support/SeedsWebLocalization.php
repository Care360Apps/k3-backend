<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait SeedsWebLocalization
{
    protected function seedWebLocalization(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \LogicException('Web localization fixtures require the isolated in-memory test database.');
        }
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $t) {
                $t->id();
                $t->string('language');
            });
        }
        if (! Schema::hasTable('language')) {
            Schema::create('language', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('direction');
            });
        }
        DB::table('settings')->insertOrIgnore(['id' => 1, 'language' => 'en']);
        DB::table('language')->insertOrIgnore(['id' => 1, 'name' => 'en', 'direction' => 'ltr']);
    }
}

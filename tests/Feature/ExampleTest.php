<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    use \Tests\Support\SeedsWebLocalization;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.key' => 'base64:'.base64_encode(str_repeat('e', 32))]);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        $this->seedWebLocalization();
        foreach (['banner', 'review', 'blog', 'treatments', 'doctor'] as $table) {
            \Illuminate\Support\Facades\Schema::create($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($table) {
                $t->id();
                $t->boolean('status')->default(1);
                if ($table === 'doctor') {
                    $t->boolean('is_approved');
                    $t->boolean('is_filled');
                    $t->boolean('subscription_status');
                }
            });
        }
        $fields = array_unique(array_merge((new \App\Models\Setting)->getFillable(), ['banner_url', 'popup_target_url']));
        foreach ($fields as $field) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('settings', $field)) {
                \Illuminate\Support\Facades\Schema::table('settings', fn (\Illuminate\Database\Schema\Blueprint $t) => $t->text($field)->nullable());
            }
        }
        \Illuminate\Support\Facades\DB::table('settings')->where('id', 1)->update(array_merge(array_fill_keys($fields, ''), ['language' => 'en', 'banner_url' => 'https://example.org/', 'popup_target_url' => 'https://example.org/', 'business_name' => 'Synthetic Care360']));
    }

    /**
     * A basic test example.
     *
     * @return void
     */
    public function test_example()
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}

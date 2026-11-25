<?php

namespace Tests\Feature;

use App\Http\Controllers\PatientPlaceSearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PatientPlaceSearchTest extends TestCase
{
    private const TOKEN = 'a2ca8d84-8e7e-441b-a1bf-e3a0714c97df';

    public function test_places_stays_off_when_server_key_is_missing(): void
    {
        config()->set('services.google_places.key', '');
        Http::preventStrayRequests();
        $request = Request::create('/api/places/autocomplete', 'POST', [
            'query' => 'Keffi General Hospital',
            'session_token' => self::TOKEN,
        ]);
        $response = (new PatientPlaceSearchController)->autocomplete($request);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('PLACES_NOT_CONFIGURED', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_suggestions_use_approved_google_host_and_return_minimal_fields(): void
    {
        config()->set('services.google_places.key', str_repeat('X', 39));
        Http::preventStrayRequests();
        Http::fake([
            'https://places.googleapis.com/v1/places:autocomplete' => Http::response([
                'suggestions' => [
                    ['placePrediction' => [
                        'placeId' => 'ChIJKeffiSyntheticABC123',
                        'text' => ['text' => 'Keffi General Hospital, Nigeria'],
                        'otherSensitiveData' => 'never-return',
                    ]],
                ],
            ], 200),
        ]);
        $request = Request::create('/api/places/autocomplete', 'POST', [
            'query' => 'Keffi General Hospital',
            'session_token' => self::TOKEN,
        ]);
        $result = (new PatientPlaceSearchController)->autocomplete($request);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('Keffi General Hospital', $result->getContent());
        $this->assertStringNotContainsString('never-return', $result->getContent());
        $this->assertStringNotContainsString(str_repeat('X', 39), $result->getContent());
        Http::assertSent(function ($request) {
            return $request->url() === 'https://places.googleapis.com/v1/places:autocomplete'
                && $request->hasHeader('X-Goog-Api-Key')
                && $request['sessionToken'] === self::TOKEN
                && $request['includeQueryPredictions'] === false;
        });
    }

    public function test_details_return_coordinates_without_google_response_headers(): void
    {
        config()->set('services.google_places.key', str_repeat('X', 39));
        Http::preventStrayRequests();
        Http::fake([
            'https://places.googleapis.com/v1/places/ChIJKeffiSyntheticABC123*' => Http::response([
                'id' => 'ChIJKeffiSyntheticABC123',
                'formattedAddress' => 'Synthetic Road, Keffi, Nigeria',
                'location' => ['latitude' => 8.846, 'longitude' => 7.873],
                'irrelevant' => ['owner' => 'never-return'],
            ], 200),
        ]);
        $request = Request::create('/api/places/details', 'POST', [
            'place_id' => 'ChIJKeffiSyntheticABC123',
            'session_token' => self::TOKEN,
        ]);
        $result = (new PatientPlaceSearchController)->details($request);
        $this->assertSame(200, $result->getStatusCode());
        $json = json_decode($result->getContent(), true);
        $this->assertSame('Synthetic Road, Keffi, Nigeria', $json['data']['address']);
        $this->assertSame(8.846, $json['data']['latitude']);
        $this->assertArrayNotHasKey('irrelevant', $json['data']);
    }

    public function test_geoapify_search_returns_only_nigerian_suggestions(): void
    {
        config()->set('services.care360_places.provider', 'geoapify');
        config()->set('services.care360_places.geoapify_key', 'synthetic-key');
        Http::preventStrayRequests();
        Http::fake(['https://api.geoapify.com/v1/geocode/autocomplete*' => Http::response(['results' => [
            ['place_id' => 'abcdef123456', 'formatted' => 'Keffi, Nigeria', 'country_code' => 'ng'],
            ['place_id' => 'abcdef123457', 'formatted' => 'Other', 'country_code' => 'us'],
        ]])]);
        $result = (new PatientPlaceSearchController)->autocomplete(Request::create('/api/places/autocomplete', 'POST', [
            'query' => 'Keffi', 'session_token' => self::TOKEN,
        ]));
        $this->assertSame(200, $result->getStatusCode());
        $this->assertCount(1, json_decode($result->getContent(), true)['data']);
        $this->assertStringNotContainsString('synthetic-key', $result->getContent());
    }

    public function test_geoapify_details_preserve_mobile_contract(): void
    {
        config()->set('services.google_places.key', '');
        config()->set('services.care360_places.provider', 'geoapify');
        config()->set('services.care360_places.geoapify_key', 'synthetic-key');
        Http::preventStrayRequests();
        Http::fake(['https://api.geoapify.com/v2/place-details*' => Http::response(['features' => [
            ['properties' => ['lat' => 8.846, 'lon' => 7.873, 'formatted' => 'Keffi, Nigeria', 'country_code' => 'ng']],
        ]])]);
        $result = (new PatientPlaceSearchController)->details(Request::create('/api/places/details', 'POST', [
            'place_id' => 'abcdef123456', 'session_token' => self::TOKEN,
        ]));
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame(8.846, json_decode($result->getContent(), true)['data']['latitude']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.geoapify.com/v2/place-details'));
        Http::assertSentCount(1);
    }
}

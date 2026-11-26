<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Authenticated Places API (New) gateway. No Google credentials, arbitrary
 * URLs, response headers, user tokens or patient records reach the app.
 * Requires a server-only, IP-restricted API key with Places API (New).
 */
class PatientPlaceSearchController extends Controller
{
    private function placesKey(): ?string
    {
        $key = trim((string) config('services.google_places.key', ''));
        return strlen($key) >= 25 ? $key : null;
    }

    private function disabledResponse()
    {
        return response()->json([
            'success' => false,
            'code' => 'PLACES_NOT_CONFIGURED',
            'msg' => 'Search is unavailable. Select a point on the map and enter its address.',
        ], 503);
    }

    public function autocomplete(Request $request)
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:100'],
            'session_token' => ['required', 'uuid'],
        ]);

        $query = trim($data['query']);
        if (mb_strlen($query) < 3) {
            return response()->json(['success' => false, 'code' => 'QUERY_TOO_SHORT'], 422);
        }
        if (!($key = $this->placesKey())) {
            return $this->disabledResponse();
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'suggestions.placePrediction.placeId,suggestions.placePrediction.text.text',
            ])
                ->acceptJson()->timeout(6)->connectTimeout(3)
                ->post('https://places.googleapis.com/v1/places:autocomplete', [
                    'input' => $query,
                    'languageCode' => 'en',
                    'regionCode' => 'NG',
                    'sessionToken' => $data['session_token'],
                    'includeQueryPredictions' => false,
                ]);

            if (!$response->successful()) {
                // Do not log Google error response (may contain location/query/key).
                return response()->json([
                    'success' => false, 'code' => 'PLACES_UNAVAILABLE',
                    'msg' => 'Search temporarily unavailable. Select a point on the map.',
                ], 502);
            }

            $items = [];
            foreach (($response->json('suggestions') ?? []) as $suggestion) {
                $prediction = $suggestion['placePrediction'] ?? [];
                $id = $prediction['placeId'] ?? '';
                $label = $prediction['text']['text'] ?? '';
                if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{8,220}$/', $id)
                    || !is_string($label) || trim($label) === '') {
                    continue;
                }
                $items[] = ['place_id' => $id, 'description' => mb_substr($label, 0, 220)];
                if (count($items) >= 8) {
                    break;
                }
            }

            return response()->json(['success' => true, 'data' => $items]);
        } catch (ConnectionException $e) {
            return response()->json([
                'success' => false, 'code' => 'PLACES_UNAVAILABLE',
                'msg' => 'Search temporarily unavailable. Select a point on the map.',
            ], 503);
        }
    }

    public function details(Request $request)
    {
        $data = $request->validate([
            'place_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{8,220}$/'],
            'session_token' => ['required', 'uuid'],
        ]);
        if (!($key = $this->placesKey())) {
            return $this->disabledResponse();
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'id,formattedAddress,location',
            ])->acceptJson()->timeout(6)->connectTimeout(3)->get(
                'https://places.googleapis.com/v1/places/'.$data['place_id'],
                ['sessionToken' => $data['session_token'], 'languageCode' => 'en'],
            );
            if (!$response->successful()) {
                return response()->json(['success' => false, 'code' => 'PLACE_UNAVAILABLE'], 502);
            }

            $latitude = $response->json('location.latitude');
            $longitude = $response->json('location.longitude');
            $address = $response->json('formattedAddress');

            if (!is_numeric($latitude) || !is_numeric($longitude) || !is_string($address)
                || abs((float) $latitude) > 90 || abs((float) $longitude) > 180
                || trim($address) === '') {
                return response()->json(['success' => false, 'code' => 'PLACE_INCOMPLETE'], 502);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'address' => mb_substr($address, 0, 255),
                    'latitude' => (float) $latitude,
                    'longitude' => (float) $longitude,
                ],
            ]);
        } catch (ConnectionException $e) {
            return response()->json(['success' => false, 'code' => 'PLACES_UNAVAILABLE'], 503);
        }
    }
}

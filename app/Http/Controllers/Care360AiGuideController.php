<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class Care360AiGuideController extends Controller
{
    /**
     * Only selected, non-clinical Care360 navigation topics may reach a model.
     * No patient records, identifiers, freeform chat, or session history leave
     * Care360. The caller's Authorization header is NEVER forwarded upstream.
     */
    private const TOPICS = [
        'get_started' => 'Create an account, verify your identity, then sign in.',
        'appointments' => 'Sign in, select a professional, review availability, and confirm an appointment.',
        'account' => 'Use your account settings to review your profile or change your password.',
        'partners' => 'Partner services, including MindCheck by SpeakOut Mental Health Outreach, open separately in your browser.',
    ];

    public function guide(Request $request)
    {
        $data = $request->validate([
            'topic' => 'required|in:get_started,appointments,account,partners',
            'language' => 'required|in:en,ha,ig,yo,fr',
        ]);

        $topic = $data['topic'];
        $language = $data['language'];
        $fallback = self::TOPICS[$topic];
        $response = [
            'success' => true,
            'topic' => $topic,
            'language' => $language,
            'answer' => $fallback,
            'source' => 'curated',
            'disclaimer' => 'General app-navigation information only. Not medical advice.',
        ];

        // Default-off until the isolated backend, provider, privacy notice,
        // consent flow and costs are approved by the Care360 data controller.
        $key = config('services.care360_ai.key');
        if (!config('services.care360_ai.enabled', false) || !is_string($key) || $key === '') {
            return response()->json($response);
        }

        // Only the allowlisted topic and language are sent to Gemini.
        // Never include $request->user(), $request->all(), bearer tokens,
        // patient content, medical history, or caller-supplied prompt text.
        $instruction = 'You are the Care360 non-clinical app guide. '
            . 'Explain ONLY the following app-navigation information clearly, '
            . 'in at most 80 words. Do not give diagnosis, treatment advice, '
            . 'prescriptions, clinical triage, or appointment guarantees. '
            . 'If unsure, say the user should check with Care360 support. '
            . 'Respond in language code ' . $language . '. Topic: ' . $topic
            . '. Approved facts: ' . $fallback;

        try {
            $upstream = Http::withHeaders(['x-goog-api-key' => $key])
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => $instruction]],
                    ]],
                    'generationConfig' => [
                        'maxOutputTokens' => 220,
                        'temperature' => 0.2,
                    ],
                ]);

            if ($upstream->successful()) {
                $answer = data_get($upstream->json(), 'candidates.0.content.parts.0.text');
                if (is_string($answer) && trim($answer) !== '') {
                    $response['answer'] = mb_substr(trim($answer), 0, 750);
                    $response['source'] = 'ai';
                }
            }
        } catch (\Throwable $exception) {
            // Never reveal API keys, response payloads or user identifiers.
            // Fail back to curated help with no secrets in error messages.
        }

        return response()->json($response);
    }
}

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
        'get_started' => "Use Care360 Patient to find care, manage appointments and access supported services.\n\n1. Choose your preferred language on the welcome or sign-in screen.\n\n2. Tap Sign Up if you are new. Enter your own contact details, create a password and complete the verification requested by the app. Already registered? Use Sign In instead.\n\n3. After signing in, use Select Address to add a location when a service needs it. Give the address a recognisable label and check it before saving.\n\n4. Explore the dashboard: Hospitals, Doctors, Providers, Pharmacies, Laboratories and Appointments. Open a category to see available options. Availability depends on your location and the service setup.\n\n5. Open the menu for your account and Settings. Review your details and privacy information before using a service.\n\n6. If you cannot sign in, use Forgot Password rather than creating a duplicate account. A local test build needs its test server running; an offline preview cannot sign in.",
        'appointments' => "Book only after checking the professional, appointment details and any stated charge.\n\n1. Sign in and open Doctors, or search for a doctor from the dashboard. You can also explore the supported care categories.\n\n2. Select a professional and read the profile, speciality, location and available appointment options. Do not assume that every listed professional offers video consultations.\n\n3. Choose an available date, time and consultation option. Check the displayed time carefully. If no slot is available, choose another date or professional.\n\n4. Review the booking summary, patient details, location and displayed fee before continuing. Provide only the information requested for the appointment.\n\n5. If payment is required, use the supported checkout. Wait for confirmation; do not submit another payment while the first result is uncertain.\n\n6. Open Appointments to confirm the booking status and review its details. A payment attempt or loading screen alone is not a confirmed appointment.\n\n7. For changes or cancellation, open that appointment and review the options and any stated conditions. For a supported video appointment, open its call option at the appropriate time and allow camera or microphone only when needed.\n\n8. If confirmation is missing or a payment result is unclear, check Appointments first, then contact the service support shown in the app. Do not share your password or payment PIN.",
        'account' => "Keep your account details and device preferences up to date.\n\n1. Open the dashboard menu, then choose your profile or Settings. Review the details attached to your account before making changes.\n\n2. Edit supported profile fields and save. Check that the updated information appears when you return.\n\n3. Use Select Address to manage a location for supported services. Check the map or search result and the written address; do not save an incorrect location.\n\n4. Use the language control to choose the interface language. Some guide content may still be in English.\n\n5. Enable notifications from Settings if you want supported reminders. Camera, microphone and location permissions should be requested only when you use the related feature.\n\n6. For a forgotten password, return to Sign In and use Forgot Password. Follow verification instructions using your registered contact details.\n\n7. Review the privacy information before requesting account deletion. Read the confirmation carefully: deletion can affect access to your account and associated services.\n\n8. Use Log Out on a shared device. Never give another person your password, verification code or payment PIN.",
        'partners' => "Partner services are separate from the core Care360 appointment flow.\n\n1. Scroll to Explore partner services on the dashboard and read the service name and description.\n\n2. Select the service you intend to use. For example, MindCheck is provided by SpeakOut Mental Health Outreach.\n\n3. Check the destination before continuing. A partner may open in your browser and have its own account, terms, privacy notice and charges. Your Care360 sign-in does not guarantee access.\n\n4. Read that service's information before sharing personal details. Do not enter your Care360 password into a partner website.\n\n5. Return to Care360 with your device's back control or reopen the app. Check Care360 Appointments separately if you need to verify a Care360 booking.\n\nThis guide explains app navigation. It does not assess symptoms, interpret results or replace advice from a qualified healthcare professional.",
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
            . 'with clear numbered steps, in at most 250 words. Do not give diagnosis, treatment advice, '
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
                        'maxOutputTokens' => 900,
                        'temperature' => 0.2,
                    ],
                ]);

            if ($upstream->successful()) {
                $answer = data_get($upstream->json(), 'candidates.0.content.parts.0.text');
                if (is_string($answer) && trim($answer) !== '') {
                    $response['answer'] = mb_substr(trim($answer), 0, 4500);
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

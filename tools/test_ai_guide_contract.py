#!/usr/bin/env python3
"""Static, offline guard: no API secrets/patient data in Care360 AI prompts."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AiGuideSafetyTest(unittest.TestCase):
    def test_feature_disabled_by_default(self):
        config = (ROOT / "config/services.php").read_text()
        self.assertIn("env('K3_AI_GUIDE_ENABLED', false)", config)
        self.assertIn("env('K3_AI_GEMINI_API_KEY')", config)

    def test_nonclinical_allowlist_and_no_raw_user_input(self):
        code = (ROOT / "app/Http/Controllers/Care360AiGuideController.php").read_text()
        self.assertIn("required|in:get_started,appointments,account,partners", code)
        self.assertIn("required|in:en,ha,ig,yo,fr", code)
        self.assertIn("['x-goog-api-key' => $key]", code)
        self.assertIn("config('services.care360_ai.enabled', false)", code)
        self.assertNotRegex(code, r"(?m)^[^/\n]*\$request->(?:all|input|user)\(")
        self.assertNotIn("Authorization' =>", code)
        self.assertIn("'source' => 'curated'", code)
        self.assertIn("'maxOutputTokens' => 220", code)

    def test_route_requires_auth_and_throttle(self):
        routes = (ROOT / "routes/api.php").read_text()
        self.assertRegex(
            routes,
            r"(?s)Route::post\('/ai/guide'.*?middleware\(\['auth:api', 'throttle:5,1'\]\)"
        )


if __name__ == "__main__":
    unittest.main()

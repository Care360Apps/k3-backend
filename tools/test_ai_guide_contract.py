#!/usr/bin/env python3
"""Static, offline guard: no API secrets/patient data in Care360 AI prompts."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AiGuideSafetyTest(unittest.TestCase):
    def test_feature_disabled_by_default(self):
        config = (ROOT / "config/services.php").read_text(encoding="utf-8")
        self.assertIn("env('K3_AI_GUIDE_ENABLED', false)", config)
        self.assertIn("env('K3_AI_GEMINI_API_KEY')", config)

    def test_nonclinical_allowlist_and_no_raw_user_input(self):
        code = (ROOT / "app/Http/Controllers/Care360AiGuideController.php").read_text(encoding="utf-8")
        self.assertIn("required|in:get_started,appointments,account,partners", code)
        self.assertIn("required|in:en,ha,ig,yo,fr", code)
        self.assertIn("['x-goog-api-key' => $key]", code)
        self.assertIn("config('services.care360_ai.enabled', false)", code)
        self.assertNotRegex(code, r"(?m)^[^/\n]*\$request->(?:all|input|user)\(")
        self.assertNotIn("Authorization' =>", code)
        self.assertIn("'source' => 'curated'", code)
        # The expanded numbered navigation guide remains bounded and non-clinical.
        limits = re.findall(r"'maxOutputTokens'\s*=>\s*(\d+)", code)
        self.assertEqual(len(limits), 1)
        self.assertGreater(int(limits[0]), 0)
        self.assertLessEqual(int(limits[0]), 900)
        output = re.findall(r"mb_substr\(trim\(\$answer\),\s*0,\s*(\d+)\)", code)
        self.assertEqual(len(output), 1)
        self.assertGreater(int(output[0]), 0)
        self.assertLessEqual(int(output[0]), 4500)

    def test_route_requires_auth_and_throttle(self):
        routes = (ROOT / "routes/api.php").read_text(encoding="utf-8")
        self.assertRegex(
            routes,
            r"(?s)Route::post\('/ai/guide'.*?middleware\(\['auth:api', 'throttle:5,1'\]\)"
        )


if __name__ == "__main__":
    unittest.main()

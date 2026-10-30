#!/usr/bin/env python3
"""Offline fail-closed contract checks for the K3 chat push hardening.

These checks inspect source; they do NOT prove runtime authorization or substitute
for Laravel feature tests with synthetic identities and a staging OneSignal app.
"""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
user = (root / 'app/Http/Controllers/UserApiController.php').read_text()
doctor = (root / 'app/Http/Controllers/DoctorApiController.php').read_text()
routes = (root / 'routes/api.php').read_text()
config = (root / 'config/k3.php').read_text()

def method(text, name):
    start = text.find(f'function {name}(Request $request)')
    assert start > 0, f'{name}: missing'
    end = text.find('\n    public function ', start + 1)
    if end < 0:
        end = text.find('\n    private function ', start + 1)
    if end < 0:
        end = len(text)
    return text[start:end]

scenarios = [
    ('patient->doctor', method(user, 'apiSendMessageToDoctorNotificationTrigger'),
     ["Doctor::where('user_id', $recipient->id)", "->where('user_id', $patient->id)", "->where('doctor_id', $doctorId)"]),
    ('doctor->patient', method(doctor, 'apiSendMessageToPatientNotificationTrigger'),
     ["Doctor::where('user_id', $doctorUser->id)", "->where('user_id', $recipient->id)", "->where('doctor_id', $doctor->id)"]),
]
for name, body, booking_terms in scenarios:
    for term in [
        "config('k3.chat_push_enabled', false)",
        "User::where('device_token', $request->input('to_device_token'))",
        "Appointment::query()",
        "->whereNotIn('appointment_status'",
        "->exists()",
        "'message' => 'Not authorized'",
        "'headings' => ['en' => 'New chat message']",
        "'contents' => ['en' => 'You have a new message']",
        "'to_device_token' => 'required|string|max:512'",
        *booking_terms,
    ]:
        assert term in body, f'{name}: missing safety condition {term}'
    assert "['en' => $request->content]" not in body, f'{name}: leaks untrusted message'
    assert "'contents' => ['en' => $request->content]" not in body, f'{name}: untrusted content'
    assert 'return response()->json([\'success\' => false' in body, f'{name}: no fail-closed response'
    assert 'device_token' in body, f'{name}: no recipient binding'

for endpoint in ['send_message_to_doctor_notification', 'send_message_to_patient_notification']:
    pattern = re.compile(r"Route::post\('/"+endpoint+r"'[^;]*->middleware\('throttle:10,1'\)")
    assert pattern.search(routes), f'{endpoint}: missing endpoint throttle'
assert "env('K3_CHAT_PUSH_ENABLED', false)" in config
print('PASS: 2 notification endpoints: role/appointment guard, generic content, off-by-default gate, request limits')

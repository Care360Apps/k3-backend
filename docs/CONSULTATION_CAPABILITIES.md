# Care360 consultation capabilities — implementation contract

## Roles, not separate apps
The existing `doctor` database table owns both doctor and provider profiles, distinguished by `user_role`. `doctor_id` in legacy appointment APIs identifies the professional profile, not necessarily a medical doctor. Do not add a second provider appointment table without a data migration plan.

## Booking and communication permissions
| Capability | Doctor role | Provider role |
|---|---|---|
| Video consultation | If `offers_video_call=1` | If `offers_video_call=1` |
| Home visit | Not allowed | If `offers_home_visit=1` |
| Secure chat | Only authenticated parties with existing booked relationship | Same |
| Audio-only call | Planned. Not an independently bookable visit type yet | Same |
| Prescriptions | Approved/licensed physician only | No automatic permission |

The booking API rejects visit types outside the role-backed capabilities *before* it processes payments, and virtual call tokens must require an eligible video booking. Existing provider profiles with `offers_video_call=0` stay home-visit-only until enabled deliberately; the change does not silently activate telehealth for unreviewed accounts. The Care360 Plus profile is the intended operator control, with backend validation.

## User experience and follow-up work
- **Patient:** One "Book consultation" entry; choose offered remote video or home visit; show type, professional role, duration, total price and cancellation policy before payment.
- **Plus:** Role-aware profile editor and dashboard. Provider: video + home visit toggles; doctor: video only. Common schedule, patient records, notifications, messaging and call history. Prescription creation remains role-restricted.
- **Audio-only:** Requires microphone-only Agora mode, consent wording, call-mode metadata/history, appointment permission and device test. Don't label this live yet.
- **Secure chat:** Verify Firebase UID mapping and Firestore rules for `provider_{userId}`; do not leak medical data or let a frontend-only button grant access.
- **Home visits:** Service area, travel radius, verified location, safety escalation and scheduling buffers.
- **Clinic data:** Clear verified credentials, speciality/type, languages, availability and review origin; avoid implying a provider holds medical-doctor registration.
- **Testing:** Local Laravel backend, staging Firebase identities, patient/doctor/provider synthetic accounts, rejected-role routes, booking/payment and Agora scenarios; never test using real patients.

## Deployment boundary
These source changes do not modify the existing local XAMPP backend automatically. Run and smoke-test an authorised staging backend before merging any changes to a production deployment. No database migration is required by this contract.

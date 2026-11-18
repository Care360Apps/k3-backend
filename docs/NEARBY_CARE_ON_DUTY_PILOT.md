# Care360 Nearby Care — opt-in professional availability pilot

**Status:** source-only draft. Not deployed or connected to real clinical accounts.

This feature adds an optional availability layer behind the existing
Patient/Doctor/Provider apps. It does **not** replace appointment booking,
teleconsultation, payment verification, clinical documentation or medical
record access, and is NOT emergency dispatch.

## Backend contract

1. Apply `2026_11_07_000001_create_professional_service_presence_table.php`
   to isolated MySQL/MariaDB staging after backup and migration review.
   The legacy `doctor` ID type was created by a vendor SQL import, so the
   migration intentionally does not assume a foreign-key type. Confirm the
   true PK type and create a verified FK/cleanup procedure before production;
   delete orphan presence rows if a professional profile is removed.
   Existing professionals are **offline** by default and are not
   published from existing `doctor.lat/lng` coordinates.
2. Authenticated professional:
   - `GET /api/professional/presence` returns only that professional's status
   - `POST /api/professional/presence` with
     `accepting_requests=1`, `confirm_public_location=1`,
     `public_lat`, `public_lng`, `service_radius_km` (1–50),
     `minutes_on_duty` (15–240) activates a temporary presence
   - Sending `accepting_requests=0` deactivates immediately
   - Only an approved, active, completed, subscribed Doctor/Provider whose
     allowed service flags permit appointments can start a shift
   - Shift expires automatically; there is no continuous GPS access
3. Patient/public `POST /api/nearby-care` with coarse, explicitly selected area coordinates in the request body (`lat`, `lng`, `radius_km`, `limit`)
   returns public, opted-in service points only, near the chosen location. Use POST rather than a query-string GET to reduce accidental location exposure in proxy/server access logs.
   Optional filters `role=doctor|provider` and
   `visit_type=video_call|home_visit` are server-enforced.
4. Only coarsened public service-area coordinates (2 decimal degrees,
   approximately kilometer-scale) are stored in this opt-in table; location
   matching and distance responses use those same deliberately approximate
   points, preventing reconstruction of an unrounded address from repeated
   distance queries. This also means the map is **not precise routing**.
   Response uses these coarsened coordinates,
   approximate **straight-line** distance, and `accepting_requests=true`
   for an unexpired shift. It never returns contact numbers, private
   residences, movement trails, patient/clinical records or auth tokens.
5. The app shows **Accepting requests**, not `Available now`, `Booked`
   or `Arriving in 5 minutes`. Actual booking capacity is rechecked using
   the unchanged existing `timeslot` and `book_appointment` flows.
6. Rate limits: public discovery 30/minute; own-status 30/minute;
   status updates 10/minute. Migrate to authenticated/paginated endpoint
   and caching as usage grows. SQL distance ranking currently targets
   MySQL/MariaDB (see tests and operator checklist).

## Partner app review flow

- Doctor/Provider Settings > **Nearby Care availability**.
- Verify the **published practice/service area** fields (not device GPS).
- Choose service radius and 30-minute to 4-hour shift.
- Explicitly confirm public-location consent; then select Start accepting.
- The backend response, not the button tap alone, establishes the status.
- Professional can stop immediately; backend will also stop automatically
  when the shift expires.
- A 404/failed backend response keeps action controls disabled with
  an explanatory message. No simulated online state is accepted.

## Patient app review flow

- Map > **Accepting requests** toggles the live public endpoint, only when
  the patient chooses it and has a manually selected or permitted location.
- If the endpoint is unavailable, show the service-unavailable state.
  Do not invent pins. Existing Doctors/Providers/Hospitals/Labs filters,
  distance cards and profile/booking journeys remain intact.
- Doctor/Provider cards route to their existing profiles. No direct
  video/home-visit dispatch is invented at this stage.

## Safety and acceptance gate

- Test **unapproved, inactive, incomplete, unpaid-subscription, unrelated
  account and unauthenticated** actors cannot publish availability.
- A user cannot edit any other professional's presence.
- No professional appears online until explicit consent and on-duty start.
- Validate bad lat/lng, (0,0), out-of-radius service areas, denied GPS,
  expired shift, offline toggle, and role-specific visit types.
- Verify public response exposes only coarse published location and
  minimal professional listing data; do not use device-current GPS
  as a public professional location.
- Use synthetic staging users and MySQL/MariaDB for exact-distance
  SQL query, migration up/down and authorization feature tests.
- Validate provider/doctor profile navigation and consultation eligibility
  on physical Android, BlueStacks and iOS staging.
- Confirm payment status remains gateway-verified and patient medical files
  are authorized. Backend issue #22 and security PR #21 still block release.
- Test 3G/offline behavior, cache expiry, 20/50 km range, and concurrency.
- Do not claim route ETA, live arrival tracking, driver-style accept/reject
  dispatch or a confirmed slot from this presence endpoint.

## Planned phase: request / accept / dispatch

Build a separately reviewed, authenticated request model with idempotent
state transitions and professional notifications. The professional must
accept; only then create/confirm a booking. Home-visit position sharing
requires explicit time-limited consent, is limited to the assigned
patient/provider and is revoked upon arrival, cancellation or completion.
Virtual calls need no GPS.

## Correct deployment sequence

1. Review and merge approved provider discovery base PR #25.
2. Deploy migration and Nearby Care backend on isolated staging.
3. Build Doctor/Provider app with availability screen and Patient app
   with on-duty map toggle, pointed **only** to staging.
4. Perform security and integration QA, then obtain owner approval.
5. Original publisher/developer deploys backend change before signed
   Play Store/App Store client update; preserve signing keys privately.
6. Activate for approved pilot professionals first, then expand.

**No real server, account, location data or app-store listing has been
modified as part of these draft source changes.**

# Care360 Patient — partner and sponsored carousel

## Placement
Only the authenticated Patient home feed displays the discovery carousel. MindCheck by SpeakOut Mental Health Outreach is a fixed unpaid **Partner** card. Active backend banners appear alongside it. Neither Care360 Plus nor active clinical views display advertising.

## Behaviour
- Patient carousel rotates every **5 seconds**, with swipe, manual controls and reduced-motion support.
- Every clickable destination is an independently approved, public HTTPS site. No Care360 patient ID, medication, diagnosis, booking, session token or referrer query parameters are appended.
- Admin banner records carry title, description, uploaded image, destination, status, optional campaign start/end dates, and a **Sponsored** checkbox.
- Advertisements must be clearly identified as **Sponsored**; unpaid partner listings are identified as **Partner**. External sites carry their own policies.
- Admins can enable, disable or schedule campaigns without publishing a new APK after the Laravel migration is deployed.
- This release does **not** count impressions/clicks for billing; partner pricing and placement must be agreed and invoiced separately. Avoid claims of verified reach or conversion until non-identifying measurement is developed and independently reviewed.

## Safeguards
- Reject http, localhost, private-IP, username/password URL and nonstandard-port partner destinations.
- Avoid personalised advertisements derived from health information, clinical history, location, prescriptions or mental-health screening.
- Run an editorial and legal review before accepting any health-product, medication, financial or treatment advertising. No claims of clinical endorsement.
- Keep partners distinct from paid advertisers; do not mislabel legacy banners as paid when they are not.

## Before publishing
1. Backup staging database. Apply `php artisan migrate --force` to staging *only* after confirming schema and rollback. The migration adds `is_sponsored`, `starts_at`, and `ends_at` to `banner`.
2. In admin banner panel, test create/edit/schedule/disable more than 3 campaigns, including unsafe URL rejection.
3. Test Patient rotation (five seconds), swipe, links, labels, disabled campaigns, offline states and 320px screens with synthetic accounts.
4. Verify paid-placement sales/agreements, ad-content review and transparency policy. Do not claim live monetisation merely because carousel source code merged.
5. Final production rollout requires staging smoke tests and explicit cutover approval; GitHub quality checks do not deploy to Namecheap.

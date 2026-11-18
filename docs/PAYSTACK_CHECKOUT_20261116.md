# Paystack checkout integration and acceptance

## API contract

Authenticated `POST /api/paystack_init` supports Patient and Plus. New clients send `amount` in major currency units with `amount_unit: major`; the server converts to minor units once. For existing Plus clients which already send kobo, omitted `amount_unit` means minor units. Currency must match server settings; payer email and user metadata come from the authenticated account, not the request's email field.

Successful initialization retains `access_code` and `reference` for existing clients and adds validated `authorization_url` and same-backend `callback_url`. Only known HTTPS Paystack checkout hosts are accepted. Network failures and malformed/unsuccessful provider responses return a generic error without logging private exception data.

`GET /api/paystack/return` is a stateless browser landing page, NOT a webhook and NOT proof of payment. Patient intercepts only its configured return origin/path and expected reference, then submits the reference to its existing booking/order API. Those APIs must verify gateway status, exact Paystack amount, currency, authenticated owner and receipt reuse before creating a paid record. The existing atomic receipt migration/gate must be deployed and validated before production enablement.

## Mobile changes

- Patient consultation, medicine and laboratory Paystack choices previously displayed unavailable messages. They now use one hosted-checkout component built on the existing WebView dependency, with a same-reference return check, secure URL validation, cancellation confirmation and serialized Paystack submission. The client never receives a gateway secret or collects raw card details.
- Plus previously multiplied plan prices by 100 before the backend multiplied again. Its shared API client now explicitly sends major units; its native SDK is launched with the server access code and only the matching server-created reference is submitted for subscription activation.
- Raw request-body, payment-reference and exception logging was removed from the touched payment screens. Existing payment methods and profession permissions are preserved.

Deploy the coordinated backend before the new apps. Keep production Paystack disabled until remaining acceptance and reconciliation/operator procedures are complete. Existing Plus checkout requests in minor units remain supported.

## Validation and limits

The new mobile client tests cover amount units, untrusted checkout/callback URLs, secure API transport, malformed responses and expected-reference return matching. Backend tests cover single conversion, existing-client units, server-derived identity, currency/credential failures, malicious provider URLs and safe timeout handling. The payment-service harness also rejects overpaid receipts instead of silently accepting an accidental multiplied price.

Local sandbox initialization was exercised through the authenticated API using dedicated fictional accounts. Reserved `.test` addresses are rejected by Paystack; payment fixtures therefore use exact, reserved `care360.example.com` addresses and are separate from the existing demo logins. A trusted CA bundle is configured only for the loopback test runtime; do not disable certificate verification. Only one owned test server should listen on the local API port.

Gateway initialization and automated tests are not completed-payment evidence. Direct test-card Charge API simulation returned HTTP 403 and was not counted as success. Native/hosted successful and declined checkout, backend finalization, receipt replay, timeout/restart recovery, stock/slot failures after charging, refunds, deployed webhook/reconciliation, real-device compatibility and controlled live acceptance remain explicit release gates until demonstrated. A new return route does not implement webhooks. Local flags and fictional data do not authorize production deployment.

Credentials and checkout capability URLs belong in protected private storage, never source control or this document. Live keys must never be loaded by the local sandbox harness. Do not send additional external emails based on the separate, already-completed single-message SMTP test.

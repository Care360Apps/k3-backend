# Care360 Pharmacy — stock import and 15% marketplace commission

## Financial contract
- A pharmacy enters its **selling price per strip**. We do **not** increase the displayed price automatically by 15%.
- Default Care360 platform fee is **15% of the medicine subtotal only**, calculated on the backend at checkout.
- Pharmacy settlement is the **remaining 85%** at the default rate, before separately disclosed gateway costs or adjustments.
- Delivery charges are not part of the commission base and must be accounted for separately.
- Admins may configure a different commission percentage **per pharmacy**, 0–100%, with audit/history planned. Existing rates must not silently be overwritten.
- Money values are rounded to 2 decimals and reconcile exactly. Never trust subtotal, quantity or percent submitted by a mobile client.
- Example: 10 strips at ₦1,000 each = ₦10,000 medicine subtotal; Care360 fee ₦1,500; pharmacy share ₦8,500. Delivery ₦500 is accounted for separately. Patient total is ₦10,500, **not ₦12,000**.

## Existing pharmacy web portal
1. Sign in through the existing pharmacy web dashboard.
2. Open Medicines > Bulk Upload. Download the current CSV template.
3. Enter `name,category,price_pr_strip,number_of_medicine,incoming_stock,description,works,prescription_required,status`.
4. Select Preview & validate to check required data and new/updated/restocked counts **without database mutation**.
5. Select file again, Import medicines and confirm to make the all-or-nothing inventory update.
6. Review product prices, stock, prescription-required flags and photos; publish after review.

Each upload supports up to `MedicineImportService::MAX_ROWS` products (currently 1,000) and a 2 MB file. For larger inventories, split into batches. Changes to pricing and stock should be auditable before production release.

## Production safeguards still required
- Stock reservation/locking under simultaneous checkout; existing stock verification and increment are not a complete concurrency guarantee.
- Pharmacy product identifiers/SKU to avoid ambiguous updates by name; barcode, batch, expiry, manufacturer, dosage/unit conventions and zero-stock handling.
- NAFDAC/registration and prescription verification flows appropriate for the medicines being offered.
- Immutable fee-rate snapshot on each order, refund/chargeback handling, gateway fee allocation, and settlement reconciliation dashboard.
- Verification with a local or staging Laravel database; web and mobile end-to-end payment flows. Do not run production migrations or test charges with real cards.

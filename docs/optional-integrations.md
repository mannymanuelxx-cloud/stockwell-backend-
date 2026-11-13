# Optional storefront integrations

These settings are optional. The CSV export works without external credentials.
Google Sheets API, manual GCash transfer, Turnstile, and Google Analytics
remain disabled until configured.

## Cloudflare Turnstile for customer registration

1. In Cloudflare, create a Turnstile widget for the storefront hostname
   `stockwell-storefront.onrender.com`.
2. Add both keys to the Render `stockwell-api` service:
   - `TURNSTILE_SITE_KEY`: public site key
   - `TURNSTILE_SECRET_KEY`: private secret key
3. Keep the secret key private and redeploy the API.

The site key is returned by the public integration-config endpoint; the secret
stays on the backend. The storefront renders a widget only on customer
registration. The API validates the single-use token with Cloudflare and checks
the expected registration action and configured frontend hostname. If only one
key is set, registration fails closed with a service-unavailable message.

## Google Analytics 4 page views

1. In Google Analytics, create a GA4 property and a Web data stream for
   `https://stockwell-storefront.onrender.com`.
2. Copy the stream's Measurement ID (format `G-...`).
3. Add `GA4_MEASUREMENT_ID` to the Render `stockwell-api` environment and
   redeploy.

The storefront asks visitors before loading Google Analytics. Only page views
for customer-facing storefront/auth screens are sent after consent; admin pages
are excluded. The integration does not send account names, emails, addresses,
order details, passwords, ecommerce events, or advertising events. In the GA4
Web data stream settings, disable Enhanced Measurement events other than page
views if you want to keep this page-view-only scope. Visitors can change their
choice with **Privacy choices** in the footer.

## Manual GCash transfer

1. In the GCash app, open **Receive Money / My QR** and save the correct
   receiving QR image. Host it at a public HTTPS image URL ending in `.png`,
   `.jpg`, `.jpeg`, or `.webp`.
2. Add these values to the Render `stockwell-api` environment:
   - `GCASH_ACCOUNT_NAME`: the recipient name shown in GCash
   - `GCASH_ACCOUNT_NUMBER`: the receiving GCash mobile/account number
   - `GCASH_QR_IMAGE_URL`: public HTTPS URL of the QR image
3. Keep the receiving details accurate. They are intentionally displayed to
   storefront customers. Never use someone else's QR/account without consent.
4. Redeploy the API and refresh the storefront. GCash appears only when all
   three values are valid.

Customers can still choose Cash on delivery. For GCash, the customer places the
order, transfers the exact displayed total, then submits the transaction
reference under **My orders → Details**. The order remains unpaid until an
authorized admin checks the GCash transaction and manually marks the order paid
in the Orders section. A submitted reference is not proof of payment by itself.
Paid orders retain the existing stock reservation and fulfillment flow.

## Google Sheets API manual sync

1. In Google Cloud, enable the Google Sheets API and create a service account.
2. Create or choose a spreadsheet, then share it with the service account's
   `client_email` as an Editor.
3. Add these variables to the Render `stockwell-api` environment:
   - `GOOGLE_SHEETS_SPREADSHEET_ID`: the ID between `/d/` and `/edit` in the
     spreadsheet URL
   - `GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON`: the full service-account JSON key
4. Keep the JSON key private; never put it in the storefront, source control, or
   chat. Redeploy the API after saving the environment variables.
5. Sign in as an administrator, open **Reports**, select a date range, and
   choose **Sync to Google Sheets**.

The sync creates and replaces data only in the dedicated **Stockwell Sales**,
**Stockwell Orders**, and **Stockwell Inventory** tabs. Sales and orders use
the selected report date range; inventory is a current stock snapshot. The
export includes IDs, dates, status, payment method/status, totals, and product
stock fields, but does not include customer contact details or delivery
addresses. Do not use the three dedicated tabs for unrelated data because each
sync replaces their contents. Other spreadsheet tabs are left unchanged.

## Google Sheets-compatible CSV export

The Reports page's **CSV for Google Sheets** button downloads the selected
report as a CSV file. In Google Sheets, use **File → Import → Upload** and
select the downloaded file. This remains available as a separate manual export.

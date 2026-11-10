# Optional storefront integrations

These settings are optional. Google Sheets export works without external
credentials. Turnstile and Google Analytics remain disabled until configured.

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

## Google Sheets-compatible manual export

The Reports page's **CSV for Google Sheets** button downloads the selected
report as a CSV file. In Google Sheets, use **File → Import → Upload** and
select the downloaded file. This is a manual export only: Stockwell does not
connect to a Google account or automatically sync customer or order data.

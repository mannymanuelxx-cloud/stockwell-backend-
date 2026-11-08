# Brevo transactional email setup

Stockwell sends order notifications and password-reset emails through Brevo's
transactional email API when `BREVO_API_KEY` is configured. The API uses HTTPS
and takes precedence over the existing SMTP fallback.

## Brevo setup

1. Create or sign in to a Brevo account.
2. Verify a sender email address in Brevo. The sender must be permitted for
   transactional email before Stockwell can send from it.
3. Create a Brevo API key with permission to send transactional emails.
4. In Render, open the `stockwell-api` service and add:

   | Key | Value |
   | --- | --- |
   | `BREVO_API_KEY` | The API key from Brevo |
   | `BREVO_FROM_EMAIL` | The verified sender email address |
   | `BREVO_FROM_NAME` | `Stockwell` |
   | `BREVO_REPLY_TO` | Optional support email address |
   | `BREVO_REPLY_TO_NAME` | Optional; defaults to `Stockwell` |

5. Save, rebuild, and deploy the API service.

Keep the API key private. Do not send it in chat or commit it to source control.
For local development, set these values in an untracked `.env` file.

If `BREVO_API_KEY` is unset, the mailer continues using its configured SMTP
fallback. If the key is set but the sender is invalid or unverified, the mail
send fails explicitly instead of silently falling back to SMTP.

## API behavior

Stockwell calls Brevo's `POST /v3/smtp/email` endpoint over HTTPS with the API
key in the `api-key` request header. Existing order, return, and password-reset
messages keep their current content and are sent as transactional emails.

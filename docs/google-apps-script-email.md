# Free Gmail email relay for Render

This relay sends Stockwell password-reset, order, and return emails through
Google Apps Script over HTTPS. The Gmail account that authorizes the script is
the sender. A personal Google account currently has a quota of 100 email
recipients per day; Google can change quotas, and Gmail is not a transactional
email delivery guarantee.

## 1. Create the relay script

1. Sign in to the Gmail account Stockwell should send from.
2. Open [Google Apps Script](https://script.google.com/home/start) and create a
   standalone project named `Stockwell Email Relay`.
3. Replace the editor contents with
   [`scripts/google-apps-script-email-relay.gs`](../scripts/google-apps-script-email-relay.gs)
   from this repository and save.
4. Open **Project Settings** → **Script Properties** → **Add script property**.
   Set the property name to `RELAY_SECRET`. For its value, generate a unique
   random secret on your own computer, for example in PowerShell:

   ```powershell
   node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
   ```

   Keep the generated value private. Use the same value in the Script Property
   and in Render; do not send it in chat or commit it to source control.

5. Select **Deploy** → **New deployment** → gear icon → **Web app**.
   - **Execute as:** Me
   - **Who has access:** Anyone
6. Click **Deploy**, authorize the script to send email using your Google
   account, then copy the web app URL ending in `/exec`. Do not share that URL
   publicly.

The endpoint must be publicly reachable by Render, so it uses
`GMAIL_RELAY_SECRET` to authorize each request. Keep this secret private. If it
is exposed, change the Script Property and the Render value immediately.

## 2. Configure Render

In the Render Dashboard, open the `stockwell-api` service → **Environment** and
add:

| Key | Value |
| --- | --- |
| `GMAIL_RELAY_URL` | The Apps Script web app URL ending in `/exec` |
| `GMAIL_RELAY_SECRET` | The same random secret saved as the `RELAY_SECRET` Script Property |

Save changes and wait for the service to redeploy. Do not remove any other
environment variables.

## 3. Test delivery

Request a password reset for your own Stockwell account and check the inbox and
Spam/Junk folder. Then verify that a customer order status update sends its
notification. Google Apps Script's **Executions** page and the Render API logs
can help diagnose failures. The API intentionally returns a generic response
for password recovery whether or not an account exists.

If you change the Apps Script code later, create a **new deployment version** or
edit the existing deployment to point to the latest version.

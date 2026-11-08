# Google sign-in setup

Stockwell Google sign-in is for customer accounts only. Existing staff and
administrator access remains on the regular username/password sign-in.

## Google Cloud setup

1. Open [Google Cloud Console](https://console.cloud.google.com/) and select or
   create a project.
2. Configure the OAuth consent screen. Use **External** for customer accounts
   and add test users while the app is in testing.
3. Create an OAuth client ID with application type **Web application**.
4. Add this exact **Authorized redirect URI**:

   ```text
   https://stockwell-api-nlyi.onrender.com/api/auth/google/callback
   ```

5. Copy the client ID and client secret. Keep the secret private.

## Render setup

In the Render Dashboard, open `stockwell-api` → **Environment** and add:

| Key | Value |
| --- | --- |
| `GOOGLE_OAUTH_CLIENT_ID` | The OAuth client ID from Google Cloud |
| `GOOGLE_OAUTH_CLIENT_SECRET` | The OAuth client secret from Google Cloud |
| `GOOGLE_OAUTH_REDIRECT_URI` | `https://stockwell-api-nlyi.onrender.com/api/auth/google/callback` |

Save changes and wait for the API to redeploy. Do not send OAuth credentials in
chat or commit them to source control.

The storefront shows **Continue with Google** on the sign-in form. Google
accounts with a verified email are matched to an existing customer account or
create a new customer account. Google sign-in cannot create or access staff or
administrator accounts. A newly created customer can add a delivery address
in the Stockwell account page before checkout.

Google sign-in is separate from email delivery; it does not send password-reset
or order-notification messages.

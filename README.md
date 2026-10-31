# Ontraport to SamCart Cancel

A small WordPress plugin that receives an unsubscribe automation webhook, verifies the contact through the Ontraport API, reads a configured contact field, and immediately cancels that numeric subscription ID through SamCart API v1.

## Install and configure

1. Copy the `ontraport-to-samcart-cancel` folder into `wp-content/plugins/` (or zip the folder and upload it in WordPress), then activate it.
2. Open **Settings → Ontraport → SamCart**. Enter the Ontraport App ID and API key, and the SamCart API key. Save.
3. Click **Load Ontraport fields**, select the contact field containing the SamCart subscription ID, and save again. The field must contain a positive numeric SamCart v1 subscription ID. Leave **Suppress SamCart’s cancellation email** off to use SamCart’s normal email behavior.
4. In the Ontraport automation that runs for the intended unsubscribe form, add **Send to a webhook**. Use **POST**, paste the destination URL shown on the plugin settings page, choose JSON for the Content-Type header, and set POST data to `{"contact_id":"[Contact ID]"}` using Ontraport’s Contact ID merge field picker. Do not add the Ontraport or SamCart API keys to the webhook.
5. Test with an appropriate contact in a safe account. Review the HTTP response in Ontraport’s webhook activity log and **Last webhook result** in the plugin settings.

The webhook URL contains a secret token. Treat it as a password. **Generate a new webhook URL** on the settings page if it is exposed, then replace the URL in Ontraport. The WordPress site should use HTTPS. The plugin does not create the Ontraport automation or the contact field for you.

## Behavior

- Invalid token: HTTP 401. Invalid contact ID: HTTP 400. Contact not found: HTTP 404.
- Verified contact with no subscription ID: HTTP 200 and no SamCart call.
- Verified contact with a nonnumeric subscription ID: HTTP 422 and no SamCart call.
- Successful cancellation: HTTP 200. A repeat webhook for the same contact and subscription is acknowledged without another SamCart call for seven days.
- Ontraport or SamCart API problems return an error so the webhook can be reviewed or retried. SamCart HTTP 409 means the subscription cannot be canceled in its current status.

The plugin reads the subscription ID from Ontraport, never from the incoming webhook. It stores API keys in WordPress options, shows only placeholders after saving, and stores only a short last-result summary (no keys or contact details). Restrict WordPress administrator access and protect database backups accordingly.

## API references

- [Ontraport single contact and metadata endpoints](https://api.ontraport.com/doc/)
- [Ontraport webhook setup](https://ontraport.com/support/Integrations/webhooks)
- [SamCart API v1 cancellation endpoint](https://developer.samcart.com/v1/)

## Local verification

Run `php -l ontraport-samcart-cancel.php` and `php tests/run.php`. The latter uses WordPress function stubs and fake API responses; no real cancellation is sent.

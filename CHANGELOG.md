# Changelog
## 1.1.0 - 2026-05-17

### Changed
- Default API base URL is now https://api.nakopay.com/v1 (branded primary). Added BASE_FALLBACK constant pointing at Supabase functions URL.

## 1.0.0 (2026-05-01)

### Added
- Drupal Commerce off-site payment gateway (`nakopay`)
- Admin configuration form for API key and webhook secret
- Off-site redirect form using NakoPay hosted checkout
- Webhook controller at `/nakopay/webhook` with HMAC-SHA256 signature verification
- API client service (`nakopay.api_client`) with idempotency keys
- Automatic order state transitions on `invoice.paid` / `invoice.expired`
- Compatible with Drupal 10 and 11

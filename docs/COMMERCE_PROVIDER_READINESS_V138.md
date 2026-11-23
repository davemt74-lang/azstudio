# v1.38.0 — Commerce Provider Readiness

Staff-only, read-only Commerce provider configuration and reconciliation review. No schema change (43). CI verifies PHP role gating, secret isolation, test-vs-live modes, no production sign-off and responsive 1440/768/390/320 px browser acceptance with no provider POST requests.

Hosted Stripe TEST refund, EasyPost TEST return label, Connect TEST transfer, signed webhooks and timeout/replay checks must still be verified independently before allowing live money movement. Live financial actions remain disabled by default; this dashboard neither enables them nor claims provider acceptance.

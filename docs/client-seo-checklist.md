# SEO and launch checklist

Giuseppe’s approval note skipped from section 7 to section 9. There is no section 8 in that note. Renumber the old section 9 to section 8, then keep the points below in that section.

## 8. Before approval

- Heritage Nº 001 stays one product with all 100 numbers. Number 001 is shown in the register as Maison Archive and is not for sale. Numbers 002 to 100 stay available.
- Test accounts `yusuf_savran@icloud.com` and `admin@dev.com` may be deleted only after Giuseppe confirms that his real admin login is a different account. Do not delete them before that confirmation. `admin@dev.com` is a seeded development admin. `yusuf_savran@icloud.com` is not created by the seeders.
- Stripe live keys are entered by this team through Stripe team access. Checkout already requests card and Bancontact. Turn on Apple Pay and Google Pay in the Stripe Dashboard, including domain verification. After the live keys are in place, confirm the live webhook receives `checkout.session.completed`.
- Brevo: delete only test contacts and test lists. Real subscribers and their consent records stay. Giuseppe will add a Brevo user, so do not ask for login codes by email.
- Account registration stays on for this test. Switch it off before go-live (`MAISON_REGISTRATION_ENABLED` and storefront access). Do not switch it off on staging while the test is still running.
- Privacy: cookie banner with Necessary and Analytics only, Google Analytics, DeepL Pro, server logs kept 30 days, backups kept 4 weeks.
- Google Analytics: set the Measurement ID he provided (`ANALYTICS_MEASUREMENT_ID`). Set data retention to 14 months in GA4 Admin, under Data settings, Data retention. The website cannot set that retention.
- DeepL: when the Pro key arrives by one-time link, replace `DEEPL_API_KEY` and remove the free key and any `api-free.deepl.com` host. Do not commit the key.
- Session emails already send in the user’s language (NL, FR, or EN), including cancellation and the join request to the organiser. A screen recording of each email is a manual check, not a code change.

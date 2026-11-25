# Famly Enquiry Form (wp-famly)

A WordPress plugin for nursery websites that use [Famly](https://www.famly.co/). Each nursery can work in one of two modes:

- **Link mode.** A styled "Book your tour" button opens the nursery's own Famly enquiry form. Parents can book a tour slot there, and the answers to the nursery's custom questions land in proper Famly fields. No API access is needed. Famly forms can't be embedded (app.famly.co sends `X-Frame-Options: SAMEORIGIN`), so this is a link.
- **API form mode.** An on-page enquiry form, styled to match the site, that creates the enquiry in Famly through the [Famly Public API](https://docs.famly.co/) (`inquiries.create`). It needs Famly's paid API add-on and an API token. Tour booking isn't possible through the API, so staff arrange tours.

Built by Two Ten Studio. The markup, styling hooks and GTM events follow our EYWorks Enquiry Form 3.x, so a site can switch over with only a shortcode change.

## Shortcode

```
[famly_enquiry_form site="deptford"]
```

`site` is a nursery key from **Famly Enquiries → Settings → Nurseries**. You can also use the nursery's name. Only one form per page is supported.

## API form mode

### What gets sent to Famly

| Form field | Famly `InquiryInput` |
|---|---|
| Child first + last name | `name` (Famly takes a single full-name string) |
| Child date of birth / expected DOB | `birthday` (required by Famly) |
| Legal gender: Female / Male | `gender` FEMALE / MALE ("Unknown / Other" goes in the note) |
| Preferred start date | `startDate` (required by Famly) |
| Parent first + last name, email | `contacts[0].name`, `contacts[0].email` (required by Famly) |
| Phone | `contacts[0].mobilePhone` (normalised to +44…; otherwise added to the note) |
| How did you hear about us? | `source` (mapped to Famly's fixed list), and the label in the note |
| Postcode, extra questions, consent, UTM | `note` (Famly's API has no fields for these) |
| — | `bookingSource: WEBSITE`, `referenceNumber: {foreignId: "wp-<id>", system}` |

### Nothing is lost, nothing is kept

1. Every enquiry is saved to the `wp_famly_enquiries` table before Famly is called.
2. If Famly fails (connection error, expired token, missing site ID, validation error), the enquiry is marked **Not in Famly**. An **ACTION NEEDED** email with all the details goes to the notification addresses, and the admin menu shows a count.
3. **Details aren't kept.** As soon as an enquiry has been sent to Famly or emailed, its personal details are removed from WordPress (`famly_ef_anonymise_enquiry`). A clean-up every 5 minutes (`famly_ef_purge`, also `wp famly-enquiry purge`) catches anything left. A submission stuck "sending" for 10+ minutes gets its ACTION NEEDED email first. Only the date, nursery, outcome and a hash of the Famly ID stay, so the ACTION NEEDED email is the only copy of a failed enquiry. Rows from before 1.2.0 are left alone unless you run `wp famly-enquiry purge --include-existing`.
4. **Famly Enquiries** in wp-admin can mark a failed enquiry **Added by hand**. An enquiry that already has a Famly ID is never resent.
4. The parent always sees the thank-you message once the enquiry is saved.

### Spam protection

- A honeypot field, hidden with `display:none` and ignore flags for password managers. A filled honeypot only holds the enquiry (**Held**, not sent) when Turnstile hasn't verified the visitor, because autofill sometimes fills hidden fields.
- Cloudflare Turnstile in `interaction-only` mode. It can reuse the Gravity Forms Turnstile add-on's keys. If Cloudflare is unreachable, the enquiry is let through rather than lost.
- A WordPress nonce. A stale nonce on a cached page doesn't block a submission that passed Turnstile.

## Configuration

Settings live in **Famly Enquiries → Settings**. Secrets can go in `wp-config.php` instead, and constants take priority:

```php
define('FAMLY_API_TOKEN', '…');               // organisation token, used for every nursery
define('FAMLY_API_TOKEN_SURREY_QUAYS', '…');  // optional per-nursery token (key upper-cased, - → _)
define('FAMLY_API_URL', 'https://famlyapi.famly.de/v1/graphql'); // only for app.famly.de
define('FAMLY_NOTIFY_EMAIL', 'team@example.com');
define('FAMLY_TURNSTILE_SITE_KEY', '…');
define('FAMLY_TURNSTILE_SECRET', '…');
```

### Getting a Famly API token

API access is a paid Famly add-on (support@famly.co). Once it's enabled, create either kind of token:

- **Organisation token:** Home → Overview → choose the organisation → Settings → Manage API Tokens → Add new token.
- **Site token:** Settings → Integrations → Manage API Tokens → Add new token.

Give it a publisher name such as "Website", a long expiry, and the **Enrolment → Manage enquiries** permission only. A site token only covers the site it was created in, so switch Famly's site picker to the right nursery before creating it. Then use **Test connection** on the settings page to list the sites the token can see, along with their site IDs.

### Extra questions (API form mode)

One per line, in this form: `Question | select | Option A; Option B`, `Question | checkbox* | Mon; Tue` (`*` = required) or `Question | text`. A nursery can have its own list. The answers go into the Famly note and the emails.

## New-enquiry alerts from Famly

Famly doesn't email the nursery when a family enquires, and it has no enquiry webhooks. So the plugin checks each nursery's Famly enquiries every 10 minutes (`inquiries.listBySiteIdsPaginated`, newest first) and emails anything new to the notification email, plus the nursery's extra address.

- **First check:** the first check for a nursery only records the enquiries already there, so the backlog isn't emailed.
- **Which enquiries:** by default only online enquiries trigger an email. That means Famly forms and lead sites (booking source `WEBSITE`, `CHILDCARE_SITE`, `DAYNURSERIES_SITE`, `NUURI_SITE`, `FACEBOOK`, or "created through registration form"), not ones staff add by hand or import. A setting switches this to every new enquiry.
- **No double emails:** enquiries this plugin created itself (API form mode) are skipped, because they were emailed on submission.
- **What's in the email:** it's kept short: subject "New enquiry – Surrey Quays", then the nursery, the enquirer's name and email, and a link to the nursery's Famly enquiries. Reply-To is set to the enquirer.
  - The name and email come from the enquiry's contact (needs **View child information** and **View contact details**), or else from the matched Famly form answers (needs **View forms and responses**).
  - With **Manage enquiries** only, they show as "not available", with a one-line hint to check Famly.
- **Failures:** after 3 failed checks in a row (usually an expired or revoked token), one "ACTION NEEDED" email goes out. A "working again" email follows when it recovers.
- **What's stored:** only keyed hashes of the Famly enquiry IDs already seen (so nothing is emailed twice), timestamps, counts and a fixed error description. Famly's response text and enquiry data are never stored.
- **Status:** **Famly Enquiries** in wp-admin shows the last check, the last new enquiry, any errors, and a **Check Famly now** button.

**Scheduling:** it runs from WP-Cron (`famly_ef_poll`, every 10 minutes). WP-Cron only runs when the site gets traffic, and page caching reduces that, so use a server cron as well, for example:

```
*/5 * * * * cd /path/to/webapp && wp cron event run --due-now --quiet
```

or `wp famly-enquiry poll --quiet` directly. A lock stops overlapping runs.

## Tracking

| Event (`dataLayer`) | When |
|---|---|
| `tour_booking_attempted` | Submit pressed and the form is valid |
| `tour_booking_submitted` | Enquiry saved. Also fires the DOM event `famly:enquiry-submitted` |
| `tour_booking_error` | Submission failed |
| `tour_booking_link_clicked` | Link mode: "Book your tour" clicked. Also fires `famly:tour-link-clicked` |

All events carry `nursery`. The AJAX action is `famly_submit_enquiry`.

## WP-CLI

```
wp famly-enquiry status            # config + counts, no personal data
wp famly-enquiry poll [--dry-run] [--seed]  # check Famly for new enquiries and email them
wp famly-enquiry check             # per nursery: does its token see its site, with the enquiries permission? (read-only)
wp famly-enquiry sites             # Famly sites visible to the shared token
wp famly-enquiry dry-run --site=…  # checks the payload against Famly's schema WITHOUT a token (creates nothing)
wp famly-enquiry retry <id>        # send a stored enquiry to Famly
```

## Updates

The plugin checks GitHub Releases on `twotenstudio/wp-famly`. To ship an update, bump the version in `wp-famly.php` (header and `FAMLY_EF_VERSION`), tag it as `vX.Y.Z`, and publish a Release.

## Changelog

### 1.2.0
- Nothing personal is kept: on-page enquiries have their details removed as soon as they've been sent to Famly or emailed, with a 5-minute clean-up as a backstop. Spam-held entries store no details. Rows from before 1.2.0 are untouched unless purged with `--include-existing`.
- The new-enquiry alerts store keyed hashes of Famly enquiry IDs (not the IDs) and only fixed error descriptions (never Famly's response text).
- New `wp famly-enquiry purge`. The DB schema check now also runs for cron/CLI requests.

### 1.1.1
- The new-enquiry alert email is now short: "New enquiry – <nursery>", then the nursery, the enquirer's name and email, and a Famly link, with Reply-To set to the enquirer. The child, phone, tour, form answers and notes are no longer included. The failure and recovery emails are unchanged.

### 1.1.0
- New-enquiry alerts: checks each nursery's Famly enquiries every 10 minutes and emails new ones (with form answers when the token can read them). Includes first-run seeding, no double emails for this site's own enquiries, a failure alert after 3 failed checks, a dashboard status panel, a "Check Famly now" button and `wp famly-enquiry poll`.

### 1.0.1
- Fix: browser or password-manager autofill could fill the hidden honeypot field, so real enquiries were held and never sent to Famly. The honeypot is now `display:none` with ignore flags, and it only holds an enquiry when Turnstile didn't verify the visitor.
- Test connection (and the new `wp famly-enquiry check`) now checks each nursery's token against that nursery's own site and the enquiries permission, without reading or creating anything.

### 1.0.0
- First release. Link mode and API form mode per nursery, local storage, ACTION NEEDED email fallback, manual retry, honeypot and Turnstile, GTM events, CSV export, WP-CLI commands, and a GitHub updater.

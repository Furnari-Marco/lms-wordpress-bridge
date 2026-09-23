# Design decisions

Why this integration is shaped the way it is. Most of these were settled by running it against a live platform, not decided up front.

## 1. WordPress mirrors the platform, it does not query it

The LMS owns the truth about who bought what. WordPress keeps a copy, fed by webhooks.

The alternative is asking the platform on every entitlement check. That puts a third-party network call on the critical path of page rendering, makes every membership check subject to someone else's rate limit, and turns an outage over there into an outage over here. The cost of mirroring is eventual consistency, measured in the seconds a webhook takes to arrive, which nobody notices.

## 2. Custom tables, not a custom post type

The mirror is read by `(email, course_id)` and written by webhooks arriving in bursts. As posts and post meta that lookup becomes a meta join, and a full re-sync becomes thousands of post inserts with every hook and index update that implies.

Custom tables are the wrong default in WordPress and the right answer here. The test for which is whether the data is content, and this is not: nobody edits it, searches it, or attaches a template to it.

## 3. Webhook authentication is a switch, not a requirement

The key is generated at activation but only enforced when an operator ticks a box.

The webhook URL lives in the platform's admin panel and is updated by a human. Enforcing immediately means every delivery is rejected between deploy and that human getting around to it, and rejected deliveries are not replayed. The settings screen therefore spells out the order rather than just labelling the field.

This is the decision I would defend hardest. It looks like weaker security and it is the reason the rollout loses nothing.

## 4. Unknown events get a 200

A 4xx tells the platform the delivery failed. It will retry, back off, retry again, and eventually fill its queue with an event this site will never understand, delaying the ones it does.

Acknowledging what we cannot process is not sloppiness. It is the difference between ignoring one event and degrading the whole feed.

## 5. Selective upsert, never REPLACE

**Found in production:** the first version used `$wpdb->replace()` on re-enrollment. A re-enrollment webhook wiped the sale price and the progress already stored for that row, so revenue reports drifted and nobody could say when it had started.

Now each handler selects the row, then updates only the fields the event actually carries. Re-delivery of the same event converges instead of destroying.

## 6. Sales and refunds are scoped to a course

**Also found in production:** sale and refund events were applied by email alone, so one purchase was attributed to every course the learner owned, and one refund deactivated all of them.

With a course id, only that row is touched. Without one, a sale fills only rows with no price yet, and a refund hits only the most recent paid purchase. The refund uses a derived-table subquery because MySQL will not let you select from the table you are updating.

## 7. The SSO signature covers the redirect

**Found by reading it again:** the original signature covered `email|ts|token` and `redirect_to` was followed blindly. That is an open redirect, which is a phishing primitive with a legitimate domain attached, reachable by anyone who ever saw one valid link.

Now the HMAC covers all four fields, the target is validated against a host whitelist when the link is signed, and validated again through `wp_safe_redirect` when it is consumed. The second check exists because `/auth` must not assume the link came from `/generate-sso-link`.

## 8. Single-use tokens, marked spent for twice the TTL

A link is valid for five minutes. Once used, its hash is stored for ten. The extra window means a late replay is rejected as "already used" rather than falling through to the expiry branch, which is the same outcome by accident rather than on purpose, and would stop being the same outcome the day somebody changes the TTL.

## 9. SSO never signs in a privileged account

If the shared secret leaks, and the design assumes it eventually will, the worst case must be a subscriber session and not an administrator one. Editors and administrators sign in through WordPress, where the site's own policy applies.

## 10. The risk the code cannot close is documented, not faked

Anyone with the shared secret can mint a link for any address. The endpoint cannot distinguish a call from the LMS back end from a call made by a browser that read the secret out of public JavaScript.

An earlier version tried to mitigate this by requiring recent activity from the same IP. It helped a little and broke learners on mobile networks that rotate addresses. The honest answer is that this is a deployment property, so it is stated in the source, in the settings screen and in the rollout notes, and enforced by the person deploying.

Security theatre that costs real users access is worse than an accurate warning.

## 11. Entitlements are cached, and a "no" is cached too

Membership checks run on every gated page view. Without caching the negative answer, a visitor browsing a catalogue of courses they have not bought generates one query per card.

Invalidation is per learner, driven by the webhook that changed something. Flushing the whole cache group over a single refund would discard every other learner's answer, which converts one person's refund into a traffic spike.

## 12. No persistent caching when there is no object cache

WordPress transients fall back to the options table. Caching across requests on a site without a real object cache would write a row on every miss, which is slower than the query it was meant to save and adds autoloaded weight.

The group is registered as non-persistent in that case, so caching degrades to per-request rather than becoming a liability.

## 13. Schema upgrades run on load, not on activation

Updating a plugin by uploading a zip never fires the activation hook. Comparing a stored version option against the code version on every load, and running `dbDelta` only when they differ, makes the upgrade path independent of how the files arrived. The option is autoloaded, so the check costs an array lookup.

## 14. Sessions expire, enrollments never do

Sign-in records carry an IP address and a user agent. They are personal data, they have no reason to exist for ever, and a retention horizon is the difference between a defensible privacy position and an indefensible one.

Enrollments are the record of a purchase. Deleting one silently revokes access to something somebody paid for, so they are excluded from the job entirely rather than given a longer horizon.

## 15. Deletion happens in bounded batches

Up to 20 000 rows per statement, five statements per run, stopping early when nothing matches. An unbounded `DELETE` against a table with years of rows locks it, and the operator finds out from a customer rather than from a monitor.

Whatever is left waits for tomorrow. There is no deadline on deleting old rows.

## 16. The dashboard answers one question: is it still working?

The failure mode of this integration is not a crash, it is silence. Deliveries stop, the mirror freezes, and the site keeps serving stale entitlements until somebody who paid cannot open their course.

So the dashboard leads with when the last event arrived and warns when the feed has gone quiet. It is a worse dashboard by the usual measures and a better one for the person who has to trust it.

## 17. Every rate limit is a fixed window

A sliding window is more precise and needs a sorted structure per key. These limits exist to make brute force slow, not to meter usage, and at a boundary a caller can briefly exceed the nominal rate without that mattering.

Transients were chosen over a table because they land in the object cache when one exists and degrade gracefully when it does not.

## 18. Failures on `/auth` redirect, they do not render

The person following an SSO link is a customer who clicked a button. A JSON error body is a support ticket. Every failure sends them home with a reason code in the query string, which the site can display in its own language and its own design.

## 19. Tests execute real SQL

`tests/bootstrap.php` is a minimal WordPress plus a `$wpdb` adapter over SQLite. Every statement the plugin issues actually runs.

Mocking `$wpdb` and asserting on query strings tests that the test agrees with the code. Running the SQL found two MySQL-only constructs that had to be rewritten, and it is the only way the cache tests can prove a second lookup did not reach the database.

## 20. What building this taught me

- The hard parts of an integration are not the API calls. They are ordering during rollout, idempotency under re-delivery, and noticing when it has stopped.
- Every safety feature that cost a real user access got removed. Every one that cost only an attacker stayed.
- The settings screen is documentation. If a field needs a paragraph explaining what happens when you get it wrong, that paragraph belongs next to the field and not in a wiki nobody opens.

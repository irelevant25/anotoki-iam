-----------------------------------------------------------
-- THE LEGAL PAGES' FACTS
--
-- anotoki-lib 0.6.0 gives every anotoki site three pages - Terms, Privacy
-- and Contact (the owner's decisions of 2026-10-13, docs/decisions.md): their
-- structure and the family's shared words are the library's (anotoki.legal.*,
-- written by its own set's 005, which runs after the IAM's files), and each
-- site gives its own facts. The IAM's Privacy page is the one every anotoki
-- site links to for the account itself, so its facts are the account's: what
-- it keeps of a person - the account, two-factor authentication, Google, the
-- browsers signed in, the sign-in attempts, the remembered devices, the
-- browsers known for the new-browser mail, the sites, the mails, the news,
-- the approvals, the deletion and what stays -, the audit log and the
-- backups in its own words, its own cookies and browser storage, and Google
-- and the mail service in words true of the IAM. The tabs' titles of the
-- three pages are the IAM's keys too, so that a tab is never titled with a
-- library key the bundle may not have yet; and so is the heading of Terms'
-- first section, which the library words with the site's name standing alone
-- ("What anotoki account is"). The frontend reads them in
-- core/legal/legal-facts.ts and core/legal/legal.config.ts.
--
-- Each fact is written from the code as it is - the tables of 001-019,
-- api/housekeeping.php, api/account_deletion.php, api/admin_tools.php,
-- config/iam.php: a change to what the IAM keeps, or for how long, changes
-- these strings too (and the day in legal-facts.ts). No address is in any
-- of them: where a link goes is the code's, never a string an administrator
-- can reword on the Translations page (the 0.6.0 review).
--
-- Seventy page keys with their English (en.ts has the same) and their
-- Slovak (informal, "ty"), in 006's form: every insert steps aside for a row
-- that is already there.
--
-- And one line of the sign-in pages' sky, brand.point3, which 006 wrote as
-- "Nothing is shared with anybody outside anotoki" - untrue beside the
-- Privacy page, which names the hosting company, Cloudflare, Google and the
-- mail service. It becomes what is true and short: nothing is sold or used
-- for ads, and the Privacy page says who else handles data. An UPDATE of 006's
-- own words only, and only where no administrator saved the string
-- (updated_by): one saved on the Translations page is the owner's choice -
-- reworded, or put back to 006's very words on purpose - and stays, with its
-- writer; a string the owner blanked has no row to update.
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('legal.termsPageTitle', 'Legal pages: the browser tab''s title of the Terms page (the page itself is headed by the library''s anotoki.legal.termsTitle). No placeholders.'),
    ('legal.privacyPageTitle', 'Legal pages: the browser tab''s title of the Privacy page (the page itself is headed by the library''s anotoki.legal.privacyTitle). No placeholders.'),
    ('legal.contactPageTitle', 'Legal pages: the browser tab''s title of the Contact page (the page itself is headed by the library''s anotoki.legal.contactTitle). No placeholders.'),
    ('legal.about', 'Terms page: its first section, under its heading (legal.termsWhatTitle) - what the anotoki account is, in a sentence or two. No placeholders.'),
    ('legal.termsWhatTitle', 'Terms page: the heading of its first section, and its line in the list of the page''s sections - said in place of the library''s anotoki.legal.termsWhatTitle, whose site name standing alone reads oddly with the account''s ("What anotoki account is"). No placeholders.'),
    ('legal.account.name', 'Privacy page, what the anotoki account keeps - the account itself: the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.account.what', 'Privacy page, what the anotoki account keeps - the account itself: what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.account.why', 'Privacy page, what the anotoki account keeps - the account itself: what it is kept for (under "What for"). No placeholders.'),
    ('legal.account.kept', 'Privacy page, what the anotoki account keeps - the account itself: how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.twoFactor.name', 'Privacy page, what the anotoki account keeps - two-factor authentication: the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.twoFactor.what', 'Privacy page, what the anotoki account keeps - two-factor authentication: what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.twoFactor.why', 'Privacy page, what the anotoki account keeps - two-factor authentication: what it is kept for (under "What for"). No placeholders.'),
    ('legal.twoFactor.kept', 'Privacy page, what the anotoki account keeps - two-factor authentication: how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.google.name', 'Privacy page, what the anotoki account keeps - signing in with Google: the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.google.what', 'Privacy page, what the anotoki account keeps - signing in with Google: what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.google.why', 'Privacy page, what the anotoki account keeps - signing in with Google: what it is kept for (under "What for"). No placeholders.'),
    ('legal.google.kept', 'Privacy page, what the anotoki account keeps - signing in with Google: how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.sessions.name', 'Privacy page, what the anotoki account keeps - the browsers signed in (sessions): the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.sessions.what', 'Privacy page, what the anotoki account keeps - the browsers signed in (sessions): what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.sessions.why', 'Privacy page, what the anotoki account keeps - the browsers signed in (sessions): what it is kept for (under "What for"). No placeholders.'),
    ('legal.sessions.kept', 'Privacy page, what the anotoki account keeps - the browsers signed in (sessions): how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.attempts.name', 'Privacy page, what the anotoki account keeps - sign-in attempts (the limiter''s record): the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.attempts.what', 'Privacy page, what the anotoki account keeps - sign-in attempts (the limiter''s record): what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.attempts.why', 'Privacy page, what the anotoki account keeps - sign-in attempts (the limiter''s record): what it is kept for (under "What for"). No placeholders.'),
    ('legal.attempts.kept', 'Privacy page, what the anotoki account keeps - sign-in attempts (the limiter''s record): how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.remembered.name', 'Privacy page, what the anotoki account keeps - the devices remembered for two-factor authentication: the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.remembered.what', 'Privacy page, what the anotoki account keeps - the devices remembered for two-factor authentication: what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.remembered.why', 'Privacy page, what the anotoki account keeps - the devices remembered for two-factor authentication: what it is kept for (under "What for"). No placeholders.'),
    ('legal.remembered.kept', 'Privacy page, what the anotoki account keeps - the devices remembered for two-factor authentication: how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.browsers.name', 'Privacy page, what the anotoki account keeps - the browsers known for the mail about a sign-in from a new one: the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.browsers.what', 'Privacy page, what the anotoki account keeps - the browsers known for the mail about a sign-in from a new one: what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.browsers.why', 'Privacy page, what the anotoki account keeps - the browsers known for the mail about a sign-in from a new one: what it is kept for (under "What for"). No placeholders.'),
    ('legal.browsers.kept', 'Privacy page, what the anotoki account keeps - the browsers known for the mail about a sign-in from a new one: how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.sites.name', 'Privacy page, what the anotoki account keeps - the anotoki sites: roles, the sites'' sign-ins, tokens for scripts, what a site is told: the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.sites.what', 'Privacy page, what the anotoki account keeps - the anotoki sites: roles, the sites'' sign-ins, tokens for scripts, what a site is told: what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.sites.why', 'Privacy page, what the anotoki account keeps - the anotoki sites: roles, the sites'' sign-ins, tokens for scripts, what a site is told: what it is kept for (under "What for"). No placeholders.'),
    ('legal.sites.kept', 'Privacy page, what the anotoki account keeps - the anotoki sites: roles, the sites'' sign-ins, tokens for scripts, what a site is told: how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.mails.name', 'Privacy page, what the anotoki account keeps - the e-mails it sends (the mail log, the mailed codes and links): the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.mails.what', 'Privacy page, what the anotoki account keeps - the e-mails it sends (the mail log, the mailed codes and links): what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.mails.why', 'Privacy page, what the anotoki account keeps - the e-mails it sends (the mail log, the mailed codes and links): what it is kept for (under "What for"). No placeholders.'),
    ('legal.mails.kept', 'Privacy page, what the anotoki account keeps - the e-mails it sends (the mail log, the mailed codes and links): how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.news.name', 'Privacy page, what the anotoki account keeps - the news (announcements) and who it goes to: the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.news.what', 'Privacy page, what the anotoki account keeps - the news (announcements) and who it goes to: what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.news.why', 'Privacy page, what the anotoki account keeps - the news (announcements) and who it goes to: what it is kept for (under "What for"). No placeholders.'),
    ('legal.news.kept', 'Privacy page, what the anotoki account keeps - the news (announcements) and who it goes to: how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.approvals.name', 'Privacy page, what the anotoki account keeps - the approval of new accounts (the admin panel''s Settings, New accounts need approval): the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.approvals.what', 'Privacy page, what the anotoki account keeps - the approval of new accounts (the admin panel''s Settings, New accounts need approval): what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.approvals.why', 'Privacy page, what the anotoki account keeps - the approval of new accounts (the admin panel''s Settings, New accounts need approval): what it is kept for (under "What for"). No placeholders.'),
    ('legal.approvals.kept', 'Privacy page, what the anotoki account keeps - the approval of new accounts (the admin panel''s Settings, New accounts need approval): how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.deletion.name', 'Privacy page, what the anotoki account keeps - deleting the account, and what stays: the part''s heading, and its line under "How long it is kept" too. No placeholders.'),
    ('legal.deletion.what', 'Privacy page, what the anotoki account keeps - deleting the account, and what stays: what exactly it keeps. Must stay true of the code (backend/migrations, api/). No placeholders.'),
    ('legal.deletion.why', 'Privacy page, what the anotoki account keeps - deleting the account, and what stays: what it is kept for (under "What for"). No placeholders.'),
    ('legal.deletion.kept', 'Privacy page, what the anotoki account keeps - deleting the account, and what stays: how long it is kept (under "How long it is kept"). Must stay true of the code (api/housekeeping.php, api/account_deletion.php). No placeholders.'),
    ('legal.audit.what', 'Privacy page, what the anotoki account keeps - the audit log (api/audit.php), in the IAM''s own words over the library''s: what exactly it keeps. Must stay true of the code. No placeholders.'),
    ('legal.audit.why', 'Privacy page, what the anotoki account keeps - the audit log (api/audit.php), in the IAM''s own words over the library''s: what it is kept for (under "What for"). Must stay true of the code. No placeholders.'),
    ('legal.audit.kept', 'Privacy page, what the anotoki account keeps - the audit log (api/audit.php), in the IAM''s own words over the library''s: how long it is kept (under "How long it is kept"). Must stay true of the code. No placeholders.'),
    ('legal.backups.what', 'Privacy page, what the anotoki account keeps - the backups (api/admin_tools.php, iamBackups(): encrypted, never restored here; the library adds that they stay on the server, encrypted), in the IAM''s own words over the library''s: what exactly it keeps. Must stay true of the code. No placeholders.'),
    ('legal.backups.why', 'Privacy page, what the anotoki account keeps - the backups, in the IAM''s own words over the library''s: what it is kept for (under "What for"). Must stay true of the code. No placeholders.'),
    ('legal.backups.kept', 'Privacy page, what the anotoki account keeps - the backups (14 daily, 5 before an update, by hand until deleted), in the IAM''s own words over the library''s: how long it is kept (under "How long it is kept"). Must stay true of the code. No placeholders.'),
    ('legal.storage.session.purpose', 'Privacy page, cookies and storage in the browser - the session cookie (__Host-anotoki_iam; anotoki_iam on plain http): what it is for. No placeholders.'),
    ('legal.storage.session.kept', 'Privacy page, cookies and storage in the browser - the session cookie: how long it stays (config/iam.php: a day without use, a week at most; remembered, 30 days without use, 90 at most). No placeholders.'),
    ('legal.storage.device.purpose', 'Privacy page, cookies and storage in the browser - the device cookie (anotoki_iam_device, api/sign_in_devices.php): what it is for. No placeholders.'),
    ('legal.storage.device.kept', 'Privacy page, cookies and storage in the browser - the device cookie: how long it stays (400 days, set again at every sign-in). No placeholders.'),
    ('legal.storage.remembered.purpose', 'Privacy page, cookies and storage in the browser - the remembered device''s key in local storage (anotoki-iam:device-token, core/auth/device-token.ts): what it is for. No placeholders.'),
    ('legal.storage.remembered.kept', 'Privacy page, cookies and storage in the browser - the remembered device''s key: how long it stays. No placeholders.'),
    ('legal.storage.tabLanguage.purpose', 'Privacy page, cookies and storage in the browser - the language a link chose for one tab (anotoki-iam:lang in session storage): what it is for. ?lang= is written as it is in an address. No placeholders.'),
    ('legal.storage.drafts.purpose', 'Privacy page, cookies and storage in the browser - the admin panel''s unsaved strings (anotoki-iam:localization-drafts in session storage): what it is for. The two pages keep their English names (the admin panel is English). No placeholders.'),
    ('legal.storage.untilTabClosed', 'Privacy page, cookies and storage in the browser: how long an item in session storage stays (the tab''s language, the admin panel''s unsaved strings). No placeholders.'),
    ('legal.google.role', 'Privacy page, who else handles data - Google, in the IAM''s own words over the library''s: its button is loaded from Google wherever it is drawn (the sign-in and sign-up pages, the account page while Google is not connected), whenever signing in with Google is switched on. The button''s own words are Google''s ("Sign in with Google"). No placeholders.'),
    ('legal.mail.role', 'Privacy page, who else handles data - the mail service, in the IAM''s own words over the library''s: what it sends (news only to those who have it switched on). No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('legal.termsPageTitle', 'Terms of use'),
    ('legal.privacyPageTitle', 'Privacy policy'),
    ('legal.contactPageTitle', 'Contact'),
    ('legal.about', 'The anotoki account is one account for every anotoki site: you make it here, sign in with it here and look after it here - your name and address, your password, two-factor authentication, where you are signed in and the sites you use.'),
    ('legal.termsWhatTitle', 'About the anotoki account'),
    ('legal.account.name', 'Your account'),
    ('legal.account.what', 'Your e-mail address and whether you confirmed it, your name, your password - only as a hash, a fingerprint it can be checked against, never the password itself -, your language, your look and how dates and times are written for you, whether you want news, and when the account was made and last signed in.'),
    ('legal.account.why', 'To make and keep your account, to sign you in, and to write to you about it.'),
    ('legal.account.kept', 'Until your account is deleted.'),
    ('legal.twoFactor.name', 'Two-factor authentication'),
    ('legal.twoFactor.what', 'If you turn it on: your authenticator app’s secret, encrypted with a key that is not in the database; your recovery codes, only as hashes, and which you have used; and, after five wrong codes in 15 minutes, until when its codes are paused.'),
    ('legal.twoFactor.why', 'To ask for a second proof when you sign in, so that your password alone gets nobody in.'),
    ('legal.twoFactor.kept', 'Until you turn it off - the secret and the codes go then - or your account is deleted; new recovery codes replace the old ones.'),
    ('legal.google.name', 'Signing in with Google'),
    ('legal.google.what', 'If you sign in with Google or connect it to your account: your Google account’s permanent id and the e-mail address Google gave with it.'),
    ('legal.google.why', 'To know your Google account when you sign in with it.'),
    ('legal.google.kept', 'Until you disconnect Google, or your account is deleted.'),
    ('legal.sessions.name', 'Where you are signed in'),
    ('legal.sessions.what', 'Each time a browser signs in to your account: how you signed in, the browser and system it calls itself, its IP address, when it began and was last used, and when and why it ended - and only a hash of the cookie that keeps it signed in.'),
    ('legal.sessions.why', 'To keep you signed in, to show you where you are signed in and let you sign a browser out from afar, and to end the sites’ sign-ins with it.'),
    ('legal.sessions.kept', 'Until your account is deleted - also after the sign-in ended, so that the account page can show the ones there were.'),
    ('legal.attempts.name', 'Sign-in attempts'),
    ('legal.attempts.what', 'Every try to sign in, right or wrong, every wrong password or code typed on the account page, and every request that mails a code or a link - signing up, a password reset, a sign-in code, a new confirmation: the address typed, its account if there is one, the IP address (an IPv6 address by its first half), the way and how it went. Also for an address no account has.'),
    ('legal.attempts.why', 'To stop guessing and floods: the limits count them.'),
    ('legal.attempts.kept', 'A year; those of your account go when it is deleted.'),
    ('legal.remembered.name', 'Remembered devices'),
    ('legal.remembered.what', 'A browser where you asked, after a two-factor code, to be remembered for 30 days: a hash of the key it keeps, the browser and system it calls itself, its IP address, and when it was remembered and last used.'),
    ('legal.remembered.why', 'To skip the two-factor code in that browser for 30 days - never the password.'),
    ('legal.remembered.kept', 'It works for 30 days, until you forget your remembered devices or change your password; the record stays until your account is deleted.'),
    ('legal.browsers.name', 'Browsers you sign in from'),
    ('legal.browsers.what', 'For each browser your account signed in from: a hash of a random value the browser keeps in a cookie, the browser and system in a few words ("Firefox · Windows"), when it was first and last seen, and when you were mailed about it.'),
    ('legal.browsers.why', 'To mail you when your account signs in from a browser it has not signed in from before.'),
    ('legal.browsers.kept', 'Until you reset your password, or your account is deleted.'),
    ('legal.sites.name', 'The anotoki sites'),
    ('legal.sites.what', 'Which anotoki sites your account may use and its roles on each, with who gave a role and when; the sign-ins of the sites you use - only hashes of the keys each was given, and when they were used; and the tokens for scripts you make, with their names. When you sign in to a site, the anotoki account tells it your account’s id, your name, your e-mail address, your language, look and date and time format, and your roles there.'),
    ('legal.sites.why', 'To sign you in to every anotoki site with one account, and to tell each site what you may do there.'),
    ('legal.sites.kept', 'Your roles until your account is deleted; a site’s sign-in or a token for scripts 30 days after it ended or ran out.'),
    ('legal.mails.name', 'E-mails to you'),
    ('legal.mails.what', 'For every e-mail the account sends you - a confirmation, a code, a password reset, a security notice, an approval, news: the address, what kind it was, its subject (not when it holds a code), whether it went and when. Never its text. The codes and links in them only as hashes, and when they were used.'),
    ('legal.mails.why', 'To know what was sent and whether it went, and to check a code or a link when you use it.'),
    ('legal.mails.kept', 'The record of an e-mail until your account is deleted; a code or a link 30 days after it ran out.'),
    ('legal.news.name', 'News from anotoki sites'),
    ('legal.news.what', 'Now and then an e-mail about a new version, a new site or a survey, sent only to accounts with the news switched on. Signing up offers it in a box left unticked; an account made before the news began, made by an administrator or brought over from the genshin site’s old accounts started with it on. For each news e-mail: who it went to, at which address, in which language and how it went.'),
    ('legal.news.why', 'To tell those who want it what is new on the anotoki sites. Switch it off on the account page, or with the link at the end of every news e-mail.'),
    ('legal.news.kept', 'Who a news e-mail went to stays with it; when your account is deleted, your address there is replaced by a placeholder.'),
    ('legal.approvals.name', 'Approval of new accounts'),
    ('legal.approvals.what', 'While new accounts wait for approval - a switch the operator may turn on -, an account made by signing up signs in nowhere until an administrator of the anotoki account approves it: they see its name and address, and approving it is mailed to you. Declining switches the account off without deleting it, and is not mailed.'),
    ('legal.approvals.why', 'To keep the anotoki sites to the people they are meant for, while that switch is on.'),
    ('legal.approvals.kept', 'Whether the account was approved, until it is deleted. A declined account cannot sign in to delete itself: to have it deleted, write to us.'),
    ('legal.deletion.name', 'Deleting your account'),
    ('legal.deletion.what', 'When you ask for it, your account closes at once: you are signed out everywhere else, no anotoki site lets you in any more, and you are mailed. For 30 days you can still sign in here and cancel it. Then everything of yours listed here is deleted or taken out, and you are mailed once more; only a placeholder stays - your account’s number with a made-up name and address, and when it was made, asked to be deleted and deleted. The anotoki sites learn the number, and take your name out of what they keep.'),
    ('legal.deletion.why', 'So that a deletion can still be undone for a while, and so that the account’s number is never anybody else’s.'),
    ('legal.deletion.kept', 'The placeholder for good. Each deletion asked for, cancelled and done is also a line - the account’s number and the time - of a record kept beside the database, for good too, so that it is done again if the database is ever put back from a copy.'),
    ('legal.audit.what', 'What the administrators of the anotoki account do on its admin panel, and the security changes you make to your own account - your password, two-factor authentication, Google, a token for scripts, a deletion asked for or cancelled: who did what, to what and when, and the IP address it came from.'),
    ('legal.audit.why', 'So that every change can be traced, and a misused account found out.'),
    ('legal.audit.kept', 'With no set time limit. When an account is deleted, its IP addresses, name and address go from the entries by it and about it; what was done stays.'),
    ('legal.backups.what', 'A copy of the whole database - every account in it -, made on each day the anotoki account is used, before every update, and by hand when an administrator asks for one. Each copy holds what was kept when it was made. A copy is never put back over the database from here: if the database is ever lost and put back from a copy by hand, every deletion asked for since is done again.'),
    ('legal.backups.why', 'To put the database back by hand if it is ever lost.'),
    ('legal.backups.kept', 'The 14 newest daily copies and the 5 newest made before an update; a copy made by hand until an administrator deletes it. A copy an administrator downloads stays encrypted, and is kept off the server until the operator deletes it. What is deleted here stays in the copies made before, until they go.'),
    ('legal.storage.session.purpose', 'Keeps you signed in to the anotoki account in this browser: a random value only this site can read - no script on its pages can. The account keeps only its hash.'),
    ('legal.storage.session.kept', 'Until you sign out or close the browser - the sign-in ends a day after you last used it, a week at most. If you asked to stay signed in: up to 90 days, the sign-in ending 30 days after you last used it.'),
    ('legal.storage.device.purpose', 'Tells the anotoki account whether your account has signed in from this browser before, so that a sign-in from a new browser can be mailed to you: a random value only this site can read. It opens nothing, and the account keeps only its hash.'),
    ('legal.storage.device.kept', '400 days, set again at every sign-in.'),
    ('legal.storage.remembered.purpose', 'If you asked, after a two-factor code, to remember this device: the key that skips the code in this browser for 30 days - never the password.'),
    ('legal.storage.remembered.kept', 'Until a later sign-in replaces it, you forget your remembered devices on the account page, or you clear the site’s data in your browser. It works for 30 days.'),
    ('legal.storage.tabLanguage.purpose', 'A language a link chose for this tab (?lang= in its address).'),
    ('legal.storage.drafts.purpose', 'Administrators only: words typed on the admin panel’s Translations and Languages pages and not saved yet.'),
    ('legal.storage.untilTabClosed', 'Until the tab is closed.'),
    ('legal.google.role', 'Draws its "Sign in with Google" button on the sign-in and sign-up pages, and on the account page while Google is not connected, whenever signing in with Google is switched on: your browser loads the button from Google, so Google sees your IP address and its own cookies. Only if you choose to sign in with Google does Google tell the anotoki account your Google account’s id, e-mail address and name. What Google itself keeps, its own privacy policy says. A company based in the USA: data may be handled outside the EU.'),
    ('legal.mail.role', 'Sends the anotoki account’s e-mails: address confirmations, sign-in codes, password resets, security notices, approvals, and news to those who have it switched on.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('legal.termsPageTitle', 'Podmienky používania'),
    ('legal.privacyPageTitle', 'Ochrana osobných údajov'),
    ('legal.contactPageTitle', 'Kontakt'),
    ('legal.about', 'anotoki účet je jeden účet pre všetky stránky anotoki: tu si ho založíš, tu sa ním prihlasuješ a tu sa oň staráš - o meno a adresu, heslo, dvojfaktorové overenie, o to, kde si prihlásený, a o stránky, ktoré používaš.'),
    ('legal.termsWhatTitle', 'O anotoki účte'),
    ('legal.account.name', 'Tvoj účet'),
    ('legal.account.what', 'Tvoja e-mailová adresa a to, či si ju potvrdil, tvoje meno, heslo - len ako odtlačok (hash), podľa ktorého sa dá overiť, nikdy nie samotné heslo -, jazyk, vzhľad a to, ako sa ti píšu dátumy a časy, či chceš novinky, a kedy bol účet založený a kedy si sa naposledy prihlásil.'),
    ('legal.account.why', 'Aby sme ti mohli účet založiť a viesť, prihlásiť ťa a písať ti o ňom.'),
    ('legal.account.kept', 'Kým sa tvoj účet nezmaže.'),
    ('legal.twoFactor.name', 'Dvojfaktorové overenie'),
    ('legal.twoFactor.what', 'Ak ho zapneš: tajný kľúč tvojej overovacej aplikácie, zašifrovaný kľúčom, ktorý v databáze nie je; tvoje záložné kódy, len ako odtlačky (hash), a ktoré z nich si už použil; a po piatich nesprávnych kódoch za 15 minút aj to, dokedy sú kódy pozastavené.'),
    ('legal.twoFactor.why', 'Aby sa pri prihlásení pýtal druhý dôkaz a samotné heslo nikoho dnu nepustilo.'),
    ('legal.twoFactor.kept', 'Kým ho nevypneš - vtedy tajný kľúč aj kódy zmiznú - alebo kým sa tvoj účet nezmaže; nové záložné kódy nahradia staré.'),
    ('legal.google.name', 'Prihlásenie cez Google'),
    ('legal.google.what', 'Ak sa prihlásiš cez Google alebo ho pripojíš k účtu: trvalý identifikátor tvojho účtu Google a e-mailová adresa, ktorú k nemu Google poslal.'),
    ('legal.google.why', 'Aby sme spoznali tvoj účet Google, keď sa ním prihlásiš.'),
    ('legal.google.kept', 'Kým Google neodpojíš alebo kým sa tvoj účet nezmaže.'),
    ('legal.sessions.name', 'Kde je tvoj účet prihlásený'),
    ('legal.sessions.what', 'Každé prihlásenie prehliadača do tvojho účtu: ako si sa prihlásil, prehliadač a systém, za ktorý sa vydáva, jeho IP adresa, kedy sa začalo a kedy bolo naposledy použité, a kedy a prečo skončilo - a z cookie, ktoré ho drží prihlásený, len odtlačok (hash).'),
    ('legal.sessions.why', 'Aby si zostal prihlásený, aby si videl, kde si prihlásený, a mohol prehliadač odhlásiť na diaľku, a aby sa s ním skončili aj prihlásenia stránok.'),
    ('legal.sessions.kept', 'Kým sa tvoj účet nezmaže - aj po skončení prihlásenia, aby stránka účtu mohla ukázať aj tie, ktoré boli.'),
    ('legal.attempts.name', 'Pokusy o prihlásenie'),
    ('legal.attempts.what', 'Každý pokus o prihlásenie, správny aj nesprávny, každé nesprávne heslo či kód zadaný na stránke účtu a každá požiadavka, po ktorej príde e-mailom kód alebo odkaz - registrácia, obnovenie hesla, prihlasovací kód, nové potvrdenie: zadaná adresa, jej účet, ak nejaký má, IP adresa (pri IPv6 jej prvá polovica), spôsob a výsledok. Aj pri adrese, ku ktorej žiadny účet nie je.'),
    ('legal.attempts.why', 'Aby sa zastavilo hádanie a zahlcovanie: počítajú ich limity.'),
    ('legal.attempts.kept', 'Rok; tie tvojho účtu zmiznú, keď sa zmaže.'),
    ('legal.remembered.name', 'Zapamätané zariadenia'),
    ('legal.remembered.what', 'Prehliadač, v ktorom si po dvojfaktorovom kóde požiadal o zapamätanie na 30 dní: odtlačok (hash) kľúča, ktorý si uchováva, prehliadač a systém, za ktorý sa vydáva, jeho IP adresa a kedy bol zapamätaný a naposledy použitý.'),
    ('legal.remembered.why', 'Aby sa v tom prehliadači 30 dní nepýtal dvojfaktorový kód - heslo áno.'),
    ('legal.remembered.kept', 'Funguje 30 dní, kým zapamätané zariadenia nezabudneš alebo nezmeníš heslo; záznam zostáva, kým sa tvoj účet nezmaže.'),
    ('legal.browsers.name', 'Prehliadače, z ktorých sa prihlasuješ'),
    ('legal.browsers.what', 'Pri každom prehliadači, z ktorého sa tvoj účet prihlásil: odtlačok (hash) náhodnej hodnoty, ktorú si prehliadač uchováva v cookie, prehliadač a systém v pár slovách („Firefox · Windows“), kedy bol prvýkrát a naposledy videný a kedy ti o ňom prišiel e-mail.'),
    ('legal.browsers.why', 'Aby ti prišiel e-mail, keď sa tvoj účet prihlási z prehliadača, z ktorého sa ešte neprihlasoval.'),
    ('legal.browsers.kept', 'Kým si neobnovíš heslo alebo kým sa tvoj účet nezmaže.'),
    ('legal.sites.name', 'Stránky anotoki'),
    ('legal.sites.what', 'Ktoré stránky anotoki môže tvoj účet používať a aké roly má na každej, aj kto a kedy rolu pridelil; prihlásenia stránok, ktoré používaš - len odtlačky (hash) kľúčov, ktoré každá dostala, a kedy sa použili; a tokeny pre skripty, ktoré si vytvoríš, aj s ich názvami. Keď sa prihlásiš na stránku, anotoki účet jej oznámi identifikátor tvojho účtu, tvoje meno, e-mailovú adresu, jazyk, vzhľad, formát dátumu a času a tvoje roly na nej.'),
    ('legal.sites.why', 'Aby ťa jeden účet prihlásil na všetky stránky anotoki a aby každá stránka vedela, čo na nej môžeš robiť.'),
    ('legal.sites.kept', 'Roly, kým sa tvoj účet nezmaže; prihlásenie stránky alebo token pre skripty 30 dní od jeho skončenia alebo vypršania.'),
    ('legal.mails.name', 'E-maily, ktoré ti posielame'),
    ('legal.mails.what', 'Pri každom e-maile, ktorý ti účet pošle - potvrdenie, kód, obnovenie hesla, bezpečnostné upozornenie, schválenie, novinky: adresa, druh e-mailu, jeho predmet (nie keď obsahuje kód), či odišiel a kedy. Nikdy nie jeho text. Kódy a odkazy v nich len ako odtlačky (hash) a kedy sa použili.'),
    ('legal.mails.why', 'Aby bolo jasné, čo sa poslalo a či to odišlo, a aby sa dal overiť kód alebo odkaz, keď ho použiješ.'),
    ('legal.mails.kept', 'Záznam o e-maile, kým sa tvoj účet nezmaže; kód alebo odkaz 30 dní po tom, čo vypršal.'),
    ('legal.news.name', 'Novinky zo stránok anotoki'),
    ('legal.news.what', 'Občas e-mail o novej verzii, novej stránke alebo prieskume, len účtom so zapnutými novinkami. Pri registrácii ich ponúka nezaškrtnuté políčko; účet založený pred spustením noviniek, založený správcom alebo prenesený zo starých účtov stránky genshin ich mal zapnuté od začiatku. Pri každom e-maile s novinkami: komu odišiel, na akú adresu, v akom jazyku a ako to dopadlo.'),
    ('legal.news.why', 'Aby sa tí, ktorí o to stoja, dozvedeli, čo je nové na stránkach anotoki. Vypnúť ich môžeš na stránke účtu alebo odkazom na konci každého e-mailu s novinkami.'),
    ('legal.news.kept', 'Komu e-mail s novinkami odišiel, zostáva pri ňom; po zmazaní tvojho účtu sa tvoja adresa v ňom nahradí zástupnou.'),
    ('legal.approvals.name', 'Schvaľovanie nových účtov'),
    ('legal.approvals.what', 'Kým nové účty čakajú na schválenie - prevádzkovateľ to môže zapnúť -, účet založený registráciou sa nikam neprihlási, kým ho správca anotoki účtu neschváli: vidí jeho meno a adresu a o schválení ti príde e-mail. Zamietnutie účet vypne bez zmazania a e-mail oň nepríde.'),
    ('legal.approvals.why', 'Aby boli stránky anotoki len pre tých, pre ktorých sú určené, kým je to zapnuté.'),
    ('legal.approvals.kept', 'Či bol účet schválený, kým sa nezmaže. Do zamietnutého účtu sa nedá prihlásiť, aby sa zmazal: ak ho chceš zmazať, napíš nám.'),
    ('legal.deletion.name', 'Zmazanie účtu'),
    ('legal.deletion.what', 'Keď o to požiadaš, tvoj účet sa hneď uzavrie: všade inde ťa odhlási, žiadna stránka anotoki ťa už nepustí dnu a príde ti e-mail. Ešte 30 dní sa sem môžeš prihlásiť a zmazanie zrušiť. Potom sa všetko tvoje, čo je tu uvedené, zmaže alebo odstráni a príde ti ešte jeden e-mail; zostane len zástupný záznam - číslo tvojho účtu s vymysleným menom a adresou a kedy bol účet založený, kedy si požiadal o zmazanie a kedy sa zmazal. Stránky anotoki sa to číslo dozvedia a odstránia tvoje meno z toho, čo uchovávajú.'),
    ('legal.deletion.why', 'Aby sa zmazanie dalo ešte nejaký čas vrátiť a aby číslo účtu nikdy nedostal nikto iný.'),
    ('legal.deletion.kept', 'Zástupný záznam natrvalo. Každá požiadavka o zmazanie, jej zrušenie aj samotné zmazanie je tiež riadok - číslo účtu a čas - v zázname vedenom popri databáze, tiež natrvalo, aby sa zmazanie zopakovalo, keby sa databáza niekedy obnovovala z kópie.'),
    ('legal.audit.what', 'Čo robia správcovia anotoki účtu v jeho administrátorskom paneli a bezpečnostné zmeny, ktoré urobíš vo svojom účte - heslo, dvojfaktorové overenie, Google, token pre skripty, požiadavka o zmazanie či jej zrušenie: kto čo urobil, s čím a kedy, a IP adresa, z ktorej to prišlo.'),
    ('legal.audit.why', 'Aby sa dala každá zmena dohľadať a odhaliť zneužitie účtu.'),
    ('legal.audit.kept', 'Bez pevne stanovenej lehoty. Po zmazaní účtu sa z jeho záznamov a zo záznamov o ňom odstránia jeho IP adresy, meno a adresa; čo sa urobilo, zostane.'),
    ('legal.backups.what', 'Kópia celej databázy - so všetkými účtami -, ktorá vzniká v každý deň, keď sa anotoki účet používa, pred každou aktualizáciou a ručne, keď o ňu požiada správca. Každá kópia obsahuje to, čo sa uchovávalo v čase jej vzniku. Kópia sa odtiaľto nikdy nenahrá späť cez databázu: keby sa databáza niekedy stratila a obnovovala z kópie ručne, každé zmazanie, o ktoré sa odvtedy požiadalo, sa vykoná znova.'),
    ('legal.backups.why', 'Aby sa dala databáza ručne obnoviť, keby sa niekedy stratila.'),
    ('legal.backups.kept', '14 najnovších denných kópií a 5 najnovších kópií pred aktualizáciou; ručne vytvorená kópia, kým ju správca nezmaže. Kópia, ktorú si správca stiahne, zostáva šifrovaná a prevádzkovateľ ju uchováva mimo servera, kým ju nezmaže. Čo sa tu zmaže, zostáva v skôr vytvorených kópiách, kým nezaniknú.'),
    ('legal.storage.session.purpose', 'Drží ťa prihláseného do anotoki účtu v tomto prehliadači: náhodná hodnota, ktorú prečíta len táto stránka - žiadny skript na jej stránkach. Účet si z nej uchováva len odtlačok (hash).'),
    ('legal.storage.session.kept', 'Kým sa neodhlásiš alebo nezatvoríš prehliadač - prihlásenie skončí deň po poslednom použití, najneskôr o týždeň. Ak si chcel zostať prihlásený: najviac 90 dní, pričom prihlásenie skončí 30 dní po poslednom použití.'),
    ('legal.storage.device.purpose', 'Hovorí anotoki účtu, či sa tvoj účet z tohto prehliadača už prihlasoval, aby ti o prihlásení z nového prehliadača mohol prísť e-mail: náhodná hodnota, ktorú prečíta len táto stránka. Nič neotvára a účet si z nej uchováva len odtlačok (hash).'),
    ('legal.storage.device.kept', '400 dní, pri každom prihlásení sa nastaví znova.'),
    ('legal.storage.remembered.purpose', 'Ak si po dvojfaktorovom kóde požiadal o zapamätanie tohto zariadenia: kľúč, ktorý v tomto prehliadači 30 dní preskočí kód - heslo nikdy.'),
    ('legal.storage.remembered.kept', 'Kým ho nenahradí ďalšie prihlásenie, kým nezabudneš zapamätané zariadenia na stránke účtu alebo kým nevymažeš údaje stránky v prehliadači. Funguje 30 dní.'),
    ('legal.storage.tabLanguage.purpose', 'Jazyk, ktorý pre túto kartu zvolil odkaz (?lang= v jeho adrese).'),
    ('legal.storage.drafts.purpose', 'Len pre správcov: texty napísané na stránkach Translations a Languages v administrátorskom paneli, ktoré ešte nie sú uložené.'),
    ('legal.storage.untilTabClosed', 'Kým sa karta nezatvorí.'),
    ('legal.google.role', 'Na stránkach prihlásenia a registrácie a na stránke účtu, kým Google nie je pripojený, zobrazuje svoje tlačidlo „Prihlásiť sa cez Google“, ak je prihlasovanie cez Google zapnuté: prehliadač ho načíta od Googlu, takže Google vidí tvoju IP adresu a svoje vlastné cookies. Len ak sa rozhodneš prihlásiť cez Google, oznámi Google anotoki účtu identifikátor, e-mailovú adresu a meno tvojho účtu Google. Čo uchováva samotný Google, opisujú jeho vlastné zásady ochrany súkromia. Spoločnosť so sídlom v USA: údaje sa môžu spracúvať aj mimo EÚ.'),
    ('legal.mail.role', 'Posiela e-maily anotoki účtu: potvrdenia adresy, prihlasovacie kódy, obnovenie hesla, bezpečnostné upozornenia, schválenia a novinky tým, ktorí ich majú zapnuté.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The sign-in pages' sky: 006's words, and only those, reworded - where no administrator saved the string.
UPDATE translations SET value = 'Nothing is sold or used for ads; Privacy says who else handles data.', updated_at = CURRENT_TIMESTAMP
 WHERE key_name = 'brand.point3' AND language_code = 'en' AND value = 'Nothing is shared with anybody outside anotoki.' AND updated_by IS NULL;

-- Slovak, informal.
UPDATE translations SET value = 'Nič sa nepredáva ani nepoužíva na reklamu; kto ďalší spracúva údaje, nájdeš v Súkromí.', updated_at = CURRENT_TIMESTAMP
 WHERE key_name = 'brand.point3' AND language_code = 'sk' AND value = 'Nič sa nezdieľa s nikým mimo anotoki.' AND updated_by IS NULL;

-----------------------------------------------------------
-- PROVE IT
--
-- Every key this file writes has its English and its Slovak string. Only
-- these keys: the admin panel may blank a Slovak string elsewhere on
-- purpose, and the library adds keys of its own. And the sky no longer
-- holds 006's words in either language where nobody saved it: a string
-- an administrator saved is theirs - in 006's own words too -, one
-- reworded holds other words, and a blanked one none: all pass.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
    left_over TEXT;
BEGIN
    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('legal.termsPageTitle'), ('legal.privacyPageTitle'), ('legal.contactPageTitle'), ('legal.about'), ('legal.termsWhatTitle'), ('legal.account.name'),
                   ('legal.account.what'), ('legal.account.why'), ('legal.account.kept'), ('legal.twoFactor.name'), ('legal.twoFactor.what'), ('legal.twoFactor.why'),
                   ('legal.twoFactor.kept'), ('legal.google.name'), ('legal.google.what'), ('legal.google.why'), ('legal.google.kept'), ('legal.sessions.name'),
                   ('legal.sessions.what'), ('legal.sessions.why'), ('legal.sessions.kept'), ('legal.attempts.name'), ('legal.attempts.what'), ('legal.attempts.why'),
                   ('legal.attempts.kept'), ('legal.remembered.name'), ('legal.remembered.what'), ('legal.remembered.why'), ('legal.remembered.kept'),
                   ('legal.browsers.name'), ('legal.browsers.what'), ('legal.browsers.why'), ('legal.browsers.kept'), ('legal.sites.name'), ('legal.sites.what'),
                   ('legal.sites.why'), ('legal.sites.kept'), ('legal.mails.name'), ('legal.mails.what'), ('legal.mails.why'), ('legal.mails.kept'), ('legal.news.name'),
                   ('legal.news.what'), ('legal.news.why'), ('legal.news.kept'), ('legal.approvals.name'), ('legal.approvals.what'), ('legal.approvals.why'),
                   ('legal.approvals.kept'), ('legal.deletion.name'), ('legal.deletion.what'), ('legal.deletion.why'), ('legal.deletion.kept'), ('legal.audit.what'),
                   ('legal.audit.why'), ('legal.audit.kept'), ('legal.backups.what'), ('legal.backups.why'), ('legal.backups.kept'), ('legal.storage.session.purpose'),
                   ('legal.storage.session.kept'), ('legal.storage.device.purpose'), ('legal.storage.device.kept'), ('legal.storage.remembered.purpose'),
                   ('legal.storage.remembered.kept'), ('legal.storage.tabLanguage.purpose'), ('legal.storage.drafts.purpose'), ('legal.storage.untilTabClosed'),
                   ('legal.google.role'), ('legal.mail.role')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the legal pages'' keys without an English and a Slovak string: %', missing;
    END IF;

    SELECT string_agg(t.language_code, ', ' ORDER BY t.language_code) INTO left_over
      FROM translations t
     WHERE t.key_name = 'brand.point3' AND t.updated_by IS NULL
       AND ((t.language_code = 'en' AND t.value = 'Nothing is shared with anybody outside anotoki.') OR (t.language_code = 'sk' AND t.value = 'Nič sa nezdieľa s nikým mimo anotoki.'));
    IF left_over IS NOT NULL THEN
        RAISE EXCEPTION 'the sign-in pages'' sky still says nothing is shared outside anotoki: %', left_over;
    END IF;
END $$;

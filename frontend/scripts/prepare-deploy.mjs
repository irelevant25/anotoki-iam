// Turns a production `ng build` into something ready to upload, by writing the
// one thing the build cannot know on its own: the .htaccess for the web root.
//
//   /api/...                     the PHP backend, passed through untouched to
//                                its own front controller (api/.htaccess).
//
//   /.well-known/openid-configuration and /.well-known/jwks.json
//                                OpenID Connect discovery lives at the issuer's
//                                root, not under /api. Both are rewritten to
//                                api/index.php, which routes on the original
//                                path (REQUEST_URI survives an internal rewrite).
//
//   everything else              the app is one page with client-side routes,
//                                so a path that is not a real file falls back to
//                                index.html - or a refresh on /account/security
//                                would be a 404. /setup.php, while it is there
//                                for the first install, is a real file and is
//                                run as one; its own script is served by it
//                                (?asset=js), which the policy below allows.
//
// And the headers every page of an identity provider should carry: no framing
// (clickjacking a sign-in form is a classic), no MIME sniffing, a referrer that
// never leaks a path to another site, and a Content-Security-Policy that allows
// exactly this app, Google Identity Services and the family's visitor counter
// (Umami, whose script sends to its own origin) - nothing inline. The build
// cooperates: angular.json turns off critical-CSS inlining, whose loader is an
// inline onload handler that this policy would block. (Component styles are
// still injected as <style> elements at runtime, hence style-src 'unsafe-inline'.)
//
// Run by `npm run build:deploy` after `ng build`. Nothing here is used in dev.

import { existsSync, readFileSync, readdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";

// The application builder puts the web files under dist/iam/browser/ - that
// folder, not its parent, is what becomes the web root.
const base = join(process.cwd(), "dist", "iam");
const dist = existsSync(join(base, "browser")) ? join(base, "browser") : base;

if (!existsSync(join(dist, "index.html"))) {
    console.error(`No build at ${dist} (no index.html). Run \`ng build --configuration production\` first.`);
    process.exit(1);
}

// A build that slipped an inline script past the policy would load as a blank
// page in production and nowhere else. Cheaper to refuse it here.
const index = readFileSync(join(dist, "index.html"), "utf8");
const inlineScript = /<script(?![^>]*\bsrc=)[^>]*>/i.test(index);
const inlineHandler = /\son[a-z]+=/i.test(index);
if (inlineScript || inlineHandler) {
    console.error("index.html contains an inline script or event handler, which the Content-Security-Policy below would block.");
    console.error("Check angular.json (optimization.styles.inlineCritical must stay false) before deploying.");
    process.exit(1);
}

// The database's strings - which any administrator rewords on the Translations page - are drawn by the
// family's components (@anotoki/lib) as well as by the IAM's own. The IAM's own are held to "a string is
// text, never HTML" by the backend's scan of src/app (`php translations.php --status`, the library's
// KeyScanner with innerHTML refused anywhere); the library is
// outside it, so its bundles are held to it here, before anything is uploaded: a release that sets HTML
// from a string, or switches Angular's sanitizer off for one, goes no further. (Angular's own sanitizer is
// in @angular/core, not in these files.) Every way a string becomes markup counts: setting innerHTML or
// outerHTML, inserting HTML, an iframe's srcdoc, document.write, parsing it (DOMParser,
// parseFromString, a Range's createContextualFragment), and the sanitizer's bypasses. Any spelling counts
// - Angular binds [innerHtml] and [srcdoc] too - and so does a comment that names one: the fix is a release
// of the library, never a way around this check.
const library = join(process.cwd(), "node_modules", "@anotoki", "lib", "fesm2022");
const bundles = existsSync(library) ? readdirSync(library).filter((name) => name.endsWith(".mjs")) : [];
if (!bundles.length) {
    console.error(`No @anotoki/lib bundles in ${library}: install the packages (bun ci) and build again.`);
    process.exit(1);
}
const htmlSink = /innerHTML|outerHTML|insertAdjacentHTML|bypassSecurityTrust|srcdoc|document\.write|DOMParser|parseFromString|createContextualFragment/i;
const drawsHtml = [];
for (const name of bundles) {
    readFileSync(join(library, name), "utf8")
        .split(/\r?\n/)
        .forEach((line, index) => {
            const found = htmlSink.exec(line);
            if (found) drawsHtml.push(`node_modules/@anotoki/lib/fesm2022/${name}:${index + 1}: ${found[0]}`);
        });
}
if (drawsHtml.length) {
    console.error("@anotoki/lib draws a string as HTML, or switches Angular's sanitizer off - the IAM's strings, which administrators reword, must stay text:");
    for (const place of drawsHtml) console.error(`  ${place}`);
    console.error("Take a release of the library without it (irelevant25/anotoki-lib) before deploying.");
    process.exit(1);
}

// The family's visitor counter (Umami) reads the address as soon as it runs, and a confirm or reset link
// carries its token in the query string: its tag must tell it to leave the query string and the fragment
// out - and be async, so that no page of the sign-in waits for the counter. The snippet Umami's dashboard
// hands out has none of the three, so a build that pasted it as it is goes no further.
const counters = index.match(/<script\b[^>]*\bdata-website-id=[^>]*>/gi) ?? [];
const unsafe = counters.filter((tag) => !/\sasync(?=[\s>=])/i.test(tag) || !/\sdata-exclude-search="true"/i.test(tag) || !/\sdata-exclude-hash="true"/i.test(tag));
if (unsafe.length) {
    console.error('index.html loads the visitor counter without async, data-exclude-search="true" and data-exclude-hash="true":');
    for (const tag of unsafe) console.error(`  ${tag}`);
    process.exit(1);
}

const csp = [
    "default-src 'self'",
    "script-src 'self' https://accounts.google.com/gsi/client https://umami.anotoki.eu",
    "frame-src https://accounts.google.com/gsi/",
    "connect-src 'self' https://accounts.google.com/gsi/ https://umami.anotoki.eu",
    "style-src 'self' 'unsafe-inline' https://accounts.google.com/gsi/style",
    "img-src 'self' data: https:",
    "font-src 'self'",
    "frame-ancestors 'none'",
    "base-uri 'self'",
    "form-action 'self'",
].join("; ");

const htaccess = `# anotoki IAM - the web root: the Angular app, with the PHP API beside it.
# Written by frontend/scripts/prepare-deploy.mjs; edit that, not this.

<IfModule mod_rewrite.c>
RewriteEngine On

# The API is its own handler (api/.htaccess routes it to api/index.php).
RewriteRule ^api/ - [L]

# OpenID Connect discovery lives at the issuer's root. The front controller
# routes on the original request path, so it sees /.well-known/... as asked.
RewriteRule ^\\.well-known/(openid-configuration|jwks\\.json)$ api/index.php [L]

# Real files (the hashed bundles, the logo, theme-boot.js) as they are; every
# other path is a client-side route and gets the app.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]
</IfModule>

<IfModule mod_headers.c>
Header always set X-Frame-Options "DENY"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Content-Security-Policy "${csp}"
</IfModule>
`;

writeFileSync(join(dist, ".htaccess"), htaccess);

const files = readdirSync(dist).length;
const shown = dist
    .replace(process.cwd() + "\\", "")
    .replace(process.cwd() + "/", "")
    .replace(/\\/g, "/");
console.log(`Prepared ${shown}/ for production (${files} entries; upload its contents to the web root, next to api/):`);
console.log("  .htaccess  -> /api passed through, /.well-known -> api/index.php, SPA fallback");
console.log("               X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Content-Security-Policy");
console.log(`  checked    -> no inline script; @anotoki/lib's ${bundles.length} bundle${bundles.length === 1 ? "" : "s"}: no string drawn as HTML`);

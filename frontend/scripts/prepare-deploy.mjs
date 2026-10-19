// Checks a production `ng build` before it becomes a release - anotoki-deploy runs it with the build
// (backend/composer.json, extra["anotoki-deploy"].frontendBuild: `npm run build:deploy`), and a release that fails
// one goes no further:
//
//   - no inline script or event handler in index.html: the Content-Security-Policy (backend/composer.json's
//     extra["anotoki-deploy"].csp - this app, Google Identity Services and the family's visitor counter, nothing
//     inline) would block it, and the page would load blank in production alone. angular.json keeps
//     critical-CSS inlining off, whose loader is an inline onload handler;
//   - the family's library (@anotoki/lib) draws no string as HTML (below);
//   - the visitor counter's tag is async and leaves the query string and the fragment out (below).
//
// The web root's rules and headers are anotoki-deploy's frontend/.htaccess: /api and OpenID Connect's discovery
// (/.well-known/openid-configuration, /.well-known/jwks.json) to the front controller, which routes on the original
// path; every other path that is no file to the app; the headers and the CSP; changelog.json asked for again each
// time. backend/, config/ and storage/ (the master key, the backups, the logs) are never served: they are beside the
// release, not in it.
//
// Nothing here is used in dev.

import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

// The application builder puts the web files under dist/iam/browser/ - that
// folder, not its parent, is what becomes the web root.
const base = join(process.cwd(), 'dist', 'iam');
const dist = existsSync(join(base, 'browser')) ? join(base, 'browser') : base;

if (!existsSync(join(dist, 'index.html'))) {
  console.error(`No build at ${dist} (no index.html). Run \`ng build --configuration production\` first.`);
  process.exit(1);
}

// A build that slipped an inline script past the policy would load as a blank
// page in production and nowhere else. Cheaper to refuse it here.
const index = readFileSync(join(dist, 'index.html'), 'utf8');
const inlineScript = /<script(?![^>]*\bsrc=)[^>]*>/i.test(index);
const inlineHandler = /\son[a-z]+=/i.test(index);
if (inlineScript || inlineHandler) {
  console.error('index.html contains an inline script or event handler, which the Content-Security-Policy below would block.');
  console.error('Check angular.json (optimization.styles.inlineCritical must stay false) before deploying.');
  process.exit(1);
}

// The database's strings - which any administrator rewords on the Translations page - are drawn by the
// family's components (@anotoki/lib) as well as by the IAM's own. The IAM's own are held to "a string is
// text, never HTML" by the backend's scan of src/app (`php translations.php --status`, the library's
// KeyScanner with innerHTML refused anywhere); the library is
// outside it, so its bundles are held to it here, before anything is uploaded: a release that sets HTML
// from a string, or switches Angular's sanitizer off for one, goes no further. (Angular's own sanitizer is
// in @angular/core, not in these files.) Every way a string becomes markup counts: setting innerHTML or
// outerHTML, the newer setHTMLUnsafe, inserting HTML, an iframe's srcdoc, document.write, parsing it
// (DOMParser, parseFromString, Document.parseHTMLUnsafe, a Range's createContextualFragment), and the
// sanitizer's bypasses. Any spelling counts - Angular binds [innerHtml] and [srcdoc] too - and so does a
// comment that names one: the fix is a release of the library, never a way around this check. Every
// script of the library that reaches the web root is read: its bundles (fesm2022/) and the boot script
// angular.json copies beside index.html (assets/theme-boot.js, which runs before the app).
const packageRoot = join(process.cwd(), 'node_modules', '@anotoki', 'lib');
const scripts = [
  ...(existsSync(join(packageRoot, 'fesm2022'))
    ? readdirSync(join(packageRoot, 'fesm2022'))
        .filter((name) => name.endsWith('.mjs'))
        .map((name) => `fesm2022/${name}`)
    : []),
  ...(existsSync(join(packageRoot, 'assets'))
    ? readdirSync(join(packageRoot, 'assets'))
        .filter((name) => /\.m?js$/.test(name))
        .map((name) => `assets/${name}`)
    : []),
];
const bundles = scripts.filter((name) => name.startsWith('fesm2022/'));
if (!bundles.length || !scripts.includes('assets/theme-boot.js')) {
  console.error(`No @anotoki/lib bundles, or no assets/theme-boot.js, in ${packageRoot}: install the packages (bun ci) and build again.`);
  process.exit(1);
}
const htmlSink = /innerHTML|outerHTML|setHTMLUnsafe|parseHTMLUnsafe|insertAdjacentHTML|bypassSecurityTrust|srcdoc|document\.write|DOMParser|parseFromString|createContextualFragment/i;
const drawsHtml = [];
for (const name of scripts) {
  readFileSync(join(packageRoot, name), 'utf8')
    .split(/\r?\n/)
    .forEach((line, index) => {
      const found = htmlSink.exec(line);
      if (found) drawsHtml.push(`node_modules/@anotoki/lib/${name}:${index + 1}: ${found[0]}`);
    });
}
if (drawsHtml.length) {
  console.error("@anotoki/lib draws a string as HTML, or switches Angular's sanitizer off - the IAM's strings, which administrators reword, must stay text:");
  for (const place of drawsHtml) console.error(`  ${place}`);
  console.error('Take a release of the library without it (irelevant25/anotoki-lib) before deploying.');
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


const files = readdirSync(dist).length;
const shown = dist
  .replace(process.cwd() + '\\', '')
  .replace(process.cwd() + '/', '')
  .replace(/\\/g, '/');
console.log(`Checked ${shown}/ (${files} entries) for a release:`);
console.log(
  `  checked    -> no inline script; @anotoki/lib's ${bundles.length} bundle${bundles.length === 1 ? '' : 's'} and ${scripts.length - bundles.length} boot script${scripts.length - bundles.length === 1 ? '' : 's'}: no string drawn as HTML`,
);

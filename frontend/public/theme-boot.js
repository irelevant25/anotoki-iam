// Applies the stored light/dark choice before the first paint.
//
// The app sets the theme itself once it has loaded, but that is after the page
// has already been drawn once - and somebody who chose dark would see it flash
// light on every visit. A few lines here, loaded synchronously from <head>,
// close that gap. It is a file rather than an inline script because the
// Content-Security-Policy allows scripts from this origin only.
//
// Keep the key and the values in step with src/app/core/theme/theme.service.ts.
(function () {
  var theme = null;
  try {
    theme = localStorage.getItem('anotoki-iam:theme');
  } catch (e) {
    // Storage switched off: the system preference decides, through CSS.
  }
  if (theme !== 'light' && theme !== 'dark') {
    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-theme', theme);
  document.documentElement.style.colorScheme = theme;
})();

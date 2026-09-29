export interface DeviceDescription {
  /** "Firefox", "Edge" - or null when nothing recognisable was found. */
  browser: string | null;
  /** "Windows", "Android", "iPhone" - or null. */
  platform: string | null;
  mobile: boolean;
}

/**
 * The browser and system, roughly, from a user agent.
 *
 * A user agent is a long string built to be lied to, and every one of them
 * claims to be Mozilla. This picks out the part a person would recognise and
 * gives up gracefully - it labels a row in a table, and nothing depends on it.
 * Order matters: Edge and Opera also say Chrome, and Chrome also says Safari.
 */
export function describeUserAgent(agent: string | null | undefined): DeviceDescription {
  const ua = agent ?? '';

  const browsers: [RegExp, string][] = [
    [/Edg(e|A|iOS)?\//, 'Edge'],
    [/OPR\/|Opera/, 'Opera'],
    [/SamsungBrowser/, 'Samsung Internet'],
    [/Vivaldi/, 'Vivaldi'],
    [/Firefox\/|FxiOS/, 'Firefox'],
    [/Chrome\/|CriOS/, 'Chrome'],
    [/Safari\//, 'Safari'],
    [/curl\//, 'curl'],
  ];

  const platforms: [RegExp, string][] = [
    [/Android/, 'Android'],
    [/iPhone/, 'iPhone'],
    [/iPad/, 'iPad'],
    [/Windows/, 'Windows'],
    [/Mac OS X|Macintosh/, 'macOS'],
    [/CrOS/, 'ChromeOS'],
    [/Linux/, 'Linux'],
  ];

  const browser = browsers.find(([pattern]) => pattern.test(ua))?.[1] ?? null;
  const platform = platforms.find(([pattern]) => pattern.test(ua))?.[1] ?? null;

  return { browser, platform, mobile: /Mobi|Android|iPhone/.test(ua) };
}

/** "Firefox · Windows", or a shortened raw string when nothing was recognised, or null for no agent at all. */
export function deviceLabel(agent: string | null | undefined): string | null {
  if (!agent) {
    return null;
  }
  const { browser, platform } = describeUserAgent(agent);
  if (!browser && !platform) {
    return agent.length > 48 ? `${agent.slice(0, 45)}…` : agent;
  }
  return [browser, platform].filter(Boolean).join(' · ');
}

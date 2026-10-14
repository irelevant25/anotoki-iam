import { Injectable, computed, signal } from "@angular/core";
import { TestBed } from "@angular/core/testing";
import { ANOTOKI_TRANSLATIONS_CONFIG, AdminLanguage, TranslationService as LibraryTranslations, TranslationBundle, provideAnotokiTranslations } from "@anotoki/lib/translations";
import { TEST_LANGUAGES } from "@anotoki/lib/translations/testing";
import { Observable, defer, of, tap, throwError } from "rxjs";
import { localizationPages } from "../../pages/admin/localization";
import { AccountApi } from "../api/account.api";
import { ApiError } from "../api/api-error";
import { AccountUser } from "../api/models";
import { SessionService } from "../auth/session.service";
import { en } from "./en";
import { TranslationService } from "./translation.service";
import { LANGUAGE_STORAGE, iamTranslationsConfig, startTranslations, useAdminPages } from "./translations.config";

const ENGLISH: TranslationBundle = { language: "en", languages: TEST_LANGUAGES, values: { ...en } };
const SLOVAK: TranslationBundle = { language: "sk", languages: TEST_LANGUAGES, values: { ...en, "overview.languageSaved": "Jazyk je uložený." }, english: { ...en } };

/** The family's service with no HTTP: English on the page, either language answered at once. */
@Injectable()
class Answered extends LibraryTranslations {
  constructor() {
    super();
    this.use(ENGLISH);
  }

  protected override fetchBundle(code: string): Promise<unknown> {
    return Promise.resolve(code === "sk" ? SLOVAK : ENGLISH);
  }

  protected override canAsk(): boolean {
    return true;
  }
}

function account(language: string, isAdmin = false): AccountUser {
  return {
    id: 7,
    username: "Mira",
    email: "mira@example.test",
    email_confirmed: true,
    language,
    created_at: "2026-01-01T00:00:00Z",
    has_password: true,
    password_login_enabled: true,
    google_connected: false,
    google_email: null,
    totp_enabled: false,
    recovery_codes_remaining: 0,
    trusted_devices: 0,
    force_password_change: false,
    is_admin: isAdmin,
    news_mail: true,
    preferences: null,
    deletion_due_at: null,
  };
}

function language(code: string, accounts: number): AdminLanguage {
  return { code, name: code === "sk" ? "Slovak" : "Czech", native_name: code === "sk" ? "Slovenčina" : "Čeština", enabled: true, sort_order: 2, seeded: false, strings: 3, accounts };
}

/**
 * The family's translations as the IAM configures them (iamTranslationsConfig):
 * the names it kept the language, the words and the admin drafts under, the
 * account (the session's) and how a choice reaches it, and the admin panel's
 * Translations and Languages pages as it words them - handed over only when
 * the admin panel loads.
 */
describe("the translations, as the IAM configures them", () => {
  const user = signal<AccountUser | null>(null);
  let generation = 0;
  const session = {
    user,
    signedIn: computed(() => !!user()),
    isAdmin: computed(() => !!user()?.is_admin),
    setFrom: (request: Observable<AccountUser>) =>
      defer(() => {
        const sent = generation;
        return request.pipe(tap((next) => sent === generation && user.set(next)));
      }),
    clear: () => {
      generation++;
      user.set(null);
    },
  };
  const accountApi = { updateProfile: vi.fn() };
  const i18n = () => TestBed.inject(LibraryTranslations);

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    user.set(account("en"));
    accountApi.updateProfile.mockReset().mockImplementation((body: { language: string }) => of({ ...user()!, language: body.language }));
    TestBed.configureTestingModule({
      providers: [
        { provide: SessionService, useValue: session },
        { provide: AccountApi, useValue: accountApi },
        provideAnotokiTranslations(iamTranslationsConfig),
        { provide: LibraryTranslations, useClass: Answered },
      ],
    });
  });

  afterEach(() => useAdminPages({}));

  it("keeps the names the IAM had: a visitor’s chosen language, the cached words and the admin drafts survive the upgrade", async () => {
    expect(LANGUAGE_STORAGE).toEqual({ language: "anotoki-iam:lang", cache: "anotoki-iam:lang-cache", drafts: "anotoki-iam:localization-drafts" });
    user.set(null);
    expect(await i18n().setLanguage("sk")).toBe(true);
    expect(localStorage.getItem("anotoki-iam:lang")).toBe("sk");
    expect(JSON.parse(localStorage.getItem("anotoki-iam:lang-cache") ?? "{}").language).toBe("sk");
  });

  it("signed in, a choice is saved to the account through the IAM’s own API - and the answer is the session’s account", async () => {
    expect(await i18n().setLanguage("sk")).toBe(true);
    await new Promise((done) => setTimeout(done));
    expect(accountApi.updateProfile.mock.calls).toEqual([[{ language: "sk" }]]);
    expect(user()?.language).toBe("sk");
    expect(i18n().languageNotSaved()).toBe(false);
  });

  it("signed out, a choice is this device’s alone: nothing is sent", async () => {
    user.set(null);
    expect(await i18n().setLanguage("sk")).toBe(true);
    await new Promise((done) => setTimeout(done));
    expect(accountApi.updateProfile).not.toHaveBeenCalled();
  });

  it("the account page’s select: refused, the page goes back to the language it was in - and every refusal is news elsewhere", async () => {
    const refused: ApiError = { status: 0, code: "network", message: "The server could not be reached.", details: {} };
    accountApi.updateProfile.mockReturnValue(throwError(() => refused));
    // The account page's way (revertible): the page first, back when the account refuses.
    expect(await i18n().setLanguage("sk", { revertible: true })).toBe(false);
    expect(i18n().language()).toBe("en");
    expect(user()?.language).toBe("en");
    expect(i18n().languageNotSaved()).toBe(false);
    // The bar's way: the page keeps the choice for the visit, and the switcher's note says the account did not take it.
    expect(await i18n().setLanguage("sk")).toBe(true);
    await new Promise((done) => setTimeout(done));
    expect(i18n().language()).toBe("sk");
    expect(i18n().languageNotSaved()).toBe(true);
  });

  it("the admin pages’ words are handed over by the admin panel as it loads - nothing of them before", () => {
    const config = TestBed.inject(ANOTOKI_TRANSLATIONS_CONFIG);
    expect(config.admin).toEqual({});

    useAdminPages(localizationPages(TestBed.inject(SessionService)));
    const admin = config.admin!;
    // For the IAM's administrators alone; the server checks it again.
    expect(admin.allows?.strings?.()).toBe(false);
    user.set(account("en", true));
    expect(admin.allows?.strings?.()).toBe(true);
    expect(admin.allows?.languages?.()).toBe(true);
    expect(admin.routes).toEqual({ translations: "/admin/translations", languages: "/admin/languages", migrations: "/admin/migrations" });
    // The mails, one block each, in the IAM's order and their parts in the message's.
    const mails = admin.groups?.[0];
    expect(mails?.prefix).toBe("mail.");
    expect(mails?.order?.slice(0, 4)).toEqual(["confirm", "reset", "loginCode", "approved"]);
    expect(mails?.parts?.slice(0, 3)).toEqual(["subject", "greeting", "heading"]);
    expect(mails?.about?.["mail.reset"]?.title).toBe("The password reset mail");
    // How many accounts have a language, in words - and what deleting it does to them.
    expect(admin.languageNotes?.row?.(language("cs", 0))).toBe("No account has it");
    expect(admin.languageNotes?.row?.(language("sk", 1))).toBe("1 account has it");
    expect(admin.languageNotes?.beforeDelete?.(language("cs", 3))).toBe("3 accounts have it as their language, and go back to English.");
    expect(admin.languageNotes?.afterDelete?.(language("cs", 3), { strings: 4, accounts: 3 })).toBe("3 accounts went back to English.");
    expect(admin.languageNotes?.afterDelete?.(language("cs", 0), { strings: 4, accounts: 0 })).toBeNull();
    // A failure in the admin panel's words.
    expect(admin.failureText?.({ status: 0, code: "network", message: "", details: {} })).toBe("The server could not be reached. Check the connection and try again.");
  });

  it("the start asks for the account before the words on the account and admin pages and on the legal pages, whose bar shows who is signed in - never on the sign-in pages", async () => {
    const asked: string[] = [];
    const ask = {
      ensure: () =>
        defer(() => {
          asked.push("account");
          return of(null);
        }),
    } as unknown as SessionService;
    const words = {
      init: () => {
        asked.push("words");
        return Promise.resolve();
      },
    } as unknown as TranslationService;
    for (const path of ["/", "/account/security", "/admin/users", "/privacy", "/terms", "/contact", "/login", "/register", "/not-set-up"]) {
      asked.length = 0;
      await startTranslations(ask, words, path);
      expect([path, ...asked]).toEqual(["/login", "/register", "/not-set-up"].includes(path) ? [path, "words"] : [path, "account", "words"]);
    }
  });

  it("a legal page opens without the account when the server cannot say: the words still come", async () => {
    const unreachable: ApiError = { status: 0, code: "network", message: "", details: {} };
    const ask = { ensure: () => throwError(() => unreachable) } as unknown as SessionService;
    const init = vi.fn(() => Promise.resolve());
    await startTranslations(ask, { init } as unknown as TranslationService, "/privacy");
    expect(init).toHaveBeenCalledTimes(1);
  });
});

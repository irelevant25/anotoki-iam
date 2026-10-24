import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Title } from '@angular/platform-browser';
import { Router, TitleStrategy, provideRouter } from '@angular/router';
import { TranslationService } from '@anotoki/lib/translations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { IamTitleStrategy } from './title.strategy';

@Component({ selector: 'app-nowhere', template: '', changeDetection: ChangeDetectionStrategy.OnPush })
class NowhereComponent {}

/**
 * The browser tab's title in the anotoki family's form (IamTitleStrategy):
 * the page, then the site - and in the admin panel which part of it, "Users ·
 * Admin panel · anotoki account", as every anotoki site's admin area says it.
 * The account pages' titles are keys, worded in the page's language; the admin
 * panel's are English, the IAM's name in it too.
 */
describe('the tab title (IamTitleStrategy)', () => {
  let router: Router;
  let title: Title;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'account/security', title: 'security.pageTitle', component: NowhereComponent },
          { path: 'admin/users', title: 'Users', component: NowhereComponent },
          { path: 'admin/backups', title: 'Backups', component: NowhereComponent },
          { path: 'admin/nameless', component: NowhereComponent },
        ]),
        { provide: TitleStrategy, useClass: IamTitleStrategy },
        provideTestTranslations(
          { 'brand.account': 'anotoki účet', 'security.pageTitle': 'Zabezpečenie' },
          { language: 'sk', english: { 'brand.account': 'anotoki account', 'security.pageTitle': 'Security' } },
        ),
      ],
    });
    router = TestBed.inject(Router);
    title = TestBed.inject(Title);
  });

  async function open(url: string): Promise<string> {
    await router.navigateByUrl(url);
    TestBed.tick();
    return title.getTitle();
  }

  it('an account page: the page, then the IAM - in the page’s language', async () => {
    expect(await open('/account/security')).toBe('Zabezpečenie · anotoki účet');
  });

  it('the admin panel: the page, "Admin panel", then the IAM - in English, as the panel is', async () => {
    expect(await open('/admin/users')).toBe('Users · Admin panel · anotoki account');
    expect(await open('/admin/backups')).toBe('Backups · Admin panel · anotoki account');
    expect(await open('/admin/nameless')).toBe('Admin panel · anotoki account');
  });

  it('follows the language without a navigation', async () => {
    expect(await open('/account/security')).toBe('Zabezpečenie · anotoki účet');
    expect(await TestBed.inject(TranslationService).setLanguage('en')).toBe(true);
    TestBed.tick();
    expect(title.getTitle()).toBe('Security · anotoki account');
  });
});

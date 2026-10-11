<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations\Http;

use Anotoki\Lib\Support\JsonBody;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Translations\ETag;
use Anotoki\Lib\Translations\LanguageCode;
use Anotoki\Lib\Translations\TranslationFile;
use Anotoki\Lib\Translations\Translations;
use Closure;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Handlers\Strategies\RequestResponse;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteGroupInterface;
use Slim\Interfaces\RouteInterface;

/**
 * The routes of a site's words, on its Slim app: the bundle every page reads,
 * the strings its editors reword, and the languages its administrators keep.
 * One wire format for every site - the IAM's, snake_case (anotoki-iam's
 * docs/api.md is the contract).
 *
 * The library decides nothing about who may do what: strings() and languages()
 * return their route groups, and the site adds its own checks to each (the
 * IAM: ADMIN for both; the survey: ADMIN or EDITOR for the strings, ADMIN for
 * the languages; genshin: ADMIN or EDITOR for both), and its CSRF guard as it
 * has one.
 *
 * Every handler is called as ($request, $response) whatever invocation
 * strategy the site's app uses, and answers in this order, after the site's
 * own middleware (401, 403): a {code} that is no language code 404 before any
 * query; a body that is too large, not JSON, empty or broken (413, 415, 422
 * empty_body, 400 invalid_json) - read by the library itself from the raw
 * stream, never from the site's parsed body; the tables not there yet, 503
 * translations_unavailable with Retry-After: 60; then the work. JSON answers
 * carry Cache-Control: no-store, but the bundle's.
 *
 * The closures:
 * - $translations: fn(): Translations - built when a request needs it, like the Migrator;
 * - $actor: fn(ServerRequestInterface): ?int - who is saving: the person's id from the
 *   session or the token, never from a body;
 * - $error: fn(ResponseInterface, Refusal): ResponseInterface - the site's error body
 *   (the IAM: {error, code, ...extra}); by default {code, message, ...extra};
 * - $audit: fn(ServerRequestInterface $request, string $action, ?string $type, ?string $id,
 *   array $details): void - after a write that changed something: language.created (name,
 *   native_name, enabled), language.updated (what changed), language.deleted (name, strings
 *   and the site's fields), translations.saved and translations.imported (the save's summary).
 */
final class TranslationsRoutes
{
    /** How long a page waits before asking again while the tables are not there. */
    public const RETRY_AFTER_SECONDS = 60;

    /**
     * GET $path/{code} - the pages' words in one language (Translations::bundle()).
     * Public: it reads no token and no cookie, and sets none - the words are the
     * same for everybody, and a page whose sign-in has run out still reads in its
     * language. ETag (the body's MD5) and Cache-Control: no-cache, so the browser
     * asks every time and is told 304 - with both headers - when nothing changed.
     * A database that cannot be asked answers 503 translations_unavailable too:
     * the pages have their compiled English, and nothing is logged on every page load.
     *
     * Open it in the SiteGate with openPath($path), so the pages have their words
     * while an update waits.
     */
    public static function bundle(RouteCollectorProxyInterface $app, Closure $translations, ?Closure $error = null, string $path = '/api/translations'): void
    {
        self::plain($app->get($path . '/{code}', function (ServerRequestInterface $request, ResponseInterface $response) use ($translations, $error): ResponseInterface {
            $code = (string) $request->getAttribute('code');
            if (!LanguageCode::ok($code)) {
                return self::refuse($response, Translations::notFound(), $error);
            }

            try {
                $bundle = $translations()->bundle($code);
            } catch (PDOException) {
                $bundle = Translations::unavailable();
            }
            if ($bundle instanceof Refusal) {
                return self::refuse($response, $bundle, $error);
            }

            $body = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $etag = ETag::of($body);
            // Both headers before the 304 too: without them it would go out as no-store.
            $response = $response->withHeader('ETag', $etag)->withHeader('Cache-Control', 'no-cache');
            if (ETag::notModified($request->getHeaderLine('If-None-Match'), $etag)) {
                return $response->withStatus(304);
            }
            $response->getBody()->write($body);

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }));
    }

    /**
     * The strings, for whoever may reword them (the site's check on the group):
     *
     *   GET $base/languages                    {languages: AdminLanguage[]} - every language, the switched-off ones too
     *   GET $base/translations                 TranslationGrid
     *   PUT $base/translations                 {values: {key: {language: text}}}, only what changed -> TranslationGrid
     *   GET $base/translations/{code}/export   the language's own strings as a file, {key: text}
     *   PUT $base/translations/{code}/import   {key: text} -> TranslationGrid
     */
    public static function strings(
        RouteCollectorProxyInterface $app,
        string $base,
        Closure $translations,
        Closure $actor,
        ?Closure $error = null,
        ?Closure $audit = null,
    ): RouteGroupInterface {
        return $app->group($base, function (RouteCollectorProxyInterface $group) use ($translations, $actor, $error, $audit): void {
            self::plain($group->get('/languages', function (ServerRequestInterface $request, ResponseInterface $response) use ($translations, $error): ResponseInterface {
                $words = $translations();
                if (!$words->ready()) {
                    return self::refuse($response, Translations::unavailable(), $error);
                }

                return JsonResponse::write($response, 200, ['languages' => $words->languages()]);
            }));

            self::plain($group->get('/translations', function (ServerRequestInterface $request, ResponseInterface $response) use ($translations, $error): ResponseInterface {
                $words = $translations();
                if (!$words->ready()) {
                    return self::refuse($response, Translations::unavailable(), $error);
                }

                return JsonResponse::write($response, 200, $words->grid());
            }));

            self::plain($group->put('/translations', function (ServerRequestInterface $request, ResponseInterface $response) use ($translations, $actor, $error, $audit): ResponseInterface {
                $words = $translations();
                $body = JsonBody::read($request, $words->config()->bodyLimit);
                if ($body instanceof Refusal) {
                    return self::refuse($response, $body, $error);
                }
                if (!$words->ready()) {
                    return self::refuse($response, Translations::unavailable(), $error);
                }

                $result = $words->save(self::values($body), $actor($request));
                if ($result instanceof Refusal) {
                    return self::refuse($response, $result, $error);
                }
                if ($audit !== null && $result['count'] > 0) {
                    $audit($request, 'translations.saved', null, null, $result);
                }

                return JsonResponse::write($response, 200, $words->grid());
            }));

            self::plain($group->get('/translations/{code}/export', function (ServerRequestInterface $request, ResponseInterface $response) use ($translations, $error): ResponseInterface {
                $code = (string) $request->getAttribute('code');
                if (!LanguageCode::ok($code)) {
                    return self::refuse($response, Translations::notFound(), $error);
                }
                $words = $translations();
                if (!$words->ready()) {
                    return self::refuse($response, Translations::unavailable(), $error);
                }

                $result = $words->export($code);
                if ($result instanceof Refusal) {
                    return self::refuse($response, $result, $error);
                }
                $response->getBody()->write(TranslationFile::text($result['values']));

                return $response
                    ->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withHeader('Cache-Control', 'no-store')
                    ->withHeader('Content-Disposition', 'attachment; filename="translations-' . $code . '.json"');
            }));

            self::plain($group->put('/translations/{code}/import', function (ServerRequestInterface $request, ResponseInterface $response) use ($translations, $actor, $error, $audit): ResponseInterface {
                $code = (string) $request->getAttribute('code');
                if (!LanguageCode::ok($code)) {
                    return self::refuse($response, Translations::notFound(), $error);
                }
                $words = $translations();
                $body = JsonBody::read($request, $words->config()->bodyLimit);
                if ($body instanceof Refusal) {
                    return self::refuse($response, $body, $error);
                }
                if (!$words->ready()) {
                    return self::refuse($response, Translations::unavailable(), $error);
                }

                $result = $words->import($code, JsonBody::map($body), $actor($request));
                if ($result instanceof Refusal) {
                    return self::refuse($response, $result, $error);
                }
                if ($audit !== null && $result['count'] > 0) {
                    $audit($request, 'translations.imported', 'language', $code, $result);
                }

                return JsonResponse::write($response, 200, $words->grid());
            }));
        });
    }

    /**
     * The languages, for whoever may keep them (the site's check on the group):
     *
     *   POST   $base/languages          {code, name, native_name, enabled?} -> 201 {language: AdminLanguage}
     *   PUT    $base/languages/{code}   {name?, native_name?, enabled?, sort_order?} -> {language: AdminLanguage}
     *   DELETE $base/languages/{code}   -> {strings, ...the site's fields}: the strings that went with it
     */
    public static function languages(
        RouteCollectorProxyInterface $app,
        string $base,
        Closure $translations,
        ?Closure $error = null,
        ?Closure $audit = null,
    ): RouteGroupInterface {
        return $app->group($base, function (RouteCollectorProxyInterface $group) use ($translations, $error, $audit): void {
            self::plain($group->post('/languages', function (ServerRequestInterface $request, ResponseInterface $response) use ($translations, $error, $audit): ResponseInterface {
                $words = $translations();
                $body = JsonBody::read($request, $words->config()->bodyLimit);
                if ($body instanceof Refusal) {
                    return self::refuse($response, $body, $error);
                }
                if (!$words->ready()) {
                    return self::refuse($response, Translations::unavailable(), $error);
                }

                $result = $words->createLanguage(JsonBody::map($body));
                if ($result instanceof Refusal) {
                    return self::refuse($response, $result, $error);
                }
                $language = $result['language'];
                if ($audit !== null) {
                    $audit($request, 'language.created', 'language', $language['code'], [
                        'name' => $language['name'],
                        'native_name' => $language['native_name'],
                        'enabled' => $language['enabled'],
                    ]);
                }

                return JsonResponse::write($response, 201, ['language' => $language]);
            }));

            self::plain($group->put('/languages/{code}', function (ServerRequestInterface $request, ResponseInterface $response) use ($translations, $error, $audit): ResponseInterface {
                $code = (string) $request->getAttribute('code');
                if (!LanguageCode::ok($code)) {
                    return self::refuse($response, Translations::notFound(), $error);
                }
                $words = $translations();
                $body = JsonBody::read($request, $words->config()->bodyLimit);
                if ($body instanceof Refusal) {
                    return self::refuse($response, $body, $error);
                }
                if (!$words->ready()) {
                    return self::refuse($response, Translations::unavailable(), $error);
                }

                $result = $words->updateLanguage($code, JsonBody::map($body));
                if ($result instanceof Refusal) {
                    return self::refuse($response, $result, $error);
                }
                if ($audit !== null && $result['changed'] !== []) {
                    $audit($request, 'language.updated', 'language', $code, $result['changed']);
                }

                return JsonResponse::write($response, 200, ['language' => $result['language']]);
            }));

            self::plain($group->delete('/languages/{code}', function (ServerRequestInterface $request, ResponseInterface $response) use ($translations, $error, $audit): ResponseInterface {
                $code = (string) $request->getAttribute('code');
                if (!LanguageCode::ok($code)) {
                    return self::refuse($response, Translations::notFound(), $error);
                }
                $words = $translations();
                if (!$words->ready()) {
                    return self::refuse($response, Translations::unavailable(), $error);
                }

                $result = $words->deleteLanguage($code);
                if ($result instanceof Refusal) {
                    return self::refuse($response, $result, $error);
                }
                if ($audit !== null) {
                    $audit($request, 'language.deleted', 'language', $code, ['name' => $result['language']['name'], 'strings' => $result['strings']] + $result['extras']);
                }

                return JsonResponse::write($response, 200, ['strings' => $result['strings']] + $result['extras']);
            }));
        });
    }

    /**
     * The bundle's path as one of the SiteGate's open paths: exactly $path/{code},
     * one segment and nothing after it - never a prefix ('~^/api/translations/[^/]+$~D').
     */
    public static function openPath(string $path = '/api/translations'): string
    {
        return '~^' . preg_quote($path, '~') . '/[^/]+$~D';
    }

    /**
     * The strings of a save's body, {values: {key: {language: text}}}, as Translations::save() takes them -
     * or null when the body has no such object. A key whose strings are not given as an object (a list, a
     * text, nothing) gets false in their place, which the check refuses, naming the key.
     *
     * @return ?array<array-key, mixed>
     */
    private static function values(mixed $body): ?array
    {
        $values = JsonBody::map(JsonBody::map($body)['values'] ?? null);
        if ($values === null) {
            return null;
        }
        foreach ($values as $key => $cells) {
            $values[$key] = JsonBody::map($cells) ?? false;
        }

        return $values;
    }

    private static function refuse(ResponseInterface $response, Refusal $refusal, ?Closure $error): ResponseInterface
    {
        $response = $error !== null ? $error($response, $refusal) : JsonResponse::write($response, $refusal->status, $refusal->body());

        return $refusal->status === 503 ? $response->withHeader('Retry-After', (string) self::RETRY_AFTER_SECONDS) : $response;
    }

    /** The route's handler gets ($request, $response), whatever the app's default invocation strategy. */
    private static function plain(RouteInterface $route): void
    {
        $route->setInvocationStrategy(new RequestResponse());
    }
}

<?php

declare(strict_types=1);

namespace Anotoki\Lib\Files;

use Anotoki\Lib\Support\AtomicFile;
use Anotoki\Lib\Support\GuardedDirectory;
use Anotoki\Lib\Support\Keys;
use Anotoki\Lib\Support\Log;
use Anotoki\Lib\Support\Redactor;
use Anotoki\Lib\Support\Refusal;
use Closure;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * The admin panel's file explorer: the site's roots (FileRoot) browsed,
 * searched, and - where a root is written into - uploaded to, given new
 * folders, renamed, moved, and emptied into a trash that can be restored from.
 * Every path goes through PathGuard, every name and every upload's bytes
 * through UploadPolicy (each rule there with the attack it stops).
 *
 * No table: a root of many files is listed from a snapshot,
 * {storage}/cache/explorer-<root>.json - taken by rescan(), kept in step by the
 * explorer's own writes, and telling what is new since the scan before (what
 * an FTP upload brought) - genshin's design; a small root is read as it is. The
 * trash is {storage}/trash/<root>/<folder>/<YYYYMMDD-HHMMSS>__<name> (genshin's
 * layout under the root's name), in a denied folder.
 *
 * A preview is a link signed for five minutes (an <img> or an <audio> sends no
 * Bearer header; signed rather than public, so a root that is not public
 * stays so): raw() checks it, and the raw route serves pictures, sound and
 * short texts inline and everything else (SVG among it) only as a download.
 *
 * `afterWrite(root, op, path, from)` keeps a site's own records in step (genshin:
 * its catalogue, its folder cache, its conversions): op is upload, folder,
 * rename, move, delete or restore.
 */
final class FileExplorer
{
    public const PAGE_SIZE = 120;

    /** The most of a text file a preview shows in the page. */
    public const TEXT_PREVIEW_BYTES = 65536;

    /** The pictures a browser shows as pictures: served inline. */
    private const INLINE_IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'];

    private const OPTIONS = ['storage', 'key', 'afterWrite', 'rawPath', 'previewSeconds', 'walkLimit'];

    /** @var array<string, FileRoot> */
    private readonly array $roots;
    private readonly string $storage;
    private ?string $key;
    private readonly ?Closure $afterWrite;
    private string $rawPath;
    private readonly int $previewSeconds;
    private readonly int $walkLimit;

    /**
     * @param list<FileRoot> $roots
     * @param array{
     *   storage: string,
     *   key?: string,
     *   afterWrite?: Closure(string, string, string, ?string): void,
     *   rawPath?: string,
     *   previewSeconds?: int,
     *   walkLimit?: int,
     * } $options
     *   - storage: the site's storage folder (snapshots, the trash, uploads on their way in)
     *   - key: the previews' HMAC key (a site's secret through Keys::derive()); default the installation's
     *   - rawPath: the raw route's path, as the browser asks it (FilesRoutes::raw() sets it)
     *   - walkLimit: the most entries a search of a root without a snapshot reads (200,000)
     */
    public function __construct(array $roots, array $options)
    {
        $unknown = array_diff(array_map('strval', array_keys($options)), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown FileExplorer option: ' . implode(', ', $unknown) . '.');
        }
        $byName = [];
        foreach ($roots as $root) {
            if (!($root instanceof FileRoot)) {
                throw new InvalidArgumentException('Every root must be a FileRoot.');
            }
            if (isset($byName[$root->name])) {
                throw new InvalidArgumentException("Two roots are named {$root->name}.");
            }
            $byName[$root->name] = $root;
        }
        $this->roots = $byName;
        $storage = $options['storage'] ?? null;
        if (!is_string($storage) || $storage === '') {
            throw new InvalidArgumentException('The file explorer needs the site\'s storage folder.');
        }
        $this->storage = rtrim($storage, '/\\');
        // The storage folder holds the trash, the snapshots, the keys and the backups: never shown or written here.
        $kept = rtrim(str_replace('\\', '/', (string) (realpath($this->storage) ?: $this->storage)), '/');
        foreach ($this->roots as $root) {
            $real = rtrim(str_replace('\\', '/', (string) (realpath($root->path) ?: $root->path)), '/');
            if (strcasecmp($real, $kept) === 0 || PathGuard::inside($real, $kept) || PathGuard::inside($kept, $real)) {
                throw new InvalidArgumentException("The root {$root->name} is the storage folder, holds it or is inside it: the explorer never shows what the library keeps there.");
            }
        }
        $key = $options['key'] ?? null;
        if ($key !== null && (!is_string($key) || strlen($key) < 16)) {
            throw new InvalidArgumentException('The option key must be a string of at least 16 bytes.');
        }
        $this->key = $key;
        $this->afterWrite = $options['afterWrite'] ?? null;
        $this->rawPath = (string) ($options['rawPath'] ?? '/api/admin/files/raw');
        $this->previewSeconds = max(30, (int) ($options['previewSeconds'] ?? 300));
        $this->walkLimit = max(1000, (int) ($options['walkLimit'] ?? 200_000));
    }

    /** @internal FilesRoutes::raw() says where its route is */
    public function servedAt(string $rawPath): void
    {
        $this->rawPath = $rawPath;
    }

    /**
     * The roots, for the page's tabs.
     *
     * @return list<array{name: string, label: string, write: bool, extensions: list<string>, max_bytes: int, snapshot: bool}>
     */
    public function roots(): array
    {
        $out = [];
        foreach ($this->roots as $root) {
            $out[] = [
                'name' => $root->name,
                'label' => $root->label !== '' ? $root->label : $root->name,
                'write' => $root->write,
                'extensions' => $root->extensions,
                'max_bytes' => $root->maxBytes,
                'snapshot' => $root->snapshot,
            ];
        }

        return $out;
    }

    public function root(string $name): ?FileRoot
    {
        return $this->roots[$name] ?? null;
    }

    // ─── Reading ─────────────────────────────────────────────────────────────

    /**
     * One folder (its folders, with how many files each holds, and its files, a page at a time), every file
     * under it whose name has $search in it, or ($new) every file new since the scan before the last:
     * {root, path, search, mode, folders, files, total, page, pageSize, status}.
     *
     * @return array<string, mixed>|Refusal
     */
    public function list(string $rootName, string $path = '', string $search = '', int $page = 1, bool $new = false): array|Refusal
    {
        [$root, $real] = $this->open($rootName);
        if ($root === null) {
            return self::noRoot();
        }
        $relative = PathGuard::relative($path);
        if ($relative === null) {
            return self::notFound();
        }
        $search = trim($search);
        $page = max(1, $page);
        $snapshot = $this->snapshot($root->name);

        if ($new) {
            $files = [];
            foreach ($snapshot['new'] ?? [] as $file) {
                $file = (string) $file;
                if (isset($snapshot['files'][$file]) && ($search === '' || stripos(basename($file), $search) !== false)) {
                    $files[] = $this->fileEntry($root, $file, $snapshot['files'][$file]);
                }
            }

            return $this->page($root, '', $search, 'new', [], $files, $page, $snapshot);
        }

        if ($real === null) {
            return self::notFound();
        }
        $folder = PathGuard::resolve($real, $relative);
        if ($folder === null || !is_dir($folder)) {
            return self::notFound();
        }

        if ($root->snapshot) {
            if ($snapshot === null) {
                return $this->page($root, $relative, $search, $search === '' ? 'folder' : 'search', [], [], $page, null);
            }
            [$folders, $files] = $this->fromSnapshot($root, $snapshot, $relative, $search);
        } else {
            [$folders, $files] = $search === '' ? $this->fromDisk($root, $real, $relative) : $this->searchDisk($root, $real, $relative, $search);
        }

        return $this->page($root, $relative, $search, $search === '' ? 'folder' : 'search', $folders, $files, $page, $snapshot);
    }

    /**
     * Walks the root again into its snapshot; what is new is told against the walk before.
     *
     * @return array{scanned_at: ?string, previous_scanned_at: ?string, total: int, new_count: int}|Refusal
     */
    public function rescan(string $rootName): array|Refusal
    {
        [$root, $real] = $this->open($rootName);
        if ($root === null || $real === null) {
            return $root === null ? self::noRoot() : self::notFound();
        }
        $walk = $this->walk($real, '', PHP_INT_MAX);

        return $this->withSnapshot(
            $root->name,
            static function (?array $before) use ($walk): array {
                $previous = $before['files'] ?? null;
                $new = is_array($previous) ? array_map('strval', array_values(array_diff(array_map('strval', array_keys($walk['files'])), array_map('strval', array_keys($previous))))) : [];
                sort($new, SORT_NATURAL | SORT_FLAG_CASE);

                return [
                    'scanned_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'previous_scanned_at' => $before['scanned_at'] ?? null,
                    'files' => $walk['files'],
                    'folders' => $walk['folders'],
                    'new' => $new,
                ];
            },
            true,
        ) ?? self::status(null);
    }

    // ─── Writing ─────────────────────────────────────────────────────────────

    /**
     * Files into $folder of a written root: each named by UploadPolicy::storedName(), its bytes checked,
     * written over a file of that name only with $replace. One answer per file:
     * {uploaded, refused, files: [{name, stored_as, path, status: uploaded|replaced|refused, code?, message?, size?}]}.
     *
     * @param list<UploadedFileInterface> $files
     * @return array<string, mixed>|Refusal
     */
    public function upload(string $rootName, string $folder, array $files, bool $replace = false): array|Refusal
    {
        [$root, $real] = $this->open($rootName);
        if ($root === null) {
            return self::noRoot();
        }
        $refusal = $this->writable($root, $real);
        if ($refusal !== null) {
            return $refusal;
        }
        $relative = PathGuard::relative($folder);
        $directory = $relative === null ? null : PathGuard::resolve((string) $real, $relative);
        if ($directory === null || !is_dir($directory)) {
            return new Refusal(404, 'folder_not_found', 'There is no such folder.');
        }
        if ($files === []) {
            return Refusal::invalid('no_files', 'Choose the files to upload.');
        }
        $this->sweepIncoming();

        $results = [];
        $uploaded = 0;
        foreach ($files as $file) {
            $result = $this->uploadOne($root, (string) $real, (string) $relative, $file, $replace);
            $uploaded += $result['status'] === 'refused' ? 0 : 1;
            $results[] = $result;
        }

        return ['uploaded' => $uploaded, 'refused' => count($results) - $uploaded, 'files' => $results];
    }

    /** A new folder ($path: its parent must be there). @return array{path: string}|Refusal */
    public function makeFolder(string $rootName, string $path): array|Refusal
    {
        [$root, $real] = $this->open($rootName);
        if ($root === null) {
            return self::noRoot();
        }
        $refusal = $this->writable($root, $real);
        if ($refusal !== null) {
            return $refusal;
        }
        $relative = PathGuard::relative($path);
        if ($relative === null || $relative === '') {
            return Refusal::invalid('name_invalid', 'That is no name a folder can have here.');
        }
        [$parent, $name] = PathGuard::split($relative);
        $checked = UploadPolicy::newName($name, $root, true);
        if ($checked instanceof Refusal) {
            return $checked;
        }
        $parentPath = PathGuard::resolve((string) $real, $parent);
        if ($parentPath === null || !is_dir($parentPath)) {
            return new Refusal(404, 'folder_not_found', 'There is no such folder.');
        }
        $target = PathGuard::resolve((string) $real, $relative, mustExist: false);
        if ($target === null) {
            return Refusal::invalid('name_invalid', 'That is no name a folder can have here.');
        }
        if (file_exists($target)) {
            return new Refusal(409, 'exists', 'Something of that name is there already.');
        }
        if (!@mkdir($target, 0755)) {
            return new Refusal(500, 'write_failed', 'The folder could not be made.');
        }
        $this->updateSnapshot($root->name, static function (array $snapshot) use ($relative): array {
            $snapshot['folders'][$relative] = true;

            return $snapshot;
        });
        $this->notify($root->name, 'folder', $relative, null);

        return ['path' => $relative];
    }

    /**
     * A file or a folder renamed or moved within its root - never onto something that is there, never a
     * folder into itself. @return array{from: string, to: string, op: string}|Refusal
     */
    public function move(string $rootName, string $from, string $to): array|Refusal
    {
        [$root, $real] = $this->open($rootName);
        if ($root === null) {
            return self::noRoot();
        }
        $refusal = $this->writable($root, $real);
        if ($refusal !== null) {
            return $refusal;
        }
        $source = PathGuard::relative($from);
        $target = PathGuard::relative($to);
        if ($source === null || $source === '' || $target === null || $target === '') {
            return self::notFound();
        }
        $sourcePath = PathGuard::resolve((string) $real, $source);
        if ($sourcePath === null) {
            return self::notFound();
        }
        $isFolder = is_dir($sourcePath);
        if ($isFolder && ($target === $source || str_starts_with($target . '/', $source . '/'))) {
            return new Refusal(409, 'into_itself', 'A folder cannot go into itself.');
        }
        [$targetParent, $targetName] = PathGuard::split($target);
        $checked = UploadPolicy::newName($targetName, $root, $isFolder);
        if ($checked instanceof Refusal) {
            return $checked;
        }
        $target = ($targetParent === '' ? '' : $targetParent . '/') . $checked;
        $parentPath = PathGuard::resolve((string) $real, $targetParent);
        if ($parentPath === null || !is_dir($parentPath)) {
            return new Refusal(404, 'folder_not_found', 'There is no such folder.');
        }
        $targetPath = PathGuard::resolve((string) $real, $target, mustExist: false);
        if ($targetPath === null) {
            return Refusal::invalid('name_invalid', 'That is no name a file can have here.');
        }
        if (file_exists($targetPath)) {
            return new Refusal(409, 'exists', 'Something of that name is there already.');
        }
        // A file given another extension must be of it, as an upload must: a text renamed .png is no picture.
        $extension = strtolower((string) pathinfo($checked, PATHINFO_EXTENSION));
        if (!$isFolder && $extension !== strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION))) {
            $content = UploadPolicy::contentRefusal($sourcePath, $extension);
            if ($content !== null) {
                return $content;
            }
        }
        if (!@rename($sourcePath, $targetPath)) {
            return new Refusal(500, 'write_failed', 'It could not be moved.');
        }
        $this->updateSnapshot($root->name, static fn(array $snapshot): array => self::moveInSnapshot($snapshot, $source, $target));
        $op = PathGuard::split($source)[0] === $targetParent ? 'rename' : 'move';
        $this->notify($root->name, $op, $target, $source);

        return ['from' => $source, 'to' => $target, 'op' => $op];
    }

    /**
     * A file into the trash (a folder only when empty: it is simply removed).
     *
     * @return array{path: string, trashed: ?string}|Refusal
     */
    public function delete(string $rootName, string $path): array|Refusal
    {
        [$root, $real] = $this->open($rootName);
        if ($root === null) {
            return self::noRoot();
        }
        $refusal = $this->writable($root, $real);
        if ($refusal !== null) {
            return $refusal;
        }
        $relative = PathGuard::relative($path);
        $target = $relative === null || $relative === '' ? null : PathGuard::resolve((string) $real, $relative);
        if ($target === null) {
            return self::notFound();
        }
        if (is_dir($target)) {
            if (count(@scandir($target) ?: []) > 2) {
                return new Refusal(409, 'folder_not_empty', 'Only an empty folder is deleted: move or delete what it holds first.');
            }
            if (!@rmdir($target)) {
                return new Refusal(500, 'write_failed', 'The folder could not be deleted.');
            }
            $this->updateSnapshot($root->name, static function (array $snapshot) use ($relative): array {
                unset($snapshot['folders'][$relative]);

                return $snapshot;
            });
            $this->notify($root->name, 'delete', $relative, null);

            return ['path' => $relative, 'trashed' => null];
        }

        [$folder, $name] = PathGuard::split($relative);
        $trashFolder = $this->trashRoot($root->name) . ($folder === '' ? '' : '/' . $folder);
        if (!$this->ensureTrashFolder($root->name, $folder)) {
            return new Refusal(500, 'write_failed', 'The trash could not be made ready.');
        }
        $stamp = gmdate('Ymd-His');
        $stamped = $stamp . '__' . $name;
        for ($n = 2; file_exists($trashFolder . '/' . $stamped) && $n < 1000; $n++) {
            $stamped = $stamp . '-' . $n . '__' . $name;
        }
        if (!@rename($target, $trashFolder . '/' . $stamped)) {
            return new Refusal(500, 'write_failed', 'The file could not be moved to the trash.');
        }
        $this->updateSnapshot($root->name, static function (array $snapshot) use ($relative): array {
            unset($snapshot['files'][$relative]);
            $snapshot['new'] = array_values(array_diff($snapshot['new'] ?? [], [$relative]));

            return $snapshot;
        });
        $this->notify($root->name, 'delete', $relative, null);

        return ['path' => $relative, 'trashed' => ($folder === '' ? '' : $folder . '/') . $stamped];
    }

    /**
     * What the root's trash holds, the last deleted first.
     *
     * @return list<array{trashed: string, folder: string, name: string, deleted_at: ?string, size: int}>|Refusal
     */
    public function trash(string $rootName): array|Refusal
    {
        $root = $this->root($rootName);
        if ($root === null) {
            return self::noRoot();
        }
        $items = [];
        $trashRoot = PathGuard::root($this->trashRoot($root->name));
        if ($trashRoot !== null) {
            foreach ($this->walk($trashRoot, '', $this->walkLimit)['files'] as $relative => [$size]) {
                $relative = (string) $relative;
                [$folder, $stamped] = PathGuard::split($relative);
                if (preg_match('/^(\d{8})-(\d{6})(?:-\d+)?__(.+)$/D', $stamped, $match) !== 1) {
                    continue;
                }
                $items[] = [
                    'trashed' => $relative,
                    'folder' => $folder,
                    'name' => $match[3],
                    'deleted_at' => self::stampTime($match[1], $match[2]),
                    'size' => $size,
                ];
            }
        }
        usort($items, static fn(array $a, array $b): int => [$b['deleted_at'], $b['trashed']] <=> [$a['deleted_at'], $a['trashed']]);

        return $items;
    }

    /**
     * A file from the trash back where it was (its folder made again if it went) - never over a file of
     * that name, never a name a server could run. @return array{path: string}|Refusal
     */
    public function restore(string $rootName, string $trashed): array|Refusal
    {
        [$root, $real] = $this->open($rootName);
        if ($root === null) {
            return self::noRoot();
        }
        $refusal = $this->writable($root, $real);
        if ($refusal !== null) {
            return $refusal;
        }
        $relative = PathGuard::relative($trashed);
        $trashRoot = PathGuard::root($this->trashRoot($root->name));
        $source = $relative === null || $relative === '' || $trashRoot === null ? null : PathGuard::resolve($trashRoot, $relative);
        if ($source === null || !is_file($source)) {
            return self::notFound();
        }
        [$folder, $stamped] = PathGuard::split((string) $relative);
        if (preg_match('/^\d{8}-\d{6}(?:-\d+)?__(.+)$/D', $stamped, $match) !== 1) {
            return self::notFound();
        }
        $name = $match[1];
        if (PathGuard::segment($name) === null || UploadPolicy::runsOnServer($name)) {
            return Refusal::invalid('name_runs_on_server', "\"$name\" could run as a script on the web server, so it stays in the trash.", ['name' => $name]);
        }
        $directory = $this->makeFolders($root, (string) $real, $folder);
        if ($directory === null) {
            return new Refusal(500, 'write_failed', 'Its folder could not be made again.');
        }
        $target = ($folder === '' ? '' : $folder . '/') . $name;
        $targetPath = PathGuard::resolve((string) $real, $target, mustExist: false);
        if ($targetPath === null) {
            return self::notFound();
        }
        if (file_exists($targetPath)) {
            return new Refusal(409, 'exists', 'A file of that name is there already.');
        }
        if (!@rename($source, $targetPath)) {
            return new Refusal(500, 'write_failed', 'The file could not be put back.');
        }
        $this->updateSnapshot($root->name, static function (array $snapshot) use ($target, $targetPath): array {
            $snapshot['files'][$target] = [(int) @filesize($targetPath), (int) @filemtime($targetPath)];

            return $snapshot;
        });
        $this->notify($root->name, 'restore', $target, null);

        return ['path' => $target];
    }

    /**
     * Deletes for good what the trash holds - all of it, or the one $trashed.
     *
     * @return array{deleted: int, bytes: int, failed: int}|Refusal
     */
    public function emptyTrash(string $rootName, ?string $trashed = null): array|Refusal
    {
        $root = $this->root($rootName);
        if ($root === null) {
            return self::noRoot();
        }
        $trashRoot = PathGuard::root($this->trashRoot($root->name));
        if ($trashRoot === null) {
            return ['deleted' => 0, 'bytes' => 0, 'failed' => 0];
        }
        $deleted = 0;
        $bytes = 0;
        $failed = 0;
        if ($trashed !== null && $trashed !== '') {
            $relative = PathGuard::relative($trashed);
            $path = $relative === null || $relative === '' ? null : PathGuard::resolve($trashRoot, $relative);
            // Only what the trash put there - never its own index.html or anything else of the folder's.
            if ($path === null || !is_file($path) || preg_match('/^\d{8}-\d{6}(?:-\d+)?__.+$/D', PathGuard::split((string) $relative)[1]) !== 1) {
                return self::notFound();
            }
            $size = (int) @filesize($path);
            if (!@unlink($path)) {
                return ['deleted' => 0, 'bytes' => 0, 'failed' => 1];
            }

            return ['deleted' => 1, 'bytes' => $size, 'failed' => 0];
        }
        foreach ($this->walk($trashRoot, '', PHP_INT_MAX)['files'] as $relative => [$size]) {
            // Only what the trash put there: its own guard and index stay.
            if (preg_match('/^\d{8}-\d{6}(?:-\d+)?__.+$/D', PathGuard::split((string) $relative)[1]) !== 1) {
                continue;
            }
            if (@unlink($trashRoot . '/' . $relative)) {
                $deleted++;
                $bytes += $size;
            } else {
                $failed++;
            }
        }
        self::removeEmptyFolders($trashRoot);

        return ['deleted' => $deleted, 'bytes' => $bytes, 'failed' => $failed];
    }

    // ─── Previews ────────────────────────────────────────────────────────────

    /** A link to the file's preview, signed for five minutes. */
    public function previewUrl(string $rootName, string $relative): string
    {
        $expires = time() + $this->previewSeconds;

        return $this->rawPath .
            '?' .
            http_build_query(
                [
                    'root' => $rootName,
                    'path' => $relative,
                    'exp' => $expires,
                    'sig' => $this->signature($rootName, $relative, $expires),
                ],
                '',
                '&',
                PHP_QUERY_RFC3986,
            );
    }

    /**
     * The file a signed preview link names - or null: a signature that is not
     * this link's, a link past its time, a path the guard refuses.
     *
     * @return ?array{path: string, name: string, extension: string, size: int, type: string, inline: bool}
     */
    public function raw(string $rootName, string $path, string $expires, string $signature): ?array
    {
        if (!ctype_digit($expires) || strlen($expires) > 12 || (int) $expires < time() || (int) $expires > time() + $this->previewSeconds + 60) {
            return null;
        }
        if (!hash_equals($this->signature($rootName, $path, (int) $expires), $signature)) {
            return null;
        }
        [$root, $real] = $this->open($rootName);
        $relative = PathGuard::relative($path);
        if ($root === null || $real === null || $relative === null || $relative === '') {
            return null;
        }
        $file = PathGuard::resolve($real, $relative);
        if ($file === null || !is_file($file)) {
            return null;
        }
        $name = PathGuard::split($relative)[1];
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $size = (int) @filesize($file);
        $family = UploadPolicy::FAMILIES[$extension] ?? null;
        $inline = in_array($extension, self::INLINE_IMAGES, true) || $family === 'audio' || $family === 'video' || ($family === 'text' && $size <= self::TEXT_PREVIEW_BYTES);
        $type = match (true) {
            !$inline => 'application/octet-stream',
            $family === 'text' => 'text/plain; charset=utf-8',
            default => UploadPolicy::TYPES[$extension] ?? 'application/octet-stream',
        };

        return ['path' => $file, 'name' => $name, 'extension' => $extension, 'size' => $size, 'type' => $type, 'inline' => $inline];
    }

    // ─── Inside ──────────────────────────────────────────────────────────────

    /** @return array{?FileRoot, ?string} the root and its real path (null when the folder is not there) */
    private function open(string $name): array
    {
        $root = $this->roots[$name] ?? null;

        return [$root, $root === null ? null : PathGuard::root($root->path)];
    }

    private function writable(FileRoot $root, ?string $real): ?Refusal
    {
        if (!$root->write) {
            return new Refusal(403, 'read_only', 'Nothing is written into this folder from here.');
        }
        if ($real === null) {
            return new Refusal(404, 'not_found', 'The folder is not there.');
        }
        if ($root->guard && !UploadPolicy::ensureGuard($real)) {
            return new Refusal(500, 'upload_guard', 'Writing is off: the folder has no .htaccess guard and none could be written.');
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function uploadOne(FileRoot $root, string $real, string $folder, UploadedFileInterface $file, bool $replace): array
    {
        $clientName = (string) $file->getClientFilename();
        $refused = static fn(Refusal $refusal): array => ['name' => $clientName, 'stored_as' => null, 'path' => null, 'status' => 'refused', 'code' => $refusal->code, 'message' => $refusal->message];

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $refused(
                in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? new Refusal(413, 'too_large', 'The file is larger than the server takes.')
                    : new Refusal(400, 'upload_failed', 'The file did not arrive whole.'),
            );
        }
        $stored = UploadPolicy::storedName($clientName, $root);
        if ($stored instanceof Refusal) {
            return $refused($stored);
        }
        $size = $file->getSize();
        if ($size !== null && $size > $root->maxBytes) {
            return $refused(new Refusal(413, 'too_large', 'The file is larger than this folder takes (' . $root->maxBytes . ' bytes).'));
        }
        $relative = ($folder === '' ? '' : $folder . '/') . $stored;
        $target = PathGuard::resolve($real, $relative, mustExist: false);
        if ($target === null) {
            return $refused(Refusal::invalid('name_invalid', 'That is no name a file can have here.'));
        }
        $existed = file_exists($target);
        if ($existed && (!$replace || !is_file($target))) {
            return $refused(new Refusal(409, 'exists', 'A file of that name is there already.'));
        }

        $incoming = $this->storage . '/cache/uploads';
        if (!GuardedDirectory::ensure($incoming)) {
            return $refused(new Refusal(500, 'write_failed', 'The upload could not be received.'));
        }
        $temporary = $incoming . '/' . bin2hex(random_bytes(12)) . '.part';
        try {
            $file->moveTo($temporary);
        } catch (Throwable) {
            @unlink($temporary);

            return $refused(new Refusal(500, 'write_failed', 'The upload could not be received.'));
        }
        try {
            clearstatcache(true, $temporary);
            $actual = (int) @filesize($temporary);
            if ($actual > $root->maxBytes) {
                return $refused(new Refusal(413, 'too_large', 'The file is larger than this folder takes (' . $root->maxBytes . ' bytes).'));
            }
            $extension = strtolower((string) pathinfo($stored, PATHINFO_EXTENSION));
            $content = UploadPolicy::contentRefusal($temporary, $extension);
            if ($content !== null) {
                return $refused($content);
            }
            if (!@rename($temporary, $target)) {
                return $refused(new Refusal(500, 'write_failed', 'The file could not be written.'));
            }
            @chmod($target, 0644);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        $this->updateSnapshot($root->name, static function (array $snapshot) use ($relative, $target, $existed): array {
            $snapshot['files'][$relative] = [(int) @filesize($target), (int) @filemtime($target)];
            if (!$existed) {
                $snapshot['new'][] = $relative;
            }

            return $snapshot;
        });
        $this->notify($root->name, 'upload', $relative, null);

        return ['name' => $clientName, 'stored_as' => $stored, 'path' => $relative, 'status' => $existed ? 'replaced' : 'uploaded', 'size' => $actual];
    }

    /**
     * Uploads a timeout or a kill left on their way in (`finally` never ran) go, once an hour old - only
     * files of the explorer's own names.
     */
    private function sweepIncoming(): void
    {
        foreach (glob($this->storage . '/cache/uploads/*.part') ?: [] as $file) {
            if (preg_match('/^[0-9a-f]{24}\.part$/D', basename($file)) === 1 && is_file($file) && (int) @filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

    /** The folders of $folder made where they are missing, each inside the root; its path, or null. */
    private function makeFolders(FileRoot $root, string $real, string $folder): ?string
    {
        if ($folder === '') {
            return $real;
        }
        $made = '';
        foreach (explode('/', $folder) as $segment) {
            $made = $made === '' ? $segment : $made . '/' . $segment;
            $path = PathGuard::resolve($real, $made, mustExist: false);
            if ($path === null) {
                return null;
            }
            if (!is_dir($path)) {
                if (file_exists($path) || UploadPolicy::runsOnServer($segment) || !@mkdir($path, 0755)) {
                    return null;
                }
                $this->updateSnapshot($root->name, static function (array $snapshot) use ($made): array {
                    $snapshot['folders'][$made] = true;

                    return $snapshot;
                });
            }
        }

        return PathGuard::resolve($real, $folder);
    }

    /**
     * Every file and folder under $relative of a real folder: no dot-names, no links, nothing that resolves
     * elsewhere; at most $limit entries.
     *
     * @return array{files: array<string, array{int, int}>, folders: array<string, true>}
     */
    private function walk(string $real, string $relative, int $limit): array
    {
        $files = [];
        $folders = [];
        $stack = [$relative];
        $seen = 0;
        while ($stack !== [] && $seen < $limit) {
            $current = array_pop($stack);
            $directory = $current === '' ? $real : $real . '/' . $current;
            foreach (@scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry[0] === '.' || ++$seen > $limit) {
                    continue;
                }
                $path = $directory . '/' . $entry;
                $child = $current === '' ? $entry : $current . '/' . $entry;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    $resolved = realpath($path);
                    if ($resolved !== false && str_replace('\\', '/', $resolved) === $path) {
                        $folders[$child] = true;
                        $stack[] = $child;
                    }
                    continue;
                }
                if (is_file($path)) {
                    $files[$child] = [(int) @filesize($path), (int) @filemtime($path)];
                }
            }
        }
        ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($folders, SORT_NATURAL | SORT_FLAG_CASE);

        return ['files' => $files, 'folders' => $folders];
    }

    /** @return array{list<array<string, mixed>>, list<array<string, mixed>>} one folder, read from the disk */
    private function fromDisk(FileRoot $root, string $real, string $relative): array
    {
        $directory = $relative === '' ? $real : $real . '/' . $relative;
        $folders = [];
        $files = [];
        foreach (@scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            $path = $directory . '/' . $entry;
            $child = $relative === '' ? $entry : $relative . '/' . $entry;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $resolved = realpath($path);
                if ($resolved !== false && str_replace('\\', '/', $resolved) === $path) {
                    $folders[] = ['name' => $entry, 'path' => $child, 'files' => max(0, count(@scandir($path) ?: []) - 2)];
                }
            } elseif (is_file($path)) {
                $files[] = $this->fileEntry($root, $child, [(int) @filesize($path), (int) @filemtime($path)]);
            }
        }

        return [$folders, $files];
    }

    /** @return array{list<array<string, mixed>>, list<array<string, mixed>>} every file under a folder whose name has $search */
    private function searchDisk(FileRoot $root, string $real, string $relative, string $search): array
    {
        $files = [];
        foreach ($this->walk($real, $relative, $this->walkLimit)['files'] as $path => $facts) {
            $path = (string) $path;
            if (stripos(PathGuard::split($path)[1], $search) !== false) {
                $files[] = $this->fileEntry($root, $path, $facts);
            }
        }

        return [[], $files];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function fromSnapshot(FileRoot $root, array $snapshot, string $relative, string $search): array
    {
        $prefix = $relative === '' ? '' : $relative . '/';
        $folders = [];
        $files = [];
        foreach ($snapshot['files'] ?? [] as $path => $facts) {
            $path = (string) $path;
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            $rest = substr($path, strlen($prefix));
            if ($search !== '') {
                if (stripos(basename($rest), $search) !== false) {
                    $files[] = $this->fileEntry($root, $path, $facts);
                }
                continue;
            }
            $slash = strpos($rest, '/');
            if ($slash === false) {
                $files[] = $this->fileEntry($root, $path, $facts);
            } else {
                $sub = substr($rest, 0, $slash);
                $folders[$sub] = ($folders[$sub] ?? 0) + 1;
            }
        }
        if ($search === '') {
            foreach (array_keys($snapshot['folders'] ?? []) as $folder) {
                $folder = (string) $folder;
                if (($prefix === '' || str_starts_with($folder, $prefix)) && $folder !== $relative) {
                    $rest = substr($folder, strlen($prefix));
                    $sub = explode('/', $rest, 2)[0];
                    $folders[$sub] ??= 0;
                }
            }
        }
        $list = [];
        foreach ($folders as $name => $count) {
            $list[] = ['name' => (string) $name, 'path' => $prefix . $name, 'files' => $count];
        }

        return [$list, $files];
    }

    /**
     * @param list<array<string, mixed>> $folders
     * @param list<array<string, mixed>> $files
     * @param ?array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function page(FileRoot $root, string $path, string $search, string $mode, array $folders, array $files, int $page, ?array $snapshot): array
    {
        usort($folders, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        usort($files, static fn(array $a, array $b): int => $mode === 'folder' ? strnatcasecmp($a['name'], $b['name']) : strnatcasecmp($a['path'], $b['path']));

        return [
            'root' => $root->name,
            'path' => $path,
            'search' => $search,
            'mode' => $mode,
            'folders' => $mode === 'folder' ? $folders : [],
            'files' => array_slice($files, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'total' => count($files),
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'status' => self::status($snapshot),
        ];
    }

    /**
     * @param array{0?: int, 1?: int} $facts [size, modified]
     * @return array<string, mixed>
     */
    private function fileEntry(FileRoot $root, string $path, array $facts): array
    {
        [$folder, $name] = PathGuard::split($path);
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $family = UploadPolicy::FAMILIES[$extension] ?? null;
        $size = (int) ($facts[0] ?? 0);

        return [
            'name' => $name,
            'path' => $path,
            'folder' => $folder,
            'extension' => $extension,
            'size' => $size,
            'modified' => isset($facts[1]) ? gmdate('Y-m-d\TH:i:s\Z', (int) $facts[1]) : null,
            'preview' => match (true) {
                in_array($extension, self::INLINE_IMAGES, true) => 'image',
                $family === 'audio' => 'audio',
                $family === 'video' => 'video',
                $family === 'text' && $size <= self::TEXT_PREVIEW_BYTES => 'text',
                default => 'download',
            },
            'url' => $this->previewUrl($root->name, $path),
        ];
    }

    /**
     * @param ?array<string, mixed> $snapshot
     * @return array{scanned_at: ?string, previous_scanned_at: ?string, total: int, new_count: int}
     */
    private static function status(?array $snapshot): array
    {
        return [
            'scanned_at' => is_string($snapshot['scanned_at'] ?? null) ? $snapshot['scanned_at'] : null,
            'previous_scanned_at' => is_string($snapshot['previous_scanned_at'] ?? null) ? $snapshot['previous_scanned_at'] : null,
            'total' => is_array($snapshot['files'] ?? null) ? count($snapshot['files']) : 0,
            'new_count' => is_array($snapshot['new'] ?? null) ? count($snapshot['new']) : 0,
        ];
    }

    /** @return ?array<string, mixed> */
    private function snapshot(string $root): ?array
    {
        $data = json_decode((string) @file_get_contents($this->snapshotFile($root)), true);

        return is_array($data) && is_array($data['files'] ?? null) ? $data : null;
    }

    /** The snapshot changed by $change, when there is one (a root never scanned has none to keep in step). */
    private function updateSnapshot(string $root, Closure $change): void
    {
        $this->withSnapshot($root, static fn(?array $snapshot): ?array => $snapshot === null ? null : $change($snapshot), false);
    }

    /**
     * Reads, changes and writes the snapshot under its lock; $change answers the new one (null: leave it).
     *
     * @param Closure(?array<string, mixed>): ?array<string, mixed> $change
     * @return ?array{scanned_at: ?string, previous_scanned_at: ?string, total: int, new_count: int}
     */
    private function withSnapshot(string $root, Closure $change, bool $create): ?array
    {
        $file = $this->snapshotFile($root);
        if (!$create && !is_file($file)) {
            return null;
        }
        if (!GuardedDirectory::ensure(dirname($file))) {
            return null;
        }
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            return null;
        }
        try {
            flock($lock, LOCK_EX);
            $snapshot = $change($this->snapshot($root));
            if ($snapshot === null) {
                return null;
            }
            AtomicFile::write($file, (string) json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return self::status($snapshot);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private static function moveInSnapshot(array $snapshot, string $from, string $to): array
    {
        $rename = static function (string $path) use ($from, $to): string {
            if ($path === $from) {
                return $to;
            }

            return str_starts_with($path, $from . '/') ? $to . substr($path, strlen($from)) : $path;
        };
        foreach (['files', 'folders'] as $key) {
            $moved = [];
            foreach ($snapshot[$key] ?? [] as $path => $value) {
                $moved[$rename((string) $path)] = $value;
            }
            $snapshot[$key] = $moved;
        }
        $snapshot['new'] = array_map(static fn(mixed $path): string => $rename((string) $path), $snapshot['new'] ?? []);

        return $snapshot;
    }

    private function snapshotFile(string $root): string
    {
        return $this->storage . '/cache/explorer-' . $root . '.json';
    }

    private function trashRoot(string $root): string
    {
        return $this->storage . '/trash/' . $root;
    }

    private function ensureTrashFolder(string $root, string $folder): bool
    {
        if (!GuardedDirectory::ensure($this->storage . '/trash') || !GuardedDirectory::ensure($this->trashRoot($root))) {
            return false;
        }
        $path = $this->trashRoot($root) . ($folder === '' ? '' : '/' . $folder);

        return is_dir($path) || @mkdir($path, 0775, true) || is_dir($path);
    }

    private function notify(string $root, string $op, string $path, ?string $from): void
    {
        if ($this->afterWrite === null) {
            return;
        }
        try {
            ($this->afterWrite)($root, $op, $path, $from);
        } catch (Throwable $e) {
            Log::say('[files] afterWrite: ' . Redactor::text($e->getMessage()));
        }
    }

    private function signature(string $root, string $path, int $expires): string
    {
        $this->key ??= Keys::derive(Keys::installation($this->storage), 'file-preview');

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $root . "\n" . $path . "\n" . $expires, $this->key, true)), '+/', '-_'), '=');
    }

    private static function stampTime(string $date, string $time): ?string
    {
        $at = \DateTimeImmutable::createFromFormat('!Ymd His', $date . ' ' . $time, new \DateTimeZone('UTC'));

        return $at === false ? null : $at->format('Y-m-d\TH:i:s\Z');
    }

    private static function removeEmptyFolders(string $directory): void
    {
        foreach (@scandir($directory) ?: [] as $entry) {
            $path = $directory . '/' . $entry;
            if ($entry === '.' || $entry === '..' || is_link($path) || !is_dir($path)) {
                continue;
            }
            self::removeEmptyFolders($path);
            @rmdir($path);
        }
    }

    private static function noRoot(): Refusal
    {
        return new Refusal(404, 'root_not_found', 'There is no such root.');
    }

    private static function notFound(): Refusal
    {
        return new Refusal(404, 'not_found', 'There is no such file or folder.');
    }
}

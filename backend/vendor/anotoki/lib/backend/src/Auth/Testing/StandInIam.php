<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth\Testing;

use Anotoki\Lib\Auth\IamClient;
use RuntimeException;
use stdClass;

/**
 * The family's stand-in IAM (backend/testing/stand-in-iam.php), running on a free
 * local port under PHP's built-in server for as long as a test needs it - for
 * what the files of TestIam cannot say: a 404, an IAM that is down, the
 * paging of the deleted accounts, the browser's sign-in with the hint cookie.
 *
 *   $iam = StandInIam::start($dir);          // http://127.0.0.1:<port>, client test-site
 *   $iam->state(['login_required' => false]);
 *   $client = $iam->client();                // an IamClient that asks it
 *   ...
 *   $iam->stop();                            // also when the object goes
 *
 * Its tokens are signed with the key in $dir (TestIam::create() makes it), so
 * $iam->iam->token(...) makes tokens it would have made. For a browser check,
 * start it on `localhost` - the host the site's pages are opened at, or the
 * hint cookie it sets is another host's.
 */
final class StandInIam
{
    /** @param resource $process */
    private function __construct(public readonly TestIam $iam, public readonly string $issuer, private mixed $process) {}

    /** Where the router script is: in a site, vendor/anotoki/lib/backend/testing/stand-in-iam.php. */
    public static function script(): string
    {
        return dirname(__DIR__, 3) . '/testing/stand-in-iam.php';
    }

    /**
     * Starts it, on a free port of $host, keeping its key, state and logs in
     * $dir. Throws when PHP's built-in server does not come up.
     */
    public static function start(string $dir, string $clientId = 'test-site', string $host = '127.0.0.1'): self
    {
        $probe = @stream_socket_server('tcp://' . ($host === 'localhost' ? '127.0.0.1' : $host) . ':0', $errno, $error);
        if ($probe === false) {
            throw new RuntimeException("StandInIam: no free port on $host ($error).");
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $issuer = "http://$host:$port";

        $iam = TestIam::create($dir, $issuer, $clientId);
        $environment = getenv() + [];
        $environment['STAND_IN_DIR'] = $iam->dir;
        $environment['STAND_IN_ISSUER'] = $issuer;
        unset($environment['FAKE_IAM_DIR'], $environment['FAKE_IAM_ISSUER'], $environment['STAND_IN_JWKS']);

        $process = proc_open(
            [PHP_BINARY, '-S', ($host === 'localhost' ? '127.0.0.1' : $host) . ':' . $port, self::script()],
            [0 => ['pipe', 'r'], 1 => ['file', $iam->dir . '/server.out', 'a'], 2 => ['file', $iam->dir . '/server.err', 'a']],
            $pipes,
            $iam->dir,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('StandInIam: PHP\'s built-in server could not be started.');
        }

        $standIn = new self($iam, $issuer, $process);
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen($host === 'localhost' ? '127.0.0.1' : $host, $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $standIn;
            }
            usleep(50_000);
        }
        $standIn->stop();

        throw new RuntimeException('StandInIam: PHP\'s built-in server did not answer on ' . $issuer . '.');
    }

    /**
     * An IamClient that asks the stand-in (no file seams), with a cache folder
     * of its own. $overrides are IamClient::fromArray()'s keys.
     *
     * @param array<string, mixed> $overrides
     */
    public function client(array $overrides = []): IamClient
    {
        return IamClient::fromArray(
            $overrides + [
                'issuer' => $this->issuer,
                'client_id' => $this->iam->clientId,
                'cache_dir' => $this->iam->dir . '/cache',
            ],
        );
    }

    /**
     * Its state, after merging $changes into it (POST /_test/state).
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function state(array $changes = []): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($changes === [] ? new stdClass() : $changes, JSON_UNESCAPED_SLASHES),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($this->issuer . '/_test/state', false, $context);
        $state = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($state)) {
            throw new RuntimeException('StandInIam: its state could not be read.');
        }

        return $state;
    }

    /**
     * The requests it was sent, "METHOD /path", oldest first (the last 200),
     * and what it did with the hint ("hint set", "hint cleared").
     *
     * @return list<string>
     */
    public function log(): array
    {
        $state = $this->state();

        return array_values(array_filter(is_array($state['log'] ?? null) ? $state['log'] : [], 'is_string'));
    }

    /** Forgets the requests logged so far. */
    public function clearLog(): void
    {
        $this->state(['log' => []]);
    }

    public function url(string $path): string
    {
        return $this->issuer . $path;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}

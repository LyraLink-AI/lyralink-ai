<?php
/**
 * Sandboxed shell execution bridge.
 *
 * The PHP process must never run a shell itself, and it must never be handed the
 * Docker socket: socket access is root-equivalent and would let the web tier
 * mount the host filesystem. Instead this calls a root-owned, app-unwritable
 * helper through a single-entry sudoers rule. The command travels on stdin, so
 * the application cannot influence the container flags, image or mounts, and the
 * process list never shows the command text.
 *
 * Containment is the container, not the allowlist. An allowlist on the first
 * token is trivially bypassed by any interpreter or by the arguments of an
 * otherwise-innocent tool (`find -exec`, `awk system()`, `sed -e`). The role of
 * the allowlist is to reduce accidental damage and keep the surface small; the
 * security boundary is: no network, read-only root, no capabilities, no new
 * privileges, non-root user, bounded memory/CPU/PIDs, and a hard timeout.
 */

if (!function_exists('lyra_sandbox_config')) {
    function lyra_sandbox_config(): array {
        $read = static function (string $key, string $default): string {
            if (function_exists('api_get_secret')) {
                $value = api_get_secret($key, '');
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
            $env = getenv($key);
            return (is_string($env) && trim($env) !== '') ? trim($env) : $default;
        };

        return [
            // Off by default. Enabling a shell is a conscious operational choice.
            'enabled' => strtolower($read('LYRALINK_SHELL_EXECUTOR', 'disabled')) === 'enabled',
            'helper' => $read('LYRALINK_SANDBOX_HELPER', '/usr/local/bin/lyra_sandbox_run'),
            'timeout_sec' => (int)$read('LYRALINK_SANDBOX_TIMEOUT', '20'),
            'max_output_bytes' => (int)$read('LYRALINK_SANDBOX_MAX_OUTPUT', '60000'),
        ];
    }
}

if (!function_exists('lyra_sandbox_available')) {
    /**
     * True only when the executor is switched on AND the helper is present and
     * runnable. Reporting availability without a working helper would let the
     * model advertise a capability that cannot run.
     */
    function lyra_sandbox_available(): bool {
        $config = lyra_sandbox_config();
        if (empty($config['enabled'])) {
            return false;
        }
        $helper = (string)$config['helper'];
        if ($helper === '' || !is_file($helper) || !is_executable($helper)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('lyra_sandbox_execute')) {
    /**
     * Run one command inside the sandbox. Returns a structured outcome;
     * every failure path names its cause rather than returning an empty result.
     */
    function lyra_sandbox_execute(string $command, array $opts = []): array {
        $config = lyra_sandbox_config();
        $out = [
            'ok' => false,
            'reason' => '',
            'exit_code' => null,
            'stdout' => '',
            'stderr' => '',
            'duration_ms' => 0,
            'truncated' => false,
            'sandbox' => true,
        ];

        if (empty($config['enabled'])) {
            $out['reason'] = 'shell_executor_disabled';
            return $out;
        }

        $command = trim($command);
        if ($command === '') {
            $out['reason'] = 'empty_command';
            return $out;
        }
        if (strlen($command) > 4000) {
            $out['reason'] = 'command_too_long';
            return $out;
        }
        if (strpos($command, "\0") !== false) {
            $out['reason'] = 'invalid_command';
            return $out;
        }

        $helper = (string)$config['helper'];
        if (!is_file($helper) || !is_executable($helper)) {
            $out['reason'] = 'sandbox_helper_unavailable';
            return $out;
        }

        $timeout = (int)($opts['timeout_sec'] ?? $config['timeout_sec']);
        $timeout = max(1, min(120, $timeout));

        $job = json_encode([
            'command' => $command,
            'timeout_sec' => $timeout,
            'max_output_bytes' => (int)$config['max_output_bytes'],
        ]);
        if ($job === false) {
            $out['reason'] = 'job_encode_failed';
            return $out;
        }

        // Array form plus bypass_shell: the command is never interpreted by a
        // local shell, and sudo is restricted to this one binary by sudoers.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $started = microtime(true);
        $proc = @proc_open(
            ['sudo', '-n', $helper],
            $descriptors,
            $pipes,
            null,
            ['PATH' => '/usr/bin:/bin:/usr/sbin:/sbin']
        );
        if (!is_resource($proc)) {
            $out['reason'] = 'spawn_failed';
            return $out;
        }

        fwrite($pipes[0], $job);
        fclose($pipes[0]);

        // Non-blocking read with a wall-clock guard, so a hung sandbox cannot
        // hold a web worker open indefinitely.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = $started + $timeout + 8;
        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $out['reason'] = 'bridge_timeout';
                break;
            }
            usleep(50000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $out['duration_ms'] = (int)round((microtime(true) - $started) * 1000);

        if ($out['reason'] === 'bridge_timeout') {
            return $out;
        }

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            // The helper always emits JSON; anything else means it failed before
            // it could run, most likely the sudo rule or the file mode.
            $out['reason'] = 'helper_no_json_output';
            $out['exit_code'] = $exit;
            $out['stderr'] = substr($stderr, 0, 2000);
            return $out;
        }

        $out['reason'] = (string)($decoded['reason'] ?? 'unknown');
        $out['exit_code'] = isset($decoded['exit_code']) ? (int)$decoded['exit_code'] : null;
        $out['stdout'] = (string)($decoded['stdout'] ?? '');
        $out['stderr'] = (string)($decoded['stderr'] ?? '');
        $out['truncated'] = !empty($decoded['truncated']);
        $out['ok'] = !empty($decoded['ok']);
        return $out;
    }
}

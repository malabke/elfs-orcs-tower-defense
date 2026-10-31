<?php

namespace JumPHP\Tauri;

use JumPHP\Kernel\Platform;

/**
 * The Tauri runtime, a C library (tauri/, built by `bin/jumphp tauri:build`) loaded with PHP's FFI.
 * PHP stays the main program: run() calls jt_run() from PHP's main thread; Tauri runs its native event loop
 * there and calls PHP back at every frame (docs/tauri.md).
 *
 * Where the library is looked for, in order (library()):
 *   1. JUMPHP_TAURI_LIB (a file);
 *   2. <app>/native/<platform>/<name> (what `bin/jumphp app:export` puts next to the app);
 *   3. <project>/build/tauri/<platform>/<name> (what `bin/jumphp tauri:build` makes), going up from the app;
 *   4. the system: /usr/local/lib, /usr/lib.
 * <platform> is linux-x86_64, linux-aarch64, windows-x86_64, macos-<arch> or macos-universal; <name> libjumphp_tauri.so /
 * jumphp_tauri.dll / libjumphp_tauri.dylib.
 */
final class TauriRuntime implements NativeRuntime
{
    public const HEADER = <<<'C'
        int jt_run(const char *config_json, void (*frame)(void));
        const char *jt_poll(void);
        void jt_send(const char *command_json);
        const char *jt_version(void);
        C;

    /** @var mixed \FFI with the functions of HEADER (mixed: their methods are not known to static analysis) */
    private mixed $ffi;

    public function __construct(string $library)
    {
        if (!extension_loaded('ffi')) {
            throw new \RuntimeException("PHP's FFI extension is needed for desktop apps (the jumphp/tauri image has it: bin/jumphp app:run)");
        }
        $this->ffi = \FFI::cdef(self::HEADER, $library);
    }

    /** A runtime when this PHP can run one (FFI + the library found), else null. */
    public static function create(string $appRoot): ?self
    {
        $library = self::library($appRoot);
        return $library !== null && extension_loaded('ffi') && !Platform::isWasm() ? new self($library) : null;
    }

    public static function platform(): string
    {
        $arch = strtolower(php_uname('m'));
        $arch = match ($arch) {
            'amd64', 'x64' => 'x86_64',
            'arm64' => 'aarch64',
            default => $arch,
        };
        $os = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            default => 'linux',
        };
        return $os . '-' . $arch;
    }

    public static function fileName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'jumphp_tauri.dll',
            'Darwin' => 'libjumphp_tauri.dylib',
            default => 'libjumphp_tauri.so',
        };
    }

    /** The library's path, or null when there is none (see the class comment for the order). */
    public static function library(string $appRoot): ?string
    {
        $env = Platform::setting('JUMPHP_TAURI_LIB');
        if ($env !== null) {
            return is_file((string)$env) ? (string)$env : null;
        }
        // macOS: one universal library (Apple silicon + Intel) serves both
        $platforms = PHP_OS_FAMILY === 'Darwin' ? [self::platform(), 'macos-universal'] : [self::platform()];
        $candidates = [];
        foreach ($platforms as $platform) {
            $rel = $platform . '/' . self::fileName();
            $candidates[] = rtrim($appRoot, '/') . '/native/' . $rel;
            $dir = realpath($appRoot) ?: $appRoot;
            for ($i = 0; $i < 6 && $dir !== dirname($dir); $i++, $dir = dirname($dir)) {
                $candidates[] = $dir . '/build/tauri/' . $rel;
            }
        }
        $candidates[] = '/usr/local/lib/' . self::fileName();
        $candidates[] = '/usr/lib/' . self::fileName();
        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    public function run(array $config, \Closure $frame): int
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // the closure becomes a C function pointer, called by Tauri on this (the main) thread
        return (int)$this->ffi->jt_run($json, static function () use ($frame): void {
            $frame();
        });
    }

    public function poll(): array
    {
        $events = json_decode(self::string($this->ffi->jt_poll()), true);
        return is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
    }

    public function send(array $command): void
    {
        $this->ffi->jt_send(json_encode($command, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    public function version(): string
    {
        return self::string($this->ffi->jt_version());
    }

    /** const char * comes back as a PHP string or as a CData pointer, depending on PHP's version. */
    private static function string(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        return $value === null ? '' : \FFI::string($value);
    }
}

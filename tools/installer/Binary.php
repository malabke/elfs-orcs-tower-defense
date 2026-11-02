<?php
// Copied from jumphp2installer (installer/src/Binary.php, MIT, https://github.com/davidnurdin/jumphp2installer): keep it the same.

namespace JumPHP2Installer;

/**
 * The installer's executable as a server hands it out: the same file for every package, with the server's address
 * and the package's id written into its php.ini section (StaticPHP's micro reads it at start: get_cfg_var()).
 *
 * An exported JumPHP app is micro + ini section + PHAR; the ini section is "\xfd\xf6\x69\xe6" + its length (32 bits,
 * big endian) + php.ini lines. patch() finds it (the installer's own lines start with ffi.enable=1) and writes
 * another one with jumphp2installer.server / jumphp2installer.package added. Nothing else changes: the PHAR's own
 * signature stays valid. The package id is also in the file name the server gives (Name-setup-<id>.exe): the
 * installer reads that when the section has none (a binary renamed by hand).
 */
final class Binary
{
    private const MAGIC = "\xfd\xf6\x69\xe6";

    /** The line the base installer reserves (game.yaml php_ini): patched at the same size, nothing moves. */
    public const RESERVE = 'jumphp2installer.reserve=';

    /**
     * $binary with the server and the package written in; throws when it has no ini section of an installer.
     * When the section has a reserve line (the released installers do), the section keeps its exact size: the
     * reserve shrinks by what is added (Windows' micro finds its PHAR by offsets that must not move). Without one,
     * the section grows (enough on Linux and macOS).
     */
    public static function patch(string $binary, string $server, string $package, ?string $stats = null): string
    {
        [$offset, $length, $ini] = self::section($binary);
        $reserved = str_contains($ini, "\n" . self::RESERVE) || str_starts_with($ini, self::RESERVE);
        $lines = array_values(array_filter(explode("\n", $ini), static fn (string $l): bool =>
            trim($l) !== '' && !str_starts_with(ltrim($l), 'jumphp2installer.')));
        $lines[] = 'jumphp2installer.server="' . addcslashes($server, '"\\') . '"';
        $lines[] = 'jumphp2installer.package="' . addcslashes($package, '"\\') . '"';
        if ($stats !== null && $stats !== '') {
            $lines[] = 'jumphp2installer.stats="' . addcslashes($stats, '"\\') . '"';     // where it tells what happens (Stats)
        }
        $newIni = implode("\n", $lines) . "\n";
        if ($reserved) {
            $room = $length - strlen($newIni) - strlen(self::RESERVE) - 1;
            if ($room < 0) {
                throw new \RuntimeException('the address and the id are too long for this installer (' . (strlen($newIni) - $length) . ' characters too many)');
            }
            $newIni .= self::RESERVE . str_repeat('.', $room) . "\n";
        }
        return substr($binary, 0, $offset) . self::MAGIC . pack('N', strlen($newIni)) . $newIni . substr($binary, $offset + 8 + $length);
    }

    /**
     * patch() on a file: an installer executable (Linux, Windows), or the .zip of the macOS app (its executable,
     * Contents/MacOS/*, is patched inside: Zip). Writes $out.
     */
    public static function patchFile(string $in, string $out, string $server, string $package, ?string $stats = null): void
    {
        $data = @file_get_contents(self::readable($in));
        if ($data === false) {
            throw new \RuntimeException("cannot read $in");
        }
        if (!str_starts_with($data, "PK\x03\x04")) {
            if (@file_put_contents($out, self::patch($data, $server, $package, $stats)) === false) {
                throw new \RuntimeException("cannot write $out");
            }
            @chmod($out, 0755);
            return;
        }
        unset($data);
        $patched = Zip::rewrite($in, $out, static function (string $name, string $bytes) use ($server, $package, $stats): ?string {
            if (!preg_match('#\\.app/Contents/MacOS/[^/]+$#', $name)) {
                return null;
            }
            try {
                return self::patch($bytes, $server, $package, $stats);
            } catch (\RuntimeException) {
                return null;    // another program of the app (not the installer)
            }
        });
        if ($patched === 0) {
            @unlink($out);
            throw new \RuntimeException("$in: no installer executable in this macOS app");
        }
    }

    /**
     * A path to read an executable by, even the one running now: StaticPHP's micro answers its own exact path with
     * its script (the PHAR) only; dir/./file is the same file to the system, not to micro.
     */
    public static function readable(string $path): string
    {
        $dir = dirname($path);
        return $dir . DIRECTORY_SEPARATOR . '.' . DIRECTORY_SEPARATOR . basename($path);
    }

    /** What the binary's ini section says (tests, the server's checks). @return array<string, string> */
    public static function settings(string $binary): array
    {
        [, , $ini] = self::section($binary);
        $out = [];
        foreach (explode("\n", $ini) as $line) {
            if (preg_match('/^\s*([\w.]+)\s*=\s*"?(.*?)"?\s*$/', $line, $m)) {
                $out[$m[1]] = stripcslashes($m[2]);
            }
        }
        return $out;
    }

    /** The folder of a package inside a standalone installer (embed()), unpacked with the installer itself. */
    public const PAYLOAD = 'payload';

    /**
     * A standalone installer: $folder (install.yaml and the files it uses: the app's executable...) put inside the
     * installer's PHAR (game/payload/), so it installs with no network and no server (Source: the package beside
     * itself). The PHAR is rewritten with PHP's Phar (php -d phar.readonly=0) and a stub of its own build (the
     * unpacked copy of a plain installer is not taken for this one). An executable (Linux, Windows) or the .zip of the
     * macOS app (its executables inside). Writes $out.
     */
    public static function embedFile(string $in, string $out, string $folder): void
    {
        if (!is_file("$folder/install.yaml")) {
            throw new \RuntimeException("$folder has no install.yaml");
        }
        if (filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOL)) {
            throw new \RuntimeException('a standalone installer is made with php -d phar.readonly=0');
        }
        $data = @file_get_contents(self::readable($in));
        if ($data === false) {
            throw new \RuntimeException("cannot read $in");
        }
        if (!str_starts_with($data, "PK\x03\x04")) {
            if (@file_put_contents($out, self::embed($data, $folder)) === false) {
                throw new \RuntimeException("cannot write $out");
            }
            @chmod($out, 0755);
            return;
        }
        unset($data);
        $done = Zip::rewrite($in, $out, static function (string $name, string $bytes) use ($folder): ?string {
            if (!preg_match('#\\.app/Contents/MacOS/[^/]+$#', $name)) {
                return null;
            }
            try {
                return self::embed($bytes, $folder);
            } catch (\RuntimeException) {
                return null;    // another program of the app (not the installer)
            }
        });
        if ($done === 0) {
            @unlink($out);
            throw new \RuntimeException("$in: no installer executable in this macOS app");
        }
    }

    /** embedFile() on the bytes of an executable: micro + ini section stay, the PHAR after them is rewritten. */
    public static function embed(string $binary, string $folder): string
    {
        [$offset, $length] = self::section($binary);
        $start = $offset + 8 + $length;
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'j2i-embed-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0700, true);
        $file = "$tmp/installer.phar";
        try {
            file_put_contents($file, substr($binary, $start));
            $files = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files[str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($folder, '/\\')) + 1))] = $f->getPathname();
                }
            }
            ksort($files);
            $hash = hash_init('sha256');
            foreach ($files as $name => $path) {
                hash_update($hash, $name . "\0");
                hash_update_file($hash, $path);
            }
            $phar = new \Phar($file);
            $phar->startBuffering();
            foreach ($files as $name => $path) {
                $phar->addFile($path, 'game/' . self::PAYLOAD . '/' . $name);
            }
            // its own build: the stub unpacks into <cache>/<id>-<build>, which a plain installer of the same base has
            $stub = (string)preg_replace_callback("/(JUMPHP_GAME_BUILD = ')([^']*)(')/", static fn (array $m): string =>
                $m[1] . substr($m[2], 0, 12) . '-' . substr(hash_final($hash), 0, 12) . $m[3], $phar->getStub(), 1, $count);
            if ($count !== 1) {
                throw new \RuntimeException('not an installer executable (its PHAR has no JumPHP stub)');
            }
            $phar->setStub($stub);
            $phar->stopBuffering();
            unset($phar);
            return substr($binary, 0, $start) . file_get_contents($file);
        } finally {
            // Phar keeps the archive (and its alias, jumphp-game.phar) known until it is unlinked: the next one would clash
            try {
                is_file($file) && \Phar::unlinkArchive($file);
            } catch (\Throwable) {
            }
            @unlink($file);
            @rmdir($tmp);
        }
    }

    /** A download's id (Binary::fileName($name, $package, $platform, $download)): 12 letters and digits. */
    public const DOWNLOAD = '[a-z0-9]{12}';

    /**
     * The file name a server gives: "My-App-setup-k2q9x7a1.exe" (an id of the package), or, for one download of it,
     * "My-App-k3x9p2qa7m4z.exe": the id of that download, which the installer sends back when it fetches its package
     * (the server then knows that download is being installed).
     */
    public static function fileName(string $appName, string $package, string $platform, ?string $download = null): string
    {
        $name = trim((string)preg_replace('/[^\w.-]+/u', '-', $appName), '-') ?: 'Setup';
        return $name . ($download !== null ? '-' . $download : '-setup-' . $package) . match (true) {
            str_starts_with($platform, 'windows') => '.exe',
            str_starts_with($platform, 'macos') => '.zip',
            default => '',
        };
    }

    /** The package id in a file name made by fileName() (the user may add " (1)" and the like), or null. */
    public static function packageFromFileName(string $file): ?string
    {
        return preg_match('/-setup-([a-z0-9]{6,32})(?:[ ._(-]|$)/i', basename($file), $m) ? strtolower($m[1]) : null;
    }

    /** The download id in a file name made by fileName() with one ("My-App-k3x9p2qa7m4z (1).exe"), or null. */
    public static function downloadFromFileName(string $file): ?string
    {
        $name = (string)preg_replace('/\.(exe|zip|app)$/i', '', basename(str_replace('\\', '/', $file)));
        $name = (string)preg_replace('/\s*[(\[]\d+[)\]]$/', '', $name);     // "(1)" "[2]": the browser's copies
        return preg_match('/(?<!-setup)-(' . self::DOWNLOAD . ')$/i', $name, $m) ? strtolower($m[1]) : null;
    }

    /** @return array{int, int, string} offset, length, text of the installer's ini section */
    private static function section(string $binary): array
    {
        $at = 0;
        while (($at = strpos($binary, self::MAGIC, $at)) !== false) {
            $length = unpack('N', substr($binary, $at + 4, 4))[1] ?? 0;
            $ini = substr($binary, $at + 8, $length);
            if ($length > 0 && $length < 65536 && str_starts_with($ini, 'ffi.enable=')) {
                return [$at, $length, $ini];
            }
            $at++;
        }
        throw new \RuntimeException('not an installer executable (no php.ini section starting with ffi.enable=)');
    }
}

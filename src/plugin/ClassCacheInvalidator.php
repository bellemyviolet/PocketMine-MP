<?php

declare(strict_types=1);

/** [BETTERPMMP-PATCH] */

namespace pocketmine\plugin;

use pocketmine\thread\ThreadSafeClassLoader;
use function array_key_exists;
use function array_keys;
use function str_replace;
use function count;
use function explode;
use function file_get_contents;
use function filemtime;
use function function_exists;
use function implode;
use function is_dir;
use function min;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function preg_replace;
use function str_ends_with;
use function str_starts_with;
use const DIRECTORY_SEPARATOR;

class ClassCacheInvalidator
{

	private static int $reloadVersion = 0;

	/**
	 * @param array<string, int> $previousMtimes
	 * @return array{mtimes: array<string, int>, changed: bool, version: int}
	 */
	public static function invalidateChanged(string $pluginPath, array $previousMtimes, ThreadSafeClassLoader $autoloader, string $rootNamespace = ''): array
	{
		$currentMtimes = self::scanMtimes($pluginPath);
		$changedFiles = self::detectChangedFiles($currentMtimes, $previousMtimes);

		if (count($changedFiles) === 0) {
			return ['mtimes' => $currentMtimes, 'changed' => false, 'version' => self::$reloadVersion];
		}

		++self::$reloadVersion;
		$version = self::$reloadVersion;

		$opcacheInvalidate = function_exists('opcache_invalidate') ? 'opcache_invalidate' : null;
		$allFiles = array_keys($currentMtimes);
		foreach ($changedFiles as $filePath) {
			if ($opcacheInvalidate !== null) {
				$opcacheInvalidate($filePath, true);
			}
		}

		if ($rootNamespace === '') {
			$rootNamespace = self::detectRootNamespace($pluginPath);
		}

		if ($rootNamespace !== '') {
			$remaining = $allFiles;
			for ($pass = 0; $pass < 10 && count($remaining) > 0; $pass++) {
				$failed = [];
				foreach ($remaining as $filePath) {
					if (!self::evalWithVersionedNamespace($filePath, $version, $rootNamespace)) {
						$failed[] = $filePath;
					}
				}
				if (count($failed) === count($remaining)) {
					foreach ($failed as $failedPath) {
						\pocketmine\Server::getInstance()->getLogger()->warning(
							"[BetterPMMP] Failed to load versioned class from {$failedPath}"
						);
					}
					break;
				}
				$remaining = $failed;
			}
		}

		return ['mtimes' => $currentMtimes, 'changed' => true, 'version' => $version];
	}

	public static function getVersionedClassName(string $originalClass, string $rootNamespace, int $version): string
	{
		if ($rootNamespace === '' || $version === 0) {
			return $originalClass;
		}
		$prefix = $rootNamespace . '\\';
		if (str_starts_with($originalClass, $prefix)) {
			return $rootNamespace . '\\v' . $version . '\\' . substr($originalClass, strlen($prefix));
		}
		if ($originalClass === $rootNamespace) {
			return $rootNamespace . '\\v' . $version;
		}
		return $originalClass;
	}

	public static function detectRootNamespace(string $pluginPath): string
	{
		$srcPath = $pluginPath . DIRECTORY_SEPARATOR . 'src';
		$scanTarget = is_dir($srcPath) ? $srcPath : $pluginPath;
		if (!is_dir($scanTarget)) {
			return '';
		}

		$namespaces = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($scanTarget, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
		);
		foreach ($iterator as $filePath) {
			if (!str_ends_with($filePath, '.php')) {
				continue;
			}
			$source = @file_get_contents($filePath);
			if ($source === false) {
				continue;
			}
			if (preg_match('/^\s*namespace\s+([^\s;{]+)/m', $source, $m)) {
				$namespaces[] = $m[1];
			}
		}

		if (count($namespaces) === 0) {
			return '';
		}
		if (count($namespaces) === 1) {
			return $namespaces[0];
		}

		$firstParts = explode('\\', $namespaces[0]);
		$commonParts = $firstParts;
		foreach ($namespaces as $ns) {
			$parts = explode('\\', $ns);
			$newCommon = [];
			for ($i = 0; $i < min(count($commonParts), count($parts)); $i++) {
				if ($commonParts[$i] === $parts[$i]) {
					$newCommon[] = $commonParts[$i];
				} else {
					break;
				}
			}
			$commonParts = $newCommon;
			if (count($commonParts) === 0) {
				break;
			}
		}

		return implode('\\', $commonParts);
	}

	/**
	 * @return array<string, int>
	 */
	private static function scanMtimes(string $pluginPath): array
	{
		$mtimes = [];
		$srcPath = $pluginPath . DIRECTORY_SEPARATOR . 'src';
		$scanTarget = is_dir($srcPath) ? $srcPath : $pluginPath;

		if (!is_dir($scanTarget)) {
			return $mtimes;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($scanTarget, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
		);

		foreach ($iterator as $filePath) {
			if (!str_ends_with($filePath, '.php')) {
				continue;
			}
			$mtime = filemtime($filePath);
			if ($mtime !== false) {
				$mtimes[$filePath] = $mtime;
			}
		}

		return $mtimes;
	}

	/**
	 * @param array<string, int> $currentMtimes
	 * @param array<string, int> $previousMtimes
	 * @return list<string>
	 */
	private static function detectChangedFiles(array $currentMtimes, array $previousMtimes): array
	{
		$changed = [];
		foreach ($currentMtimes as $filePath => $mtime) {
			if (!array_key_exists($filePath, $previousMtimes)) {
				$changed[] = $filePath;
				continue;
			}
			if ($previousMtimes[$filePath] !== $mtime) {
				$changed[] = $filePath;
			}
		}
		return $changed;
	}

	private static function evalWithVersionedNamespace(string $filePath, int $version, string $rootNamespace): bool
	{
		$source = file_get_contents($filePath);
		if ($source === false) {
			return true;
		}

		$source = preg_replace('/^<\?php\s*/i', '', $source);
		if ($source === null) {
			return true;
		}

		$escaped = preg_quote($rootNamespace, '/');

		$source = str_replace($rootNamespace . '\\', $rootNamespace . '\\v' . $version . '\\', $source);

		$source = preg_replace_callback(
			'/^(\s*namespace\s+)(' . $escaped . ')(\s*[;{])/m',
			static function (array $matches) use ($version): string {
				return $matches[1] . $matches[2] . '\\v' . $version . $matches[3];
			},
			$source
		);
		if ($source === null) {
			return true;
		}

		try {
			eval($source);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	public static function getCurrentVersion(): int
	{
		return self::$reloadVersion;
	}
}
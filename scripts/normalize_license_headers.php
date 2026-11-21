<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('This maintenance script is available only from the command line.');
}

$root = dirname(__DIR__);
$phpHeader = <<<'HEADER'
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */
HEADER;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
	if (!$file->isFile()) {
		continue;
	}
	$path = $file->getPathname();
	$relative = substr($path, strlen($root) + 1);
	if (strpos($relative, 'backport/') === 0) {
		continue;
	}

	$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	if ($extension !== 'php' && $extension !== 'sql') {
		continue;
	}

	$source = file_get_contents($path);
	if ($source === false) {
		fwrite(STDERR, "Unable to read {$relative}\n");
		exit(1);
	}
	$normalized = $source;

	if ($extension === 'php') {
		if (substr($source, 0, 5) !== '<?php') {
			continue;
		}
		$afterOpen = ltrim(substr($source, 5), "\r\n");
		if (substr($afterOpen, 0, 2) === '/*') {
			$end = strpos($afterOpen, '*/');
			$firstComment = $end === false ? '' : substr($afterOpen, 0, $end + 2);
			if ($firstComment !== '' && (stripos($firstComment, 'copyright') !== false || stripos($firstComment, 'license') !== false)) {
				$afterOpen = ltrim(substr($afterOpen, $end + 2), "\r\n");
			}
		}
		$normalized = "<?php\n" . $phpHeader . "\n\n" . $afterOpen;
	} else {
		$normalized = preg_replace(
			'/-- This program is dual-' . 'licensed under the GNU General Public License \(GPL\) v3\.0 and a proprietary ' . 'license\./',
			'-- This program is licensed under GPL-3.0-or-later.',
			$normalized
		);
		$normalized = preg_replace(
			'/-- Copyright \(C\)[^\r\n]*<(?:mail@kreativitat\.com)>/',
			'-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>',
			$normalized
		);
		$normalized = preg_replace(
			'/-- Proprietary ' . 'License:\R(?:--.*\R)*?-- For more information, visit <https:\/\/www\.kreativitat\.com>\.\R?/',
			'',
			$normalized
		);
	}

	if ($normalized !== $source && file_put_contents($path, $normalized) === false) {
		fwrite(STDERR, "Unable to write {$relative}\n");
		exit(1);
	}
}

echo "License headers normalized.\n";

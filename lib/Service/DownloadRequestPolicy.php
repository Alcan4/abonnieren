<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCP\IRequest;

/** Distinguishes explicit external downloads from internal file-body reads. */
class DownloadRequestPolicy {
	public function __construct(private IRequest $request) {
	}

	public function isFileDownload(): bool {
		if (strtoupper($this->request->getMethod()) !== 'GET') {
			return false;
		}

		$path = parse_url($this->request->getRequestUri(), PHP_URL_PATH);
		if (!is_string($path) || $path === '') {
			return false;
		}
		$path = strtolower(rawurldecode($path));

		// Files UI downloads, desktop clients, WebDAV clients and public DAV.
		if (preg_match('~/(?:remote|public)\.php/(?:dav/(?:files|public-files)(?:/|$)|webdav(?:/|$))~', $path) === 1) {
			return true;
		}

		return preg_match('~/(?:index\.php/)?s/[^/]+/download(?:/|$)~', $path) === 1
			|| preg_match('~/(?:index\.php/)?apps/files/ajax/download\.php$~', $path) === 1;
	}
}

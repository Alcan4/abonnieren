<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\DownloadRequestPolicy;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DownloadRequestPolicyTest extends TestCase {
	#[DataProvider('nonDownloadRequests')]
	public function testInternalAndEditorReadsAreRejected(string $method, string $uri): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getRequestUri')->willReturn($uri);

		self::assertFalse((new DownloadRequestPolicy($request))->isFileDownload());
	}

	public static function nonDownloadRequests(): array {
		return [
			'CLI read without request' => ['', ''],
			'cron read' => ['GET', '/cron.php'],
			'background job read' => ['GET', '/index.php/apps/workflowengine/internal'],
			'Collabora WOPI contents' => ['GET', '/index.php/apps/richdocuments/wopi/files/42/contents?access_token=secret'],
			'Office WOPI contents' => ['GET', '/apps/office/wopi/files/42/contents'],
			'periodic WOPI poll' => ['GET', '/index.php/apps/richdocuments/wopi/files/42?access_token=secret'],
			'large preview' => ['GET', '/index.php/core/preview?fileId=42&x=2048&y=2048'],
			'DAV metadata probe' => ['HEAD', '/remote.php/dav/files/actor/report.pdf'],
			'URI in query string only' => ['GET', '/index.php/apps/example/read?next=/remote.php/dav/files/actor/report.pdf'],
		];
	}

	#[DataProvider('downloadRequests')]
	public function testExplicitExternalDownloadsAreAccepted(string $uri): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('GET');
		$request->method('getRequestUri')->willReturn($uri);

		self::assertTrue((new DownloadRequestPolicy($request))->isFileDownload());
	}

	public static function downloadRequests(): array {
		return [
			'authenticated Files UI or WebDAV' => ['/remote.php/dav/files/actor/report.pdf'],
			'subdirectory installation' => ['/nextcloud/remote.php/dav/files/actor/report.pdf'],
			'legacy authenticated WebDAV' => ['/remote.php/webdav/report.pdf'],
			'public DAV' => ['/public.php/dav/files/share-token/report.pdf'],
			'new public DAV' => ['/remote.php/dav/public-files/share-token/report.pdf'],
			'public-share browser download' => ['/index.php/s/share-token/download?path=%2F&files=report.pdf'],
			'legacy Files download' => ['/index.php/apps/files/ajax/download.php?dir=%2F&files=report.pdf'],
		];
	}
}

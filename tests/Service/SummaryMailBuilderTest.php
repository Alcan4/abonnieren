<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Tests\Service;

use OCA\Abonnieren\Service\RecipientL10N;
use OCA\Abonnieren\Service\RecipientVisiblePath;
use OCA\Abonnieren\Service\SummaryMailBuilder;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;

class SummaryMailBuilderTest extends TestCase {
	public function testUploadSummaryUsesRecipientTranslationTemplateAndLink(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $parameters = []): string {
			$translations = [
				'%1$d files uploaded to a subscribed folder' => '%1$d Dateien hochgeladen',
				'The following files were uploaded:' => 'Folgende Dateien wurden hochgeladen:',
				'Open in Nextcloud' => 'In Nextcloud öffnen',
			];
			$translated = $translations[$text] ?? $text;
			return $parameters === [] ? $translated : vsprintf($translated, $parameters);
		});
		$recipientL10N = $this->createMock(RecipientL10N::class);
		$recipientL10N->expects($this->once())->method('forUser')->with('subscriber')->willReturn($l10n);

		$template = $this->createMock(IEMailTemplate::class);
		$template->expects($this->once())->method('setSubject')->with('2 Dateien hochgeladen');
		$template->expects($this->once())->method('addHeading')->with('2 Dateien hochgeladen');
		$template->expects($this->exactly(2))->method('addBodyListItem');
		$template->expects($this->once())->method('addBodyButton')
			->with('In Nextcloud öffnen', 'https://cloud.example/open/42');
		$template->method('renderText')->willReturn('translated plain text');
		$template->method('renderHtml')->willReturn('<p>translated HTML</p>');
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->once())->method('createEMailTemplate')
			->with('abonnieren_summary')->willReturn($template);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$paths = $this->createMock(RecipientVisiblePath::class);
		$paths->expects($this->once())->method('pathFor')
			->with('subscriber', $file)->willReturn('/Shared/report.pdf');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('linkToRouteAbsolute')
			->with('files.viewcontroller.showFile', ['dir' => '/Shared', 'fileid' => '42'])
			->willReturn('https://cloud.example/open/42');

		$result = (new SummaryMailBuilder($recipientL10N, $mailer, $paths, $urls))->build('subscriber', [
			['item_name' => 'report.pdf', '_node' => $file],
			['item_name' => 'notes.txt', '_node' => $file],
		], false);
		self::assertSame('2 Dateien hochgeladen', $result['subject']);
		self::assertSame('translated plain text', $result['text']);
		self::assertSame('<p>translated HTML</p>', $result['html']);
	}
}

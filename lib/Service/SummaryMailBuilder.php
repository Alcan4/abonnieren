<?php

declare(strict_types=1);

namespace OCA\Abonnieren\Service;

use OCP\Files\File;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;

/** Builds localized grouped mail with the standard Nextcloud template. */
class SummaryMailBuilder {
	public function __construct(
		private RecipientL10N $recipientL10N,
		private IMailer $mailer,
		private RecipientVisiblePath $recipientVisiblePath,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @param list<array<string,mixed>> $rows Authorized rows with a transient _node entry.
	 * @return array{subject:string,text:string,html:string}
	 */
	public function build(string $userId, array $rows, bool $digest): array {
		if (count($rows) < 2) {
			throw new \InvalidArgumentException('At least two events required for a summary');
		}
		$l10n = $this->recipientL10N->forUser($userId);
		$count = count($rows);
		$subject = $digest
			? $l10n->t('%1$d subscription events', [$count])
			: $l10n->t('%1$d files uploaded to a subscribed folder', [$count]);
		$template = $this->mailer->createEMailTemplate('abonnieren_summary');
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($subject);
		$template->addBodyText($digest
			? $l10n->t('The following subscription events were collected:')
			: $l10n->t('The following files were uploaded:'));
		foreach ($rows as $row) {
			$name = $this->safeText((string)($row['item_name'] ?? ''));
			if ($name === '') {
				$name = $l10n->t('(unnamed file)');
			}
			if ($digest) {
				$action = $this->safeText((string)($row['subject'] ?? ''));
				$template->addBodyListItem(($action !== '' ? $action : $l10n->t('File activity')) . ': ' . $name);
			} else {
				$template->addBodyListItem($name);
			}
		}
		$link = $this->firstVisibleLink($userId, $rows);
		if ($link !== null) {
			$template->addBodyButton($l10n->t('Open in Nextcloud'), $link);
		}
		$template->addFooter();
		return [
			'subject' => $subject,
			'text' => $template->renderText(),
			'html' => $template->renderHtml(),
		];
	}

	/** @param list<array<string,mixed>> $rows */
	private function firstVisibleLink(string $userId, array $rows): ?string {
		foreach ($rows as $row) {
			$node = $row['_node'] ?? null;
			if (!$node instanceof Node) {
				continue;
			}
			$path = $this->recipientVisiblePath->pathFor($userId, $node);
			if ($path === null) {
				continue;
			}
			return $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
				'dir' => $node instanceof File ? dirname($path) : $path,
				'fileid' => (string)$node->getId(),
			]);
		}
		return null;
	}

	private function safeText(string $value): string {
		return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
	}
}

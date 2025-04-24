<?php
/*
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

namespace MediaWiki\Extension\CommentStreams;

use ManualLogEntry;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Config\Config;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\WikiPageFactory;
use MWException;
use Wikimedia\ParamValidator\ParamValidator;

class ApiCSPostReply extends ApiBase {

	/**
	 * @var ICommentStreamsStore
	 */
	private $commentStreamsStore;

	/**
	 * @var NotifierInterface
	 */
	private $notifier;

	/**
	 * @var bool
	 */
	private $suppressLogsFromRCs;

	/**
	 * @var WikiPageFactory
	 */
	private $wikiPageFactory;

	/**
	 * @param ApiMain $main main module
	 * @param string $action name of this module
	 * @param ICommentStreamsStore $commentStreamsStore
	 * @param NotifierInterface $notifier
	 * @param Config $config
	 * @param WikiPageFactory $wikiPageFactory
	 */
	public function __construct(
		ApiMain $main,
		string $action,
		ICommentStreamsStore $commentStreamsStore,
		NotifierInterface $notifier,
		Config $config,
		WikiPageFactory $wikiPageFactory
	) {
		parent::__construct( $main, $action );
		$this->commentStreamsStore = $commentStreamsStore;
		$this->notifier = $notifier;
		$this->suppressLogsFromRCs = (bool)$config->get( "CommentStreamsSuppressLogsFromRCs" );
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/**
	 * execute the API request
	 * @throws ApiUsageException
	 * @throws MWException
	 */
	public function execute() {
		if ( !$this->getPermissionManager()->userHasRight( $this->getUser(), 'cs-comment' ) ) {
			$this->dieWithError( 'commentstreams-api-error-post-permissions' );
		}

		$parentId = $this->getMain()->getVal( 'parentid' );
		$wikitext = $this->getMain()->getVal( 'wikitext' );

		$parentId = (int)$parentId;
		$parentComment = $this->commentStreamsStore->getComment( $parentId );
		if ( !$parentComment ) {
			$this->dieWithError( 'commentstreams-api-error-post-parentpagedoesnotexist' );
		} else {
			$associatedPage = $parentComment->getAssociatedPage();
			if ( !$associatedPage ) {
				$this->dieWithError( 'commentstreams-api-error-post-parentpagedoesnotexist' );
			} else {
				$reply = $this->commentStreamsStore->insertReply(
					$this->getUser(),
					$wikitext,
					$parentComment
				);

				if ( !$reply ) {
					$this->dieWithError( 'commentstreams-api-error-post' );
				} else {
					if ( $reply->getAssociatedPage() ) {
						$this->logAction( 'reply-create', $reply->getAssociatedPage() );
					}

					$this->getResult()->addValue( null, $this->getModuleName(), $reply->getId() );

					$this->notifier->sendReplyNotifications(
						$reply,
						$this->wikiPageFactory->newFromTitle( $associatedPage ),
						$this->getUser(),
						$parentComment

					);
				}
			}
		}
	}

	/**
	 * @return array allowed parameters
	 */
	public function getAllowedParams(): array {
		return [
			'wikitext' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
			],
			'parentid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true
			]
		];
	}

	/**
	 * @return string indicates that this API module requires a CSRF token
	 */
	public function needstoken(): string {
		return 'csrf';
	}

	/**
	 * log action
	 * @param string $action the name of the action to be logged
	 * @param LinkTarget|null $target the title of the page for the comment that the
	 *          action was performed upon, if different from the current comment
	 */
	protected function logAction( string $action, ?LinkTarget $target ) {
		if ( !$target ) {
			return;
		}
		$logEntry = new ManualLogEntry( 'commentstreams', $action );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $target );
		$logId = $logEntry->insert();

		if ( !$this->suppressLogsFromRCs ) {
			$logEntry->publish( $logId );
		}
	}
}

<?php
/*
 * Copyright (c) 2016 The MITRE Corporation
 *
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

class CommentStreams {

	// CommentStreams singleton instance
	private static $instance = null;

	/**
	 * create a CommentStreams singleton instance
	 *
	 * @return CommentStreams a singleton CommentStreams instance
	 */
	public static function singleton() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new CommentStreams();
		}
		return self::$instance;
	}

	private $noCommentStreams = false;

	/**
	 * disables the display of comments on the current page
	 * by default, a warning message is displayed instead
	 */
	public function disableCommentsOnPage() {
		$this->noCommentStreams = true;
	}

	/**
	 * initializes the display of comments
	 *
	 * @param OutputPage $output OutputPage object
	 */
	public function init( $output ) {
		if ( $this->checkDisplayComments( $output ) ) {
			$comments = $this->getComments( $output );
			$this->initJS( $output, $comments );
		}
	}

	/**
	 * checks to see if comments should be displayed on this page
	 *
	 * @param OutputPage $output the OutputPage object
	 * @return boolean true if comments should be displayed on this page
	 */
	private function checkDisplayComments( $output ) {
		// don't display comments on this page if they are explicitly disabled
		if ( $this->noCommentStreams ) {
			return false;
		}

		// don't display comments on any page action other than view action
		if ( Action::getActionName( $output->getContext() ) !== "view" ) {
			return false;
		}

		// if $wgCommentStreamsAllowedNamespaces is not set, display comments
		// in all content namespaces
		$csAllowedNamespaces = $GLOBALS['wgCommentStreamsAllowedNamespaces'];
		if ( is_null( $csAllowedNamespaces ) ) {
			$csAllowedNamespaces = $GLOBALS['wgContentNamespaces'];
		} elseif ( !is_array( $csAllowedNamespaces ) ) {
			$csAllowedNamespaces = [ $csAllowedNamespaces ];
		}

		// don't display comments in a talk namespace unless:
		// 1) $wgCommentStreamsEnableTalk is true, OR
		// 2) the namespace is a talk namespace for a namespace in the array of
		// allowed namespaces
		$title = $output->getTitle();
		$namespace = $title->getNamespace();
		if ( $title->isTalkPage() ) {
			$subject_namespace = MWNamespace::getSubject( $namespace );
			if ( !$GLOBALS['wgCommentStreamsEnableTalk'] &&
				!in_array( $subject_namespace, $csAllowedNamespaces ) ) {
				return false;
			}
		} elseif ( !in_array( $namespace, $csAllowedNamespaces ) ) {
			return false;
		}

		// don't display comments in CommentStreams namespace
		if ( $namespace === NS_COMMENTSTREAMS ) {
			return false;
		}

		// don't display comments on deleted pages or pages that do not exist yet
		if ( $title->isDeletedQuick() || !$title->exists() ) {
			return false;
		}

		return true;
	}

	/**
	 * retrieve all comments for the current page
	 *
	 * @param OutputPage $output the OutputPage object for the current page
	 * @return Comment[] array of comments
	 */
	private function getComments( $output ) {
		$commentData = [];
		$pageId = $output->getTitle()->getArticleID();
		$allComments = Comment::getAssociatedComments( $pageId );
		$parentComments = $this->getDiscussions( $allComments,
			$GLOBALS['wgCommentStreamsNewestStreamsOnTop'] );
		foreach ( $parentComments as $parentComment ) {
			$parentJSON = $parentComment->getJSON();
			$childComments = $this->getReplies( $allComments,
				$parentComment->getId() );
			foreach ( $childComments as $childComment ) {
				$childJSON = $childComment->getJSON();
				$parentJSON['children'][] = $childJSON;
			}
			$commentData[] = $parentJSON;
		}
		return $commentData;
	}

	/**
	 * initialize JavaScript
	 *
	 * @param OutputPage $output the OutputPage object
	 * @param Comment[] $comments array of comments on the current page
	 */
	private function initJS( $output, $comments ) {
		// determine if comments should be initially collapsed or expanded
		// if the namespace is a talk namespace, use state of its subject namespace
		$title = $output->getTitle();
		$namespace = $title->getNamespace();
		if ( $title->isTalkPage() ) {
			$namespace = MWNamespace::getSubject( $namespace );
		}
		$initiallyCollapsed = in_array( $namespace,
			$GLOBALS['wgCommentStreamsInitiallyCollapsedNamespaces'] );

		$commentStreamsParams = [
			'userDisplayName' =>
				Comment::getDisplayNameFromUser( $output->getUser() ),
			'userAvatar' =>
				Comment::getAvatarFromUser( $output->getUser() ),
			'newestStreamsOnTop' =>
				$GLOBALS['wgCommentStreamsNewestStreamsOnTop'] ? 1 : 0,
			'initiallyCollapsed' => $initiallyCollapsed,
			'comments' => $comments
		];
		$output->addJsConfigVars( 'CommentStreams', $commentStreamsParams );
		$output->addModules( 'ext.CommentStreams' );
	}

	/**
	 * return all discussions (top level comments) in an array of comments
	 *
	 * @param array $allComments an array of all comments on a page
	 * @param boolean $newestOnTop true if array should be sorted from newest to
	 * @return array an array of all discussions
	 * oldest
	 */
	private function getDiscussions( $allComments, $newestOnTop = false ) {
		$array = array_filter(
			$allComments, function ( $comment ) {
				return is_null( $comment->getParentId() );
			}
		);
		if ( $newestOnTop ) {
			usort(
				$array, function ( $comment1, $comment2 ) {
					$date1 = $comment1->getCreationTimestamp()->timestamp;
					$date2 = $comment2->getCreationTimestamp()->timestamp;
					return $date1 > $date2 ? -1 : 1;
				}
			);
		} else {
			usort(
				$array, function ( $comment1, $comment2 ) {
					$date1 = $comment1->getCreationTimestamp()->timestamp;
					$date2 = $comment2->getCreationTimestamp()->timestamp;
					return $date1 < $date2 ? -1 : 1;
				}
			);
		}
		return $array;
	}

	/**
	 * return all replies for a given discussion in an array of comments
	 *
	 * @param array $allComments an array of all comments on a page
	 * @param int $parentId the page ID of the discussion to get replies for
	 * @return array an array of replies for the given discussion
	 */
	private function getReplies( $allComments, $parentId ) {
		$array = array_filter(
			$allComments, function ( $comment ) use ( $parentId ) {
				if ( $comment->getParentId() === $parentId ) {
					return true;
				}
				return false;
			}
		);
		usort(
			$array, function ( $comment1, $comment2 ) {
				$date1 = $comment1->getCreationTimestamp()->timestamp;
				$date2 = $comment2->getCreationTimestamp()->timestamp;
				return $date1 < $date2 ? -1 : 1;
			}
		);
		return $array;
	}
}

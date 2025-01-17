<?php

/**
 * @file classes/search/ArticleSearchIndex.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleSearchIndex
 * @ingroup search
 *
 * @brief Class to maintain the article search index.
 */

namespace APP\search;

use APP\facades\Repo;
use APP\i18n\AppLocale;
use PKP\config\Config;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\plugins\HookRegistry;
use PKP\search\SearchFileParser;

use PKP\search\SubmissionSearch;
use PKP\search\SubmissionSearchIndex;
use PKP\submissionFile\SubmissionFile;

class ArticleSearchIndex extends SubmissionSearchIndex
{
    /**
     * @copydoc SubmissionSearchIndex::submissionMetadataChanged()
     */
    public function submissionMetadataChanged($submission)
    {
        // Check whether a search plug-in jumps in.
        $hookResult = HookRegistry::call(
            'ArticleSearchIndex::articleMetadataChanged',
            [$submission]
        );

        if (!empty($hookResult)) {
            return;
        }

        $publication = $submission->getCurrentPublication();

        // Build author keywords
        $authorText = [];
        foreach ($publication->getData('authors') as $author) {
            $authorText = array_merge(
                $authorText,
                array_values((array) $author->getData('givenName')),
                array_values((array) $author->getData('familyName')),
                array_values((array) $author->getData('preferredPublicName')),
                array_values(array_map('strip_tags', (array) $author->getData('affiliation'))),
                array_values(array_map('strip_tags', (array) $author->getData('biography')))
            );
        }

        // Update search index
        $submissionId = $submission->getId();
        $this->_updateTextIndex($submissionId, SubmissionSearch::SUBMISSION_SEARCH_AUTHOR, $authorText);
        $this->_updateTextIndex($submissionId, SubmissionSearch::SUBMISSION_SEARCH_TITLE, $publication->getFullTitles());
        $this->_updateTextIndex($submissionId, SubmissionSearch::SUBMISSION_SEARCH_ABSTRACT, $publication->getData('abstract'));

        $this->_updateTextIndex($submissionId, SubmissionSearch::SUBMISSION_SEARCH_SUBJECT, (array) $this->_flattenLocalizedArray($publication->getData('subjects')));
        $this->_updateTextIndex($submissionId, SubmissionSearch::SUBMISSION_SEARCH_KEYWORD, (array) $this->_flattenLocalizedArray($publication->getData('keywords')));
        $this->_updateTextIndex($submissionId, SubmissionSearch::SUBMISSION_SEARCH_DISCIPLINE, (array) $this->_flattenLocalizedArray($publication->getData('disciplines')));
        $this->_updateTextIndex($submissionId, SubmissionSearch::SUBMISSION_SEARCH_TYPE, (array) $publication->getData('type'));
        $this->_updateTextIndex($submissionId, SubmissionSearch::SUBMISSION_SEARCH_COVERAGE, (array) $publication->getData('coverage'));
        // FIXME Index sponsors too?
    }

    /**
     * @copydoc SubmissionSearchIndex::submissionMetadataChanged()
     */
    public function articleMetadataChanged($article)
    {
        if (Config::getVar('debug', 'deprecation_warnings')) {
            trigger_error('Deprecated call to articleMetadataChanged. Use submissionMetadataChanged instead.');
        }
        $this->submissionMetadataChanged($article);
    }

    /**
     * Delete keywords from the search index.
     *
     * @param $articleId int
     * @param $type int optional
     * @param $assocId int optional
     */
    public function deleteTextIndex($articleId, $type = null, $assocId = null)
    {
        $searchDao = DAORegistry::getDAO('ArticleSearchDAO'); /* @var $searchDao ArticleSearchDAO */
        return $searchDao->deleteSubmissionKeywords($articleId, $type, $assocId);
    }

    /**
     * Signal to the indexing back-end that an article file changed.
     *
     * @see ArticleSearchIndex::submissionMetadataChanged() above for more
     * comments.
     *
     * @param $articleId int
     * @param $type int
     * @param $submissionFile SubmissionFile
     */
    public function submissionFileChanged($articleId, $type, $submissionFile)
    {
        // Check whether a search plug-in jumps in.
        $hookResult = HookRegistry::call(
            'ArticleSearchIndex::submissionFileChanged',
            [$articleId, $type, $submissionFile->getId()]
        );

        // If no search plug-in is activated then fall back to the
        // default database search implementation.
        if ($hookResult === false || is_null($hookResult)) {
            $parser = SearchFileParser::fromFile($submissionFile);
            if (isset($parser) && $parser->open()) {
                $searchDao = DAORegistry::getDAO('ArticleSearchDAO'); /* @var $searchDao ArticleSearchDAO */
                $objectId = $searchDao->insertObject($articleId, $type, $submissionFile->getId());

                while (($text = $parser->read()) !== false) {
                    $this->_indexObjectKeywords($objectId, $text);
                }
                $parser->close();
            }
        }
    }

    /**
     * Remove indexed file contents for a submission
     *
     * @param $submission Submission
     */
    public function clearSubmissionFiles($submission)
    {
        $searchDao = DAORegistry::getDAO('ArticleSearchDAO'); /* @var $searchDao ArticleSearchDAO */
        $searchDao->deleteSubmissionKeywords($submission->getId(), SubmissionSearch::SUBMISSION_SEARCH_GALLEY_FILE);
    }

    /**
     * Signal to the indexing back-end that all files (supplementary
     * and galley) assigned to an article changed and must be re-indexed.
     *
     * @see ArticleSearchIndex::submissionMetadataChanged() above for more
     * comments.
     *
     * @param $article Article
     */
    public function submissionFilesChanged($article)
    {
        // Check whether a search plug-in jumps in.
        $hookResult = HookRegistry::call(
            'ArticleSearchIndex::submissionFilesChanged',
            [$article]
        );

        // If no search plug-in is activated then fall back to the
        // default database search implementation.
        if ($hookResult === false || is_null($hookResult)) {
            $collector = Repo::submissionFile()
                ->getCollector()
                ->filterBySubmissionIds([$article->getId()])
                ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF]);
            $submissionFiles = Repo::submissionFile()
                ->getMany($collector);
            foreach ($submissionFiles as $submissionFile) {
                $this->submissionFileChanged($article->getId(), SubmissionSearch::SUBMISSION_SEARCH_GALLEY_FILE, $submissionFile);
                $dependentFiles = Repo::submissionFile()
                    ->getMany(
                        Repo::submissionFile()
                            ->getCollector()
                            ->filterByAssoc(
                                PKPApplication::ASSOC_TYPE_SUBMISSION_FILE,
                                [$submissionFile->getId()]
                            )
                            ->filterBySubmissionIds([$article->getId()])
                            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_DEPENDENT])
                            ->includeDependentFiles()
                    );
                foreach ($dependentFiles as $dependentFile) {
                    $this->submissionFileChanged(
                        $article->getId(),
                        SubmissionSearch::SUBMISSION_SEARCH_SUPPLEMENTARY_FILE,
                        $dependentFile
                    );
                }
            }
        }
    }

    /**
     * Signal to the indexing back-end that a file was deleted.
     *
     * @see ArticleSearchIndex::submissionMetadataChanged() above for more
     * comments.
     *
     * @param $articleId int
     * @param $type int optional
     * @param $assocId int optional
     */
    public function submissionFileDeleted($articleId, $type = null, $assocId = null)
    {
        // Check whether a search plug-in jumps in.
        $hookResult = HookRegistry::call(
            'ArticleSearchIndex::submissionFileDeleted',
            [$articleId, $type, $assocId]
        );

        // If no search plug-in is activated then fall back to the
        // default database search implementation.
        if ($hookResult === false || is_null($hookResult)) {
            $searchDao = DAORegistry::getDAO('ArticleSearchDAO'); /* @var $searchDao ArticleSearchDAO */
            return $searchDao->deleteSubmissionKeywords($articleId, $type, $assocId);
        }
    }

    /**
     * Signal to the indexing back-end that the metadata of
     * a supplementary file changed.
     *
     * @see ArticleSearchIndex::submissionMetadataChanged() above for more
     * comments.
     *
     * @param $articleId integer
     */
    public function articleDeleted($articleId)
    {
        // Trigger a hook to let the indexing back-end know that
        // an article was deleted.
        HookRegistry::call(
            'ArticleSearchIndex::articleDeleted',
            [$articleId]
        );

        // The default indexing back-end does nothing when an
        // article is deleted (FIXME?).
    }

    /**
     * @copydoc SubmissionSearchIndex::submissionChangesFinished()
     */
    public function submissionChangesFinished()
    {
        // Trigger a hook to let the indexing back-end know that
        // the index may be updated.
        HookRegistry::call(
            'ArticleSearchIndex::articleChangesFinished'
        );

        // The default indexing back-end works completely synchronously
        // and will therefore not do anything here.
    }

    /**
     * @copydoc SubmissionSearchIndex::submissionChangesFinished()
     */
    public function articleChangesFinished()
    {
        if (Config::getVar('debug', 'deprecation_warnings')) {
            trigger_error('Deprecated call to articleChangesFinished. Use submissionChangesFinished instead.');
        }
        $this->submissionChangesFinished();
    }

    /**
     * Rebuild the search index for one or all journals.
     *
     * @param $log boolean Whether to display status information
     *  to stdout.
     * @param $journal Journal If given the user wishes to
     *  re-index only one journal. Not all search implementations
     *  may be able to do so. Most notably: The default SQL
     *  implementation does not support journal-specific re-indexing
     *  as index data is not partitioned by journal.
     * @param $switches array Optional index administration switches.
     */
    public function rebuildIndex($log = false, $journal = null, $switches = [])
    {
        // Check whether a search plug-in jumps in.
        $hookResult = HookRegistry::call(
            'ArticleSearchIndex::rebuildIndex',
            [$log, $journal, $switches]
        );

        // If no search plug-in is activated then fall back to the
        // default database search implementation.
        if ($hookResult === false || is_null($hookResult)) {
            AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON);

            // Check that no journal was given as we do
            // not support journal-specific re-indexing.
            if (is_a($journal, 'Journal')) {
                die(__('search.cli.rebuildIndex.indexingByJournalNotSupported') . "\n");
            }

            // Clear index
            if ($log) {
                echo __('search.cli.rebuildIndex.clearingIndex') . ' ... ';
            }
            $searchDao = DAORegistry::getDAO('ArticleSearchDAO'); /* @var $searchDao ArticleSearchDAO */
            $searchDao->clearIndex();
            if ($log) {
                echo __('search.cli.rebuildIndex.done') . "\n";
            }

            // Build index
            $journalDao = DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */

            $journals = $journalDao->getAll();
            while ($journal = $journals->next()) {
                $numIndexed = 0;

                if ($log) {
                    echo __('search.cli.rebuildIndex.indexing', ['journalName' => $journal->getLocalizedName()]) . ' ... ';
                }

                $submissions = Repo::submission()->getMany(
                    Repo::submission()
                        ->getCollector()
                        ->filterByContextIds([$journal->getId()])
                );
                foreach ($submissions as $submission) {
                    if ($submission->getSubmissionProgress() == 0) { // Not incomplete
                        $this->submissionMetadataChanged($submission);
                        $this->submissionFilesChanged($submission);
                        $numIndexed++;
                    }
                }
                $this->submissionChangesFinished();

                if ($log) {
                    echo __('search.cli.rebuildIndex.result', ['numIndexed' => $numIndexed]) . "\n";
                }
            }
        }
    }


    //
    // Private helper methods
    //
    /**
     * Index a block of text for an object.
     *
     * @param $objectId int
     * @param $text string|array
     */
    protected function _indexObjectKeywords($objectId, $text)
    {
        $searchDao = DAORegistry::getDAO('ArticleSearchDAO'); /* @var $searchDao ArticleSearchDAO */
        $keywords = $this->filterKeywords($text);
        $searchDao->insertObjectKeywords($objectId, $keywords);
    }

    /**
     * Add a block of text to the search index.
     *
     * @param $articleId int
     * @param $type int
     * @param $text string
     * @param $assocId int optional
     */
    protected function _updateTextIndex($articleId, $type, $text, $assocId = null)
    {
        $searchDao = DAORegistry::getDAO('ArticleSearchDAO'); /* @var $searchDao ArticleSearchDAO */
        $objectId = $searchDao->insertObject($articleId, $type, $assocId);
        $this->_indexObjectKeywords($objectId, $text);
    }

    /**
     * Flattens array of localized fields to a single, non-associative array of items
     *
     * @param $arrayWithLocales array Array of localized fields
     *
     * @return array
     */
    protected function _flattenLocalizedArray($arrayWithLocales)
    {
        $flattenedArray = [];
        foreach ($arrayWithLocales as $localeArray) {
            $flattenedArray = array_merge(
                $flattenedArray,
                $localeArray
            );
        }
        return $flattenedArray;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\search\ArticleSearchIndex', '\ArticleSearchIndex');
}

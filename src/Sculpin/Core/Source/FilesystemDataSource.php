<?php

/*
 * This file is a part of Sculpin.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sculpin\Core\Source;

use SplFileInfo;
use Dflydev\Canal\Analyzer\Analyzer;
use Dflydev\Symfony\FinderFactory\FinderFactory;
use Dflydev\Symfony\FinderFactory\FinderFactoryInterface;
use dflydev\util\antPathMatcher\AntPathMatcher;
use Sculpin\Core\Util\DirectorySeparatorNormalizer;

/**
 * Filesystem Data Source.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class FilesystemDataSource implements DataSourceInterface
{
    /**
     * Source directory
     *
     * @var string
     */
    protected $sourceDir;

    /**
     * Exclude paths
     *
     * @var array
     */
    protected $excludes;

    /**
     * Ignore paths
     *
     * @var array
     */
    protected $ignores;

    /**
     * Raw paths
     *
     * @var array
     */
    protected $raws;

    /**
     * Finder Factory
     *
     * @var FinderFactoryInterface
     */
    protected $finderFactory;

    /**
     * Path Matcher
     *
     * @var AntPathMatcher
     */
    protected $matcher;

    /**
     * Analyzer
     *
     * @var Analyzer
     */
    protected $analyzer;

    /**
     * DirectorySeparatorNormalizer
     *
     * @var DirectorySeparatorNormalizer
     */
    protected $directorySeparatorNormalizer;

    /**
     * Since time
     *
     * @var string
     */
    protected $sinceTime;

    /**
     * Constructor.
     *
     * @param string                       $sourceDir                    Source directory
     * @param array                        $excludes                     Exclude paths
     * @param array                        $ignores                      Ignore paths
     * @param array                        $raws                         Raw paths
     * @param FinderFactoryInterface       $finderFactory                Finder Factory
     * @param AntPathMatcher               $matcher                      Matcher
     * @param Analyzer                     $analyzer                     Analyzer
     * @param DirectorySeparatorNormalizer $directorySeparatorNormalizer Directory Separator Normalizer
     */
    public function __construct(
        $sourceDir,
        $excludes,
        $ignores,
        $raws,
        FinderFactoryInterface $finderFactory = null,
        AntPathMatcher $matcher = null,
        Analyzer $analyzer = null,
        DirectorySeparatorNormalizer $directorySeparatorNormalizer = null
    ) {
        $this->sourceDir = $sourceDir;
        $this->excludes = $excludes;
        $this->ignores = $ignores;
        $this->raws = $raws;
        $this->finderFactory = $finderFactory ?: new FinderFactory;
        $this->matcher = $matcher ?: new AntPathMatcher;
        $this->analyzer = $analyzer;
        $this->directorySeparatorNormalizer = $directorySeparatorNormalizer ?: new DirectorySeparatorNormalizer;
        $this->sinceTime = '1970-01-01T00:00:00Z';
    }

    /**
     * {@inheritdoc}
     */
    public function dataSourceId()
    {
        return 'FilesystemDataSource:'.$this->sourceDir;
    }

    /**
     * {@inheritdoc}
     */
    public function refresh(SourceSet $sourceSet)
    {
        // We regenerate the whole site if an excluded file changes.
        $excludedFilesHaveChanged = false;

        $files = $this->getChangedFiles($sourceSet);

        if ($this->filesDeleted($sourceSet)) {
            $excludedFilesHaveChanged = true;
        }

        foreach ($files as $file) {
            if ($this->fileExcluded($file)) {
                $excludedFilesHaveChanged = true;
                continue;
            }

            $isRaw = $this->fileRaw($file);

            $source = new FileSource($this->analyzer, $this, $file, $isRaw, true);
            $sourceSet->mergeSource($source);
        }

        if ($excludedFilesHaveChanged) {
            // If any of the exluded files have changed we should
            // mark all of the sources as having changed.
            $this->markAllChanged($sourceSet);
        }
    }

    /**
     * Mark all the files in a SourceSet as changed
     *
     * Should this be moved to the SourceSet?
     *
     * @param SplFileInfo $file  The file to check
     */
    protected function markAllChanged(SourceSet $sourceSet)
    {
        foreach ($sourceSet->allSources() as $source) {
            $source->setHasChanged();
        }
    }

    /**
     * Determine if the given file is on the excluded list
     *
     * @param SplFileInfo $file  The file to check
     *
     * @return bool
     */
    protected function fileExcluded(SplFileInfo $file)
    {
        foreach ($this->excludes as $pattern) {
            if (!$this->matcher->isPattern($pattern)) {
                continue;
            }

            if ($this->matcher->match(
                $pattern,
                $this->directorySeparatorNormalizer->normalize($file->getRelativePathname())
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the given file is on the ignored list
     *
     * @param SplFileInfo $file  The file to check
     *
     * @return bool
     */
    protected function fileIgnored(SplFileInfo $file)
    {
        foreach ($this->ignores as $pattern) {
            if (!$this->matcher->isPattern($pattern)) {
                continue;
            }
            if ($this->matcher->match(
                $pattern,
                $this->directorySeparatorNormalizer->normalize($file->getRelativePathname())
            )
            ) {
                // Ignored files are completely ignored.
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the given file is on the raw list
     *
     * @param SplFileInfo $file  The file to check
     *
     * @return bool
     */
    protected function fileRaw(SplFileInfo $file)
    {
        foreach ($this->raws as $pattern) {
            if (!$this->matcher->isPattern($pattern)) {
                continue;
            }
            if ($this->matcher->match(
                $pattern,
                $this->directorySeparatorNormalizer->normalize($file->getRelativePathname())
            )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find all the changed files in the given SourceSet
     *
     * @pararm SourceSet $sourceSet  The set to check
     *
     * @return array  An array of SplFileInfo files
     */
    protected function getChangedFiles(SourceSet $sourceSet)
    {
        $sinceTimeLast = $this->sinceTime;
        $this->sinceTime = date('c');

        $newFiles = $this->getFileList();

        $sinceTimeLastSeconds = strtotime($sinceTimeLast);
        $isChanged = function ($file) use ($sinceTimeLastSeconds) {
            return $file->getMTime() >= $sinceTimeLastSeconds;
        };

        // Switch to the more useful filename as a key
        $lastSet = $sourceSet->allSources();
        $lastFilenames = array_map(
            function ($source) {
                return $source->file()->getPathname();
            },
            array_values($lastSet)
        );

        $lastSet = array_combine($lastFilenames, $lastSet);

        $newFilenames = array_keys($newFiles);

        $excludedFiles = array_filter(
            $newFilenames,
            function ($filename) use ($newFiles) {
                return $this->fileExcluded($newFiles[$filename]);
            }
        );

        // Remove the excluded files
        $nonExcludedFilenames = array_diff($newFilenames, $excludedFiles);
        $addedFiles = array_diff($nonExcludedFilenames, $lastFilenames);
        $deletedFiles = array_diff($lastFilenames, $nonExcludedFilenames);

        $changedFiles = [];
        foreach ($newFiles as $file) {
            if ($isChanged($file)) {
                continue;
            }
            $changedFiles[$file->getPathname()] = $file;
        }



        $excludedChanged = false;
        foreach ($excludedFiles as $filename) {
            if ($isChanged($newFiles[$filename])) {
                $excludedChanged = true;
                break;
            }
        }

        echo "added\n" . implode("\n", $addedFiles);
        echo "\n\ndeleted\n". implode("\n", $deletedFiles);
        echo "\n\nExcluded\n" . implode("\n", $excludedFiles);
        echo "\n\nChanged\n" . implode("\n", array_keys($changedFiles));
        echo "\n\n";

        return $changedFiles;
    }

    function getFileList()
    {
        $sourceFiles = $this
            ->finderFactory->createFinder()
            ->files()
            ->ignoreVCS(true)
            ->ignoreDotFiles(false)
            // ->date('>='.$sinceTimeLast)
            ->followLinks()
            ->in($this->sourceDir);

        $filteredFiles = [];
        foreach ($sourceFiles as $file) {
            if ($this->fileIgnored($file)) {
                continue;
            }

            $filteredFiles[$file->getPathname()] = $file;
        }

        return $filteredFiles;
    }

    /**
     * Determine if any files in the given SourceSet have been deleted
     *
     * @param SourceSet $sourceSet  The set to scan
     *
     * @return bool
     */
    protected function filesDeleted(SourceSet $sourceSet)
    {
        // Find out what's removed from old by using array_key_diff between new list and
        // old where the key is the full path

        $oldSourceList = [];
        foreach ($sourceSet->allSources() as $source) {
            // Need to know the the actual Source so we can mark it later
            $oldSourceList[$source->file()->getPathname()] = $source;
        }

        $newFileList = [];
        $newFiles = $this
            ->finderFactory->createFinder()
            ->files()
            ->ignoreVCS(true)
            ->ignoreDotFiles(false)
            ->followLinks()
            ->in($this->sourceDir);

        foreach ($newFiles as $file) {
            // Don't care what the new files actually are, just the path
            $newFileList[$file->getPathname()] = $file;
        }

        $deletedSources = array_diff_key($oldSourceList, $newFileList);

        foreach ($deletedSources as $source) {
            $sourceSet->removeSource($source);
        }

        $addedSources = array_diff_key($newFileList, $oldSourceList);

        return count($deletedSources) > 0;
    }
}

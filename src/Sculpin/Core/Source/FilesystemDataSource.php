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

        $files = $this
            ->finderFactory->createFinder()
            ->files()
            ->ignoreVCS(true)
            ->ignoreDotFiles(false)
            ->date('>='.$sinceTimeLast)
            ->followLinks()
            ->in($this->sourceDir);

        $sinceTimeLastSeconds = strtotime($sinceTimeLast);

        $changedFiles = [];

        foreach ($files as $file) {
            if ($sinceTimeLastSeconds > $file->getMTime()) {
                // This is a hack because Finder is actually incapable
                // of resolution down to seconds.
                //
                // Sometimes this may result in the file looking like it
                // has been modified twice in a row when it has not.
                continue;
            }

            if ($this->fileIgnored($file)) {
                continue;
            }

            $changedFiles[] = $file;
        }

        return $changedFiles;
    }
}

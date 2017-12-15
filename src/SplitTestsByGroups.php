<?php
namespace Codeception\Task;

use Robo\Contract\TaskInterface;
use Robo\Exception\TaskException;
use Robo\Task\BaseTask;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use \Codeception\Util\Annotation;
use \Codeception\Test\Loader;
use \Codeception\Test\Descriptor;

trait SplitTestsByGroups
{
    protected function taskSplitTestsByGroups($numGroups)
    {
        return $this->task(SplitTestsByGroupsTask::class, $numGroups);
    }

    protected function taskSplitTestFilesByGroups($numGroups)
    {
        return $this->task(SplitTestFilesByGroupsTask::class, $numGroups);
    }
}

abstract class TestsSplitter extends BaseTask
{
    protected $numGroups;
    protected $projectRoot = '.';
    protected $testsFrom = 'tests';
    protected $saveTo = 'tests/_data/paracept_';
    protected $excludePath = 'vendor';

    public function __construct($groups)
    {
        $this->numGroups = $groups;
    }

    public function projectRoot($path)
    {
        $this->projectRoot = $path;

        return $this;
    }

    public function testsFrom($path)
    {
        $this->testsFrom = $path;

        return $this;
    }

    public function groupsTo($pattern)
    {
        $this->saveTo = $pattern;

        return $this;
    }

    public function excludePath($path)
    {
        $this->excludePath = $path;

        return $this;
    }
}

/**
 * Loads all tests into groups and saves them to groupfile according to pattern.
 *
 * ``` php
 * <?php
 * $this->taskSplitTestsByGroups(5)
 *    ->testsFrom('tests')
 *    ->groupsTo('tests/_log/paratest_')
 *    ->run();
 * ?>
 * ```
 */
class SplitTestsByGroupsTask extends TestsSplitter implements TaskInterface
{
    public function findTestBySignature($tests, $testSignature)
    {
        foreach ($tests as $test) {
            $signature = Descriptor::getTestSignature($test);
            if ($signature === $testSignature) {
                return $test;
            }
        }
    }

    public function run()
    {
        if (!class_exists('\Codeception\Test\Loader')) {
            throw new TaskException($this, 'This task requires Codeception to be loaded. Please require autoload.php of Codeception');
        }
        $testLoader = new Loader(['path' => $this->testsFrom]);
        $testLoader->loadTests($this->testsFrom);
        $tests = $testLoader->getTests();
        $dependent_tests_group = array();
        // Add all tests which are dependent on other ones or are dependencies for other ones
        // (generally tests related by @depends annotation) into separate group
        // to be possible to run them together in one docker container.
        foreach ($tests as $idx => $test) {
            $test_name = Descriptor::getTestFullName($test);
            if ($test instanceof \Codeception\Test\Cest) {
                $test->getMetadata()
                    ->setParamsFromAnnotations(Annotation::forMethod($test->getTestClass(), $test->getTestMethod())
                        ->raw());
                $test_dependencies = $test->getDependencies();
                if (!empty($test_dependencies)) {
                    // Add test which is dependent on other ones (have @depends annotation).
                    if (!in_array($test_name, $dependent_tests_group)) {
                        $dependent_tests_group[] = $test_name;
                    }
                    // Add tests that are dependencies for this test (pointed by @depends annotation).
                    foreach ($test_dependencies as $test_dependency) {
                        $test_depends = $this->findTestBySignature($tests, $test_dependency);
                        $test_depends_name = Descriptor::getTestFullName($test_depends);
                        if (!in_array($test_depends_name, $dependent_tests_group)) {
                            $dependent_tests_group[] = $test_depends_name;
                        }
                    }
                }
            }
        }
        // In case we have dependent tests, we will have one extra group,
        // so we need to decrease number of other groups to get requested
        // number of groups in total.
        if (!empty($dependent_tests_group)) {
            $this->numGroups = $this->numGroups - 1;
        }

        $i = 0;
        $groups = [];

        $this->printTaskInfo('Processing ' . count($tests) . ' tests');

        // Splitting tests by groups.
        /** @var \Codeception\TestInterface $test */
        foreach ($tests as $test) {
            if ($test instanceof PHPUnit_Framework_TestSuite_DataProvider) {
                $test = current($test->tests());
            }
            $test_name = Descriptor::getTestFullName($test);
            $test_in_dependent_group = !empty($dependent_tests_group) && in_array($test_name, $dependent_tests_group);
            if (!$test_in_dependent_group) {
                $test_group_idx = ($i % $this->numGroups) + 1;
                $groups[$test_group_idx][] = $test_name;
                $i++;
            }
        }

        // Add dependent tests group at the end.
        if (!empty($dependent_tests_group)) {
            $test_group_idx = ($i % $this->numGroups) + 1;
            $groups[$test_group_idx] = $dependent_tests_group;
        }

        // Saving group files.
        foreach ($groups as $i => $tests) {
            $filename = $this->saveTo . $i;
            $this->printTaskInfo("Writing $filename");
            file_put_contents($filename, implode("\n", $tests));
        }
    }
}

/**
 * Finds all test files and splits them by group.
 * Unlike `SplitTestsByGroupsTask` does not load them into memory and not requires Codeception to be loaded.
 *
 * ``` php
 * <?php
 * $this->taskSplitTestFilesByGroups(5)
 *    ->testsFrom('tests/unit/Acme')
 *    ->codeceptionRoot('projects/tested')
 *    ->groupsTo('tests/_log/paratest_')
 *    ->run();
 * ?>
 * ```
 */
class SplitTestFilesByGroupsTask extends TestsSplitter implements TaskInterface
{
    public function run()
    {
        $files = Finder::create()
            ->name('*Cept.php')
            ->name('*Cest.php')
            ->name('*Test.php')
            ->name('*.feature')
            ->path($this->testsFrom)
            ->in($this->projectRoot ? $this->projectRoot : getcwd())
            ->exclude($this->excludePath);

        $i = 0;
        $groups = [];

        $this->printTaskInfo('Processing ' . count($files) . ' files');
        // splitting tests by groups
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $groups[($i % $this->numGroups) + 1][] = $file->getRelativePathname();
            $i++;
        }

        // saving group files
        foreach ($groups as $i => $tests) {
            $filename = $this->saveTo . $i;
            $this->printTaskInfo("Writing $filename");
            file_put_contents($filename, implode("\n", $tests));
        }
    }
}

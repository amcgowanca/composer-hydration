<?php

/**
 * @file
 * Contains \Jkribeiro\Composer\ComposerHydrationHandler.
 */

namespace Jkribeiro\Composer;

use Composer\Script\Event;
use Symfony\Component\Finder\Finder;

/**
 * Composer Hydration Handler Class.
 */
class ComposerHydrationHandler
{
    const REPLACE_ARG = '--replace';

    /**
     * @var Event Object
     */
    public $event;

    /**
     * @var string
     */
    public $basePath;

    /**
     * Class ComposerHydrationHandler Constructor.
     *
     * @param object $event
     *   Composer Event object.
     *
     * @param string $basePath
     *   Path where the hydration will be performed.
     */
    public function __construct(Event $event, $basePath)
    {
        $this->event = $event;
        $this->basePath = $basePath;
    }

    /**
     * Checks if the given command argument is available.
     *
     * @param string $argumentName
     *   Command argument name to be verified.
     *
     * @return bool
     *   Returns TRUE in case of success, FALSE otherwise.
     */
    public function cmdArgumentExist($argumentName)
    {
        return in_array($argumentName, array(self::REPLACE_ARG));
    }

    /**
     * Returns an array containing the replace values from command argument.
     *
     * @param string $argValues
     *   Command argument replace value.
     *
     * @return array
     *   An array, following the format: SEARCH => REPLACE.
     */
    public function getReplaceValuesFromArgument($argValues)
    {
        $replaceValues = array();

        $argValues = explode(',', $argValues);
        foreach ($argValues as $argValue) {
            $argValue = explode(':', $argValue);
            if (count($argValue) != 2) {
                throw new \ErrorException('Command argument "--replace" must follow the format: --replace="{SEARCH}:{REPLACE},..."');
            }

            $replaceValues[trim($argValue[0])] = trim($argValue[1]);
        }

        return $replaceValues;
    }

    /**
     * Returns an array containing the command arguments values.
     *
     * @return array
     *   An array containing the script command arguments.
     */
    public function getArguments()
    {
        // Checks if script received command arguments.
        $cmdArguments = $this->event->getArguments();
        if (!$cmdArguments) {
            throw new \ErrorException('Hydrate command expects arguments.');
        }

        // Treats arguments.
        $returnArguments = array();
        foreach ($cmdArguments as $cmdArgument) {
            $cmdArgument = explode('=', $cmdArgument);
            $argument = $cmdArgument[0];

            // Checks if the argument exists.
            if (!$this->cmdArgumentExist($argument)) {
                throw new \ErrorException("Command argument '$argument' do not exist.");
            }

            // Treats REPLACE_ARG argument.
            if ($argument == self::REPLACE_ARG) {
                $replaceValues = !empty($cmdArgument[1]) ? $cmdArgument[1] : null;
                if (!$replaceValues) {
                    throw new \ErrorException('Command argument "--replace" must contain values, like: --replace="{SEARCH}:{REPLACE},.."');
                }

                $returnArguments[self::REPLACE_ARG] = $this->getReplaceValuesFromArgument($replaceValues);
            }
        }

        return $returnArguments;
    }

    /**
     * Process hydration to File contents.
     *
     * @param string $replaceMap
     *   An array containing the replacement map following the format:
     *     [{SEARCH} => {REPLACE},
     *     {SEARCH} => {REPLACE},
     *      ...]
     */
    public function hydrateFileContents($replaceMap)
    {
        $io = $this->event->getIO();

        $finder = new Finder();
        $finder->in($this->basePath)->notPath('vendor')->notName('composer.json');;

        // Find files.
        foreach ($replaceMap as $search => $replace) {
            // Restrict files by search.
            $finder->contains($search);
        }

        $count = iterator_count($finder);
        if (!$count) {
            $io->write("[Hydration][OK] Skipping, no file contents to be replaced.");

            return;
        }

        $io->write("[Hydration][INFO] Hydrating $count file(s).");

        foreach ($finder as $file) {
            $filePath = $file->getRelativePathname();

            // Replace values.
            $fileContent = str_replace(array_keys($replaceMap), array_values($replaceMap), $file->getContents());

            // Save file with new replaced content.
            if (!file_put_contents($filePath, $fileContent)) {
                // Failed.
                throw new \ErrorException("Unable to Hydrate the file, check the file permissions and try again: $filePath");
            }

            // Success.
            $io->write("[Hydration][OK] File Hydrated: $filePath");
        }
    }

    /**
     * Process hydration renaming files and folders.
     *
     * @param string $replaceMap
     *   An array containing the replacement map following the format:
     *     [{SEARCH} => {REPLACE},
     *     {SEARCH} => {REPLACE},
     *      ...]
     */
    public function hydrateRenameFilesAndFolders($replaceMap)
    {
        $io = $this->event->getIO();

        $finder = new Finder();
        $finder->in($this->basePath)->exclude('vendor');

        foreach ($replaceMap as $search => $replace) {
            // Restrict files by search.
            $finder->name("*$search*");
        }

        $count = iterator_count($finder);
        if (!$count) {
            $io->write("[Hydration][OK] Skipping, no folders and files to be renamed.");

            return;
        }

        $io->write("[Hydration][INFO] Renaming $count file(s)/folder(s).");

        $finder = array_keys(iterator_to_array($finder, true));
        foreach ($finder as $currentName) {
            $newName = str_replace(array_keys($replaceMap), array_values($replaceMap), $currentName);

            // Replace values.
            $renamed = rename($currentName, $newName);
            if (!$renamed) {
                // Failed.
                throw new \ErrorException("Unable to rename file/folder: $currentName");
            }

            // Success.
            $io->write("[Hydration][OK] Renamed $currentName ---> $newName");
        }
    }

    /**
     * Performs Hydration process.
     */
    public function hydrate()
    {
        $arguments = $this->getArguments();
        $replaceMap = $arguments[self::REPLACE_ARG];

        // Rename Files and Folders.
        $this->hydrateRenameFilesAndFolders($replaceMap);

        // Hydrate file contents.
        $this->hydrateFileContents($replaceMap);
    }

}

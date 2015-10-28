<?php
/**
 * This file is part of the Composer Merge plugin.
 *
 * Copyright (C) 2015 Bryan Davis, Wikimedia Foundation, and contributors
 *
 * This software may be modified and distributed under the terms of the MIT
 * license. See the LICENSE file for details.
 */

namespace Wikimedia\Composer\Merge;

use Wikimedia\Composer\Logger;

use Composer\Composer;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\AliasPackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use UnexpectedValueException;

/**
 * Processing for a composer.json file that will be merged into a RootPackageInterface
 *
 * @author Bryan Davis <bd808@bd808.com>
 */
class ExtraPackage
{

    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var Logger $logger
     */
    protected $logger;

    /**
     * @var string $path
     */
    protected $path;

    /**
     * @var array $json
     */
    protected $json;

    /**
     * @var CompletePackage $package
     */
    protected $package;

    /**
     * @param string $path Path to composer.json file
     * @param Composer $composer
     * @param Logger $logger
     */
    public function __construct($path, Composer $composer, Logger $logger)
    {
        $this->path = $path;
        $this->composer = $composer;
        $this->logger = $logger;
        $this->json = $this->readPackageJson($path);
        $this->package = $this->loadPackage($this->json);
    }

    /**
     * Get list of additional packages to include if precessing recursively.
     *
     * @return array
     */
    public function getIncludes()
    {
        return isset($this->json['extra']['merge-plugin']['include']) ?
            $this->json['extra']['merge-plugin']['include'] : array();
    }

    /**
     * Read the contents of a composer.json style file into an array.
     *
     * The package contents are fixed up to be usable to create a Package
     * object by providing dummy "name" and "version" values if they have not
     * been provided in the file. This is consistent with the default root
     * package loading behavior of Composer.
     *
     * @param string $path
     * @return array
     */
    protected function readPackageJson($path)
    {
        $file = new JsonFile($path);
        $json = $file->read();
        if (!isset($json['name'])) {
            $json['name'] = 'merge-plugin/' .
                strtr($path, DIRECTORY_SEPARATOR, '-');
        }
        if (!isset($json['version'])) {
            $json['version'] = '1.0.0';
        }
        return $json;
    }

    /**
     * @return CompletePackage
     */
    protected function loadPackage($json)
    {
        $loader = new ArrayLoader();
        $package = $loader->load($json);
        // @codeCoverageIgnoreStart
        if (!$package instanceof CompletePackage) {
            throw new UnexpectedValueException(
                'Expected instance of CompletePackage, got ' .
                get_class($package)
            );
        }
        // @codeCoverageIgnoreEnd
        return $package;
    }

    /**
     * Merge this package into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     * @param PluginState $state
     */
    public function mergeInto(RootPackageInterface $root, PluginState $state)
    {
        $this->addRepositories($root);

        $this->mergeRequires($root, $state);
        $this->mergeDevRequires($root, $state);

        // UPSTREAM: These don't have any effect if our RootPackageInterface
        // is actually a RootAliasPackage due to caching done at construction
        // of the alias.
        $this->mergeConflicts($root);
        $this->mergeReplaces($root);
        $this->mergeProvides($root);

        $this->mergeSuggests($root);

        $this->mergeAutoload($root);
        $this->mergeDevAutoload($root);

        $this->mergeExtra($root, $state);
    }

    /**
     * Add a collection of repositories described by the given configuration
     * to the given package and the global repository manager.
     *
     * @param RootPackageInterface $root
     */
    protected function addRepositories(RootPackageInterface $root)
    {
        if (!isset($this->json['repositories'])) {
            return;
        }
        $repoManager = $this->composer->getRepositoryManager();
        $newRepos = array();

        foreach ($this->json['repositories'] as $repoJson) {
            if (!isset($repoJson['type'])) {
                continue;
            }
            $this->logger->debug("Adding {$repoJson['type']} repository");
            $repo = $repoManager->createRepository(
                $repoJson['type'],
                $repoJson
            );
            $repoManager->addRepository($repo);
            $newRepos[] = $repo;
        }

        self::castRootPackage($root)->setRepositories(array_merge(
            $newRepos,
            $root->getRepositories()
        ));
    }

    /**
     * Merge require into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     * @param PluginState $state
     */
    protected function mergeRequires(
        RootPackageInterface $root,
        PluginState $state
    ) {
        $requires = $this->package->getRequires();
        if (empty($requires)) {
            return;
        }

        $this->mergeStabilityFlags($root, $requires);

        $dups = array();
        $root->setRequires($this->mergeLinks(
            $root->getRequires(),
            $requires,
            $state->replaceDuplicateLinks(),
            $dups
        ));
        $state->addDuplicateLinks('require', $dups);
    }

    /**
     * Merge require-dev into RootPackageInterface
     *
     * @param RootPackageInterface $root
     * @param PluginState $state
     */
    protected function mergeDevRequires(
        RootPackageInterface $root,
        PluginState $state
    ) {
        $requires = $this->package->getDevRequires();
        if (empty($requires)) {
            return;
        }

        $this->mergeStabilityFlags($root, $requires);

        $dups = array();
        $root->setDevRequires($this->mergeLinks(
            $root->getDevRequires(),
            $requires,
            $state->replaceDuplicateLinks(),
            $dups
        ));
        $state->addDuplicateLinks('require-dev', $dups);
    }

    /**
     * Merge two collections of package links and collect duplicates for
     * subsequent processing.
     *
     * @param array $origin Primary collection
     * @param array $merge Additional collection
     * @param bool $replace Replace existing links?
     * @param array &dups Duplicate storage
     * @return array Merged collection
     */
    protected function mergeLinks(
        array $origin,
        array $merge,
        $replace,
        array &$dups
    ) {
        foreach ($merge as $name => $link) {
            if (!isset($origin[$name]) || $replace) {
                $this->logger->debug("Merging <comment>{$name}</comment>");
                $origin[$name] = $link;
            } else {
                // Defer to solver.
                $this->logger->debug(
                    "Deferring duplicate <comment>{$name}</comment>"
                );
                $dups[] = $link;
            }
        }
        return $origin;
    }

    /**
     * Merge autoload into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     */
    protected function mergeAutoload(RootPackageInterface $root)
    {
        $autoload = $this->package->getAutoload();
        if (empty($autoload)) {
            return;
        }

        self::castRootPackage($root)->setAutoload(array_merge_recursive(
            $root->getAutoload(),
            $this->fixRelativePaths($autoload)
        ));
    }

    /**
     * Merge autoload-dev into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     */
    protected function mergeDevAutoload(RootPackageInterface $root)
    {
        $autoload = $this->package->getDevAutoload();
        if (empty($autoload)) {
            return;
        }

        self::castRootPackage($root)->setDevAutoload(array_merge_recursive(
            $root->getDevAutoload(),
            $this->fixRelativePaths($autoload)
        ));
    }

    /**
     * Fix a collection of paths that are relative to this package to be
     * relative to the base package.
     *
     * @param array $paths
     * @return array
     */
    protected function fixRelativePaths(array $paths)
    {
        $base = dirname($this->path);
        $base = ($base === '.') ? '' : "{$base}/";

        array_walk_recursive(
            $paths,
            function (&$path) use ($base) {
                $path = "{$base}{$path}";
            }
        );
        return $paths;
    }

    /**
     * Extract and merge stability flags from the given collection of
     * requires and merge them into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     * @param array $requires
     */
    protected function mergeStabilityFlags(
        RootPackageInterface $root,
        array $requires
    ) {
        $flags = $root->getStabilityFlags();

        // Adapted from RootPackageLoader::extractStabilityFlags
        $rootMin = BasePackage::$stabilities[$root->getMinimumStability()];
        $explicitStabilityRe = '/^[^@]*?@(' .
            implode('|', array_keys(BasePackage::$stabilities)) .
            ')$/i';

        foreach ($requires as $name => $link) {
            $name = strtolower($name);
            $version = $link->getPrettyConstraint();
            $unaliased = preg_replace('/^([^,\s@]+) as .+$/', '$1', $version);
            $stability = null;

            if (preg_match($explicitStabilityRe, $version, $match)) {
                // Extract explict '@stability'
                $stability = BasePackage::$stabilities[
                    VersionParser::normalizeStability($match[1])
                ];

            } elseif (preg_match('/^[^,\s@]+$/', $unaliased)) {
                // Extract explicit '-stability'
                $stability = BasePackage::$stabilities[
                    VersionParser::parseStability($unaliased)
                ];

                if ($stability === BasePackage::STABILITY_STABLE ||
                    $rootMin > $stability
                ) {
                    // Ignore if 'stable' or more stable than the global
                    // minimum
                    $stability = null;
                }
            }

            if ($stability !== null &&
                !(isset($flags[$name]) && $flags[$name] > $stability)
            ) {
                // Store if less stable than current stability for package
                $flags[$name] = $stability;
            }
        }

        // setStabilityFlags is not part of RootPackageInterface :/
        self::castRootPackage($root)->setStabilityFlags($flags);
    }

    /**
     * Merge conflicting packages into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     */
    protected function mergeConflicts(RootPackageInterface $root)
    {
        $conflicts = $this->package->getConflicts();
        if (!empty($conflicts)) {
            if ($root instanceof RootAliasPackage) {
                $this->logger->warning(
                    "Aliased packages do not support conflict merging."
                );
            }
            self::castRootPackage($root)->setConflicts(array_merge(
                $root->getConflicts(),
                $conflicts
            ));
        }
    }

    /**
     * Merge replaced packages into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     */
    protected function mergeReplaces(RootPackageInterface $root)
    {
        $replaces = $this->package->getReplaces();
        if (!empty($replaces)) {
            if ($root instanceof RootAliasPackage) {
                $this->logger->warning(
                    "Aliased packages do not support replace merging."
                );
            }
            self::castRootPackage($root)->setReplaces(array_merge(
                $root->getReplaces(),
                $replaces
            ));
        }
    }

    /**
     * Merge provided virtual packages into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     */
    protected function mergeProvides(RootPackageInterface $root)
    {
        $provides = $this->package->getProvides();
        if (!empty($provides)) {
            if ($root instanceof RootAliasPackage) {
                $this->logger->warning(
                    "Aliased packages do not support provide merging."
                );
            }
            self::castRootPackage($root)->setProvides(array_merge(
                $root->getProvides(),
                $provides
            ));
        }
    }

    /**
     * Merge suggested packages into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     */
    protected function mergeSuggests(RootPackageInterface $root)
    {
        $suggests = $this->package->getSuggests();
        if (!empty($suggests)) {
            // setSuggests is from Package and not in any interfaces
            self::castRootPackage($root)->setSuggests(array_merge(
                $root->getSuggests(),
                $suggests
            ));
        }
    }

    /**
     * Merge extra config into a RootPackageInterface
     *
     * @param RootPackageInterface $root
     * @param PluginState $state
     */
    public function mergeExtra(RootPackageInterface $root, PluginState $state)
    {
        $extra = $this->package->getExtra();
        unset($extra['merge-plugin']);
        if (!$state->shouldMergeExtra() || empty($extra)) {
            return;
        }

        $rootExtra = $root->getExtra();

        if ($state->replaceDuplicateLinks()) {
            self::castRootPackage($root)->setExtra(
                array_merge($rootExtra, $extra)
            );

        } else {
            foreach ($extra as $key => $value) {
                if (isset($rootExtra[$key])) {
                    $this->logger->debug(
                        "Ignoring duplicate <comment>{$key}</comment> in ".
                        "<comment>{$this->path}</comment> extra config."
                    );
                }
            }
            self::castRootPackage($root)->setExtra(
                array_merge($extra, $rootExtra)
            );
        }
    }

    /**
     * Get the RootPackage from a RootPackageInterface
     *
     * @param RootPackageInterface $root
     * @return RootPackage
     */
    public static function castRootPackage(RootPackageInterface $root)
    {
        if ($root instanceof AliasPackage) {
            $root = $root->getAliasOf();
        }
        // @codeCoverageIgnoreStart
        if (!$root instanceof RootPackage) {
            throw new UnexpectedValueException(
                'Expected instance of RootPackage, got ' .
                get_class($root)
            );
        }
        // @codeCoverageIgnoreEnd
        return $root;
    }
}
// vim:sw=4:ts=4:sts=4:et:

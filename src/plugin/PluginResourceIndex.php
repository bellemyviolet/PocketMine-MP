<?php

declare(strict_types=1);

/** [BETTERPMMP-PATCH] */

namespace pocketmine\plugin;

use pocketmine\command\Command;
use pocketmine\event\RegisteredListener;
use pocketmine\permission\Permission;

final class PluginResourceIndex
{

    /** @phpstan-var array<string, PluginResources> */
    private array $resourceMap = [];

    /** @phpstan-var array<string, string[]> */
    private array $reverseDependencyMap = [];

    /** @phpstan-var array<string, array<string, int>> */
    private array $mtimeSnapshots = [];

    /**
     * @param RegisteredListener[] $handlers
     * @param Command[]            $commands
     * @param Permission[]         $permissions
     */
    public function trackPlugin(string $pluginName, array $handlers, array $commands, array $permissions): void
    {
        $this->resourceMap[$pluginName] = new PluginResources($handlers, $commands, $permissions);
    }

    public function getResources(string $pluginName): PluginResources
    {
        return $this->resourceMap[$pluginName] ?? new PluginResources();
    }

    /** @return string[] */
    public function getDependents(string $pluginName): array
    {
        return $this->reverseDependencyMap[$pluginName] ?? [];
    }

    /** @return array<string, int> */
    public function getMtimeSnapshot(string $pluginName): array
    {
        return $this->mtimeSnapshots[$pluginName] ?? [];
    }

    /** @param array<string, int> $snapshot */
    public function updateMtimeSnapshot(string $pluginName, array $snapshot): void
    {
        $this->mtimeSnapshots[$pluginName] = $snapshot;
    }

    public function removePlugin(string $pluginName): void
    {
        unset($this->resourceMap[$pluginName]);
        unset($this->mtimeSnapshots[$pluginName]);

        foreach ($this->reverseDependencyMap as $target => $dependents) {
            $filtered = [];
            foreach ($dependents as $dep) {
                if ($dep !== $pluginName) {
                    $filtered[] = $dep;
                }
            }
            if (\count($filtered) === 0) {
                unset($this->reverseDependencyMap[$target]);
            } else {
                $this->reverseDependencyMap[$target] = $filtered;
            }
        }

        unset($this->reverseDependencyMap[$pluginName]);
    }

    /** @param PluginDescription[] $pluginDescriptions */
    public function buildReverseDependencyMap(array $pluginDescriptions): void
    {
        $this->reverseDependencyMap = [];

        foreach ($pluginDescriptions as $description) {
            $pluginName = $description->getName();
            $allDeps = [...$description->getDepend(), ...$description->getSoftDepend()];

            foreach ($allDeps as $depName) {
                if (!isset($this->reverseDependencyMap[$depName])) {
                    $this->reverseDependencyMap[$depName] = [];
                }
                $this->reverseDependencyMap[$depName][] = $pluginName;
            }
        }
    }
}
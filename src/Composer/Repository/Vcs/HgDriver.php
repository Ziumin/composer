<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class HgDriver extends VcsDriver
{
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $infoCache = array();

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->tmpDir = $this->config->get('home') . '/cache.hg/' . preg_replace('{[^a-z0-9]}i', '-', $this->url) . '/';

        if (is_dir($this->tmpDir)) {
            $this->process->execute('hg pull -u', $output, $this->tmpDir);
        } else {
            $dir = dirname($this->tmpDir);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $this->process->execute(sprintf('hg clone %s %s', escapeshellarg($this->url), escapeshellarg($this->tmpDir)), $output, $dir);
        }

        $this->getTags();
        $this->getBranches();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        if (null === $this->rootIdentifier) {
            $this->process->execute('hg tip --template "{node}"', $output, $this->tmpDir);
            $output = $this->process->splitLines($output);
            $this->rootIdentifier = $output[0];
        }

        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        $label = array_search($identifier, (array) $this->tags) ? : $identifier;

        return array('type' => 'hg', 'url' => $this->getUrl(), 'reference' => $label);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!isset($this->infoCache[$identifier])) {
            $this->process->execute(sprintf('hg cat -r %s composer.json', escapeshellarg($identifier)), $composer, $this->tmpDir);

            if (!trim($composer)) {
                return;
            }

            $composer = JsonFile::parseJson($composer, $identifier);

            if (!isset($composer['time'])) {
                $this->process->execute(sprintf('hg log --template "{date|rfc822date}" -r %s', escapeshellarg($identifier)), $output, $this->tmpDir);
                $date = new \DateTime(trim($output));
                $composer['time'] = $date->format('Y-m-d H:i:s');
            }
            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        if (null === $this->tags) {
            $tags = array();

            $this->process->execute('hg tags', $output, $this->tmpDir);
            foreach ($this->process->splitLines($output) as $tag) {
                if ($tag && preg_match('(^([^\s]+)\s+\d+:(.*)$)', $tag, $match)) {
                    $tags[$match[1]] = $match[2];
                }
            }
            unset($tags['tip']);

            $this->tags = $tags;
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            $branches = array();

            $this->process->execute('hg branches', $output, $this->tmpDir);
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && preg_match('(^([^\s]+)\s+\d+:(.*)$)', $branch, $match)) {
                    $branches[$match[1]] = $match[2];
                }
            }

            $this->branches = $branches;
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, $url, $deep = false)
    {
        if (preg_match('#(^(?:https?|ssh)://(?:[^@]@)?bitbucket.org|https://(?:.*?)\.kilnhg.com)#i', $url)) {
            return true;
        }

        if (!$deep) {
            return false;
        }

        $processExecutor = new ProcessExecutor();
        $exit = $processExecutor->execute(sprintf('hg identify %s', escapeshellarg($url)), $ignored, sys_get_temp_dir());

        return $exit === 0;
    }
}

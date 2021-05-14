<?php

namespace Throttle\Command;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractSymbolsDownloadCommand extends Command
{
    protected $providerName = 'general';
    protected $redisPostfix = '';
    protected $moduleBaseUrl = '';

    protected function configure()
    {
        $this->addArgument(
                'name',
                InputArgument::OPTIONAL,
                'Module Name'
            )
            ->addArgument(
                'identifier',
                InputArgument::OPTIONAL,
                'Module Identifier'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Limit'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->preExecute($input, $output);

        $app = $this->getApplication()->getContainer();

        $limit = $input->getOption('limit');
        if ($limit !== null && !ctype_digit($limit)) {
            throw new \InvalidArgumentException('\'limit\' must be an integer');
        }

        $manualName = $input->getArgument('name');
        $manualIdentifier = $input->getArgument('identifier');

        $modules = [];
        if ($manualName)
        {
            if (!$manualIdentifier)
            {
                throw new \RuntimeException('Specifying \'name\' requires specifying \'identifier\' as well.');
            }

            $modules[] = ['name' => $manualName, 'identifier' => $manualIdentifier];
        }
        else
        {
            $modules = $app['db']->executeQuery($this->getSearchModulesQuery($limit))->fetchAll();
        }

        $blacklist = $this->getBlacklistContent();
        $output->writeln('Loaded ' . count($blacklist) . ' blacklist entries');

        foreach ($modules as $key => $module)
        {
            list($name, $identifier) = [$module['name'], $module['identifier']];

            if (!$manualName && isset($blacklist[$name]))
            {
                if ($blacklist[$name]['_total'] >= 9 ||
                    (array_key_exists($identifier, $blacklist[$name]) && $blacklist[$name][$identifier] >= 3))
                {
                    unset($modules[$key]);
                    continue;
                }
            }
        }

        shuffle($modules);

        // Prepare HTTPSFutures for downloading symbols.
        $futures = [];
        foreach ($modules as $key => $module)
        {
            list($name, $identifier) = [$module['name'], $module['identifier']];

            $futures[$key] = $this->createDownloadFutureFor($name, $identifier);
        }

        $count = count($futures);
        if ($count === 0) {
            $output->writeln('Nothing to download');
            return;
        }

        /** @var ProgressHelper $progress */
        $progress = $this->getHelper('progress');
        $progress->start($output, $count);

        $failed = 0;
        $downloaded = 0;

        // Only run 10 concurrent requests.
        // Remote services can be slow.
        // FutureIterator returns them in the order they resolve, so running concurrently lets the later stages optimize.
        foreach (id(new \FutureIterator($futures))->limit(10) as $key => $future)
        {
            $module = $modules[$key];
            list($name, $identifier) = [$module['name'], $module['identifier']];
            if (!isset($blacklist[$name]))
            {
                $blacklist[$name] = [
                    '_total' => 0
                ];
            }

            if (!isset($blacklist[$name][$identifier]))
            {
                $blacklist[$name][$identifier] = 0;
            }

            if ($this->handleResponse($future, $module))
            {
                $downloaded++;
                $app['db']->executeUpdate('UPDATE module SET present = ? WHERE name = ? AND identifier = ?', [1, $name, $identifier]);
            }
            else
            {
                $failed++;
                $blacklist[$name]['_total']++;
                $blacklist[$name][$identifier]++;

                if (($failed % 50) === 0)
                {
                    $output->writeln('');
                    $output->writeln('Sending blacklist checkpoint...');

                    $this->saveBlacklist($blacklist);
                }
            }

            $progress->advance(1, true);
            // Force redrawing because we can send blacklist checkpoint and
            // inform user about this operation.
        }

        $this->saveBlacklist($blacklist);
        $progress->finish();

        $output->writeln($failed . ' symbols failed to process');
        if ($failed === $count)
        {
            return;
        }

        $lock = \PhutilFileLock::newForPath($app['root'] . '/cache/process.lck');
        $lock->lock(300);

        $app['redis']->del('throttle:cache:symbols');
        $output->writeln('Flushed symbol cache');
        $lock->unlock();

        $this->postExecute($input, $output);
    }

    protected function preExecute(InputInterface $input, OutputInterface $output)
    {
    }

    protected function postExecute(InputInterface $input, OutputInterface $output)
    {
    }

    protected function getSearchModulesQuery($limit)
    {
        $query = 'SELECT DISTINCT name, identifier FROM module WHERE present = 0 AND 1=1';
        if ($limit !== null)
        {
            $query .= ' LIMIT ' . $limit;
        }

        return $query;
    }

    protected function getBlacklistContent()
    {
        $app = $this->getApplication()->getContainer();
        $content = $app['redis']->get($this->getRedisBlacklistKey());
    }

    protected function getRedisBlacklistKey()
    {
        return 'throttle:cache:blacklist' . $this->redisPostfix;
    }

    protected function saveBlacklist($blacklist)
    {
        // Remove the unbanned modules.
        foreach ($blacklist as $key => &$details)
        {
            if ($details['_total'] === 0)
            {
                unset($blacklist[$key]);
                continue;
            }

            // Cleanup obsolete identifiers.
            foreach ($details as $identifier => $count)
            {
                if ($identifier === '_total' || $count > 0) continue;
                unset($details[$identifier]);
            }
        }

        $app = $this->getApplication()->getContainer();
        $app['redis']->set($this->getRedisBlacklistKey(), json_encode($blacklist));
    }

    /**
     * @param $name
     * @param $identifier
     * @return \BaseHTTPFuture
     */
    protected function createDownloadFutureFor($name, $identifier)
    {
        $future = new \HTTPSFuture($this->buildUrl($name, $identifier));
        $future->setExpectStatus([200, 404]);

        return $future;
    }

    /**
     * @param $name
     * @param $identifier
     * @return string
     */
    protected function buildUrl($name, $identifier)
    {
        $moduleBaseUrl = $this->moduleBaseUrl;
        if (empty($moduleBaseUrl))
        {
            throw new \LogicException('Module base URL isn\'t overriden. We can\'t build the correct link.');
        }

        $name = urlencode($name);
        return sprintf('%s/%s/%s', $moduleBaseUrl, $name, $identifier);
    }

    protected function handleResponse(\BaseHTTPFuture $future, $module)
    {
        /** @var \HTTPFutureHTTPResponseStatus $status */
        /** @var string $body */
        /** @var array $headers */
        list($status, $body, $headers) = $future->resolve();
        if ($status->isError())
        {
            throw $status;
        }

        if ($status->getStatusCode() === 404)
        {
            return false;
        }

        return $this->saveSymbols($body, $module);
    }

    protected function getSymbolsPath()
    {
        $app = $this->getApplication()->getContainer();

        return sprintf('%s/symbols/%s', $app['root'], $this->providerName);
    }

    protected function saveSymbols($body, $module)
    {
        list($name, $identifier) = [$module['name'], $module['identifier']];
        $path = sprintf('%s/%s/%s', $this->getSymbolsPath(), $name, $identifier);

        \Filesystem::createDirectory($path, 0755, true);
        $symname = $name;
        if (substr($symname, -4) === '.pdb')
        {
            $symname = substr($symname, 0, -4);
        }

        $symname .= '.sym.gz';
        \Filesystem::writeFile(sprintf('%s/%s', $path, $symname), $body);
        return true;
    }
}

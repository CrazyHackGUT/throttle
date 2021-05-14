<?php

namespace Throttle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SymbolsDownloadCommand extends AbstractSymbolsDownloadCommand
{
    protected $moduleBaseUrl = 'http://msdl.microsoft.com/download/symbols/';
    protected $providerName = 'microsoft';
    protected $requiredBinaries = ['wine', 'cabextract'];

    protected function configure()
    {
        $this->setName('symbols:download')
            ->setDescription('Download missing symbol files from the Microsoft Symbol Server.');

        parent::configure();
    }

    protected function preExecute(InputInterface $input, OutputInterface $output)
    {
        foreach ($this->requiredBinaries as $binary)
        {
            if (\Filesystem::resolveBinary($binary) === null)
            {
                $binaries = sprintf("'%s'", implode('\', \'', $this->requiredBinaries));
                throw new \RuntimeException($binaries . ' need to be available in your PATH to use this command');
            }
        }

        $root = $this->getApplication()->getContainer()['root'];

        // Initialize the wine environment.
        execx('WINEPREFIX=%s WINEDEBUG=-all wine regsvr32 %s', $root . '/.wine', $root . '/bin/msdia80.dll');

        // Create directory for PDBs.
        \Filesystem::createDirectory(sprintf('%s/cache/pdbs', $root), 0777, true);
    }

    protected function postExecute(InputInterface $input, OutputInterface $output)
    {
        $root = $this->getApplication()->getContainer()['root'];
        \Filesystem::remove(sprintf('%s/cache/pdbs', $root));
    }

    protected function getSearchModulesQuery($limit)
    {
        // On Microsoft servers, we're try find only Windows symbols.
        return str_replace('1=1', 'name LIKE \'%.pdb\'',
            parent::getSearchModulesQuery($limit));
    }

    protected function createDownloadFutureFor($name, $identifier)
    {
        return parent::createDownloadFutureFor($name, $identifier)
            ->addHeader('User-Agent', 'Microsoft-Symbol-Server');
    }

    protected function saveSymbols($body, $module)
    {
        list($name, $identifier) = [$module['name'], $module['identifier']];
        $app = $this->getApplication()->getContainer();
        $root = $app['root'];

        // Firstly, we're got an PDB. We need use dump_syms binary.
        // For this, we're flush file in another directory and run our Wine instance.
        $prefix = \Filesystem::createDirectory(sprintf('%s/cache/pdbs/%s-%s', $root, $name, $identifier),
            0777, true);
        \Filesystem::writeFile($prefix . '/' . $name, $body);

        $failed = false;
        try
        {
            execx('WINEPREFIX=%s WINEDEBUG=-all wine %s %s | gzip > %s',
                $root . '/.wine',
                $root . '/bin/dump_syms.exe',
                $prefix . '/' . $name,
                $prefix . '/' . $name . '.sym.gz'
            );
        }
        catch (\CommandException $e)
        {
            $failed = true;
        }

        if ($failed) return false;
        return parent::saveSymbols(\Filesystem::readFile(sprintf('%s/%s.sym.gz', $prefix, $name)), $module);
    }
}

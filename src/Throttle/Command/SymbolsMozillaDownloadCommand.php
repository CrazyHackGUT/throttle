<?php

namespace Throttle\Command;


class SymbolsMozillaDownloadCommand extends AbstractSymbolsDownloadCommand
{
    protected $moduleBaseUrl = 'https://s3-us-west-2.amazonaws.com/org.mozilla.crash-stats.symbols-public/v1/';
    protected $providerName = 'mozilla';
    protected $redisPostfix = ':mozilla';

    protected function configure()
    {
        $this->setName('symbols:mozilla:download')
            ->setDescription('Download missing symbol files from the Mozilla Symbol Server.');

        parent::configure();
    }
}

<?php

namespace Yiendos\MySitesIde\Servers\Nginx\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Servers\Nginx\Traits\InteractsWithNginx;

class NginxReloadCommand extends Command
{
    use InteractsWithNginx;

    /**
     * The ability to configure the console command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('servers:nginx-reload')
            ->setDescription('Reload nginx to pick up config, vhost and certificate changes, testing the config first')
        ;
    }

    /**
     * `nginx -s reload` starts new workers on the re-read config and lets
     * the old ones finish their requests, and only touches nginx - unlike
     * ide:restart, which restarts every container.
     *
     * The config is tested first. nginx would keep the old config on a bad
     * reload anyway, but only says so in its error log, so `-s reload`
     * would still look like it succeeded.
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input, SymfonyStyle $io): int
    {
        if (!$this->nginxRunning()) {
            $io->error('Nginx is not running - start it with servers:nginx-start.');
            return Command::FAILURE;
        }

        if ($this->nginxConfigTest($output) !== 0) {
            $io->error([
                'Nginx configuration has errors - not reloading, the running server is untouched.',
                "If nginx.conf itself can't be opened, the container predates a compose change - recreate it with servers:nginx-start.",
            ]);
            return Command::FAILURE;
        }

        if ($this->compose($output, 'exec -T nginx nginx -s reload') !== 0) {
            $io->error('Nginx did not reload - see above.');
            return Command::FAILURE;
        }

        $io->success('Nginx reloaded.');

        return Command::SUCCESS;
    }
}

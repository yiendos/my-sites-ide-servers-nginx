<?php

namespace Yiendos\MySitesIde\Servers\Nginx\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Servers\Nginx\Traits\InteractsWithNginx;

class NginxStartCommand extends Command
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
            ->setName('servers:nginx-start')
            ->setDescription('Start the nginx container - or recreate it if its compose config changed - testing the config first')
        ;
    }

    /**
     * Tests the config before starting - a broken one would otherwise leave
     * the container exiting straight after `up` with nothing on screen.
     *
     * Always runs `up -d`, even when nginx is already running: it's a no-op
     * for an up-to-date container, and recreates one whose compose config
     * has changed - e.g. a container created before nginx became a plugin,
     * whose nginx.conf mount points at a path that no longer exists, or a
     * new IDE_SITE_ALIAS. The test runs in a throwaway container for the
     * same reason, as the running one may be the stale one.
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input, SymfonyStyle $io): int
    {
        if ($this->nginxConfigTest($output, fresh: true) !== 0) {
            $io->error('Nginx configuration has errors - not starting.');
            return Command::FAILURE;
        }

        if ($this->compose($output, 'up -d nginx') !== 0) {
            $io->error('Nginx did not start - see above.');
            return Command::FAILURE;
        }

        $port = getenv('NGINX_PORT') ?: '443';

        $io->success('Nginx started - https://default.localhost' . ($port === '443' ? '' : ":{$port}"));

        return Command::SUCCESS;
    }
}

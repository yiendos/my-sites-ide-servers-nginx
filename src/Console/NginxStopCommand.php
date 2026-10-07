<?php

namespace Yiendos\MySitesIde\Servers\Nginx\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Servers\Nginx\Traits\InteractsWithNginx;

class NginxStopCommand extends Command
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
            ->setName('servers:nginx-stop')
            ->setDescription('Stop the nginx container, leaving the rest of the IDE running')
        ;
    }

    /**
     * Stopped rather than removed, so servers:nginx-start brings back the
     * same container. The next ide:spark starts it again (autostart).
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input, SymfonyStyle $io): int
    {
        if (!$this->nginxRunning()) {
            $io->writeln('Nginx is not running - nothing to stop.');
            return Command::SUCCESS;
        }

        if ($this->compose($output, 'stop nginx') !== 0) {
            $io->error('Nginx did not stop - see above.');
            return Command::FAILURE;
        }

        $io->success('Nginx stopped.');

        return Command::SUCCESS;
    }
}

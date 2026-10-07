<?php

namespace Yiendos\MySitesIde\Servers\Nginx\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Servers\Nginx\Traits\InteractsWithNginx;

class NginxTestCommand extends Command
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
            ->setName('servers:nginx-test')
            ->setDescription("Test nginx's configuration, including every site's *nginx.conf")
            ->addOption('dump', null, InputOption::VALUE_NONE, 'Also print the whole config with every site\'s vhost expanded (nginx -T) - handy for spotting a server_name two sites share')
        ;
    }

    /**
     * Works whether or not nginx is running, so a vhost can be checked
     * before the server ever loads it
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input, SymfonyStyle $io): int
    {
        if ($this->nginxConfigTest($output, $input->getOption('dump') ? '-T' : '-t') !== 0) {
            $io->error('Nginx configuration has errors - see above.');
            return Command::FAILURE;
        }

        $io->success('Nginx configuration is valid.');

        return Command::SUCCESS;
    }
}

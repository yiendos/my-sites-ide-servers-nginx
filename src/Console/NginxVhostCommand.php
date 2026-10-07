<?php

namespace Yiendos\MySitesIde\Servers\Nginx\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Servers\Nginx\Paths;

class NginxVhostCommand extends Command
{
    /**
     * The placeholder in stubs/sample.vhost replaced with the site name
     */
    private const PLACEHOLDER = '__PROJECT__';

    /**
     * The ability to configure the console command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('servers:nginx-vhost')
            ->setDescription("Create a site's nginx vhost (Repos/<site>/_build/config/1-<site>-nginx.conf) from the sample")
            ->addArgument('site', InputArgument::REQUIRED, 'The site folder under Repos/, e.g. example')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Write the vhost even if the site already has an nginx config')
        ;
    }

    /**
     * Run by ide:create-site and ide:repo-clone through the plugin's
     * site-created hook, or by hand for a site that predates the plugin.
     *
     * A site that already has any *nginx.conf is left alone - a cloned
     * repository usually brings its own, and nginx loads every one it finds,
     * so a second vhost for the same server_name would be ignored with a
     * "conflicting server name" warning.
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input, SymfonyStyle $io): int
    {
        $site = $input->getArgument('site');

        if (!is_dir(Paths::root() . "/Repos/{$site}")) {
            $io->error("Repos/{$site} does not exist - create or clone the site first.");
            return Command::FAILURE;
        }

        // the same pattern conf/nginx.conf includes
        $existing = glob(Paths::siteConfig($site, '*nginx.conf')) ?: [];

        if ($existing !== [] && !$input->getOption('force')) {
            $io->warning('Nginx vhost already present, leaving it alone (--force to write the sample anyway): '
                . implode(', ', array_map(Paths::relative(...), $existing)));
            return Command::SUCCESS;
        }

        if (!is_dir(Paths::siteConfig($site))) {
            mkdir(Paths::siteConfig($site), 0755, true);
        }

        $vhost = Paths::siteConfig($site, "1-{$site}-nginx.conf");
        $sample = (string) file_get_contents(Paths::package('stubs/sample.vhost'));

        file_put_contents($vhost, str_replace(self::PLACEHOLDER, $site, $sample));

        $port = getenv('NGINX_PORT') ?: '443';

        $io->writeln("<info>Created " . Paths::relative($vhost) . "</> - https://{$site}.localhost" . ($port === '443' ? '' : ":{$port}") . " once nginx reloads (servers:nginx-reload).");

        return Command::SUCCESS;
    }
}

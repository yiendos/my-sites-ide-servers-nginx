<?php

namespace Yiendos\MySitesIde\Servers\Nginx\Traits;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drives the nginx compose service from the host. Every call goes through
 * `docker compose` from the IDE root, as the my-sites-ide CLI is run there.
 */
trait InteractsWithNginx
{
    /**
     * Whether the nginx container is up
     *
     * @return bool
     */
    protected function nginxRunning(): bool
    {
        return trim((string) shell_exec('docker compose ps -q --status running nginx 2>/dev/null')) !== '';
    }

    /**
     * Checks the config with `nginx -t` - in the running container when
     * there is one, otherwise (or when $fresh) a throwaway one built from
     * the current compose file, so a broken config can be caught before
     * start/reload ever touches the real server
     *
     * @param OutputInterface $output
     * @param string $flag -t (syntax) or -T (syntax, then the whole config with every include expanded)
     * @param bool $fresh test in a throwaway container even if nginx is running
     * @return int the nginx exit code, 0 when the config is good
     */
    protected function nginxConfigTest(OutputInterface $output, string $flag = '-t', bool $fresh = false): int
    {
        $command = !$fresh && $this->nginxRunning()
            ? "exec -T nginx nginx {$flag}"
            : "run --rm --no-deps -T nginx nginx {$flag}";

        return $this->compose($output, $command);
    }

    /**
     * Runs `docker compose <arguments>`, echoing it first like the core commands do
     *
     * @param OutputInterface $output
     * @param string $arguments
     * @return int the exit code
     */
    protected function compose(OutputInterface $output, string $arguments): int
    {
        $output->writeLn("docker compose {$arguments}");
        passthru("docker compose {$arguments}", $code);

        return $code;
    }
}

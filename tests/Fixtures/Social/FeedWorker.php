<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

/** A real independent PHP/PG session parked until the parent's feed has captured its shortlist. */
final class FeedWorker
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    public function __construct(string $action, string $id)
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__.'/feed-child.php', $action, $id], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Cannot launch feed test worker');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_timeout($this->pipes[1], 10);
        if (trim((string) fgets($this->pipes[1])) !== 'READY') {
            throw new \RuntimeException('Feed worker did not reach barrier');
        }
    }

    public function commit(): void
    {
        fwrite($this->pipes[0], "GO\n");
        fclose($this->pipes[0]);
        $result = trim((string) stream_get_contents($this->pipes[1]));
        $error = (string) stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $exit = proc_close($this->process);
        fwrite(STDERR, "Feed independent-process barrier exit={$exit} result={$result}\n".$error);
        if ($exit !== 0 || $result !== 'COMMITTED') {
            throw new \RuntimeException('Feed worker failed: '.$error);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }
    }
}
